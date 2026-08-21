#!/usr/bin/env bash

set -Eeuo pipefail

ROLE=""
AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer.agi"
MAINTENANCE_DIR="/usr/local/share/did-optimizer"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

usage() {
    printf '%s\n' \
        'Usage: uninstall.sh --role dialer' \
        '  dialer  remove the AGI, web admin page, and maintenance files' \
        '' \
        'The shared database schema and dialplan are not changed.'
}

while (($#)); do
    case "$1" in
        --role) [[ $# -ge 2 ]] || die '--role requires a value'; ROLE="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done

[[ -n "$ROLE" ]] || die 'A role is required: --role dialer'
[[ "$ROLE" == 'dialer' ]] || die "Invalid role: $ROLE (only dialer is supported)"
[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this uninstaller as root.'

removed=0

remove_file() {
    local target="$1"
    if [[ -e "$target" || -L "$target" ]]; then
        rm -f -- "$target"
        printf 'Removed: %s\n' "$target"
        removed=1
    fi
}

remove_file "$AGI_TARGET"

# Check every supported VICIdial web root so stale copies from a previous web
# root migration are removed as well.
for web_root in /srv/www/htdocs /var/www/html /var/www; do
    remove_file "$web_root/vicidial/admin_did_optimizer_pool.php"
done

remove_file "$MAINTENANCE_DIR/did_optimizer.agi"
remove_file "$MAINTENANCE_DIR/admin_did_optimizer_pool.php"
remove_file "$MAINTENANCE_DIR/quick-test.sh"

if [[ -d "$MAINTENANCE_DIR" ]]; then
    if rmdir -- "$MAINTENANCE_DIR" 2>/dev/null; then
        printf 'Removed empty directory: %s\n' "$MAINTENANCE_DIR"
        removed=1
    else
        printf 'Kept non-empty directory: %s\n' "$MAINTENANCE_DIR"
    fi
fi

if ((removed == 0)); then
    printf '%s\n' 'No installed dialer files were found; nothing to remove.'
fi

printf '%s\n' \
    'DID optimizer dialer uninstall completed successfully.' \
    '  Shared database: unchanged' \
    '  Dialplan: unchanged (remove any AGI/NoOp lines manually if desired)'
