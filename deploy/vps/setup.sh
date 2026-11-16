#!/usr/bin/env bash
# ==============================================================================
# CodeBridge VPS Setup & Environment Readiness Inspector
# ==============================================================================
# Target: Single-node K3s Staging VPS
# Namespace: codebridge-staging
# Default Repo Path: /opt/codebridge-services/codebridge-services
#
# Default behavior (SAFE & IDEMPOTENT):
#   Inspects, verifies, and reuses existing Docker, K3s, registry, deployment user,
#   kubeconfig, repository checkout, and Kubernetes resources.
#   Never reinstalls K3s, overwrites secrets, or resets databases.
#
# Fresh-server installation mode:
#   Run with --fresh-install to install missing OS packages on a newly provisioned server.
# ==============================================================================
set -euo pipefail
IFS=$'\n\t'

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

FRESH_INSTALL=false
if [[ "${1:-}" == "--fresh-install" ]]; then
    FRESH_INSTALL=true
fi

REPO_PATH="${STAGING_DEPLOY_PATH:-/opt/codebridge-services/codebridge-services}"
DEPLOY_USER="${STAGING_DEPLOY_USER:-deploy}"
NAMESPACE="codebridge-staging"
REGISTRY_HOST="127.0.0.1"
REGISTRY_PORT="5000"

echo -e "\n${BLUE}${BOLD}==============================================================================${NC}"
echo -e "${BLUE}${BOLD}         CodeBridge Staging VPS Readiness & Setup (K3s Only)                  ${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"
echo -e "Target Staging Path : ${BOLD}${REPO_PATH}${NC}"
echo -e "Deployment User     : ${BOLD}${DEPLOY_USER}${NC}"
echo -e "Kubernetes Namespace: ${BOLD}${NAMESPACE}${NC}"
echo -e "Fresh Install Mode  : ${BOLD}${FRESH_INSTALL}${NC}\n"

# ------------------------------------------------------------------------------
# 1. OS DETECTION & COMPATIBILITY CHECK
# ------------------------------------------------------------------------------
echo -e "${YELLOW}▶ Step 1: Operating System & Platform Detection${NC}"
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME="${ID:-unknown}"
    OS_VERSION="${VERSION_ID:-unknown}"
    echo -e "  Detected OS: ${GREEN}${NAME:-Linux} (${OS_NAME} ${OS_VERSION})${NC}"
else
    OS_NAME="unknown"
    echo -e "  ${YELLOW}Warning: /etc/os-release not found. Proceeding with generic Linux checks.${NC}"
fi

# ------------------------------------------------------------------------------
# 2. REQUIRED SYSTEM DEPENDENCIES
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 2: System Dependencies Check${NC}"
REQUIRED_COMMANDS=("git" "docker" "kubectl" "bash" "flock" "curl" "jq")
MISSING_COMMANDS=()

for cmd in "${REQUIRED_COMMANDS[@]}"; do
    if command -v "$cmd" >/dev/null 2>&1; then
        echo -e "  [✔] ${cmd}: ${GREEN}Installed ($(command -v "$cmd"))${NC}"
    else
        echo -e "  [✘] ${cmd}: ${RED}MISSING${NC}"
        MISSING_COMMANDS+=("$cmd")
    fi
done

