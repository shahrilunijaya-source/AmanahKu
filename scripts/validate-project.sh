#!/usr/bin/env bash
set -euo pipefail
# Validate that a project created from ursb-project-template is correctly wired.
fail=0
check(){ if [ -e "$1" ]; then echo "  ok   $1"; else echo "  MISS $1"; fail=1; fi; }

echo "URSB project validation"
check ".gitlab-ci.yml"
check "CLAUDE.md"
check "CODEOWNERS"
check ".editorconfig"
check "env.example"
check "docs/"
check ".planning/"
check ".claude/commands"
check "tests/"
check "security/README.md"
check "PROJECT.md"; check "STATE.md"; check "REQUIREMENTS.md"

if grep -q "ci-templates" .gitlab-ci.yml 2>/dev/null; then
  echo "  ok   .gitlab-ci.yml includes ci-templates"
else
  echo "  MISS .gitlab-ci.yml does not include ci-templates"; fail=1
fi

if [ -e ".env" ]; then echo "  WARN .env present — must not be committed"; fi

[ "$fail" -eq 0 ] && echo "PASS" || { echo "FAIL"; exit 1; }
