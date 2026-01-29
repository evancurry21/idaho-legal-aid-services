#!/usr/bin/env bash
#
# Run k6 load test against ILAS Chatbot API
#
# Prerequisites:
#   - k6 installed: https://k6.io/docs/getting-started/installation/
#   - DDEV running: ddev start
#
# Usage:
#   ./scripts/load/run-loadtest.sh              # Run with DDEV URL
#   ./scripts/load/run-loadtest.sh --quick      # Quick smoke test (1 VU, 10s)
#   ./scripts/load/run-loadtest.sh --url https://example.com  # Custom URL
#
# Output:
#   - Console summary with P50/P95 latency and error rate
#   - JSON report: reports/load/loadtest-<timestamp>.json
#   - Markdown report: reports/load/loadtest-<timestamp>.md
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LOAD_SCRIPT="$SCRIPT_DIR/chatbot-api-loadtest.js"
REPORTS_DIR="$PROJECT_ROOT/reports/load"

# Default to DDEV URL
BASE_URL="${BASE_URL:-https://ilas-pantheon.ddev.site}"
QUICK_MODE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --quick)
            QUICK_MODE=true
            shift
            ;;
        --url)
            BASE_URL="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --quick       Run quick smoke test (1 VU, 10s)"
            echo "  --url URL     Override target URL (default: DDEV URL)"
            echo "  -h, --help    Show this help message"
            echo ""
            echo "Prerequisites:"
            echo "  - k6 must be installed (brew install k6 or apt install k6)"
            echo "  - DDEV must be running (ddev start)"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Check k6 is installed
if ! command -v k6 &> /dev/null; then
    echo "Error: k6 is not installed."
    echo ""
    echo "Install k6:"
    echo "  macOS:  brew install k6"
    echo "  Linux:  sudo apt install k6"
    echo "  Other:  https://k6.io/docs/getting-started/installation/"
    exit 1
fi

# Create reports directory
mkdir -p "$REPORTS_DIR"

echo "=========================================="
echo "ILAS Chatbot API Load Test"
echo "=========================================="
echo "Target URL: $BASE_URL"
echo "Reports:    $REPORTS_DIR"
echo ""

# Check if DDEV is running (if using DDEV URL)
if [[ "$BASE_URL" == *"ddev.site"* ]]; then
    if command -v ddev &> /dev/null; then
        if ! ddev describe &> /dev/null; then
            echo "Warning: DDEV may not be running. Start with: ddev start"
            echo ""
        fi
    fi
fi

# Quick connectivity check
echo "Checking connectivity..."
if curl -s -k -o /dev/null -w "%{http_code}" "$BASE_URL" | grep -q "200\|301\|302"; then
    echo "✓ Site is reachable"
else
    echo "⚠ Warning: Could not reach $BASE_URL (site may still work)"
fi
echo ""

# Build k6 command
K6_CMD="k6 run"
K6_CMD="$K6_CMD -e BASE_URL=$BASE_URL"

if [ "$QUICK_MODE" = true ]; then
    echo "Running QUICK smoke test (1 VU, 10s)..."
    echo ""
    # Override scenarios for quick test
    K6_CMD="$K6_CMD --vus 1 --duration 10s --no-thresholds"
else
    echo "Running FULL load test..."
    echo "  Stage 1: 1 VU for 30s"
    echo "  Stage 2: 5 VUs for 30s"
    echo "  Stage 3: 20 VUs for 30s"
    echo ""
fi

# Run the test
cd "$PROJECT_ROOT"
$K6_CMD "$LOAD_SCRIPT"

echo ""
echo "=========================================="
echo "Test Complete"
echo "=========================================="
echo "Reports saved to: $REPORTS_DIR"
echo ""

# List recent reports
if [ -d "$REPORTS_DIR" ]; then
    echo "Recent reports:"
    ls -lt "$REPORTS_DIR"/*.md 2>/dev/null | head -3 || echo "  (no markdown reports yet)"
fi
