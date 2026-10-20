#!/bin/bash
# Arhiva de instalare (Module → Adaugă → Încarcă modul): doar ce rulează pe site. Fără teste, capturi de ecran și
# notițe de lucru: n-au ce căuta pe serverul live și ar face arhiva de câteva ori mai mare.
# Folosire: tests/build-zip.sh [director-destinație]   → <director>/libertbus-bilete-<versiune>.zip
set -eu
DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mkdir -p "${1:-$DIR/..}" && cd "${1:-$DIR/..}" && pwd)"
VER=$(sed -n 's/^ \* Version: *//p' "$DIR/libertbus-bilete.php" | tr -d '[:space:]')
ZIP="$OUT/libertbus-bilete-$VER.zip"
rm -f "$ZIP"
(cd "$DIR/.." && zip -qr "$ZIP" "$(basename "$DIR")/libertbus-bilete.php" "$(basename "$DIR")/uninstall.php" "$(basename "$DIR")/index.php" \
  "$(basename "$DIR")/includes" "$(basename "$DIR")/assets" "$(basename "$DIR")/data" "$(basename "$DIR")/languages" -x '*.DS_Store')
echo "$ZIP"
