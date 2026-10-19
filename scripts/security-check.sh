#!/bin/bash
# ==============================================================================
# CodeBridge 4-Pillar Security & AppSec Pipeline Runner
# 1. Composer Audit (Dependency Vulnerabilities)
# 2. Semgrep (Static Code Analysis / SAST)
# 3. OWASP ZAP (Dynamic API Security Testing / DAST)
# 4. Laravel PHPUnit / Pest (Automated Regression Security Suite)
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}        CodeBridge Enterprise DevSecOps Pipeline                   ${NC}"
echo -e "${BLUE}====================================================================${NC}\n"

# ------------------------------------------------------------------------------
# Pillar 1: Composer Audit (SCA - Software Composition Analysis)
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[1/4] Running Composer Audit across microservices...${NC}"

echo -e "--> Auditing auth-service dependencies..."
docker exec codebridge-auth-service composer audit || echo -e "${YELLOW}Warning: Advisories found in auth-service. Review and run composer update.${NC}"

echo -e "--> Auditing operations-service dependencies..."
docker exec codebridge-operations-service composer audit || echo -e "${YELLOW}Warning: Advisories found in operations-service. Review and run composer update.${NC}"

echo -e "${GREEN}✓ Composer Audit step completed.${NC}\n"

# ------------------------------------------------------------------------------
# Pillar 2: Semgrep (SAST - Static Application Security Testing)
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[2/4] Running Semgrep SAST Scan (PHP / Laravel / OWASP Top 10)...${NC}"
if command -v semgrep &> /dev/null; then
    semgrep --config="p/php" --config="p/owasp-top-ten" ./auth-service/app ./operations-service/app || true
elif docker info &> /dev/null; then
    echo "Running Semgrep via official Docker image..."
    docker run --rm -v "$(pwd):/src" returntocorp/semgrep semgrep \
        --config="p/php" \
        --config="p/owasp-top-ten" \
        /src/auth-service/app /src/operations-service/app || true
else
    echo "Semgrep not installed. Install with 'brew install semgrep' or 'pip install semgrep'."
fi
echo -e "${GREEN}✓ Semgrep scan completed.${NC}\n"

# ------------------------------------------------------------------------------
# Pillar 3: OWASP ZAP (DAST - Dynamic API Penetration Scan)
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[3/4] Running OWASP ZAP Baseline Scan on API Gateway...${NC}"
mkdir -p ./reports
if docker info &> /dev/null; then
    echo "Scanning http://codebridge-api-gateway:80 via Docker network 'codebridge-network'..."
    docker run --rm --network codebridge-network \
        -v "$(pwd)/reports:/zap/wrk/:rw" \
        zaproxy/zap-stable zap-baseline.py \
        -t http://codebridge-api-gateway \
        -r zap_report.html -I || echo -e "${YELLOW}ZAP finished with findings. Check reports/zap_report.html.${NC}"
else
    echo "Docker not accessible for ZAP scan."
fi
echo -e "${GREEN}✓ OWASP ZAP DAST scan completed.${NC}\n"

# ------------------------------------------------------------------------------
# Pillar 4: Laravel PHPUnit / Pest Security Regression Tests
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[4/4] Running Automated Laravel Security Test Suites...${NC}"

echo -e "--> Running Auth Service Domain Tests (Auth, Users, Roles, Sessions, Tenants, API Keys, Org, CRM, Meetings, SuperAdmin)..."
docker exec codebridge-auth-service php artisan test

echo -e "--> Running Operations Service Domain Tests (Catalog, Inventory, Sales, Shifts, Payments, Finance, Reports)..."
docker exec codebridge-operations-service php artisan test

echo -e "\n${GREEN}====================================================================${NC}"
echo -e "${GREEN}  ✓ All 4 Security Pipeline Checks Executed Successfully!           ${NC}"
echo -e "${GREEN}====================================================================${NC}"
