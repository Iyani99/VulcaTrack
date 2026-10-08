#!/usr/bin/env bash
# Regenerate maintained technical and paper diagrams: SVG + 3x PNG.
#   bash docs/diagram-src/build.sh [target ...]   (from the repository root)
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
  local png_tmp="$(pwd -W 2>/dev/null || pwd)/$out.render.$$.png"
  "$CHROME" --headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu --hide-scrollbars --force-device-scale-factor="$SCALE" \
    --window-size="$w,$h" --screenshot="$png_tmp" "file:///$abs" >/dev/null 2>&1
  test -s "$png_tmp"
  mv -f -- "$png_tmp" "$out.png"
  echo "$out.svg (${w}x${h})  ->  $out.png ($((w * SCALE))x$((h * SCALE)))"
}

if (( $# == 0 )); then
  set -- erd dfd0 dfd1 activity-detailed activity-paper sequence-detailed sequence-paper use-case-detailed use-case-paper
fi
for target in "$@"; do
  case "$target" in
    erd)               build erd.php                        docs/ERD/VulcaTrack-ERD_1 ;;
    dfd0)              build dfd-level0.php                 docs/flows/VulcaTrack-DFD-0-Context ;;
    dfd1)              build dfd-level1.php                 docs/flows/VulcaTrack-DFD-1-Level1 ;;
    activity-detailed) build activity-otg-detailed.php      docs/flows/VulcaTrack-Activity-Diagram-OTG-Detailed ;;
    activity-paper)    build activity-book-rescue-paper.php docs/flows/VulcaTrack-Activity-Diagram-Book-Rescue-Paper ;;
    sequence-detailed) build sequence-pos-detailed.php      docs/flows/VulcaTrack-Sequence-Diagram-POS-Detailed ;;
    sequence-paper)    build sequence-pos-paper.php         docs/flows/VulcaTrack-Sequence-Diagram-POS-Paper ;;
    use-case-detailed) build use-case-detailed.php          docs/VulcaTrack-Use-Case-Diagram-Detailed ;;
    use-case-paper)    build use-case-paper.php             docs/VulcaTrack-Use-Case-Diagram-Paper ;;
    *) echo "Unknown diagram target: $target" >&2; exit 2 ;;
  esac
done
