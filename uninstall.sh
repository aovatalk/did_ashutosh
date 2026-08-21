#!/usr/bin/env bash

set -Eeuo pipefail

ROLE=""
PURGE_DATA=0
AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer.agi"
HANGUP_AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer_hangup.agi"
MAINTENANCE_DIR="/usr/local/share/did-optimizer"
REPUTATION_CRON_FILE="/etc/cron.d/did-optimizer-reputation"
DB_NAME="asterisk"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

usage() {
    printf '%s\n' \
        'Usage: uninstall.sh --role dialer' \
        '       uninstall.sh --role database --purge-data' \
        '  dialer    remove the AGI, web admin page, and maintenance files' \
        '  database  drop the shared optimizer tables (requires --purge-data' \
        '            and typed confirmation; irreversible)' \
        '' \
        'Only the schema/files for the given role are touched; the dialplan is' \
        'never changed by this script.'
}

while (($#)); do
    case "$1" in
        --role) [[ $# -ge 2 ]] || die '--role requires a value'; ROLE="$2"; shift 2 ;;
        --purge-data) PURGE_DATA=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done

[[ -n "$ROLE" ]] || die 'A role is required: --role dialer or --role database'
[[ "$ROLE" =~ ^(dialer|database)$ ]] || die "Invalid role: $ROLE"
[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this uninstaller as root.'

if [[ "$ROLE" == 'database' ]]; then
    command -v mysql >/dev/null 2>&1 || die 'Required command not found: mysql'
    ((PURGE_DATA)) || die 'Database removal requires the explicit --purge-data option'
    read -r -p "Type DROP $DB_NAME to permanently delete all DID optimizer data: " confirmation
    [[ "$confirmation" == "DROP $DB_NAME" ]] || die 'Database purge cancelled'
    printf 'Dropping optimizer tables from shared database %s...\n' "$DB_NAME"
    mysql --protocol=socket --database="$DB_NAME" -e \
        "DROP TABLE IF EXISTS did_optimizer_assignments;
         DROP TABLE IF EXISTS did_optimizer_campaign_state;
         DROP TABLE IF EXISTS did_optimizer_pool;
         DROP TABLE IF EXISTS did_optimizer_geo_prefixes;
         DROP TABLE IF EXISTS did_optimizer_reputation_cache;
         DROP TABLE IF EXISTS did_optimizer_settings;"
    printf '%s\n' 'DID optimizer database purge completed successfully.' \
        '  Dialplan: unchanged'
    exit 0
fi

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
remove_file "$HANGUP_AGI_TARGET"

# Check every supported VICIdial web root so stale copies from a previous web
# root migration are removed as well.
for web_root in /srv/www/htdocs /var/www/html /var/www; do
    remove_file "$web_root/vicidial/admin_did_optimizer_pool.php"
    remove_file "$web_root/vicidial/did_optimizer_reputation.inc.php"
    remove_file "$web_root/vicidial/reputation_cron.php"
done

remove_file "$MAINTENANCE_DIR/did_optimizer.agi"
remove_file "$MAINTENANCE_DIR/did_optimizer_hangup.agi"
remove_file "$MAINTENANCE_DIR/admin_did_optimizer_pool.php"
remove_file "$MAINTENANCE_DIR/did_optimizer_reputation.inc.php"
remove_file "$MAINTENANCE_DIR/reputation_cron.php"
remove_file "$MAINTENANCE_DIR/quick-test.sh"
remove_file "$REPUTATION_CRON_FILE"

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
    '  Dialplan: unchanged (remove any AGI/NoOp/h-extension lines manually if desired)'
