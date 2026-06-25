<#
  URSB local dev setup (Windows / PowerShell) — installs/verifies the SOP-03 toolchain.
  Tools: Node.js, Claude Code, glab (+auth). GSD & Superpowers are verified and optionally
  installed via env hooks ($env:GSD_INSTALL_CMD / $env:SUPERPOWERS_INSTALL_CMD).
  Idempotent. Run from PowerShell:  ./scripts/setup-dev.ps1
#>

$script:Fail = 0
function Ok($m)   { Write-Host "  ok   $m"   -ForegroundColor Green }
function Warn($m) { Write-Host "  warn $m"   -ForegroundColor Yellow }
function Err($m)  { Write-Host "  MISS $m"   -ForegroundColor Red; $script:Fail = 1 }
function Have($c) { [bool](Get-Command $c -ErrorAction SilentlyContinue) }

$useWinget = Have 'winget'
Write-Host "URSB dev setup  (Windows / PowerShell)`n"

# 1) Node.js -----------------------------------------------------------------
Write-Host "[1/5] Node.js"
if (Have 'node') {
  $major = (node -p "process.versions.node.split('.')[0]" 2>$null)
  if ([int]$major -ge 18) { Ok ("node " + (node -v)) }
  else { Err ("node " + (node -v) + " too old (need >= 18). Get Node 18+ from https://nodejs.org") }
} else { Err "node not found. Install Node 18+ from https://nodejs.org" }

# 2) Claude Code -------------------------------------------------------------
Write-Host "[2/5] Claude Code"
if (Have 'claude') {
  Ok ("claude " + ((claude --version 2>$null) | Select-Object -First 1))
} elseif (Have 'npm') {
  Write-Host "  installing @anthropic-ai/claude-code ..."
  npm install -g @anthropic-ai/claude-code *> $null
  if (Have 'claude') { Ok ("claude " + ((claude --version 2>$null) | Select-Object -First 1)) }
  else { Err "claude install failed. Try: npm install -g @anthropic-ai/claude-code" }
} else { Err "npm not available (install Node 18+ first)." }

# 3) glab + auth -------------------------------------------------------------
Write-Host "[3/5] GitLab CLI (glab)"
if (-not (Have 'glab') -and $useWinget) {
  Write-Host "  installing glab ..."
  winget install --id GLab.GLab -e --accept-source-agreements --accept-package-agreements *> $null
}
if (Have 'glab') {
  Ok ("glab " + ((glab --version 2>$null) | Select-Object -First 1))
  glab auth status *> $null
  if ($LASTEXITCODE -eq 0) { Ok "glab authenticated" }
  else { Warn "glab not authenticated — run:  glab auth login --use-keyring  (use your OWN account; no shared tokens)" }
} else {
  Err "glab not found. Install via 'winget install GLab.GLab' or https://gitlab.com/gitlab-org/cli"
}

# 4) GSD ---------------------------------------------------------------------
Write-Host "[4/5] GSD"
if ($env:GSD_INSTALL_CMD) {
  Write-Host "  running `$env:GSD_INSTALL_CMD ..."
  cmd /c $env:GSD_INSTALL_CMD; if ($LASTEXITCODE -eq 0) { Ok "GSD install command ran" } else { Warn "GSD install command failed" }
} else {
  Warn "GSD is verified inside Claude Code (e.g. /gsd-plan-phase), not the shell."
  Warn "Set `$env:GSD_INSTALL_CMD to automate. See ai-assisted-development/gsd-usage-standard.md"
}

# 5) Superpowers -------------------------------------------------------------
Write-Host "[5/5] Superpowers"
if ($env:SUPERPOWERS_INSTALL_CMD) {
  Write-Host "  running `$env:SUPERPOWERS_INSTALL_CMD ..."
  cmd /c $env:SUPERPOWERS_INSTALL_CMD; if ($LASTEXITCODE -eq 0) { Ok "Superpowers install command ran" } else { Warn "Superpowers install command failed" }
} else {
  Warn "Superpowers is a Claude Code plugin/skill set — verify inside Claude Code (/plugin)."
  Warn "Set `$env:SUPERPOWERS_INSTALL_CMD to automate. See ai-assisted-development/superpowers-usage-standard.md"
}

Write-Host ""
if ($script:Fail -eq 0) {
  Write-Host "Core toolchain OK. Next: clone your project, then run scripts/validate-project.sh"
} else {
  Write-Host "Some required tools are missing (marked MISS). Fix those, then re-run."
  exit 1
}
