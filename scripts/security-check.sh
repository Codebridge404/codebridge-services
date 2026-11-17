#!/bin/bash
# ==============================================================================
# CodeBridge 4-Pillar Security & AppSec Pipeline Runner
# 1. Composer Audit (Dependency Vulnerabilities)
# 2. Semgrep (Static Code Analysis / SAST)
# 3. OWASP ZAP (Dynamic API Security Testing / DAST)
# 4. Laravel PHPUnit / Pest (Automated Regression Security Suite)
# ==============================================================================

set -u

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

FAILED_PILLARS=0

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}        CodeBridge Enterprise DevSecOps Pipeline                   ${NC}"
echo -e "${BLUE}====================================================================${NC}\n"

# ------------------------------------------------------------------------------
# Pillar 1: Composer Audit (SCA - Software Composition Analysis)
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[1/4] Running Composer Audit across microservices...${NC}"

echo -e "--> Auditing auth-service dependencies..."
if ! docker exec codebridge-auth-service composer audit; then
    echo -e "${RED}✘ Composer audit failed in auth-service!${NC}"
    FAILED_PILLARS=$((FAILED_PILLARS + 1))
fi

echo -e "--> Auditing operations-service dependencies..."
if ! docker exec codebridge-operations-service composer audit; then
    echo -e "${RED}✘ Composer audit failed in operations-service!${NC}"
    FAILED_PILLARS=$((FAILED_PILLARS + 1))
fi

echo -e "${GREEN}✓ Composer Audit step completed.${NC}\n"

# ------------------------------------------------------------------------------
# Pillar 2: Semgrep (SAST - Static Application Security Testing)
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[2/4] Running Semgrep SAST Scan (PHP / Laravel / OWASP Top 10)...${NC}"
if command -v semgrep &> /dev/null; then
    if ! semgrep --config="p/php" --config="p/owasp-top-ten" --error ./auth-service/app ./operations-service/app; then
        echo -e "${RED}✘ Semgrep SAST scan identified security findings!${NC}"
        FAILED_PILLARS=$((FAILED_PILLARS + 1))
    else
        echo -e "${GREEN}✓ Semgrep scan completed.${NC}\n"
    fi
elif docker info &> /dev/null; then
    echo "Running Semgrep via official Docker image..."
    if ! docker run --rm -v "$(pwd):/src" returntocorp/semgrep semgrep \
        --config="p/php" \
        --config="p/owasp-top-ten" \
        --error \
        /src/auth-service/app /src/operations-service/app; then
        echo -e "${RED}✘ Semgrep SAST scan identified security findings!${NC}"
        FAILED_PILLARS=$((FAILED_PILLARS + 1))
    else
        echo -e "${GREEN}✓ Semgrep scan completed.${NC}\n"
    fi
else
    echo -e "${RED}✘ Semgrep is not installed and Docker is unavailable. SAST scan cannot run!${NC}"
    echo "Install with 'brew install semgrep' or 'pip install semgrep'."
    FAILED_PILLARS=$((FAILED_PILLARS + 1))
fi

# ------------------------------------------------------------------------------
# Pillar 3: OWASP ZAP (DAST - Dynamic API Penetration Scan)
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[3/4] Running OWASP ZAP Baseline Scan on API Gateway...${NC}"
mkdir -p ./reports
if docker info &> /dev/null; then
    echo "Scanning http://codebridge-api-gateway:80 via Docker network 'codebridge-network'..."
    if ! docker run --rm --network codebridge-network \
        -v "$(pwd)/reports:/zap/wrk/:rw" \
        zaproxy/zap-stable zap-baseline.py \
        -t http://codebridge-api-gateway \
        -r zap_report.html; then
        echo -e "${RED}✘ OWASP ZAP DAST scan detected findings! Check reports/zap_report.html.${NC}"
        FAILED_PILLARS=$((FAILED_PILLARS + 1))
    else
        echo -e "${GREEN}✓ OWASP ZAP DAST scan completed.${NC}\n"
    fi
else
    echo -e "${RED}✘ Docker is not accessible for OWASP ZAP scan. DAST scan cannot run!${NC}"
    FAILED_PILLARS=$((FAILED_PILLARS + 1))
fi

# ------------------------------------------------------------------------------
# Pillar 4: Laravel PHPUnit / Pest Security Regression Tests
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[4/4] Running Automated Laravel Security Test Suites...${NC}"

echo -e "--> Running Auth Service Domain Tests (Auth, Users, Roles, Sessions, Tenants, API Keys, Org, CRM, Meetings, SuperAdmin)..."
if ! docker exec codebridge-auth-service php artisan test; then
    echo -e "${RED}✘ Auth Service automated tests failed!${NC}"
    FAILED_PILLARS=$((FAILED_PILLARS + 1))
fi

echo -e "--> Running Operations Service Domain Tests (Catalog, Inventory, Sales, Shifts, Payments, Finance, Reports)..."
if ! docker exec codebridge-operations-service php artisan test; then
    echo -e "${RED}✘ Operations Service automated tests failed!${NC}"
    FAILED_PILLARS=$((FAILED_PILLARS + 1))
fi

if [ ${FAILED_PILLARS} -ne 0 ]; then
    echo -e "\n${RED}====================================================================${NC}"
    echo -e "${RED}  ✘ Security Pipeline Failed with ${FAILED_PILLARS} failing pillar(s)!${NC}"
    echo -e "${RED}====================================================================${NC}"
    exit 1
fi

echo -e "\n${GREEN}====================================================================${NC}"
echo -e "${GREEN}  ✓ All 4 Security Pipeline Checks Executed Successfully!           ${NC}"
echo -e "${GREEN}====================================================================${NC}"
exit 0
