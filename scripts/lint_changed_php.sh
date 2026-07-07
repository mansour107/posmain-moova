#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BASE_REF="${LINT_PHP_BASE_REF:-origin/main}"
if ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
  BASE_REF="HEAD~1"
fi

TMP_LIST="$(mktemp)"
trap 'rm -f "$TMP_LIST"' EXIT

{
  git diff --name-only --diff-filter=ACMRTUXB "$BASE_REF"...HEAD 2>/dev/null || true
  git diff --name-only --diff-filter=ACMRTUXB HEAD 2>/dev/null || true
  git ls-files --others --exclude-standard
} | grep -E '\.php$' | sort -u >"$TMP_LIST"

if [[ ! -s "$TMP_LIST" ]]; then
  echo "lint-changed-php-ok files=0"
  exit 0
fi

FAILED=0
COUNT=0
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  if [[ ! -f "$file" ]]; then
    continue
  fi
  COUNT=$((COUNT + 1))
  if ! "$PHP_BIN" -l "$file" >/dev/null; then
    "$PHP_BIN" -l "$file" || true
    FAILED=1
  fi
done <"$TMP_LIST"

if [[ $FAILED -ne 0 ]]; then
  echo "lint-changed-php-failed"
  exit 1
fi

echo "lint-changed-php-ok files=${COUNT}"
