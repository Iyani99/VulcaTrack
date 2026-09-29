#!/usr/bin/env bash
# Regenerate the four paper diagrams: SVG (vector, editable output) + PNG (3x, for Word).
#   bash docs/diagram-src/build.sh            (from the repository root)
# Needs PHP (C:\xampp\php\php.exe on the dev machine) and Google Chrome (headless PNG render).
set -euo pipefail
cd "$(dirname "$0")/../.."
PHP="${PHP:-/c/xampp/php/php.exe}"
CHROME="${CHROME:-/c/Program Files/Google/Chrome/Application/chrome.exe}"
SCALE="${SCALE:-3}"

build() { # generator  output-base
  local gen="$1" out="$2"
  "$PHP" "docs/diagram-src/$gen" > "$out.svg"
  local w h
  w=$(grep -o 'width="[0-9]*"' "$out.svg" | head -1 | tr -dc 0-9)
  h=$(grep -o 'height="[0-9]*"' "$out.svg" | head -1 | tr -dc 0-9)
  local abs; abs="$(cd "$(dirname "$out.svg")" && pwd -W 2>/dev/null || pwd)/$(basename "$out.svg")"
  "$CHROME" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor="$SCALE" \
    --window-size="$w,$h" --screenshot="$(pwd -W 2>/dev/null || pwd)/$out.png" "file:///$abs" >/dev/null 2>&1
  echo "$out.svg (${w}x${h})  ->  $out.png ($((w * SCALE))x$((h * SCALE)))"
}

build erd.php            docs/ERD/VulcaTrack-ERD_1
build activity-otg.php   docs/flows/VulcaTrack-Activity-Diagram-OTG
build dfd-level1.php     docs/flows/VulcaTrack-DFD-1-Level1
build sequence-pos.php   docs/flows/VulcaTrack-Sequence-Diagram-POS
