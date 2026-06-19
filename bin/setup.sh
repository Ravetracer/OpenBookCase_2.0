#!/usr/bin/env bash
#
# OpenBookCase — one-shot developer setup.
#
# Takes a fresh clone to a runnable app: installs PHP + JS dependencies, builds
# the frontend, generates the dev OAuth keypair, creates the database (empty or
# seeded with sample data — your choice), and creates an admin account with a
# password you set. When it finishes, `make serve` is all that's left.
#
# Safe to re-run. It is interactive by default; see the flags below for CI use.
#
# Usage:
#   bin/setup.sh                 # interactive (recommended)
#   bin/setup.sh --sample        # non-interactive, seed sample data
#   bin/setup.sh --empty         # non-interactive, empty database
#   bin/setup.sh --no-deps       # skip composer/npm install + build
#
set -euo pipefail

# ── Resolve project root (this script lives in <root>/bin) ──────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT"

# ── Pretty output helpers ───────────────────────────────────────────────────
if [ -t 1 ]; then
  BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; BLUE=$'\033[34m'; RESET=$'\033[0m'
else
  BOLD=''; GREEN=''; YELLOW=''; RED=''; BLUE=''; RESET=''
fi
step()  { printf '\n%s▶ %s%s\n' "$BOLD$BLUE" "$1" "$RESET"; }
ok()    { printf '%s✓ %s%s\n' "$GREEN" "$1" "$RESET"; }
warn()  { printf '%s! %s%s\n' "$YELLOW" "$1" "$RESET"; }
die()   { printf '%s✗ %s%s\n' "$RED" "$1" "$RESET" >&2; exit 1; }

# ── Parse flags ─────────────────────────────────────────────────────────────
DATA_MODE=""       # "", "sample" or "empty"
INSTALL_DEPS=1
INTERACTIVE=1
[ -t 0 ] || INTERACTIVE=0   # no TTY (CI / piped) → non-interactive

for arg in "$@"; do
  case "$arg" in
    --sample) DATA_MODE="sample"; INTERACTIVE=0 ;;
    --empty)  DATA_MODE="empty";  INTERACTIVE=0 ;;
    --no-deps) INSTALL_DEPS=0 ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $arg (try --help)" ;;
  esac
done

printf '%s\n' "${BOLD}OpenBookCase — developer setup${RESET}"
printf 'Project: %s\n' "$ROOT"

# ── 1. Prerequisites ────────────────────────────────────────────────────────
step "Checking prerequisites"
command -v php      >/dev/null 2>&1 || die "PHP is not installed (need 8.1+)."
command -v composer >/dev/null 2>&1 || die "Composer is not installed — https://getcomposer.org/"
if [ "$INSTALL_DEPS" -eq 1 ]; then
  command -v npm    >/dev/null 2>&1 || die "npm is not installed (need Node.js) — https://nodejs.org/"
fi
# PHP >= 8.1
PHP_OK="$(php -r 'echo (PHP_VERSION_ID >= 80100) ? "1" : "0";')"
[ "$PHP_OK" = "1" ] || die "PHP $(php -r 'echo PHP_VERSION;') is too old — need 8.1 or newer."
ok "PHP $(php -r 'echo PHP_VERSION;'), $(composer --version 2>/dev/null | head -1)"

# ── 2. Dependencies + frontend build ───────────────────────────────────────
if [ "$INSTALL_DEPS" -eq 1 ]; then
  step "Installing PHP dependencies (composer install)"
  composer install --no-interaction
  ok "Composer dependencies installed"

  step "Installing JS dependencies (npm install)"
  npm install --no-audit --no-fund
  ok "npm dependencies installed"

  step "Building frontend assets (npm run build)"
  npm run build
  ok "Frontend built"
else
  warn "Skipping dependency install / build (--no-deps)"
fi

# ── 3. Dev OAuth keypair (for the API; gitignored, throwaway) ───────────────
step "OAuth dev keypair"
if [ -f config/jwt/private.pem ] && [ -f config/jwt/public.pem ]; then
  ok "Keypair already present — leaving it untouched"
