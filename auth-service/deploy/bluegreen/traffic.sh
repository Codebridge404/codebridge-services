#!/usr/bin/env bash
# Shared by deploy and rollback. Caller supplies ROOT_DIR, SCRIPT_DIR and COMPOSE_FILE.
LOCK_DIR="${SCRIPT_DIR}/.deployment.lock"
if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
    echo "Another deployment or rollback is running (${LOCK_DIR})." >&2
    exit 1
fi
trap 'rmdir "${LOCK_DIR}"' EXIT

compose() { docker compose -f "${COMPOSE_FILE}" "$@"; }

wait_for_application() {
    local color="$1"
    local attempt
    for attempt in $(seq 1 30); do
        if compose exec -T "auth-service-${color}" php -r '
            $socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 2);
            if (!$socket) exit(1);
            fclose($socket);
            require "/var/www/html/vendor/autoload.php";
            $app = require "/var/www/html/bootstrap/app.php";
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $response = $kernel->handle(Illuminate\Http\Request::create("/api/v1/health", "GET"));
            exit($response->getStatusCode() === 200 ? 0 : 1);
        '; then
            return 0
        fi
        sleep 2
    done
    return 1
}

wait_for_proxy() {
    local color="$1"
    local attempt
    for attempt in $(seq 1 30); do
        if compose exec -T "auth-service-${color}" php -r '
            $context = stream_context_create(["http" => ["timeout" => 3, "ignore_errors" => true]]);
            $body = @file_get_contents("http://auth-proxy/health", false, $context);
            $data = json_decode($body ?: "", true);
            $headers = array_map("strtolower", $http_response_header ?? []);
            exit(($data["status"] ?? null) === "healthy" && in_array("x-auth-color: " . $argv[1], $headers, true) ? 0 : 1);
        ' "${color}"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

switch_traffic() {
    local target="$1"
    local previous="$2"
    cp "${NGINX_DIR}/upstream-${target}.conf" "${NGINX_DIR}/active-upstream.conf"
    cp "${NGINX_DIR}/stream-${target}.conf" "${NGINX_DIR}/active-stream.conf"
    if ! compose exec -T auth-proxy nginx -t || ! compose exec -T auth-proxy nginx -s reload || ! wait_for_proxy "${target}"; then
        echo "Traffic verification failed; restoring ${previous}. Both application slots remain available." >&2
        cp "${NGINX_DIR}/upstream-${previous}.conf" "${NGINX_DIR}/active-upstream.conf"
        cp "${NGINX_DIR}/stream-${previous}.conf" "${NGINX_DIR}/active-stream.conf"
        compose exec -T auth-proxy nginx -t && compose exec -T auth-proxy nginx -s reload
        return 1
    fi
    # Preserve the live application slot until old HTTP and TCP workers have drained.
    printf '%s\n' "${target}" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "${STATE_FILE}"
    local attempt
    for attempt in $(seq 1 150); do
        if ! compose exec -T auth-proxy sh -c 'ps -o args | grep -q "[n]ginx: worker process is shutting down"'; then
            compose stop -t 120 "auth-service-${previous}"
            return 0
        fi
        sleep 1
    done
    echo "Traffic is on ${target}; keeping ${previous} running because connections are still draining." >&2
}
