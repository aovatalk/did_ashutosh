#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
DB_NAME="asterisk"
AGI_SOURCE="$SCRIPT_DIR/did_optimizer.agi"
PHP_SOURCE="$SCRIPT_DIR/admin_did_optimizer_pool.php"
AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer.agi"
PHP_TARGET=""
MYSQL_DEFAULTS_FILE=""

failures=0
warnings=0

pass() { printf 'PASS: %s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*"; warnings=$((warnings + 1)); }
fail() { printf 'FAIL: %s\n' "$*"; failures=$((failures + 1)); }

cleanup() {
    if [[ -n "$MYSQL_DEFAULTS_FILE" && -f "$MYSQL_DEFAULTS_FILE" ]]; then
        rm -f -- "$MYSQL_DEFAULTS_FILE"
    fi
}
trap cleanup EXIT

config_value() {
    local key="$1"
    awk -F '=>?' -v wanted="$key" '
        $1 ~ "^[[:space:]]*" wanted "[[:space:]]*$" {
            value=$2; gsub(/^[[:space:]]+|[[:space:]]+$/, "", value); print value; exit
        }' /etc/astguiclient.conf
}

mysql_db() {
    mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --database="$DB_NAME" "$@"
}

find_vicidial_path() {
    local base candidate
    for base in /srv/www/htdocs /var/www/html /var/www; do
        candidate="$base/vicidial"
        if [[ -f "$candidate/admin.php" \
              && -f "$candidate/functions.php" \
              && -f "$candidate/dbconnect_mysqli.php" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    return 1
}

check_command() {
    if command -v "$1" >/dev/null 2>&1; then
        pass "command available: $1"
    else
        fail "required command missing: $1"
    fi
}

check_file() {
    if [[ -f "$1" ]]; then
        pass "file exists: $1"
    else
        fail "file missing: $1"
    fi
}

printf '%s\n' 'DID optimizer quick test' '========================'

if VICIDIAL_PATH=$(find_vicidial_path); then
    PHP_TARGET="$VICIDIAL_PATH/admin_did_optimizer_pool.php"
    pass "VICIdial web installation detected: $VICIDIAL_PATH"
else
    fail 'VICIdial web installation not found in the supported web roots'
    PHP_TARGET='/__didopt_vicidial_not_found__/admin_did_optimizer_pool.php'
fi

for command_name in php mysql sha256sum stat grep cmp runuser perl awk mktemp; do
    check_command "$command_name"
done

if [[ -r /etc/astguiclient.conf ]]; then
    db_server=$(config_value VARDB_server)
    db_port=$(config_value VARDB_port); db_port=${db_port:-3306}
    db_user=$(config_value VARDB_user)
    db_pass=$(config_value VARDB_pass)
    local_server_ip=$(config_value VARserver_ip)
    if [[ -n "$db_server" && -n "$db_user" && -n "$local_server_ip" ]]; then
        MYSQL_DEFAULTS_FILE=$(mktemp) || fail 'could not create temporary MySQL configuration'
        if [[ -n "$MYSQL_DEFAULTS_FILE" ]]; then
            chmod 0600 "$MYSQL_DEFAULTS_FILE"
            printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' \
                "$db_server" "$db_port" "$db_user" "$db_pass" > "$MYSQL_DEFAULTS_FILE"
            pass "cluster identity: local server $local_server_ip, shared database $db_server:$db_port"
        fi
    else
        fail 'VARserver_ip or shared database settings are missing from astguiclient.conf'
    fi
else
    fail '/etc/astguiclient.conf is not readable'
fi

for required_file in "$AGI_SOURCE" "$PHP_SOURCE" "$AGI_TARGET" "$PHP_TARGET"; do
    check_file "$required_file"
done

if [[ -r "$AGI_SOURCE" ]] \
    && [[ $(head -n 1 "$AGI_SOURCE") == '#!/usr/bin/perl' ]] \
    && perl -c "$AGI_SOURCE" >/dev/null 2>&1; then
    pass 'packaged AGI is valid Perl source with the expected shebang'
else
    fail 'packaged AGI is missing or is not valid Perl source'
fi

if [[ -x "$AGI_TARGET" ]] \
    && [[ $(head -n 1 "$AGI_TARGET") == '#!/usr/bin/perl' ]] \
    && perl -c "$AGI_TARGET" >/dev/null 2>&1; then
    pass 'deployed AGI is executable, valid Perl source'
else
    fail 'deployed AGI is missing, non-executable, or invalid Perl source'
fi

if [[ -f "$PHP_SOURCE" ]] && php -l "$PHP_SOURCE" >/dev/null 2>&1; then
    pass 'source PHP syntax'
else
    fail 'source PHP syntax'
fi

if [[ -f "$PHP_TARGET" ]] && php -l "$PHP_TARGET" >/dev/null 2>&1; then
    pass 'deployed PHP syntax'
else
    fail 'deployed PHP syntax'
fi

if [[ -f "$AGI_SOURCE" && -f "$AGI_TARGET" ]] && cmp -s "$AGI_SOURCE" "$AGI_TARGET"; then
    pass 'deployed AGI matches workspace source'
else
    fail 'deployed AGI differs from workspace source'
fi

if [[ -f "$PHP_SOURCE" && -f "$PHP_TARGET" ]] && cmp -s "$PHP_SOURCE" "$PHP_TARGET"; then
    pass 'deployed PHP matches workspace source'
else
    fail 'deployed PHP differs from workspace source'
fi

if [[ -f "$AGI_TARGET" ]]; then
    agi_identity=$(stat -c '%U:%G %a' "$AGI_TARGET" 2>/dev/null || true)
    [[ "$agi_identity" == 'asterisk:asterisk 750' ]] \
        && pass 'AGI ownership and mode are asterisk:asterisk 0750' \
        || fail "AGI ownership/mode expected asterisk:asterisk 750, got: ${agi_identity:-unknown}"
fi

if [[ -f "$PHP_TARGET" ]]; then
    php_identity=$(stat -c '%U:%G %a' "$PHP_TARGET" 2>/dev/null || true)
    [[ "$php_identity" == 'root:root 755' ]] \
        && pass 'PHP ownership and mode are root:root 0755' \
        || fail "PHP ownership/mode expected root:root 755, got: ${php_identity:-unknown}"
fi

if runuser -u asterisk -- test -r /etc/astguiclient.conf; then
    pass 'Asterisk service account can read astguiclient.conf'
else
    fail 'Asterisk service account cannot read astguiclient.conf'
fi

if [[ -n "$MYSQL_DEFAULTS_FILE" ]] && mysql_db --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1; then
    pass "MySQL connection to $DB_NAME"

    table_count=$(mysql_db --batch --skip-column-names -e \
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA='$DB_NAME'
            AND TABLE_NAME IN ('did_optimizer_pool','did_optimizer_assignments','did_optimizer_campaign_state',
              'did_optimizer_geo_prefixes','did_optimizer_geo_npa_centroids',
              'did_optimizer_reputation_cache','did_optimizer_settings');" 2>/dev/null)
    [[ "$table_count" == '7' ]] \
        && pass 'all seven optimizer tables exist' \
        || fail "expected 7 optimizer tables, found ${table_count:-unknown}"

    engine_count=$(mysql_db --batch --skip-column-names -e \
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA='$DB_NAME'
            AND TABLE_NAME IN ('did_optimizer_pool','did_optimizer_assignments','did_optimizer_campaign_state',
              'did_optimizer_geo_prefixes','did_optimizer_geo_npa_centroids',
              'did_optimizer_reputation_cache','did_optimizer_settings')
            AND ENGINE='InnoDB'
            AND TABLE_COLLATION IN ('utf8_unicode_ci','utf8mb3_unicode_ci');" 2>/dev/null)
    [[ "$engine_count" == '7' ]] \
        && pass 'all optimizer tables use InnoDB and a compatible utf8 Unicode collation' \
        || {
            fail 'one or more optimizer tables has the wrong engine or collation'
            table_storage_details=$(mysql_db --batch --skip-column-names -e \
                "SELECT CONCAT(TABLE_NAME, ': engine=', COALESCE(ENGINE,'NULL'),
                               ' collation=', COALESCE(TABLE_COLLATION,'NULL'))
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA='$DB_NAME'
                    AND TABLE_NAME IN ('did_optimizer_pool','did_optimizer_assignments',
                      'did_optimizer_campaign_state','did_optimizer_geo_prefixes',
                      'did_optimizer_geo_npa_centroids','did_optimizer_reputation_cache',
                      'did_optimizer_settings')
                  ORDER BY TABLE_NAME;" 2>/dev/null || true)
            while IFS= read -r storage_line; do
                [[ -n "$storage_line" ]] && warn "$storage_line"
            done <<< "$table_storage_details"
        }

    index_count=$(mysql_db --batch --skip-column-names -e \
        "SELECT COUNT(DISTINCT TABLE_NAME,
                 CASE WHEN TABLE_NAME='did_optimizer_reputation_cache'
                           AND INDEX_NAME IN ('idx_didopt_reputation_freshness','idx_didopt_reputation_checked')
                      THEN 'idx_didopt_reputation_time' ELSE INDEX_NAME END)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA='$DB_NAME'
            AND ((TABLE_NAME='did_optimizer_pool' AND INDEX_NAME IN
                    ('PRIMARY','uq_didopt_pool_campaign_did','idx_didopt_pool_eligible','idx_didopt_pool_lru'))
              OR (TABLE_NAME='did_optimizer_assignments' AND INDEX_NAME IN
                    ('PRIMARY','uq_didopt_assignment_call','idx_didopt_assignment_campaign_recent',
                     'idx_didopt_assignment_did_recent','idx_didopt_assignment_lead'))
              OR (TABLE_NAME='did_optimizer_assignments' AND INDEX_NAME='idx_didopt_assignment_server')
              OR (TABLE_NAME='did_optimizer_campaign_state' AND INDEX_NAME='PRIMARY')
              OR (TABLE_NAME='did_optimizer_geo_prefixes' AND INDEX_NAME IN
                    ('PRIMARY','uq_didopt_geo_exchange_postal','idx_didopt_geo_npanxx',
                     'idx_didopt_geo_npa','idx_didopt_geo_city_state','idx_didopt_geo_state'))
              OR (TABLE_NAME='did_optimizer_geo_npa_centroids' AND INDEX_NAME='PRIMARY')
              OR (TABLE_NAME='did_optimizer_reputation_cache' AND INDEX_NAME IN
                    ('PRIMARY','idx_didopt_reputation_freshness','idx_didopt_reputation_checked'))
              OR (TABLE_NAME='did_optimizer_settings' AND INDEX_NAME='PRIMARY'));" 2>/dev/null)
    [[ "$index_count" == '21' ]] \
        && pass 'all required optimizer indexes exist' \
        || fail "expected 21 required indexes, found ${index_count:-unknown}"

    reputation_column_count=$(mysql_db --batch --skip-column-names -e \
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_reputation_cache'
            AND COLUMN_NAME IN ('reputation','lookup_status','lookup_error','checked_at');" 2>/dev/null)
    [[ "$reputation_column_count" == '4' ]] \
        && pass 'reputation cache schema supports provider status and errors' \
        || fail "expected 4 reputation cache columns, found ${reputation_column_count:-unknown}"

    row_summary=$(mysql_db --batch --skip-column-names -e \
        "SELECT CONCAT('pool=', (SELECT COUNT(*) FROM did_optimizer_pool),
                       ' assignments=', (SELECT COUNT(*) FROM did_optimizer_assignments),
                       ' campaign_state=', (SELECT COUNT(*) FROM did_optimizer_campaign_state),
                       ' geo_prefixes=', (SELECT COUNT(*) FROM did_optimizer_geo_prefixes),
                       ' geo_centroids=', (SELECT COUNT(*) FROM did_optimizer_geo_npa_centroids));" 2>/dev/null || true)
    [[ -n "$row_summary" ]] && pass "table queries succeed ($row_summary)" \
        || fail 'could not query optimizer table row counts'
    geo_prefix_count=$(mysql_db --batch --skip-column-names -e \
        'SELECT COUNT(*) FROM did_optimizer_geo_prefixes;' 2>/dev/null || true)
    [[ "$geo_prefix_count" =~ ^[0-9]+$ && "$geo_prefix_count" -gt 0 ]] \
        && pass "NPA-NXX geographic dataset is populated ($geo_prefix_count rows)" \
        || fail 'NPA-NXX geographic dataset is empty'
    centroid_count=$(mysql_db --batch --skip-column-names -e \
        'SELECT COUNT(*) FROM did_optimizer_geo_npa_centroids;' 2>/dev/null || true)
    [[ "$centroid_count" =~ ^[0-9]+$ && "$centroid_count" -gt 0 ]] \
        && pass "NPA centroid cache is populated ($centroid_count rows)" \
        || fail 'NPA centroid cache is empty'
else
    fail "MySQL connection to $DB_NAME"
fi

if command -v asterisk >/dev/null 2>&1; then
    dialplan_output=$(asterisk -rx 'dialplan show' 2>/dev/null || true)
    if grep -Fq 'AGI(did_optimizer.agi' <<< "$dialplan_output"; then
        active_dialplan_lines=$(grep -Fc 'AGI(did_optimizer.agi' <<< "$dialplan_output")
        pass "DID optimizer AGI appears in the active dialplan ($active_dialplan_lines route(s))"
    else
        fail 'DID optimizer AGI line not found in the active dialplan'
    fi
else
    fail 'Asterisk CLI is unavailable; active dialplan was not checked'
fi

# Search all persistent Asterisk .conf files without assuming a context or
# extension pattern. Commented examples do not count as installed routes.
persistent_dialplan_lines=$(grep -RhsE --include='*.conf' \
    '^[[:space:]]*(same|exten)[[:space:]]*=>.*AGI\(did_optimizer\.agi' \
    /etc/asterisk 2>/dev/null || true)
if [[ -n "$persistent_dialplan_lines" ]]; then
    persistent_dialplan_count=$(grep -c . <<< "$persistent_dialplan_lines")
    pass "DID optimizer AGI appears in persistent dialplan configuration ($persistent_dialplan_count route(s))"
else
    fail 'DID optimizer AGI line not found in persistent /etc/asterisk/*.conf files'
fi

printf '%s\n' '========================'
printf 'Result: %d failure(s), %d warning(s)\n' "$failures" "$warnings"

if (( failures > 0 )); then
    exit 1
fi
exit 0