else
  php bin/console league:oauth2-server:generate-keypair --no-interaction
  ok "Generated config/jwt/{private,public}.pem"
fi

# ── 4. Choose the data mode ─────────────────────────────────────────────────
if [ -z "$DATA_MODE" ]; then
  if [ "$INTERACTIVE" -eq 1 ]; then
    printf '\n%sHow should the database start?%s\n' "$BOLD" "$RESET"
    printf '  [1] Sample data — realistic bookcases + test users (great for clicking around)\n'
    printf '  [2] Empty       — just the schema, no rows\n'
    read -r -p "Choose 1 or 2 [1]: " choice
    case "${choice:-1}" in
      2) DATA_MODE="empty" ;;
      *) DATA_MODE="sample" ;;
    esac
  else
    DATA_MODE="sample"   # sensible non-interactive default
  fi
fi

# ── 5. Create the database ──────────────────────────────────────────────────
if [ "$DATA_MODE" = "sample" ]; then
  step "Creating database + sample data"
  php bin/console app:dev:fixtures --fresh --no-interaction
  ok "Database seeded with sample bookcases and test users"
  warn 'Seeded logins (password "password"): dev@example.com (user), admin@example.com (admin)'
else
  step "Creating a clean, empty database"
  php bin/console app:dev:db-init --no-interaction
  ok "Empty database ready"
fi

# ── 6. Create an admin account ──────────────────────────────────────────────
create_admin() {
  # Loops until an account is created or the user chooses to skip. The password
  # is read by the console command itself (hidden, never shown / stored in argv).
  while true; do
    local username email
    read -r -p "Admin username [admin]: " username; username="${username:-admin}"
    read -r -p "Admin e-mail [admin@example.com]: " email; email="${email:-admin@example.com}"

    # app:dev:create-user prompts for the password (hidden) and grants ROLE_ADMIN.
    if php bin/console app:dev:create-user "$username" "$email" --admin; then
      ok "Admin account \"$username\" created — you can log in with it."
      return 0
    fi

    warn "Could not create that account (the username or e-mail may already exist)."
    read -r -p "Try again with different details? [Y/n]: " again
    case "${again:-y}" in
      n|N) warn "Skipped creating a personal admin account."; return 0 ;;
    esac
  done
}

step "Admin account"
if [ "$INTERACTIVE" -eq 1 ]; then
  if [ "$DATA_MODE" = "sample" ]; then
    printf 'A seeded admin (admin@example.com / "password") already exists.\n'
    read -r -p "Also create your own admin account with a password you set? [Y/n]: " want
    case "${want:-y}" in
      n|N) warn "Using the seeded admin only." ;;
      *)   create_admin ;;
    esac
  else
    create_admin
  fi
else
  if [ "$DATA_MODE" = "empty" ]; then
    warn "Non-interactive run: no admin created. Make one with:"
    warn "  php bin/console app:dev:create-user admin admin@example.com --admin"
  fi
fi

# ── 7. Done — how to run it ─────────────────────────────────────────────────
if command -v symfony >/dev/null 2>&1; then
  SERVE="symfony server:start    ${RESET}(or: ${BOLD}make serve${RESET})"
  URL="https://127.0.0.1:8000"
else
  SERVE="php -S localhost:8000 -t public/    ${RESET}(or: ${BOLD}make serve${RESET})"
  URL="http://localhost:8000"
fi

printf '\n%s════════════════════════════════════════════════════════%s\n' "$GREEN" "$RESET"
ok "Setup complete!"
printf '\nStart the web server:\n  %s%s%s\n' "$BOLD" "$SERVE" "$RESET"
printf '\nThen open: %s%s%s\n' "$BOLD" "$URL" "$RESET"
printf '\nHandy commands: %smake help%s   ·   run tests: %smake test%s\n' "$BOLD" "$RESET" "$BOLD" "$RESET"
printf '%s════════════════════════════════════════════════════════%s\n' "$GREEN" "$RESET"
