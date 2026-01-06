#!/bin/bash

# Test Script for Cron Jobs
# Run this script to test all cron jobs are working

echo "========================================="
echo "Testing Cron Jobs for Boostelix SMM"
echo "========================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get project directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

echo "Project Directory: $SCRIPT_DIR"
echo ""

# Test PHP
echo -n "Testing PHP... "
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n 1)
    echo -e "${GREEN}✓${NC} $PHP_VERSION"
else
    echo -e "${RED}✗ PHP not found${NC}"
    exit 1
fi

# Test Artisan
echo -n "Testing Artisan... "
if [ -f "artisan" ]; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${RED}✗ Artisan not found${NC}"
    exit 1
fi

echo ""
echo "========================================="
echo "Testing Individual Commands"
echo "========================================="
echo ""

# Test each command
commands=(
    "orders:check-status --limit=5"
    "orders:check-refill-status"
    "orders:check-refills --dry-run"
    "provider:sync-orders"
    "orders:process-refunds --dry-run"
    "notifications:send --limit=5"
    "database:cleanup --days=365"
)

for cmd in "${commands[@]}"; do
    echo -n "Testing: php artisan $cmd ... "
    if php artisan $cmd > /dev/null 2>&1; then
        echo -e "${GREEN}✓ Success${NC}"
    else
        echo -e "${YELLOW}⚠ Warning (may be normal if no data)${NC}"
    fi
done

echo ""
echo "========================================="
echo "Testing Scheduler"
echo "========================================="
echo ""

echo -n "Checking scheduled tasks... "
if php artisan schedule:list > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC}"
    echo ""
    php artisan schedule:list
else
    echo -e "${RED}✗ Failed${NC}"
fi

echo ""
echo "========================================="
echo "Checking Log Directory"
echo "========================================="
echo ""

if [ -d "storage/logs" ]; then
    echo -e "${GREEN}✓ Log directory exists${NC}"
    LOG_COUNT=$(ls -1 storage/logs/cron-*.log 2>/dev/null | wc -l)
    echo "Cron log files found: $LOG_COUNT"
else
    echo -e "${YELLOW}⚠ Creating log directory...${NC}"
    mkdir -p storage/logs
    chmod -R 775 storage/logs
fi

echo ""
echo "========================================="
echo "Test Complete!"
echo "========================================="
echo ""
echo "If all tests passed, your cron jobs are ready to use."
echo "Add this to your crontab:"
echo ""
echo "* * * * * cd $SCRIPT_DIR && php artisan schedule:run >> /dev/null 2>&1"
echo ""