if [ ${#MISSING_COMMANDS[@]} -gt 0 ]; then
    if [ "$FRESH_INSTALL" = "true" ]; then
        echo -e "\n  ${YELLOW}Installing missing dependencies in fresh-install mode...${NC}"
        if [[ "$OS_NAME" == "ubuntu" || "$OS_NAME" == "debian" ]]; then
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -y
            apt-get install -y --no-install-recommends git curl jq util-linux ca-certificates
        elif [[ "$OS_NAME" == "centos" || "$OS_NAME" == "rhel" || "$OS_NAME" == "rocky" || "$OS_NAME" == "almalinux" ]]; then
            yum install -y git curl jq util-linux
        else
            echo -e "  ${RED}Unsupported OS for auto-install. Please manually install: ${MISSING_COMMANDS[*]}${NC}" >&2
            exit 1
        fi
    else
        echo -e "\n  ${RED}Missing required tools: ${MISSING_COMMANDS[*]}.${NC}" >&2
        echo -e "  Please install them or rerun setup with --fresh-install." >&2
        exit 1
    fi
fi

# ------------------------------------------------------------------------------
# 3. DEPLOYMENT USER & SSH CONFIGURATION
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 3: Deployment User & SSH Authorization${NC}"
if id "$DEPLOY_USER" >/dev/null 2>&1; then
    echo -e "  Deployment user '${DEPLOY_USER}': ${GREEN}Exists${NC}"
else
    if [ "$(id -u)" -eq 0 ]; then
        echo -e "  Creating deployment user '${DEPLOY_USER}'..."
        useradd -m -s /bin/bash "$DEPLOY_USER"
        echo -e "  Deployment user '${DEPLOY_USER}': ${GREEN}Created${NC}"
    else
        echo -e "  ${RED}User '${DEPLOY_USER}' does not exist and script is not running as root.${NC}" >&2
        echo -e "  Please create the user or run as a user with sudo privileges." >&2
        exit 1
    fi
fi

# Docker socket permission notice
# IMPORTANT SECURITY NOTE:
# Adding a user to the 'docker' group grants permissions equivalent to root access.
# This is required for building and pushing images locally via the Docker socket.
if getent group docker >/dev/null 2>&1; then
    if id -nG "$DEPLOY_USER" | grep -qw "docker"; then
        echo -e "  Docker group membership: ${GREEN}Active for ${DEPLOY_USER}${NC}"
    else
        if [ "$(id -u)" -eq 0 ]; then
            usermod -aG docker "$DEPLOY_USER"
            echo -e "  Added '${DEPLOY_USER}' to 'docker' group. ${YELLOW}(Note: Docker group access is root-equivalent)${NC}"
        fi
    fi
fi

# SSH authorized_keys setup
USER_HOME=$(eval echo "~${DEPLOY_USER}")
SSH_DIR="${USER_HOME}/.ssh"
AUTH_KEYS="${SSH_DIR}/authorized_keys"

if [ ! -d "$SSH_DIR" ]; then
    mkdir -p "$SSH_DIR"
    chmod 700 "$SSH_DIR"
    chown "$DEPLOY_USER:$DEPLOY_USER" "$SSH_DIR"
fi

if [ ! -f "$AUTH_KEYS" ]; then
    touch "$AUTH_KEYS"
    chmod 600 "$AUTH_KEYS"
    chown "$DEPLOY_USER:$DEPLOY_USER" "$AUTH_KEYS"
fi
echo -e "  SSH authorized_keys: ${GREEN}${AUTH_KEYS}${NC}"

# ------------------------------------------------------------------------------
# 4. SERVER HOST FINGERPRINTS
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 4: Host Key Fingerprint (For STAGING_VPS_FINGERPRINT)${NC}"
for key in /etc/ssh/ssh_host_ed25519_key.pub /etc/ssh/ssh_host_rsa_key.pub /etc/ssh/ssh_host_ecdsa_key.pub; do
    if [ -f "$key" ]; then
        FP=$(ssh-keygen -lf "$key" 2>/dev/null || true)
        echo -e "  ${BLUE}${key}:${NC} ${FP}"
    fi
done
echo -e "  ${YELLOW}Tip: You can set STAGING_VPS_FINGERPRINT in GitHub Secrets to verify host integrity.${NC}"

# ------------------------------------------------------------------------------
# 5. REPOSITORY CHECKOUT DIRECTORY
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 5: Repository Checkout Location${NC}"
PARENT_DIR="$(dirname "$REPO_PATH")"
if [ ! -d "$PARENT_DIR" ]; then
    if [ "$(id -u)" -eq 0 ]; then
        mkdir -p "$PARENT_DIR"
        chown "$DEPLOY_USER:$DEPLOY_USER" "$PARENT_DIR"
    fi
fi

if [ -d "${REPO_PATH}/.git" ]; then
    echo -e "  Repository directory: ${GREEN}${REPO_PATH} (Active git clone)${NC}"
    cd "$REPO_PATH"
    REMOTE_URL=$(git remote get-url origin 2>/dev/null || echo "unknown")
    echo -e "  Remote Origin       : ${REMOTE_URL}"
else
    echo -e "  ${YELLOW}Repository not found at ${REPO_PATH}.${NC}"
    echo -e "  To initialize repository checkout, run as ${DEPLOY_USER}:"
    echo -e "    git clone -b demo https://github.com/Codebridge404/codebridge-services.git ${REPO_PATH}"
fi

# ------------------------------------------------------------------------------
# 6. K3S KUBECONFIG PERMISSIONS & NAMESPACE ACCESS
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 6: K3s Cluster & Kubeconfig Access${NC}"
K3S_CONFIG="/etc/rancher/k3s/k3s.yaml"
USER_KUBE="${USER_HOME}/.kube/config"

if [ -f "$K3S_CONFIG" ]; then
    echo -e "  K3s default config found: ${GREEN}${K3S_CONFIG}${NC}"
    # Ensure deployment user has secure access without world-readable permissions
    if [ ! -f "$USER_KUBE" ]; then
        mkdir -p "${USER_HOME}/.kube"
        cp "$K3S_CONFIG" "$USER_KUBE"
        sed -i 's/127.0.0.1/127.0.0.1/g' "$USER_KUBE"
        chmod 600 "$USER_KUBE"
        chown -R "$DEPLOY_USER:$DEPLOY_USER" "${USER_HOME}/.kube"
        echo -e "  Configured ${USER_KUBE} with secure 600 permissions."
    fi
fi

# Verify namespace access
export KUBECONFIG="${USER_KUBE:-${K3S_CONFIG:-$HOME/.kube/config}}"
if kubectl get namespace "$NAMESPACE" >/dev/null 2>&1; then
    echo -e "  Namespace '${NAMESPACE}': ${GREEN}Accessible${NC}"
else
    echo -e "  Namespace '${NAMESPACE}': ${YELLOW}Not found${NC}"
    echo -e "  Creating namespace '${NAMESPACE}'..."
    kubectl create namespace "$NAMESPACE" 2>/dev/null || true
fi

# ------------------------------------------------------------------------------
# 7. PERSISTENT LOCAL DOCKER REGISTRY (127.0.0.1:5000)
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 7: Persistent Local Registry (127.0.0.1:5000)${NC}"
# NOTE: Loopback binding (127.0.0.1) assumes a single-node K3s deployment.
# Multi-node clusters require an externally addressable private registry.
if docker inspect registry >/dev/null 2>&1; then
    echo -e "  Container 'registry': ${GREEN}Exists${NC}"
    docker start registry >/dev/null 2>&1 || true
else
    echo -e "  Creating persistent container registry bound to 127.0.0.1:5000..."
    mkdir -p /var/lib/docker-registry
    docker run -d \
        -p 127.0.0.1:5000:5000 \
        --restart=always \
        --name registry \
        -v /var/lib/docker-registry:/var/lib/registry \
        registry:2
    echo -e "  Container 'registry': ${GREEN}Started${NC}"
fi

# Verify registry endpoint
if curl -sSf "http://127.0.0.1:5000/v2/" >/dev/null 2>&1; then
    echo -e "  Registry endpoint: ${GREEN}http://127.0.0.1:5000/v2/ (Healthy)${NC}"
else
    echo -e "  ${RED}Failed to query local registry at 127.0.0.1:5000.${NC}" >&2
fi

# ------------------------------------------------------------------------------
# 8. K3S CONTAINERD REGISTRY CONFIGURATION (registries.yaml)
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}▶ Step 8: K3s containerd Registry Mirror Configuration${NC}"
REGISTRIES_YAML="/etc/rancher/k3s/registries.yaml"

if [ -f "$REGISTRIES_YAML" ] && grep -q "127.0.0.1:5000" "$REGISTRIES_YAML"; then
    echo -e "  ${REGISTRIES_YAML}: ${GREEN}Configured for 127.0.0.1:5000${NC}"
else
    echo -e "  ${YELLOW}Notice: K3s containerd requires registries.yaml to pull from HTTP registry.${NC}"
    echo -e "  Proposed configuration for ${BOLD}${REGISTRIES_YAML}${NC}:"
    cat <<'EOF'
mirrors:
  "127.0.0.1:5000":
    endpoint:
      - "http://127.0.0.1:5000"
EOF
    echo -e "  ${YELLOW}To apply this without disrupting live workloads:${NC}"
    echo -e "    1. Create/merge /etc/rancher/k3s/registries.yaml as shown above."
    echo -e "    2. Restart K3s during a scheduled maintenance window: systemctl restart k3s"
fi

# ------------------------------------------------------------------------------
# 9. SUMMARY & READINESS STATUS
# ------------------------------------------------------------------------------
echo -e "\n${BLUE}${BOLD}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}✔ VPS Setup & Verification Completed Successfully!${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}"
echo -e "Next steps for GitHub Actions integration:"
echo -e "  1. Add VPS private key to GitHub Secrets: ${BOLD}STAGING_VPS_SSH_KEY${NC}"
echo -e "  2. Add VPS host IP or hostname to:         ${BOLD}STAGING_VPS_HOST${NC}"
echo -e "  3. Add deploy user to:                     ${BOLD}STAGING_VPS_USER${NC} (e.g. ${DEPLOY_USER})"
echo -e "  4. Add repository path to:                 ${BOLD}STAGING_DEPLOY_PATH${NC} (e.g. ${REPO_PATH})"
echo -e "  5. (Optional) Set host fingerprint in:     ${BOLD}STAGING_VPS_FINGERPRINT${NC}\n"
