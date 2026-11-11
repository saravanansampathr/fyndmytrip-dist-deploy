#!/bin/bash
# Full end-to-end run on a throwaway SQLite database:
#   1. backend unit/feature tests   2. API run (every user type × service)
#   3. browser run against frontend/ (needs Node + Playwright with Chromium)
#   4. website bookings: every service and user type from search to payment
# Usage: e2e/run.sh            (from the repo root, after `composer install` in backend/)
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
export BACKEND_DIR="$ROOT/backend"
export RESULTS_DIR="${RESULTS_DIR:-$ROOT/e2e/results}"
export E2E_DB="${E2E_DB:-$RESULTS_DIR/e2e.sqlite}"
export FX_FIXED_RATES="${FX_FIXED_RATES:-USD=0.012,EUR=0.011,GBP=0.0094,AED=0.044,SAR=0.045,SGD=0.016,AUD=0.018,CAD=0.016}"
export API_URL="${API_URL:-http://127.0.0.1:8010}" FE_URL="${FE_URL:-http://127.0.0.1:8020}"
mkdir -p "$RESULTS_DIR"
[ -f "$BACKEND_DIR/.env" ] || { cp "$BACKEND_DIR/.env.example" "$BACKEND_DIR/.env"; (cd "$BACKEND_DIR" && php artisan key:generate --force >/dev/null); }

echo "== backend tests"; (cd "$BACKEND_DIR" && CACHE_STORE=array php artisan test)

echo "== preparing database"
CACHE_STORE=file SESSION_DRIVER=file "$ROOT/e2e/reset_db.sh"

(cd "$BACKEND_DIR" && CACHE_STORE=file SESSION_DRIVER=file DB_CONNECTION=sqlite DB_DATABASE="$E2E_DB" php artisan serve --host=127.0.0.1 --port="${API_URL##*:}" > "$RESULTS_DIR/serve.log" 2>&1) &
API_PID=$!
python3 "$ROOT/e2e/spa_server.py" "$ROOT/frontend" "${FE_URL##*:}" & FE_PID=$!
trap 'kill $API_PID $FE_PID 2>/dev/null; pkill -f "artisan serve --host=127.0.0.1 --port=${API_URL##*:}" 2>/dev/null || true' EXIT
sleep 3

echo "== API end-to-end"; CACHE_STORE=file python3 "$ROOT/e2e/api_e2e.py"
echo "== browser end-to-end"; CACHE_STORE=file "$ROOT/e2e/reset_db.sh" >/dev/null; node "$ROOT/e2e/ui_e2e.js"
echo "== website bookings (every service and role, search to payment)"; CACHE_STORE=file "$ROOT/e2e/reset_db.sh" >/dev/null; node "$ROOT/e2e/ui_booking_e2e.js"
