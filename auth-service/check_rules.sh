#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise - Pre-Push Quality & Testing Rule Gate
# Enforces all 9 testing and security rules before pushing to GitHub.
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

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
    
    if eval "${CMD}" > /dev/null 2>&1; then
        echo -e "  ${GREEN}✔ PASS${NC} - ${RULE_NAME}\n"
    else
        echo -e "  ${RED}✘ FAIL${NC} - ${RULE_NAME}\n"
        echo -e "${RED}Detailed error output:${NC}"
        eval "${CMD}" || true
        echo ""
        FAILED_RULES=$((FAILED_RULES + 1))
    fi
}

# ------------------------------------------------------------------------------
# 1. Unit Testing
# ------------------------------------------------------------------------------
run_rule_check "1" "Unit Testing (Methods, Classes & Domain Models)" \
    "php artisan test --testsuite=Unit"

# ------------------------------------------------------------------------------
# 2. Functional & API Testing
# ------------------------------------------------------------------------------
run_rule_check "2" "Functional & API Testing (Endpoints, Responses & Status Codes)" \
    "php artisan test --filter=AuthTest,AdminTest,ApiKeysTest,TenantsTest,UsersTest"

# ------------------------------------------------------------------------------
# 3. Input Validation Testing
# ------------------------------------------------------------------------------
run_rule_check "3" "Validation Testing (Rejection of Invalid / Malformed Inputs)" \
    "php artisan test --filter=InputValidationSecurityTest"

# ------------------------------------------------------------------------------
# 4. Authentication Testing
# ------------------------------------------------------------------------------
run_rule_check "4" "Authentication Testing (Login, JWT Lifecycle, 2FA & Logout)" \
    "php artisan test --filter=AuthTest,TwoFactorAuthenticationTest"

# ------------------------------------------------------------------------------
# 5. Authorization & RBAC Testing
# ------------------------------------------------------------------------------
run_rule_check "5" "Authorization Testing (RBAC, Scopes & Super Admin Boundaries)" \
    "php artisan test --filter=RolesPermissionsTest,SuperAdminTest"

# ------------------------------------------------------------------------------
# 6. Security & Tenant Isolation Testing
# ------------------------------------------------------------------------------
run_rule_check "6" "Security Testing (SQLi, XSS, Tenant Isolation, Audit Redaction)" \
    "php artisan test --filter=PrivilegeAndTenantIsolationSecurityTest,LoggingAndAuditSecurityTest"

# ------------------------------------------------------------------------------
# 7. Integration Testing
# ------------------------------------------------------------------------------
run_rule_check "7" "Integration Testing (Database Transactions, Redis & MinIO WebP Uploads)" \
    "php artisan test --filter=AvatarUploadTest,UserRelationshipsTest"

# ------------------------------------------------------------------------------
# 8. Regression Testing
# ------------------------------------------------------------------------------
run_rule_check "8" "Regression Testing (Ensuring Previously Resolved Defects Remain Fixed)" \
    "php artisan test --filter=ReviewRegressionTest"

# ------------------------------------------------------------------------------
# 9. Deployment & Infrastructure Health Testing
# ------------------------------------------------------------------------------
run_rule_check "9" "Deployment Testing (Blue/Green Rollback, Offline K8s Kustomize & Backup Health)" \
    "php artisan test --filter=DeploymentTest"

# ------------------------------------------------------------------------------
# 10. Dependency Vulnerability Audit
# ------------------------------------------------------------------------------
run_rule_check "10" "Dependency Security Audit (composer audit = 0 vulnerabilities)" \
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
