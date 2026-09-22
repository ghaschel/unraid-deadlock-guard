#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1; then
  php tests/php/run.php
  while IFS= read -r file; do php -l "$file" >/dev/null; done < <(find source tests/php -name '*.php' -type f)
else
  docker run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli php tests/php/run.php
  docker run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli bash -c 'find source tests/php -name "*.php" -print0 | xargs -0 -n1 php -l' >/dev/null
fi
node --test tests/js/*.test.cjs
python3 tests/package_test.py
while IFS= read -r file; do bash -n "$file"; done < <(find source -type f \( -name '*.sh' -o -path '*/event/*' -o -name lifecycle -o -name qemu-hook \))
echo 'All local checks passed'
