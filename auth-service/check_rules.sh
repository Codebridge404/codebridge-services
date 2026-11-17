#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise - Pre-Push Quality & Testing Rule Gate
# Enforces all 9 testing and security rules before pushing to GitHub.
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

# Ensure isolated testing environment for repeatable test execution
export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE=:memory:
export CACHE_STORE=array
export CACHE_DRIVER=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync
export FILESYSTEM_DISK=local
export TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "\n${BLUE}${BOLD}==============================================================================${NC}"
echo -e "${BLUE}${BOLD}         CodeBridges Pre-Push Quality & Security Rule Verification            ${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"

FAILED_RULES=0

run_rule_check() {
    local RULE_NUM="$1"
    local RULE_NAME="$2"
    local CMD="$3"

    echo -e "${YELLOW}[Rule ${RULE_NUM}] Checking: ${RULE_NAME}...${NC}"

    local OUTPUT
    if OUTPUT=$(eval "${CMD}" 2>&1) && ! printf '%s' "${OUTPUT}" | grep -q "No tests found"; then
        echo -e "  ${GREEN}✔ PASS${NC} - ${RULE_NAME}\n"
    else
        echo -e "  ${RED}✘ FAIL${NC} - ${RULE_NAME}\n"
        echo -e "${RED}Detailed error output:${NC}"
        printf '%s\n\n' "${OUTPUT}"
        FAILED_RULES=$((FAILED_RULES + 1))
    fi
}

# ------------------------------------------------------------------------------
# 1. Complete Test Suite Quality Gate (Zero Omissions - All Tests)
# ------------------------------------------------------------------------------
run_rule_check "1" "Comprehensive Test Suite Gate (All Unit & Feature Test Classes Without Omission)" \
    "php artisan test"

# ------------------------------------------------------------------------------
# 2. Unit Testing
# ------------------------------------------------------------------------------
run_rule_check "2" "Unit Testing (Methods, Classes & Domain Models)" \
    "php artisan test --testsuite=Unit"

# ------------------------------------------------------------------------------
# 3. Functional & API Testing
# ------------------------------------------------------------------------------
run_rule_check "3" "Functional & API Testing (Endpoints, Responses & Status Codes)" \
    "php artisan test --filter='AuthTest|AdminTest|ApiKeysTest|TenantsTest|UsersTest|UserInvitationTest|OrganizationTest|MeetingsTest|CrmTest|SuperAdminTest'"

# ------------------------------------------------------------------------------
# 4. Input Validation Testing
# ------------------------------------------------------------------------------
run_rule_check "4" "Validation Testing (Rejection of Invalid / Malformed Inputs)" \
    "php artisan test --filter='InputValidationSecurityTest'"

# ------------------------------------------------------------------------------
# 5. Authentication & Token Lifecycle Testing
# ------------------------------------------------------------------------------
run_rule_check "5" "Authentication Testing (Login, JWT, 2FA, Passkeys, Atomic Fallbacks & Legacy Tokens)" \
    "php artisan test --filter='AuthTest|JwtAuthenticationTest|TwoFactorAuthenticationTest|TwoFactorHardeningTest|PasskeyAuthenticationTest|SessionsSecurityTest|RejectExpiredSanctumTokenTest|UserSessionRotationFlowTest|AtomicAuthenticationFallbackTest|LegacyTokenCompatibilityTest|ReviewDefectsRegressionTest'"

# ------------------------------------------------------------------------------
# 6. Authorization & RBAC Testing
# ------------------------------------------------------------------------------
run_rule_check "6" "Authorization Testing (Policies, RBAC, Scopes & Super Admin Boundaries)" \
    "php artisan test --filter='RolesPermissionsTest|SuperAdminTest|PolicySecurityEnforcementTest'"

# ------------------------------------------------------------------------------
# 7. Security & Tenant Isolation Testing
# ------------------------------------------------------------------------------
run_rule_check "7" "Security Testing (WAF, SQLi, XSS, Tenant Isolation, Financial & Log Redaction)" \
    "php artisan test --filter='ProtectAttackMiddlewareTest|PrivilegeAndTenantIsolationSecurityTest|LoggingAndAuditSecurityTest|FinancialAndApiHardeningTest|StepFiveHardeningTest|TenantRoleIsolationSecurityTest|AuditP1RemediationTest|NotificationProviderSecurityTest|MeetingTelegramEncryptionAndRetryTest|SecurityEventsTest'"

# ------------------------------------------------------------------------------
# 8. Integration & Infrastructure Testing
# ------------------------------------------------------------------------------
run_rule_check "8" "Integration Testing (Database Transactions, Redis & MinIO WebP Uploads)" \
    "php artisan test --filter='AvatarUploadTest|UserRelationshipsTest|InfrastructureIntegrationTest'"

# ------------------------------------------------------------------------------
# 9. Performance & Telemetry Testing
# ------------------------------------------------------------------------------
run_rule_check "9" "Performance & Telemetry Testing (Sub-200ms Latency, Redis Cache & Queues)" \
    "php artisan test --filter='StepThreeSettingsAndRevocationTest|StepFourQueueAndHorizonTest|StepSixTelemetryAndLatencyTest'"

# ------------------------------------------------------------------------------
# 10. Regression Testing
# ------------------------------------------------------------------------------
run_rule_check "10" "Regression Testing (Ensuring Previously Resolved Defects Remain Fixed)" \
    "php artisan test --filter='ReviewRegressionTest|AllModelsCrudTest|ReviewDefectsRegressionTest'"

# ------------------------------------------------------------------------------
# 11. Deployment, Backup & Recovery Testing
# ------------------------------------------------------------------------------
run_rule_check "11" "Deployment & Recovery Testing (Blue/Green Rollback, K8s Kustomize & Backup Recovery)" \
    "php artisan test --filter='DeploymentTest|RecoveryAndBackupTest'"

# ------------------------------------------------------------------------------
# 12. Dependency Vulnerability Audit
# ------------------------------------------------------------------------------
run_rule_check "12" "Dependency Security Audit (composer audit = 0 vulnerabilities, Packagist reachable)" \
    "composer audit"

# ------------------------------------------------------------------------------
# Final Verification Summary
# ------------------------------------------------------------------------------
echo -e "${BLUE}${BOLD}==============================================================================${NC}"
if [ ${FAILED_RULES} -eq 0 ]; then
    echo -e "${GREEN}${BOLD}✔ ALL QUALITY & SECURITY RULES PASSED! (0 Failures)${NC}"
    echo -e "${GREEN}Safe to commit and push to GitHub.${NC}"
    echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"
    exit 0
else
    echo -e "${RED}${BOLD}✘ PRE-PUSH CHECKS FAILED! (${FAILED_RULES} Failed Rules)${NC}"
    echo -e "${RED}Resolve the failures above before pushing to GitHub.${NC}"
    echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"
    exit 1
fi
