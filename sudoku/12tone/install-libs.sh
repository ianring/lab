#!/bin/sh
#
# Install TCPDF and FPDI without Composer.
#
#   sh install-libs.sh
#
# Drops them in ./libs/, which lib/PdfSupport.php looks for and autoloads on its
# own. Composer is the tidier option if you have it - this exists so a missing
# composer binary is not a blocker.
#
# Neither library has any runtime dependency of its own, which is what makes a
# plain download workable here.

set -eu

DIR="$(cd "$(dirname "$0")" && pwd)/libs"
mkdir -p "$DIR"

# Try a pinned release first, then the same tag with the other naming
# convention, then the default branch. Report which one actually landed.
fetch() {
    name="$1"
    repo="$2"
    version="$3"
    target="$DIR/$name"

    if [ -f "$target/.installed" ]; then
        echo "  $name already present ($(cat "$target/.installed")) - delete $target to reinstall"
        return 0
    fi

    mkdir -p "$target"
    for url in \
        "https://github.com/$repo/archive/refs/tags/$version.tar.gz" \
        "https://github.com/$repo/archive/refs/tags/v$version.tar.gz" \
        "https://api.github.com/repos/$repo/tarball"
    do
        printf '  %s: trying %s ... ' "$name" "$url"
        if curl -fsSL "$url" | tar xz -C "$target" --strip-components=1 2>/dev/null; then
            echo "ok"
            echo "$url" > "$target/.installed"
            return 0
        fi
        echo "no"
    done

    echo "  $name FAILED - download it by hand from https://github.com/$repo/releases"
    echo "  and unpack it into $target so that its top-level files sit directly there."
    return 1
}

echo "installing into $DIR"
# || true so one failed download still lets the other run and the layout
# check below still reports what actually landed.
fetch tcpdf tecnickcom/TCPDF 6.7.5 || true
fetch fpdi  Setasign/FPDI    2.6.1 || true

echo
echo "checking layout:"
[ -f "$DIR/tcpdf/tcpdf.php" ]        && echo "  ok   libs/tcpdf/tcpdf.php"        || echo "  MISSING libs/tcpdf/tcpdf.php"
[ -f "$DIR/fpdi/src/Tcpdf/Fpdi.php" ] && echo "  ok   libs/fpdi/src/Tcpdf/Fpdi.php" || echo "  MISSING libs/fpdi/src/Tcpdf/Fpdi.php"

echo
echo "now run:  php diagnose.php"
