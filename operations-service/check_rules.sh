#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise - Operations Service Pre-Push Quality & Testing Gate
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
echo -e "${BLUE}${BOLD}   CodeBridges Operations Service - Pre-Push Quality & Security Rule Gate     ${NC}"
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

# 1. Unit Testing
run_rule_check "1" "Unit Testing (Currency Math, Logic & Domain Models)" \
    "php artisan test --filter=ExchangeRateUnitTest,ExampleTest"

# 2. Functional & API Testing
run_rule_check "2" "Functional & API Testing (Catalog, Sales, Finance & Inventory)" \
    "php artisan test --filter=CatalogAndOperationsApiTest,CatalogTest,SalesTest,FinanceTest,InventoryTest"

# 3. Validation Testing
run_rule_check "3" "Input Validation Testing (Rejection of Malformed / Missing Inputs)" \
    "php artisan test --filter=OperationsInputValidationTest,InputValidationSecurityTest"

# 4. Authentication Testing
run_rule_check "4" "Authentication Testing (Stateless JWT Verification & Forgery Defense)" \
    "php artisan test --filter=OperationsAuthenticationTest,JwtAuthenticationTest"

# 5. Shift & Operational Workflow Testing
run_rule_check "5" "Operational Testing (Full Shift Lifecycle, Movements, PIN & Variance Alerts)" \
    "php artisan test --filter=ShiftWorkflowTest,ShiftsTest,ShiftVarianceNotificationTest,PaymentsTest"

# 6. Offline POS Conflict & Idempotency Testing
run_rule_check "6" "Offline POS Sync Testing (Stock Collision Resolution & Idempotency)" \
    "php artisan test --filter=OfflineSyncConflictTest"

# 7. Exchange Rate & Financial Integration Testing
run_rule_check "7" "Financial Integration Testing (NBC Exchange Rates & Currency Conversion)" \
    "php artisan test --filter=ExchangeRateTest"

# 8. Dependency Security Audit
run_rule_check "8" "Dependency Security Audit (composer audit = 0 vulnerabilities)" \
    "composer audit"

# 9. Full Test Suite Regression Gate
run_rule_check "9" "Complete Regression Suite (All Feature & Unit Tests)" \
    "php artisan test"

# Summary
echo -e "${BLUE}${BOLD}==============================================================================${NC}"
if [ ${FAILED_RULES} -eq 0 ]; then
    echo -e "${GREEN}${BOLD}✔ ALL OPERATIONS SERVICE QUALITY & SECURITY RULES PASSED! (0 Failures)${NC}"
    echo -e "${GREEN}Safe to commit and push to GitHub.${NC}"
    echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"
    exit 0
else
    echo -e "${RED}${BOLD}✘ PRE-PUSH CHECKS FAILED! (${FAILED_RULES} Failed Rules)${NC}"
    echo -e "${RED}Resolve the failures above before pushing to GitHub.${NC}"
    echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"
    exit 1
fi

