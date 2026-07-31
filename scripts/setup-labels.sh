#!/usr/bin/env bash
set -euo pipefail
# Create the canonical URSB issue labels in the current GitLab project.
# Requires: glab (authenticated). Idempotent. Source of truth: .gitlab/labels.yml

labels=(
  "gsd-clarification|#1f75cb|Issue awaiting GSD clarification answers"
  "decision-required|#ec5e00|A human decision/approval is required"
  "business-decision|#6699cc|Decision owned by PM / Product Owner"
  "technical-decision|#6699cc|Decision owned by Conventional Coder / Tech Lead"
  "security-decision|#cc0000|Decision owned by Security / Compliance"
  "client-input-required|#fc9403|Blocked pending client/stakeholder input"
  "changes-requested|#d9534f|Returned for changes; loops back to proposal"
  "pm-approved|#5cb85c|Scope/proposal approved by PM / Product Owner"
  "technical-approved|#5cb85c|Technical plan approved (Conventional Coder/Tech Lead)"
  "qa-approved|#5cb85c|QA evidence signed off by QA / Test Owner"
  "ready-for-release|#17a2b8|All gates passed; ready for the release owner"
  "confirmed|#2e7d32|Released and confirmed in production"
)

for row in "${labels[@]}"; do
  IFS="|" read -r name color desc <<< "$row"
  if glab label create --name "$name" --color "$color" --description "$desc" 2>/dev/null; then
    echo "  created  $name"
  else
    glab label update "$name" --color "$color" --description "$desc" 2>/dev/null \
      && echo "  updated  $name" || echo "  skipped  $name (exists / glab version differs)"
  fi
done
echo "Done. Verify with: glab label list"
