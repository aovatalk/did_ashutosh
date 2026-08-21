#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

SQL_SOURCE="$SCRIPT_DIR/did_optimizer.sql"
AGI_SOURCE="$SCRIPT_DIR/did_optimizer.agi"
HANGUP_AGI_SOURCE="$SCRIPT_DIR/did_optimizer_hangup.agi"
PHP_SOURCE="$SCRIPT_DIR/admin_did_optimizer_pool.php"
REPUTATION_INC_SOURCE="$SCRIPT_DIR/did_optimizer_reputation.inc.php"
REPUTATION_CRON_SOURCE="$SCRIPT_DIR/reputation_cron.php"
QUICK_TEST_SOURCE="$SCRIPT_DIR/quick-test.sh"
DB_NAME="asterisk"
AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer.agi"
HANGUP_AGI_TARGET="/var/lib/asterisk/agi-bin/did_optimizer_hangup.agi"
MAINTENANCE_DIR="/usr/local/share/did-optimizer"
REPUTATION_CRON_FILE="/etc/cron.d/did-optimizer-reputation"
ROLE=""
CLEAN_INSTALL=0
REPUTATION_CRON_ENABLED=1
SOURCE_BASE_URL="${DIDOPT_SOURCE_BASE_URL:-https://raw.githubusercontent.com/aovatalk/did_ashutosh/refs/heads/main}"
GEO_DATASET_URL="${DIDOPT_GEO_DATASET_URL:-https://raw.githubusercontent.com/aovatalk/did_ashutosh/refs/heads/main/NPA_dataset.zip}"
GEO_ZIP_SOURCE="$SCRIPT_DIR/NPA_dataset.zip"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

download_url_file() {
    local filename="$1" url="$2" target="$SCRIPT_DIR/$1" temp_file
    local -a curl_args=(--fail --location --silent --show-error)
    require_command curl
    require_command mktemp
    if [[ "${DIDOPT_CURL_INSECURE:-0}" =~ ^(1|Y|y|YES|yes|true|TRUE)$ ]]; then
        curl_args+=(--insecure)
        printf 'WARNING: downloading %s without TLS certificate verification.\n' "$filename" >&2
    fi
    temp_file=$(mktemp "$SCRIPT_DIR/.didopt-download.XXXXXX") \
        || die "Could not create a temporary download for $filename"
    if ! curl "${curl_args[@]}" "$url" --output "$temp_file"; then
        rm -f -- "$temp_file"
        die "Could not download required file: $filename"
    fi
    chmod 0644 "$temp_file"
    mv -f -- "$temp_file" "$target"
    printf 'Downloaded required file: %s\n' "$target"
}

