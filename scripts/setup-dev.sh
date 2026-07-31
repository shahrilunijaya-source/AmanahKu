#!/usr/bin/env bash
# URSB local dev setup — installs/verifies the SOP-03 toolchain in one run.
# Tools: Node.js, Claude Code, glab (+auth). GSD & Superpowers are verified and
# optionally installed via env hooks (their install method is set by your org).
# Idempotent: safe to re-run. Hard-fails only on the must-have tools.
set -uo pipefail

ok(){   printf '  \033[32mok\033[0m   %s\n' "$1"; }
warn(){ printf '  \033[33mwarn\033[0m %s\n' "$1"; }
err(){  printf '  \033[31mMISS\033[0m %s\n' "$1"; FAIL=1; }
have(){ command -v "$1" >/dev/null 2>&1; }
FAIL=0

OS="$(uname -s)"
case "$OS" in
  Darwin) PKG="brew install" ;;
  Linux)  if   have apt-get; then PKG="sudo apt-get install -y"
          elif have dnf;     then PKG="sudo dnf install -y"
          elif have pacman;  then PKG="sudo pacman -S --noconfirm"
          else PKG="" ; fi ;;
  *)      PKG="" ;;
esac

echo "URSB dev setup  (OS: $OS)"
echo

# 1) Node.js (Claude Code requires Node) -------------------------------------
echo "[1/5] Node.js"
if have node; then
  NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
  if [ "${NODE_MAJOR:-0}" -ge 18 ]; then ok "node $(node -v)"; else
    err "node $(node -v) is too old (need >= 18). Install Node 18+ from https://nodejs.org"; fi
else
  err "node not found. Install Node 18+ from https://nodejs.org (or nvm)."
fi

# 2) Claude Code -------------------------------------------------------------
echo "[2/5] Claude Code"
if have claude; then
  ok "claude $(claude --version 2>/dev/null | head -1)"
elif have npm; then
  echo "  installing @anthropic-ai/claude-code ..."
  if npm install -g @anthropic-ai/claude-code >/dev/null 2>&1; then
    ok "claude $(claude --version 2>/dev/null | head -1)"
  else
    err "claude install failed. Try: npm install -g @anthropic-ai/claude-code"
  fi
else
  err "npm not available to install Claude Code (install Node 18+ first)."
fi

# 3) glab + auth -------------------------------------------------------------
echo "[3/5] GitLab CLI (glab)"
if have glab; then
  ok "glab $(glab --version 2>/dev/null | head -1)"
else
  if [ -n "$PKG" ]; then echo "  installing glab ..."; $PKG glab >/dev/null 2>&1 || true; fi
  if have glab; then ok "glab installed"
  else err "glab not found. Install: https://gitlab.com/gitlab-org/cli (brew/apt/dnf/winget 'glab')."; fi
fi
if have glab; then
  if glab auth status >/dev/null 2>&1; then ok "glab authenticated"
  else warn "glab not authenticated — run:  glab auth login --use-keyring   (use your OWN account; no shared tokens)"; fi
fi

# 4) GSD (verify; optional install via $GSD_INSTALL_CMD) ----------------------
echo "[4/5] GSD"
if [ -n "${GSD_INSTALL_CMD:-}" ]; then
  echo "  running \$GSD_INSTALL_CMD ..."; bash -c "$GSD_INSTALL_CMD" && ok "GSD install command ran" || warn "GSD install command failed"
else
  warn "GSD verified inside Claude Code, not the shell. Confirm GSD commands are available (e.g. /gsd-plan-phase)."
  warn "Set GSD_INSTALL_CMD to your org's install command to automate this. See ai-assisted-development/gsd-usage-standard.md"
fi

# 5) Superpowers (verify; optional install via $SUPERPOWERS_INSTALL_CMD) ------
echo "[5/5] Superpowers"
if [ -n "${SUPERPOWERS_INSTALL_CMD:-}" ]; then
  echo "  running \$SUPERPOWERS_INSTALL_CMD ..."; bash -c "$SUPERPOWERS_INSTALL_CMD" && ok "Superpowers install command ran" || warn "Superpowers install command failed"
else
  warn "Superpowers is a Claude Code plugin/skill set — verify inside Claude Code (/plugin or skill list)."
  warn "Set SUPERPOWERS_INSTALL_CMD to your org's install command to automate this. See ai-assisted-development/superpowers-usage-standard.md"
fi

echo
if [ "$FAIL" -eq 0 ]; then
  echo "Core toolchain OK. Next: clone your project, then run scripts/validate-project.sh"
else
  echo "Some required tools are missing (marked MISS). Fix those, then re-run."
  exit 1
fi
