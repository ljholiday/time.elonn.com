#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASS=0
FAIL=0
ERRORS=()

for test_file in "$SCRIPT_DIR"/tests/*-test.php; do
    [ -f "$test_file" ] || continue
    name="$(basename "$test_file")"
    echo "--- $name ---"
    if php "$test_file"; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        ERRORS+=("$name")
    fi
    echo ""
done

echo "========================================"
echo "Tests: passed=$PASS  failed=$FAIL"
if [ "${#ERRORS[@]}" -gt 0 ]; then
    echo "Failed:"
    for e in "${ERRORS[@]}"; do echo "  - $e"; done
    exit 1
fi
exit 0
