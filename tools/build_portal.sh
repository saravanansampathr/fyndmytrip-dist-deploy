#!/usr/bin/env bash
# Rebuilds frontend/ (the compiled portal) from the original import build
# plus every patch script, in order. Run from the repo root:
#   bash tools/build_portal.sh
# The React source is not in this repo; see tools/frontend-margin-patch.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$ROOT" archive 8aeb02c frontend | tar -x -C "$TMP"
python3 "$ROOT/tools/frontend-margin-patch/patch_frontend.py" "$TMP/frontend" "$TMP/out"
python3 "$ROOT/tools/guest-checkout-patch/patch_guest_checkout.py" "$TMP/out"
if [ -f "$ROOT/tools/fare-display-patch/patch_fare_display.py" ]; then
  python3 "$ROOT/tools/fare-display-patch/patch_fare_display.py" "$TMP/out"
fi
rm -rf "$ROOT/frontend"
cp -a "$TMP/out" "$ROOT/frontend"
echo "frontend/ rebuilt: $(ls "$ROOT/frontend/assets" | grep '^index-.*\.js$')"
