-- DID Optimizer custom schema for VICIdial.
-- These tables must use InnoDB: the AGI relies on transactions and row locks.

-- sample_size/human_answered_calls/good_calls/answered_seconds_sum and
-- performance_score are maintained incrementally by did_optimizer_hangup.agi
-- (bound to the dialplan's h extension) as each call completes, so
-- did_optimizer.agi never recomputes a score in the selection request path -
-- it only reads performance_score and reserves a row.
CREATE TABLE IF NOT EXISTS did_optimizer_pool (
    did_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    did_number VARCHAR(32) NOT NULL,
    campaign_id VARCHAR(20) NOT NULL,
    country_code VARCHAR(8) NOT NULL DEFAULT '1',
    local_key VARCHAR(16) NOT NULL DEFAULT '',
    enabled ENUM('Y','N') NOT NULL DEFAULT 'Y',
    admin_priority SMALLINT NOT NULL DEFAULT 0,
    total_assignments BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calls_today INT UNSIGNED NOT NULL DEFAULT 0,
    usage_date DATE DEFAULT NULL,
    daily_limit INT UNSIGNED NOT NULL DEFAULT 0,
    last_used DATETIME DEFAULT NULL,
    sample_size INT UNSIGNED NOT NULL DEFAULT 0,
    human_answered_calls INT UNSIGNED NOT NULL DEFAULT 0,
    good_calls INT UNSIGNED NOT NULL DEFAULT 0,
    answered_seconds_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    performance_score DECIMAL(8,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (did_id),
    UNIQUE KEY uq_didopt_pool_campaign_did (campaign_id, did_number),
    KEY idx_didopt_pool_eligible
        (campaign_id, enabled, local_key, usage_date, calls_today, daily_limit),
    KEY idx_didopt_pool_lru (campaign_id, last_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- stats_applied guards did_optimizer_hangup.agi against double-counting a
-- call: it flips Y only once, inside the same transaction that applies that
-- call's delta to did_optimizer_pool/did_optimizer_campaign_state.
CREATE TABLE IF NOT EXISTS did_optimizer_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unique_call_id VARCHAR(64) NOT NULL,
    campaign_id VARCHAR(20) NOT NULL,
    server_ip VARCHAR(45) NOT NULL DEFAULT '',
    lead_id BIGINT UNSIGNED DEFAULT NULL,
    auto_call_id BIGINT UNSIGNED DEFAULT NULL,
    did_number VARCHAR(32) NOT NULL,
    destination VARCHAR(32) NOT NULL,
    local_key VARCHAR(16) NOT NULL DEFAULT '',
    selection_reason VARCHAR(64) NOT NULL,
    callerid_applied ENUM('Y','N') NOT NULL DEFAULT 'N',
    stats_applied ENUM('Y','N') NOT NULL DEFAULT 'N',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assignment_id),
    UNIQUE KEY uq_didopt_assignment_call (unique_call_id),
    KEY idx_didopt_assignment_campaign_recent
        (campaign_id, assigned_at, assignment_id),
    KEY idx_didopt_assignment_did_recent
        (campaign_id, did_number, assigned_at, assignment_id),
    KEY idx_didopt_assignment_lead (lead_id, assigned_at),
    KEY idx_didopt_assignment_server (server_ip, assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per campaign. prior_* are the campaign-wide running totals used as
-- the Bayesian smoothing prior (updated alongside each DID's own counters in
-- did_optimizer_hangup.agi, in the same transaction).
CREATE TABLE IF NOT EXISTS did_optimizer_campaign_state (
    campaign_id VARCHAR(20) NOT NULL,
    last_did VARCHAR(32) DEFAULT NULL,
    prior_sample INT UNSIGNED NOT NULL DEFAULT 0,
    prior_human INT UNSIGNED NOT NULL DEFAULT 0,
    prior_good INT UNSIGNED NOT NULL DEFAULT 0,
    prior_seconds_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- NANP NPA-NXX geography imported from full_dataset_csv.csv. Multiple postal
-- codes may map to the same exchange, so the natural key includes postal code.
CREATE TABLE IF NOT EXISTS did_optimizer_geo_prefixes (
    geo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npa CHAR(3) NOT NULL,
    nxx CHAR(3) NOT NULL,
    npanxx CHAR(6) NOT NULL,
    city VARCHAR(100) NOT NULL DEFAULT '',
    state VARCHAR(100) NOT NULL DEFAULT '',
    state_iso VARCHAR(8) NOT NULL DEFAULT '',
    country VARCHAR(100) NOT NULL DEFAULT '',
    country_iso VARCHAR(8) NOT NULL DEFAULT '',
    postal_code VARCHAR(20) NOT NULL DEFAULT '',
    gmt_offset DECIMAL(5,2) DEFAULT NULL,
    gmt_offset_dst DECIMAL(5,2) DEFAULT NULL,
    dst_observed TINYINT(1) NOT NULL DEFAULT 0,
    latitude DECIMAL(10,6) DEFAULT NULL,
    longitude DECIMAL(10,6) DEFAULT NULL,
    PRIMARY KEY (geo_id),
    UNIQUE KEY uq_didopt_geo_exchange_postal (npanxx, postal_code),
    KEY idx_didopt_geo_npanxx (npanxx),
    KEY idx_didopt_geo_npa (npa, country_iso),
    KEY idx_didopt_geo_city_state (city, state_iso, country_iso),
    KEY idx_didopt_geo_state (state_iso, country_iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS did_optimizer_reputation_cache (
    did_number VARCHAR(32) NOT NULL,
    reputation VARCHAR(32) DEFAULT NULL,
    lookup_status VARCHAR(32) DEFAULT NULL,
    lookup_error VARCHAR(255) DEFAULT NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (did_number),
    KEY idx_didopt_reputation_freshness (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Shared cluster configuration. Secrets are managed from the authenticated
-- VICIdial admin page and are never embedded in deployed files.
CREATE TABLE IF NOT EXISTS did_optimizer_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