download_source_file() {
    download_url_file "$1" "$SOURCE_BASE_URL/$1"
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

usage() {
    printf '%s\n' \
        'Usage: install_did_optimizer.sh --role database|dialer [--clean] [--reputation yes|no]' \
        '  database          install/upgrade shared schema only' \
        '  dialer            install AGI and web admin page on this node only' \
        '  --clean           drop optimizer data before recreating the schema' \
        '  --reputation no   skip installing the reputation sweep cron (--role dialer only);' \
        '                    the reputation cache/admin UI still work, just without the' \
        '                    automatic background sweep. Default: yes.'
}

while (($#)); do
    case "$1" in
        --role) [[ $# -ge 2 ]] || die '--role requires a value'; ROLE="$2"; shift 2 ;;
        --clean) CLEAN_INSTALL=1; shift ;;
        --reputation)
            [[ $# -ge 2 ]] || die '--reputation requires a value: yes or no'
            case "$2" in
                yes) REPUTATION_CRON_ENABLED=1 ;;
                no) REPUTATION_CRON_ENABLED=0 ;;
                *) die "Invalid --reputation value: $2 (expected yes or no)" ;;
            esac
            shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done
[[ -n "$ROLE" ]] || die 'A role is required: --role database or --role dialer'
[[ "$ROLE" =~ ^(database|dialer)$ ]] || die "Invalid role: $ROLE"
[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this installer as root.'
[[ "$ROLE" == 'database' || "$CLEAN_INSTALL" == '0' ]] \
    || die '--clean is valid only with --role database.'
[[ "$ROLE" == 'dialer' || "$REPUTATION_CRON_ENABLED" == '1' ]] \
    || die '--reputation is valid only with --role dialer.'

if [[ "$ROLE" == 'database' ]]; then
    download_source_file did_optimizer.sql
    download_url_file NPA_dataset.zip "$GEO_DATASET_URL"
else
    download_source_file did_optimizer.agi
    download_source_file did_optimizer_hangup.agi
    download_source_file admin_did_optimizer_pool.php
    download_source_file did_optimizer_reputation.inc.php
    if ((REPUTATION_CRON_ENABLED)); then
        download_source_file reputation_cron.php
    fi
    download_source_file quick-test.sh
fi

install_database() {
    local geo_csv geo_count geo_schema_ready
    require_command mysql
    require_command unzip
    require_command mktemp
    [[ -r "$SQL_SOURCE" ]] || die "Missing schema: $SQL_SOURCE"
    [[ -r "$GEO_ZIP_SOURCE" ]] || die "Missing geographic dataset: $GEO_ZIP_SOURCE"
    if ((CLEAN_INSTALL)); then
        printf 'Dropping optimizer tables from shared database %s...\n' "$DB_NAME"
        mysql --protocol=socket --database="$DB_NAME" -e \
            "DROP TABLE IF EXISTS did_optimizer_assignments;
             DROP TABLE IF EXISTS did_optimizer_campaign_state;
             DROP TABLE IF EXISTS did_optimizer_pool;
             DROP TABLE IF EXISTS did_optimizer_geo_prefixes;
             DROP TABLE IF EXISTS did_optimizer_reputation_cache;
             DROP TABLE IF EXISTS did_optimizer_settings;"
    fi
    printf 'Applying optimizer schema to shared database %s...\n' "$DB_NAME"
    mysql --protocol=socket --database="$DB_NAME" < "$SQL_SOURCE"

    # Replace the narrow placeholder table from early cluster releases. This
    # table contains only derived public data, so rebuilding it does not remove
    # DID pools, assignments, or campaign state.
    geo_schema_ready=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_geo_prefixes'
            AND COLUMN_NAME IN ('geo_id','nxx','postal_code','latitude','longitude');")
    if [[ "$geo_schema_ready" != '5' ]]; then
        printf '%s\n' 'Upgrading geographic prefix table to the full NPA-NXX schema...'
        mysql --protocol=socket --database="$DB_NAME" -e \
            'DROP TABLE IF EXISTS did_optimizer_geo_prefixes;'
        mysql --protocol=socket --database="$DB_NAME" < "$SQL_SOURCE"
    fi

    geo_csv=$(mktemp /tmp/didopt-geo.XXXXXX.csv) \
        || die 'Could not create temporary geographic CSV file.'
    if ! unzip -p "$GEO_ZIP_SOURCE" full_dataset_csv.csv > "$geo_csv"; then
        rm -f -- "$geo_csv"
        die 'Could not extract full_dataset_csv.csv from NPA_dataset.zip.'
    fi
    [[ -s "$geo_csv" ]] || {
        rm -f -- "$geo_csv"; die 'Extracted geographic CSV is empty.';
    }
    printf '%s\n' 'Refreshing NPA-NXX geographic prefix dataset...'
    mysql --protocol=socket --database="$DB_NAME" -e \
        'TRUNCATE TABLE did_optimizer_geo_prefixes;'
    if ! mysql --protocol=socket --local-infile=1 --database="$DB_NAME" -e \
        "LOAD DATA LOCAL INFILE '$geo_csv' IGNORE INTO TABLE did_optimizer_geo_prefixes
         FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"'
         LINES TERMINATED BY '\\n' IGNORE 1 LINES
         (@npa,@nxx,@npanxx,@city,@state,@state_iso,@country,@country_iso,@postal,@gmt,@gmt_dst,@dst,@lat,@lon)
         SET npa=@npa,nxx=@nxx,npanxx=@npanxx,city=@city,state=@state,state_iso=@state_iso,
             country=@country,country_iso=@country_iso,postal_code=@postal,
             gmt_offset=NULLIF(@gmt,''),gmt_offset_dst=NULLIF(@gmt_dst,''),
             dst_observed=IFNULL(NULLIF(@dst,''),0),latitude=NULLIF(@lat,''),
             longitude=NULLIF(TRIM(TRAILING '\\r' FROM @lon),'');"; then
        rm -f -- "$geo_csv"
        die 'NPA-NXX geographic dataset import failed.'
    fi
    rm -f -- "$geo_csv"
    geo_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        'SELECT COUNT(*) FROM did_optimizer_geo_prefixes;')
    [[ "$geo_count" =~ ^[0-9]+$ && "$geo_count" -gt 0 ]] \
        || die 'NPA-NXX geographic dataset import produced no rows.'
    printf 'Geographic prefix dataset ready (%s rows).\n' "$geo_count"

    # Upgrade installations made by the original three-table release.
    column_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_assignments'
            AND COLUMN_NAME='server_ip';")
    if [[ "$column_count" == '0' ]]; then
        mysql --protocol=socket --database="$DB_NAME" -e \
            "ALTER TABLE did_optimizer_assignments
               ADD COLUMN server_ip VARCHAR(45) NOT NULL DEFAULT '' AFTER campaign_id,
               ADD KEY idx_didopt_assignment_server (server_ip, assigned_at);"
    fi
    index_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_assignments'
            AND INDEX_NAME='idx_didopt_assignment_server';")
    if [[ "$index_count" == '0' ]]; then
        mysql --protocol=socket --database="$DB_NAME" -e \
            "ALTER TABLE did_optimizer_assignments
               ADD KEY idx_didopt_assignment_server (server_ip, assigned_at);"
    fi

    # Add the incremental scoring columns for installations made before the
    # did_optimizer_hangup.agi / SKIP LOCKED selection redesign.
    for pool_column_def in \
        'sample_size:INT UNSIGNED NOT NULL DEFAULT 0' \
        'human_answered_calls:INT UNSIGNED NOT NULL DEFAULT 0' \
        'good_calls:INT UNSIGNED NOT NULL DEFAULT 0' \
        'answered_seconds_sum:BIGINT UNSIGNED NOT NULL DEFAULT 0' \
        'performance_score:DECIMAL(8,6) NOT NULL DEFAULT 0'
    do
        pool_column_name="${pool_column_def%%:*}"
        pool_column_type="${pool_column_def#*:}"
        pool_column_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_pool'
                AND COLUMN_NAME='$pool_column_name';")
        if [[ "$pool_column_count" == '0' ]]; then
            mysql --protocol=socket --database="$DB_NAME" -e \
                "ALTER TABLE did_optimizer_pool ADD COLUMN $pool_column_name $pool_column_type;"
        fi
    done

    stats_applied_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_assignments'
            AND COLUMN_NAME='stats_applied';")
    if [[ "$stats_applied_count" == '0' ]]; then
        mysql --protocol=socket --database="$DB_NAME" -e \
            "ALTER TABLE did_optimizer_assignments
               ADD COLUMN stats_applied ENUM('Y','N') NOT NULL DEFAULT 'N';"
    fi

    for prior_column_def in \
        'prior_sample:INT UNSIGNED NOT NULL DEFAULT 0' \
        'prior_human:INT UNSIGNED NOT NULL DEFAULT 0' \
        'prior_good:INT UNSIGNED NOT NULL DEFAULT 0' \
        'prior_seconds_sum:BIGINT UNSIGNED NOT NULL DEFAULT 0'
    do
        prior_column_name="${prior_column_def%%:*}"
        prior_column_type="${prior_column_def#*:}"
        prior_column_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_campaign_state'
                AND COLUMN_NAME='$prior_column_name';")
        if [[ "$prior_column_count" == '0' ]]; then
            mysql --protocol=socket --database="$DB_NAME" -e \
                "ALTER TABLE did_optimizer_campaign_state ADD COLUMN $prior_column_name $prior_column_type;"
        fi
    done

    # Expand the early minimal reputation cache without discarding cached data.
    mysql --protocol=socket --database="$DB_NAME" -e \
        "ALTER TABLE did_optimizer_reputation_cache
           MODIFY reputation VARCHAR(32) DEFAULT NULL;"
    for reputation_column in lookup_status lookup_error; do
        reputation_column_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='did_optimizer_reputation_cache'
                AND COLUMN_NAME='$reputation_column';")
        if [[ "$reputation_column_count" == '0' ]]; then
            if [[ "$reputation_column" == 'lookup_status' ]]; then
                mysql --protocol=socket --database="$DB_NAME" -e \
                    "ALTER TABLE did_optimizer_reputation_cache
                       ADD COLUMN lookup_status VARCHAR(32) DEFAULT NULL AFTER reputation;"
            else
                mysql --protocol=socket --database="$DB_NAME" -e \
                    "ALTER TABLE did_optimizer_reputation_cache
                       ADD COLUMN lookup_error VARCHAR(255) DEFAULT NULL AFTER lookup_status;"
            fi
        fi
    done

    table_count=$(mysql --protocol=socket --batch --skip-column-names --database="$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA='$DB_NAME'
            AND TABLE_NAME IN ('did_optimizer_pool','did_optimizer_assignments',
              'did_optimizer_campaign_state','did_optimizer_geo_prefixes',
              'did_optimizer_reputation_cache','did_optimizer_settings');")
    [[ "$table_count" == '6' ]] \
        || die "Schema verification failed: expected 6 optimizer tables, found $table_count"
    printf 'Shared database schema ready (%s tables).\n' "$table_count"
}

install_dialer() {
    local vicidial_path php_target source_agi_hash target_agi_hash source_php_hash target_php_hash
    local source_hangup_hash target_hangup_hash
    require_command php
    require_command install
    require_command sha256sum
    require_command awk
    require_command perl
    require_command grep
    [[ -r "$AGI_SOURCE" && -r "$HANGUP_AGI_SOURCE" && -r "$PHP_SOURCE" \
       && -r "$REPUTATION_INC_SOURCE" && -r "$QUICK_TEST_SOURCE" ]] \
        || die 'AGI, hangup AGI, PHP, reputation, or quick-test source is missing.'
    if ((REPUTATION_CRON_ENABLED)); then
        [[ -r "$REPUTATION_CRON_SOURCE" ]] || die 'Reputation cron source is missing.'
    fi
    vicidial_path=$(find_vicidial_path) \
        || die 'VICIdial web installation not found in the supported web roots.'
    php_target="$vicidial_path/admin_did_optimizer_pool.php"
    [[ -d "$(dirname -- "$AGI_TARGET")" ]] \
        || die "Asterisk AGI directory does not exist: $(dirname -- "$AGI_TARGET")"
    [[ -r /etc/astguiclient.conf ]] || die '/etc/astguiclient.conf is not readable.'
    grep -Eq '^[[:space:]]*VARserver_ip[[:space:]]*=>?[[:space:]]*[^[:space:]]+' /etc/astguiclient.conf \
        || die 'VARserver_ip is missing from /etc/astguiclient.conf.'

    perl -c "$AGI_SOURCE"
    perl -c "$HANGUP_AGI_SOURCE"
    php -l "$PHP_SOURCE"
    php -l "$REPUTATION_INC_SOURCE"
    install -o asterisk -g asterisk -m 0750 "$AGI_SOURCE" "$AGI_TARGET"
    install -o asterisk -g asterisk -m 0750 "$HANGUP_AGI_SOURCE" "$HANGUP_AGI_TARGET"
    install -o root -g root -m 0755 "$PHP_SOURCE" "$php_target"
    # did_optimizer_reputation.inc.php requires dbconnect_mysqli.php via
    # __DIR__, so it must live next to admin_did_optimizer_pool.php in the
    # VICIdial web root regardless of whether the cron sweep is installed.
    install -o root -g root -m 0644 "$REPUTATION_INC_SOURCE" "$vicidial_path/did_optimizer_reputation.inc.php"
    install -d -o root -g root -m 0755 "$MAINTENANCE_DIR"
    install -o root -g root -m 0644 "$AGI_SOURCE" "$MAINTENANCE_DIR/did_optimizer.agi"
    install -o root -g root -m 0644 "$HANGUP_AGI_SOURCE" "$MAINTENANCE_DIR/did_optimizer_hangup.agi"
    install -o root -g root -m 0644 "$PHP_SOURCE" "$MAINTENANCE_DIR/admin_did_optimizer_pool.php"
    install -o root -g root -m 0644 "$REPUTATION_INC_SOURCE" "$MAINTENANCE_DIR/did_optimizer_reputation.inc.php"
    install -o root -g root -m 0755 "$QUICK_TEST_SOURCE" "$MAINTENANCE_DIR/quick-test.sh"
    perl -c "$AGI_TARGET"
    perl -c "$HANGUP_AGI_TARGET"
    php -l "$php_target"

    if ((REPUTATION_CRON_ENABLED)); then
        php -l "$REPUTATION_CRON_SOURCE"
        install -o root -g root -m 0750 "$REPUTATION_CRON_SOURCE" "$vicidial_path/reputation_cron.php"
        install -o root -g root -m 0750 "$REPUTATION_CRON_SOURCE" "$MAINTENANCE_DIR/reputation_cron.php"
        printf '%s\n' "*/5 * * * * root php $vicidial_path/reputation_cron.php >> /var/log/did-optimizer-reputation.log 2>&1" \
            > "$REPUTATION_CRON_FILE"
        chmod 0644 "$REPUTATION_CRON_FILE"
        printf 'Reputation sweep cron installed: %s (every 5 minutes)\n' "$REPUTATION_CRON_FILE"
    else
        rm -f -- "$REPUTATION_CRON_FILE" "$vicidial_path/reputation_cron.php" "$MAINTENANCE_DIR/reputation_cron.php"
        printf '%s\n' 'Reputation sweep cron skipped (--reputation no). Reputation checks will only' \
            'happen for DIDs actually viewed on the admin page.'
    fi

    source_agi_hash=$(sha256sum "$AGI_SOURCE" | awk '{print $1}')
    target_agi_hash=$(sha256sum "$AGI_TARGET" | awk '{print $1}')
    source_hangup_hash=$(sha256sum "$HANGUP_AGI_SOURCE" | awk '{print $1}')
    target_hangup_hash=$(sha256sum "$HANGUP_AGI_TARGET" | awk '{print $1}')
    source_php_hash=$(sha256sum "$PHP_SOURCE" | awk '{print $1}')
    target_php_hash=$(sha256sum "$php_target" | awk '{print $1}')
    [[ "$source_agi_hash" == "$target_agi_hash" ]] || die 'Installed AGI hash mismatch.'
    [[ "$source_hangup_hash" == "$target_hangup_hash" ]] || die 'Installed hangup AGI hash mismatch.'
    [[ "$source_php_hash" == "$target_php_hash" ]] || die 'Installed PHP hash mismatch.'
    printf 'Dialer/web node ready: AGI=%s hangup=%s admin=%s test=%s/quick-test.sh\n' \
        "$AGI_TARGET" "$HANGUP_AGI_TARGET" "$php_target" "$MAINTENANCE_DIR"
}

[[ "$ROLE" == 'database' ]] && install_database
[[ "$ROLE" == 'dialer' ]] && install_dialer

printf '%s\n' \
    'DID optimizer installation completed successfully.' \
    "  Role: $ROLE" \
    "  Clean schema: $([[ $CLEAN_INSTALL == 1 ]] && echo Y || echo N)" \
    '  Dialplan: unchanged' \
    '' \
    'Add after call_log and immediately before Dial() on every dialer node:' \
    ' same => n,AGI(did_optimizer.agi,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})' \
    ' same => n,NoOp(DID optimizer: ${DIDOPT_STATUS} ${DIDOPT_SELECTED} ${DIDOPT_REASON})' \
    '' \
    'Add to the h extension on the same route(s) - required for DID scores to' \
    'ever update; without it, selection falls back to ordering by last_used only:' \
    ' exten => h,1,AGI(did_optimizer_hangup.agi,${UNIQUEID},${DIALSTATUS},${ANSWEREDTIME},${AMDSTATUS})' \
    '(the last three args are optional but recommended - they let most hangups' \
    'be scored without waiting on vicidial_log; drop ${AMDSTATUS} if you do not run AMD)'

if [[ "$ROLE" == 'dialer' ]]; then
    chmod u+x "$MAINTENANCE_DIR/quick-test.sh"
    "$MAINTENANCE_DIR/quick-test.sh"
fi
