#!/bin/sh
#
# Install FPDF without Composer -- same pattern as sudoku/12tone/install-libs.sh.
#
#   sh install-libs.sh
#
# Drops it in ./libs/fpdf/, which render.php requires directly. FPDF is a
# single PHP file with no runtime dependencies, which is what makes a plain
# download workable here.

set -eu

DIR="$(cd "$(dirname "$0")" && pwd)/libs"
TARGET="$DIR/fpdf"
mkdir -p "$TARGET"

if [ -f "$TARGET/.installed" ]; then
    echo "fpdf already present ($(cat "$TARGET/.installed")) - delete $TARGET to reinstall"
    exit 0
fi

for url in \
    "https://github.com/Setasign/FPDF/archive/refs/tags/1.8.6.tar.gz" \
    "https://github.com/Setasign/FPDF/archive/refs/tags/v1.8.6.tar.gz" \
    "https://api.github.com/repos/Setasign/FPDF/tarball"
do
    printf 'fpdf: trying %s ... ' "$url"
    if curl -fsSL "$url" | tar xz -C "$TARGET" --strip-components=1 2>/dev/null; then
        echo "ok"
        echo "$url" > "$TARGET/.installed"
        break
    fi
    echo "no"
done

[ -f "$TARGET/fpdf.php" ] && echo "ok   libs/fpdf/fpdf.php" || { echo "MISSING libs/fpdf/fpdf.php"; exit 1; }
