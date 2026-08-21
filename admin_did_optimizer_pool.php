<?php
# admin_did_optimizer_pool.php
#
# Admin page for managing the did_optimizer_pool table used by the custom
# DID Optimizer AGI (did_optimizer.agi). Not part of stock VICIdial - this
# table and this page are project-specific additions.
#
# Requires full admin login (vicidial_users, user_level > 7), same
# authorization mechanism used by admin.php.
#
# Features: add single DID, bulk-add via CSV upload, and sync outbound DIDs
# directly from a VICIdial CID group (spread across area codes).

require("dbconnect_mysqli.php");
require("functions.php");

# Match did_optimizer_pool / vicidial_* table collation (utf8_unicode_ci) so
# comparisons against VICIdial inventory do not hit "Illegal mix of collations".
mysqli_query($link, "SET NAMES utf8 COLLATE utf8_unicode_ci");

$PHP_AUTH_USER=$_SERVER['PHP_AUTH_USER'];
$PHP_AUTH_PW=$_SERVER['PHP_AUTH_PW'];
$PHP_SELF=$_SERVER['PHP_SELF'];

$auth_message = user_authorization($PHP_AUTH_USER,$PHP_AUTH_PW,'',1,0);
if ($auth_message != 'GOOD')
	{
	Header("WWW-Authenticate: Basic realm=\"CONTACT-CENTER-ADMIN\"");
	Header("HTTP/1.0 401 Unauthorized");
	echo "Login incorrect, please try again: |$PHP_AUTH_USER|$auth_message|\n";
	exit;
	}

function diop_clean_digits($val, $max_len)
	{
	$val = preg_replace('/\D/', '', (string)$val);
	return substr($val, 0, $max_len);
	}

function diop_clean_campaign_id($val)
	{
	$val = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$val);
	return substr($val, 0, 20);
	}

function diop_local_key_from_did($did_number)
	{
	if (preg_match('/^1([2-9]\d{2})[2-9]\d{6}$/', $did_number, $m))
		{return $m[1];}
	if (preg_match('/^([2-9]\d{2})[2-9]\d{6}$/', $did_number, $m))
		{return $m[1];}
	return '';
	}

function diop_insert_did($link, $did_number, $campaign_id, $local_key, $enabled, $admin_priority, $daily_limit, $country_code='1')
	{
	if (strlen($did_number) < 7 || strlen($did_number) > 32) {return 'invalid_did';}
	if ($campaign_id == '') {return 'invalid_campaign';}
	if ($local_key == '') {$local_key = diop_local_key_from_did($did_number);}
	$local_key = substr($local_key, 0, 16);
	$enabled = ($enabled == 'N') ? 'N' : 'Y';
	$admin_priority = (int)$admin_priority;
	$daily_limit = max(0, (int)$daily_limit);

	$stmt = mysqli_prepare($link,
		"INSERT INTO did_optimizer_pool
		    (did_number, campaign_id, country_code, local_key, enabled, admin_priority, daily_limit)
		 VALUES (?, ?, ?, ?, ?, ?, ?)");
	mysqli_stmt_bind_param($stmt, 'sssssii',
		$did_number, $campaign_id, $country_code, $local_key, $enabled, $admin_priority, $daily_limit);
	$ok = mysqli_stmt_execute($stmt);
	$err = mysqli_errno($link);
	mysqli_stmt_close($stmt);
	if ($ok) {return 'added';}
	if ($err == 1062) {return 'duplicate';}
	return 'error';
	}

function diop_shared_settings($link)
	{
	$settings = array();
	$rslt = mysqli_query($link,
		"SELECT setting_key, setting_value FROM did_optimizer_settings
		  WHERE setting_key IN ('reputation_api_url','reputation_api_key','reputation_cache_ttl')");
	if ($rslt)
		{
		while ($row = mysqli_fetch_assoc($rslt)) {$settings[$row['setting_key']] = $row['setting_value'];}
		}
	return $settings;
	}

function diop_reputation_config($link)
	{
	$settings = diop_shared_settings($link);
	if (empty($settings['reputation_api_url']) || empty($settings['reputation_api_key'])) {return null;}
	return array(
		'api_url' => $settings['reputation_api_url'],
		'api_key' => $settings['reputation_api_key'],
		'cache_ttl' => isset($settings['reputation_cache_ttl'])
			? max(60, min(86400, (int)$settings['reputation_cache_ttl'])) : 900,
	);
	}

function diop_save_setting($link, $key, $value)
	{
	$stmt = mysqli_prepare($link,
		"INSERT INTO did_optimizer_settings (setting_key, setting_value)
		 VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
	mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
	$ok = mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	return $ok;
	}

function diop_load_reputations($link, $numbers)
	{
	$results = array(); $normalized = array();
	foreach ($numbers as $number)
		{
		$digits = preg_replace('/\D/', '', $number);
		if ($digits != '') {$normalized[$digits] = $digits;}
		}
	if (!count($normalized)) {return $results;}

	$config = diop_reputation_config($link);
	$ttl = $config ? $config['cache_ttl'] : 900;
	$quoted = array();
	foreach ($normalized as $digits) {$quoted[] = "'" . mysqli_real_escape_string($link, $digits) . "'";}
	$sql = "SELECT did_number, reputation, lookup_status, lookup_error, checked_at,
	               checked_at >= (NOW() - INTERVAL " . (int)$ttl . " SECOND) AS is_fresh
	          FROM did_optimizer_reputation_cache
	         WHERE did_number IN (" . implode(',', $quoted) . ")";
	$rslt = mysqli_query($link, $sql);
	if ($rslt) {while ($row = mysqli_fetch_assoc($rslt)) {$results[$row['did_number']] = $row;}}

	$stale = array();
	foreach ($normalized as $digits)
		{
		if (!isset($results[$digits]) || !$results[$digits]['is_fresh']) {$stale[] = '+' . $digits;}
		}
	if ($config && count($stale) && function_exists('curl_init'))
		{
		$ch = curl_init($config['api_url']);
		curl_setopt_array($ch, array(
			CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-api-key: ' . $config['api_key']),
			CURLOPT_POSTFIELDS => json_encode(array('numbers' => $stale)),
		));
		$body = curl_exec($ch);
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);
		$payload = ($body !== false && $http_code >= 200 && $http_code < 300) ? json_decode($body, true) : null;
		if (is_array($payload) && isset($payload['results']) && is_array($payload['results']))
			{
			$stmt = mysqli_prepare($link,
				"INSERT INTO did_optimizer_reputation_cache
				    (did_number, reputation, lookup_status, lookup_error, checked_at)
				 VALUES (?, ?, ?, ?, NOW())
				 ON DUPLICATE KEY UPDATE reputation=VALUES(reputation), lookup_status=VALUES(lookup_status),
				                         lookup_error=VALUES(lookup_error), checked_at=NOW()");
			$disable_stmt = mysqli_prepare($link,
				"UPDATE did_optimizer_pool SET enabled='N' WHERE did_number=? AND enabled='Y'");
			foreach ($payload['results'] as $item)
				{
				$digits = isset($item['number']) ? preg_replace('/\D/', '', $item['number']) : '';
				if ($digits == '' || !isset($normalized[$digits])) {continue;}
				$reputation = isset($item['rk_reputation']) ? substr((string)$item['rk_reputation'], 0, 32) : null;
				$status = isset($item['rk_status']) ? substr((string)$item['rk_status'], 0, 32) : null;
				$error = !empty($item['error']) ? substr((string)$item['error'], 0, 255) : null;
				mysqli_stmt_bind_param($stmt, 'ssss', $digits, $reputation, $status, $error);
				mysqli_stmt_execute($stmt);
				if ($reputation !== null && strtolower($reputation) == 'negative')
					{
					mysqli_stmt_bind_param($disable_stmt, 's', $digits);
					mysqli_stmt_execute($disable_stmt);
					}
				$results[$digits] = array('did_number'=>$digits, 'reputation'=>$reputation,
					'lookup_status'=>$status, 'lookup_error'=>$error, 'checked_at'=>date('Y-m-d H:i:s'), 'is_fresh'=>1);
				}
			mysqli_stmt_close($stmt);
			mysqli_stmt_close($disable_stmt);
			}
		elseif (!count($results))
			{
			$results['_request_error'] = array('lookup_error' => $curl_error ? $curl_error : "HTTP $http_code");
			}
		}
	return $results;
	}

function diop_exact_log_match_sql()
	{
	return "v.uniqueid = a.unique_call_id AND v.campaign_id = a.campaign_id";
	}

function diop_fallback_log_match_sql()
	{
	return "a.lead_id IS NOT NULL
		AND v.lead_id = a.lead_id
		AND v.campaign_id = a.campaign_id
		AND RIGHT(v.phone_number, 10) = RIGHT(a.destination, 10)
		AND v.call_date BETWEEN a.assigned_at - INTERVAL 2 MINUTE
		                    AND a.assigned_at + INTERVAL 30 MINUTE";
	}

function diop_fallback_log_order_sql()
	{
	return "ABS(TIMESTAMPDIFF(SECOND, a.assigned_at, v.call_date)),
		v.call_date DESC, v.uniqueid DESC";
	}

function diop_calculate_performance_scores($link, $visible_rows)
	{
	$scores = array();
	$candidates = array();
	foreach ($visible_rows as $row)
		{
		if ($row['enabled'] != 'Y') {continue;}
		$rep = isset($row['reputation_data']['reputation']) ? $row['reputation_data']['reputation'] : null;
		$candidates[] = array(
			'did_number' => $row['did_number'],
			'campaign_id' => $row['campaign_id'],
			'reputation' => $rep
		);
		}
	if (!count($candidates)) {return $scores;}

	$stats = array();
	$totals = array();
	$exact_match = diop_exact_log_match_sql();
	$fallback_match = diop_fallback_log_match_sql();
	$fallback_order = diop_fallback_log_order_sql();
	$stmt = mysqli_prepare($link,
		"SELECT mapped.duration,
		        COALESCE(
		          (SELECT vcs.human_answered FROM vicidial_campaign_statuses vcs
		            WHERE vcs.campaign_id=mapped.campaign_id AND vcs.status=mapped.status LIMIT 1),
		          (SELECT vs.human_answered FROM vicidial_statuses vs
		            WHERE vs.status=mapped.status LIMIT 1), 'N') AS human_answered
		   FROM (
		        SELECT a.campaign_id, a.assignment_id, a.assigned_at,
		               COALESCE(
		                 (SELECT v.status FROM vicidial_log v WHERE $exact_match LIMIT 1),
		                 (SELECT v.status FROM vicidial_log v
		                   WHERE $fallback_match ORDER BY $fallback_order LIMIT 1)
		               ) AS status,
		               COALESCE(
		                 (SELECT v.length_in_sec FROM vicidial_log v WHERE $exact_match LIMIT 1),
		                 (SELECT v.length_in_sec FROM vicidial_log v
		                   WHERE $fallback_match ORDER BY $fallback_order LIMIT 1), 0
		               ) AS duration
		          FROM did_optimizer_assignments a
		         WHERE a.campaign_id=? AND a.did_number=? AND a.callerid_applied='Y'
		           AND (EXISTS (SELECT 1 FROM vicidial_log v WHERE $exact_match)
		                OR EXISTS (SELECT 1 FROM vicidial_log v WHERE $fallback_match))
		         ORDER BY a.assigned_at DESC, a.assignment_id DESC
		         LIMIT 20
		   ) mapped
		  ORDER BY mapped.assigned_at DESC, mapped.assignment_id DESC");
	foreach ($candidates as $candidate)
		{
		$campaign = $candidate['campaign_id'];
		$did = $candidate['did_number'];
		mysqli_stmt_bind_param($stmt, 'ss', $campaign, $did);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$sample = 0; $human = 0; $good = 0; $seconds = 0;
		while ($call = mysqli_fetch_assoc($res))
			{
			$sample++;
			if ($call['human_answered'] == 'Y')
				{
				$human++;
				$seconds += (int)$call['duration'];
				if ((int)$call['duration'] > 5) {$good++;}
				}
			}
		$key = $campaign . '|' . $did;
		$stats[$key] = array('sample'=>$sample, 'human'=>$human, 'good'=>$good, 'seconds'=>$seconds);
		if (!isset($totals[$campaign]))
			{$totals[$campaign] = array('sample'=>0, 'human'=>0, 'good'=>0, 'seconds'=>0);}
		$totals[$campaign]['sample'] += $sample;
		$totals[$campaign]['human'] += $human;
		$totals[$campaign]['good'] += $good;
		$totals[$campaign]['seconds'] += $seconds;
		}
	mysqli_stmt_close($stmt);

	foreach ($candidates as $candidate)
		{
		$campaign = $candidate['campaign_id'];
		$key = $campaign . '|' . $candidate['did_number'];
		$stat = $stats[$key];
		$total = $totals[$campaign];
		$prior_good = $total['sample'] ? $total['good'] / $total['sample'] : 0.50;
		$prior_human = $total['sample'] ? $total['human'] / $total['sample'] : 0.50;
		$prior_duration = $total['human'] ? $total['seconds'] / $total['human'] : 30;
		$smoothed_good = ($stat['good'] + 5 * $prior_good) / ($stat['sample'] + 5);
		$smoothed_human = ($stat['human'] + 5 * $prior_human) / ($stat['sample'] + 5);
		$smoothed_duration = ($stat['seconds'] + 5 * $prior_duration) / ($stat['human'] + 5);
		$duration_component = min(1, $smoothed_duration / 30);
		$reputation_name = isset($candidate['reputation']) ? $candidate['reputation'] : 'Unknown';
		$reputation_lower = strtolower($reputation_name);
		$reputation_component = ($reputation_lower == 'positive') ? 1
			: (($reputation_lower == 'negative') ? 0 : 0.50);
		$scores[$key] = array(
			'score' => 0.40 * $smoothed_good + 0.24 * $smoothed_human
				+ 0.16 * $duration_component + 0.20 * $reputation_component,
			'sample' => $stat['sample'],
			'good_rate' => $smoothed_good,
			'human_rate' => $smoothed_human,
			'duration' => $smoothed_duration,
			'reputation' => $reputation_name
		);
		}
	return $scores;
	}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$message = '';
$message_class = 'diop-ok';

if ($action == 'reputation_settings')
	{
	$api_url = isset($_POST['reputation_api_url']) ? trim($_POST['reputation_api_url']) : '';
	$api_key = isset($_POST['reputation_api_key']) ? trim($_POST['reputation_api_key']) : '';
	$cache_ttl = max(60, min(86400, isset($_POST['reputation_cache_ttl']) ? (int)$_POST['reputation_cache_ttl'] : 900));
	$existing_settings = diop_shared_settings($link);
	if ($api_key == '' && isset($existing_settings['reputation_api_key'])) {$api_key = $existing_settings['reputation_api_key'];}
	$scheme = strtolower((string)parse_url($api_url, PHP_URL_SCHEME));
	if (!filter_var($api_url, FILTER_VALIDATE_URL) || !in_array($scheme, array('http','https')))
		{$message = 'Reputation API URL must be a valid HTTP or HTTPS URL.'; $message_class = 'diop-err';}
	elseif ($api_key == '')
		{$message = 'Reputation API key is required for initial configuration.'; $message_class = 'diop-err';}
	else
		{
		$ok = diop_save_setting($link, 'reputation_api_url', $api_url)
			&& diop_save_setting($link, 'reputation_api_key', $api_key)
			&& diop_save_setting($link, 'reputation_cache_ttl', (string)$cache_ttl);
		if ($ok) {$message = 'Shared reputation settings updated for all web nodes.';}
		else {$message = 'Could not update reputation settings: ' . mysqli_error($link); $message_class = 'diop-err';}
		}
	}
elseif ($action == 'add')
	{
	$did_number  = diop_clean_digits($_POST['did_number'], 32);
	$campaign_id = diop_clean_campaign_id($_POST['campaign_id']);
	$local_key   = diop_clean_digits($_POST['local_key'], 16);
	$enabled     = ($_POST['enabled'] == 'N') ? 'N' : 'Y';
	$admin_priority = (int)$_POST['admin_priority'];
	$daily_limit    = max(0, (int)$_POST['daily_limit']);
	$country_code   = diop_clean_digits(isset($_POST['country_code']) ? $_POST['country_code'] : '1', 8);
	if ($country_code == '') {$country_code = '1';}

	$result = diop_insert_did($link, $did_number, $campaign_id, $local_key, $enabled, $admin_priority, $daily_limit, $country_code);
	if ($result == 'added')
		{$message = "Added DID $did_number to campaign $campaign_id.";}
	elseif ($result == 'duplicate')
		{$message = "That DID already exists in this campaign's pool."; $message_class = 'diop-err';}
	elseif ($result == 'invalid_did')
		{$message = "DID number must be 7-32 digits."; $message_class = 'diop-err';}
	elseif ($result == 'invalid_campaign')
		{$message = "Campaign is required."; $message_class = 'diop-err';}
	else
		{$message = "Could not add DID: " . mysqli_error($link); $message_class = 'diop-err';}
	}
elseif ($action == 'toggle')
	{
	$did_id = (int)$_POST['did_id'];
	$stmt = mysqli_prepare($link,
		"UPDATE did_optimizer_pool SET enabled = IF(enabled='Y','N','Y') WHERE did_id = ?");
	mysqli_stmt_bind_param($stmt, 'i', $did_id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$message = "Updated DID #$did_id.";
	}
elseif ($action == 'update')
	{
	$did_id = (int)$_POST['did_id'];
	$daily_limit = max(0, (int)$_POST['daily_limit']);
	$admin_priority = (int)$_POST['admin_priority'];
	$stmt = mysqli_prepare($link,
		"UPDATE did_optimizer_pool SET daily_limit = ?, admin_priority = ? WHERE did_id = ?");
	mysqli_stmt_bind_param($stmt, 'iii', $daily_limit, $admin_priority, $did_id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$message = "Updated DID #$did_id.";
	}
elseif ($action == 'delete')
	{
	$did_id = (int)$_POST['did_id'];
	$stmt = mysqli_prepare($link, "DELETE FROM did_optimizer_pool WHERE did_id = ?");
	mysqli_stmt_bind_param($stmt, 'i', $did_id);
	mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	$message = "Deleted DID #$did_id.";
	}
elseif ($action == 'upload_csv')
	{
	$default_campaign = diop_clean_campaign_id($_POST['csv_campaign_id']);
	$default_enabled  = ($_POST['csv_enabled'] == 'N') ? 'N' : 'Y';
	$default_priority = (int)$_POST['csv_admin_priority'];
	$default_limit    = max(0, (int)$_POST['csv_daily_limit']);

	if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK)
		{
		$message = "No CSV file uploaded, or upload failed.";
		$message_class = 'diop-err';
		}
	else
		{
		$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
		$added = 0; $dup = 0; $invalid = 0; $rows_seen = 0;
		$max_rows = 5000;
		while ($fh && ($row = fgetcsv($fh)) !== false && $rows_seen < $max_rows)
			{
			$rows_seen++;
			if (count($row) < 1) {continue;}
			$raw_did = isset($row[0]) ? $row[0] : '';
			$did_number = diop_clean_digits($raw_did, 32);
			if ($did_number == '') {continue;} # blank / header row
			if (!preg_match('/^\d+$/', preg_replace('/\D/','',$raw_did)) && strlen($did_number) < 7)
				{$invalid++; continue;}

			$campaign_id = isset($row[1]) && trim($row[1]) != '' ? diop_clean_campaign_id($row[1]) : $default_campaign;
			$local_key   = isset($row[2]) ? diop_clean_digits($row[2], 16) : '';
			$enabled     = isset($row[3]) && trim($row[3]) != '' ? (strtoupper(trim($row[3]))=='N' ? 'N' : 'Y') : $default_enabled;
			$admin_priority = isset($row[4]) && trim($row[4]) != '' ? (int)$row[4] : $default_priority;
			$daily_limit    = isset($row[5]) && trim($row[5]) != '' ? max(0,(int)$row[5]) : $default_limit;

			$result = diop_insert_did($link, $did_number, $campaign_id, $local_key, $enabled, $admin_priority, $daily_limit);
			if ($result == 'added') {$added++;}
			elseif ($result == 'duplicate') {$dup++;}
			else {$invalid++;}
			}
		if ($fh) {fclose($fh);}
		$message = "CSV processed: $added added, $dup already existed (skipped), $invalid invalid/skipped rows.";
		}
	}
elseif ($action == 'sync')
	{
	$sync_campaign  = diop_clean_campaign_id(isset($_POST['sync_campaign_id']) ? $_POST['sync_campaign_id'] : '');
	$sync_cid_group = diop_clean_campaign_id(isset($_POST['sync_cid_group_id']) ? $_POST['sync_cid_group_id'] : 'NORMAL');

	if ($sync_campaign == '' || $sync_cid_group == '')
		{
		$message = "Source CID group and target campaign are required for sync.";
		$message_class = 'diop-err';
		}
	else
		{
		$stmt = mysqli_prepare($link,
			"SELECT outbound_cid
			   FROM vicidial_campaign_cid_areacodes
			  WHERE campaign_id = ? AND COALESCE(outbound_cid, '') <> ''
			  ORDER BY outbound_cid");
		mysqli_stmt_bind_param($stmt, 's', $sync_cid_group);
		mysqli_stmt_execute($stmt);
		$res = mysqli_stmt_get_result($stmt);
		$source_rows = 0; $added = 0; $dup = 0; $invalid = 0;
		while ($row = mysqli_fetch_assoc($res))
			{
			$source_rows++;
			$did_number = diop_clean_digits($row['outbound_cid'], 32);
			$result = diop_insert_did($link, $did_number, $sync_campaign, '', 'Y', 0, 0);
			if ($result == 'added') {$added++;}
			elseif ($result == 'duplicate') {$dup++;}
			else {$invalid++;}
			}
		mysqli_stmt_close($stmt);
		$message = "CID group $sync_cid_group sync complete: all $source_rows source DIDs processed; $added added, $dup already present, $invalid invalid.";
		}
	}
elseif ($action == 'bulk_action')
	{
	# ponytail: one prepared statement executed per id (N+1), capped at 1000 rows -
	# switch to a single UPDATE/DELETE ... WHERE did_id IN (...) if this list ever needs to be huge.
	$did_ids = array();
	if (isset($_POST['did_ids']) && is_array($_POST['did_ids']))
		{
		foreach (array_slice($_POST['did_ids'], 0, 1000) as $raw_id)
			{$id = (int)$raw_id; if ($id > 0) {$did_ids[] = $id;}}
		}
	$bulk_op = isset($_POST['bulk_op']) ? $_POST['bulk_op'] : '';
	if (!count($did_ids) || !in_array($bulk_op, array('enable','disable','delete','set_limit')))
		{
		$message = "Select at least one DID and a bulk action to apply.";
		$message_class = 'diop-err';
		}
	else
		{
		$affected = 0;
		if ($bulk_op == 'set_limit')
			{
			$bulk_row_limit = max(0, (int)(isset($_POST['bulk_row_limit']) ? $_POST['bulk_row_limit'] : 0));
			$stmt = mysqli_prepare($link, "UPDATE did_optimizer_pool SET daily_limit = ? WHERE did_id = ?");
			foreach ($did_ids as $id)
				{mysqli_stmt_bind_param($stmt, 'ii', $bulk_row_limit, $id); mysqli_stmt_execute($stmt); $affected += mysqli_stmt_affected_rows($stmt);}
			mysqli_stmt_close($stmt);
			$message = "Set daily limit to $bulk_row_limit for $affected selected DID(s).";
			}
		elseif ($bulk_op == 'delete')
			{
			$stmt = mysqli_prepare($link, "DELETE FROM did_optimizer_pool WHERE did_id = ?");
			foreach ($did_ids as $id)
				{mysqli_stmt_bind_param($stmt, 'i', $id); mysqli_stmt_execute($stmt); $affected += mysqli_stmt_affected_rows($stmt);}
			mysqli_stmt_close($stmt);
			$message = "Deleted $affected selected DID(s).";
			}
		else
			{
			$enabled_val = ($bulk_op == 'enable') ? 'Y' : 'N';
			$stmt = mysqli_prepare($link, "UPDATE did_optimizer_pool SET enabled = ? WHERE did_id = ?");
			foreach ($did_ids as $id)
				{mysqli_stmt_bind_param($stmt, 'si', $enabled_val, $id); mysqli_stmt_execute($stmt); $affected += mysqli_stmt_affected_rows($stmt);}
			mysqli_stmt_close($stmt);
			$message = ($enabled_val == 'Y' ? "Enabled " : "Disabled ") . "$affected selected DID(s).";
			}
		}
	}
elseif ($action == 'bulk_limit')
	{
	$bulk_campaign = diop_clean_campaign_id($_POST['bulk_campaign_id']);
	$bulk_limit = max(0, (int)$_POST['bulk_daily_limit']);
	if ($bulk_campaign == '')
		{
		$message = "Campaign is required for bulk limit update.";
		$message_class = 'diop-err';
		}
	else
		{
		$stmt = mysqli_prepare($link, "UPDATE did_optimizer_pool SET daily_limit = ? WHERE campaign_id = ?");
		mysqli_stmt_bind_param($stmt, 'is', $bulk_limit, $bulk_campaign);
		mysqli_stmt_execute($stmt);
		$affected = mysqli_stmt_affected_rows($stmt);
		mysqli_stmt_close($stmt);
		$message = "Set daily limit to $bulk_limit for $affected DID(s) in campaign $bulk_campaign.";
		}
	}

$filter_campaign = isset($_GET['campaign_id']) ? diop_clean_campaign_id($_GET['campaign_id']) : '';
$filter_search   = isset($_GET['q']) ? diop_clean_digits($_GET['q'], 32) : '';
$filter_status   = isset($_GET['status']) && in_array($_GET['status'], array('Y','N')) ? $_GET['status'] : '';
$filter_reputation = isset($_GET['reputation']) && in_array($_GET['reputation'], array('positive','negative','unknown')) ? $_GET['reputation'] : '';
$filter_local_key = isset($_GET['local_key']) ? diop_clean_digits($_GET['local_key'], 16) : '';
$filter_at_limit = isset($_GET['at_limit']) && $_GET['at_limit'] == '1';

$campaign_rows = array();
$rslt = mysqli_query($link, "SELECT campaign_id, campaign_name FROM vicidial_campaigns ORDER BY campaign_id;");
while ($row = mysqli_fetch_assoc($rslt))
	{$campaign_rows[] = $row;}

$cid_group_rows = array();
$rslt = mysqli_query($link, "SELECT cid_group_id, cid_group_notes FROM vicidial_cid_groups ORDER BY cid_group_id;");
while ($row = mysqli_fetch_assoc($rslt))
	{$cid_group_rows[] = $row;}

$where_parts = array();
if ($filter_campaign != '')
	{$where_parts[] = "p.campaign_id = '" . mysqli_real_escape_string($link, $filter_campaign) . "'";}
if ($filter_search != '')
	{$where_parts[] = "p.did_number LIKE '%" . mysqli_real_escape_string($link, $filter_search) . "%'";}
if ($filter_status != '')
	{$where_parts[] = "p.enabled = '" . mysqli_real_escape_string($link, $filter_status) . "'";}
if ($filter_reputation == 'positive' || $filter_reputation == 'negative')
	{$where_parts[] = "LOWER(COALESCE(rc.reputation,'')) = '" . $filter_reputation . "' AND COALESCE(rc.lookup_error,'') = ''";}
elseif ($filter_reputation == 'unknown')
	{$where_parts[] = "(rc.did_number IS NULL OR COALESCE(rc.lookup_error,'') <> '' OR LOWER(COALESCE(rc.reputation,'')) NOT IN ('positive','negative'))";}
if ($filter_local_key != '')
	{$where_parts[] = "p.local_key = '" . mysqli_real_escape_string($link, $filter_local_key) . "'";}
if ($filter_at_limit)
	{$where_parts[] = "p.daily_limit > 0 AND (CASE WHEN p.usage_date = CURDATE() THEN p.calls_today ELSE 0 END) >= p.daily_limit";}
$where = count($where_parts) ? ('WHERE ' . implode(' AND ', $where_parts)) : '';
$pool_from = "did_optimizer_pool p LEFT JOIN did_optimizer_reputation_cache rc ON rc.did_number = p.did_number";

# Whitelisted sortable columns only - never interpolate $_GET['sort'] directly into SQL.
$sort_map = array(
	'did'         => 'p.did_number',
	'campaign'    => 'p.campaign_id',
	'npa'         => 'p.local_key',
	'status'      => 'p.enabled',
	'priority'    => 'p.admin_priority',
	'limit'       => 'p.daily_limit',
	'calls_today' => 'calls_today_effective',
	'total'       => 'p.total_assignments',
	'last_used'   => 'p.last_used',
	'reputation'  => 'rc.reputation',
);
# Default view (no explicit sort clicked) is calls-today descending, so the busiest DIDs surface first.
$sort_key = isset($_GET['sort']) && isset($sort_map[$_GET['sort']]) ? $_GET['sort'] : 'calls_today';
$sort_dir = isset($_GET['dir']) ? (($_GET['dir'] == 'asc') ? 'asc' : 'desc') : 'desc';
$order_by = $sort_map[$sort_key] . ' ' . $sort_dir . ', did_id ' . $sort_dir;

$per_page = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$count_rslt = mysqli_query($link,
	"SELECT COUNT(*) AS cnt,
	        COALESCE(SUM(p.enabled='Y'), 0) AS enabled_cnt,
	        COALESCE(SUM(CASE WHEN p.usage_date=CURDATE() THEN p.calls_today ELSE 0 END), 0) AS calls_today_sum,
	        COALESCE(SUM(p.total_assignments), 0) AS assignments_sum,
	        COALESCE(SUM(p.daily_limit>0 AND (CASE WHEN p.usage_date=CURDATE() THEN p.calls_today ELSE 0 END)>=p.daily_limit), 0) AS limit_reached_cnt
	   FROM $pool_from $where;");
$count_row = mysqli_fetch_assoc($count_rslt);
$total_filtered = (int)$count_row['cnt'];
$total_filtered_enabled = (int)$count_row['enabled_cnt'];
$total_calls_today = (int)$count_row['calls_today_sum'];
$total_assignments_sum = (int)$count_row['assignments_sum'];
$total_limit_reached = (int)$count_row['limit_reached_cnt'];
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
if ($page > $total_pages) {$page = $total_pages;}
$offset = ($page - 1) * $per_page;

$pool_rows = array();
$rslt = mysqli_query($link,
	"SELECT p.did_id, p.did_number, p.campaign_id, p.local_key, p.enabled, p.admin_priority,
	        p.total_assignments,
	        CASE WHEN p.usage_date = CURDATE() THEN p.calls_today ELSE 0 END AS calls_today_effective,
	        p.daily_limit, p.last_used, p.created_at
	   FROM $pool_from
	   $where
	  ORDER BY $order_by
	  LIMIT $per_page OFFSET $offset;");
while ($row = mysqli_fetch_assoc($rslt))
	{$pool_rows[] = $row;}

$page_reputations = diop_load_reputations($link, array_column($pool_rows, 'did_number'));
foreach ($pool_rows as $idx => $row)
	{
	$digits = preg_replace('/\D/', '', $row['did_number']);
	$pool_rows[$idx]['reputation_data'] = isset($page_reputations[$digits]) ? $page_reputations[$digits] : null;
	}
$page_performance = diop_calculate_performance_scores($link, $pool_rows);
foreach ($pool_rows as $idx => $row)
	{
	$performance_key = $row['campaign_id'] . '|' . $row['did_number'];
	$pool_rows[$idx]['performance_data'] = isset($page_performance[$performance_key])
		? $page_performance[$performance_key] : null;
	}
$reputation_config = diop_reputation_config($link);
$shared_settings = diop_shared_settings($link);

$total_pool = $total_filtered;
$total_enabled = $total_filtered_enabled;

function diop_sort_link($PHP_SELF, $label, $key, $sort_key, $sort_dir, $qs_base)
	{
	$next_dir = ($sort_key == $key && $sort_dir == 'asc') ? 'desc' : 'asc';
	$arrow = '';
	if ($sort_key == $key) {$arrow = ($sort_dir == 'asc') ? ' &uarr;' : ' &darr;';}
	$url = htmlspecialchars($PHP_SELF) . '?' . $qs_base . '&sort=' . urlencode($key) . '&dir=' . urlencode($next_dir);
	return '<a href="'.$url.'" class="hover:text-blue-600">'.htmlspecialchars($label).$arrow.'</a>';
	}

$qs_parts = array();
if ($filter_campaign != '') {$qs_parts[] = 'campaign_id=' . urlencode($filter_campaign);}
if ($filter_search != '') {$qs_parts[] = 'q=' . urlencode($filter_search);}
if ($filter_status != '') {$qs_parts[] = 'status=' . urlencode($filter_status);}
if ($filter_reputation != '') {$qs_parts[] = 'reputation=' . urlencode($filter_reputation);}
if ($filter_local_key != '') {$qs_parts[] = 'local_key=' . urlencode($filter_local_key);}
if ($filter_at_limit) {$qs_parts[] = 'at_limit=1';}
$qs_base = implode('&', $qs_parts);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DID Optimizer Pool</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
:root {
	--ink: #1b1b18;
	--muted: #756f63;
	--line: #e4e0d6;
	--surface: #ffffff;
	--surface-muted: #f0eee7;
	--canvas: #f6f5f1;
	--navy: #16211c;
	--accent: #245a48;
	--accent-dark: #163c30;
	--accent-soft: #e4ede8;
	--signal: #245a48;
	--good: #1f8a4c;
	--bad: #c1442c;
	--bad-dark: #9c3520;
	--warn: #b9800f;
}
* { box-sizing: border-box; }
body.diop-body {
	margin: 0; color: var(--ink); background: var(--canvas);
	font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.diop-display { font-family: "Space Grotesk", Inter, sans-serif; }
.diop-mono { font-family: "IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, monospace; }

/* Plain top bar: title only, no banner chrome */
.diop-topbar { background: var(--surface); border-bottom: 1px solid var(--line); }
.diop-topbar-inner { max-width: 80rem; margin: auto; padding: 1.1rem 1.5rem; }
.diop-title { margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--ink); letter-spacing: -.01em; }

.diop-shell { max-width: 80rem; margin: 0 auto; padding: 1.4rem 1.5rem 3rem; position: relative; z-index: 2; }

/* Stats strip: its own row of cards above the toolbar */
.diop-statsbar { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: .8rem; margin-bottom: 1rem; }
.diop-topbar-stat { background: var(--surface); border: 1px solid var(--line); border-radius: 1rem; padding: .9rem 1.1rem;
	box-shadow: 0 3px 14px rgba(27,27,24,.04); }
.diop-topbar-stat-value { display: block; color: var(--ink); font-weight: 700; font-size: 1.35rem; line-height: 1.2; font-variant-numeric: tabular-nums; }
.diop-topbar-stat-label { display: block; color: var(--muted); font-size: .66rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; margin-top: .3rem; }

/* Toolbar: action buttons (with description) + filter row merged into one panel */
.diop-toolbar { background: var(--surface); border: 1px solid var(--line); border-radius: 1.1rem; box-shadow: 0 3px 14px rgba(27,27,24,.04); margin-bottom: 1rem; overflow: hidden; }
.diop-actions { display: flex; flex-wrap: wrap; gap: .6rem; padding: 1rem; }
.diop-btn { cursor: pointer; display: flex; flex-direction: column; align-items: flex-start; gap: .15rem; border: 1px solid var(--line); border-radius: .7rem;
	padding: .55rem .85rem; min-width: 10.5rem; flex: 1 1 10.5rem; max-width: 15rem; background: var(--surface-muted);
	transition: .15s ease; }
.diop-btn-label { font-size: .78rem; font-weight: 700; color: var(--ink); }
.diop-btn-desc { font-size: .68rem; color: var(--muted); line-height: 1.35; }
.diop-btn:hover { border-color: var(--accent); background: var(--accent-soft); }
.diop-btn:hover .diop-btn-label { color: var(--accent-dark); }
.diop-btn-primary { background: var(--accent); border-color: var(--accent); box-shadow: 0 8px 20px rgba(36,90,72,.24); }
.diop-btn-primary .diop-btn-label { color: #fff; }
.diop-btn-primary .diop-btn-desc { color: rgba(255,255,255,.78); }
.diop-btn-primary:hover { background: var(--accent-dark); border-color: var(--accent-dark); }
.diop-btn-primary:hover .diop-btn-label { color: #fff; }
.diop-filter-card { border-top: 1px solid var(--line); padding: .8rem 1rem; background: var(--surface-muted); margin: 0; }

.diop-table-card { background: var(--surface); border: 1px solid var(--line); border-radius: 1rem; box-shadow: 0 3px 14px rgba(27,27,24,.04); overflow: auto; }
.diop-table-card table { border-collapse: separate; border-spacing: 0; min-width: 1080px; }
.diop-table-card thead th { position: sticky; top: 0; z-index: 2; background: var(--surface-muted); border-bottom: 1px solid var(--line); }
.diop-table-card tbody tr { transition: background .12s ease; }
.diop-table-card tbody tr:hover { background: var(--accent-soft); }
.diop-col-sticky { position: sticky; left: 0; z-index: 1; background: inherit; box-shadow: 2px 0 6px -4px rgba(27,27,24,.3); }
thead .diop-col-sticky { z-index: 3; background: var(--surface-muted); }
/* Explicit color instead of relying on inherited text color for sort-link anchors -
   inheritance through an <a> depends on Tailwind CDN's preflight loading before paint,
   which is not guaranteed on every reload (e.g. the auto-refresh timer's full navigation). */
.diop-table-card thead a { color: var(--muted); text-decoration: none; }
.diop-table-card thead a:hover { color: var(--accent-dark); }

/* Signature widget: signal-strength meter for the Bayesian score */
.diop-meter { display: inline-flex; align-items: flex-end; gap: 2px; height: 13px; vertical-align: middle; margin-right: .4rem; }
.diop-meter-bar { width: 3px; border-radius: 1px; background: var(--line); }
.diop-meter-bar:nth-child(1) { height: 5px; } .diop-meter-bar:nth-child(2) { height: 7px; }
.diop-meter-bar:nth-child(3) { height: 9px; } .diop-meter-bar:nth-child(4) { height: 11px; }
.diop-meter-bar:nth-child(5) { height: 13px; }
.diop-meter-bar.is-on { background: var(--signal); }
.diop-score-pct { font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; }

.diop-bulkbar { display: none; position: sticky; top: 0; z-index: 4; align-items: center; gap: .6rem; flex-wrap: wrap;
	background: var(--navy); color: white; padding: .6rem .9rem; border-radius: .8rem; margin-bottom: .6rem; font-size: .74rem; }
.diop-bulkbar.is-active { display: flex; }
.diop-bulkbar button { border: 1px solid rgba(255,255,255,.22); background: rgba(255,255,255,.08); color: white; border-radius: .5rem; padding: .35rem .7rem; cursor: pointer; font-weight: 600; transition: .15s ease; }
.diop-bulkbar button:hover { background: var(--accent); border-color: var(--accent); }
.diop-bulkbar button.diop-bulk-danger { background: var(--bad); border-color: var(--bad); }
.diop-bulkbar button.diop-bulk-danger:hover { background: var(--bad-dark); }
.diop-bulkbar input[type=number] { width: 5rem; border-radius: .5rem; border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.08); color: white; padding: .35rem .5rem; }

input, select { transition: border-color .15s, box-shadow .15s; }
input:focus, select:focus { outline: none; border-color: var(--accent) !important; box-shadow: 0 0 0 3px rgba(36,90,72,.14); }

/* Retint stock Tailwind blue (modal submit buttons, filter/edit buttons) to the accent palette in one place */
.diop-shell .bg-blue-600 { background-color: var(--accent) !important; }
.diop-shell .hover\:bg-blue-700:hover { background-color: var(--accent-dark) !important; }
.diop-shell .focus\:ring-blue-500:focus { --tw-ring-color: var(--accent) !important; }
.diop-shell .text-blue-600, .diop-shell .text-blue-700 { color: var(--accent-dark) !important; }
.diop-shell .hover\:text-blue-600:hover { color: var(--accent-dark) !important; }
.diop-shell .border-blue-600 { border-color: var(--accent) !important; }
.diop-modal-overlay { background: rgba(20,24,20,.66); backdrop-filter: blur(4px); padding-left: 1rem; padding-right: 1rem; }
.diop-modal-overlay > div { border: 1px solid rgba(255,255,255,.7); }
.diop-empty { padding: 3.5rem 1rem; text-align: center; color: var(--muted); }
.diop-empty strong { display: block; color: var(--ink); font-size: .9rem; margin-bottom: .3rem; }
.diop-toast { position: fixed; z-index: 80; top: 1rem; right: 1rem; width: min(26rem,calc(100vw - 2rem));
	border-radius: .8rem; padding: .85rem 2.7rem .85rem 1rem; box-shadow: 0 18px 50px rgba(17,24,39,.22);
	transition: opacity .2s ease, transform .2s ease; }
.diop-toast.is-hiding { opacity: 0; transform: translateY(-.6rem); }
.diop-toast-close { position: absolute; right: .7rem; top: .55rem; border: 0; background: transparent;
	font-size: 1.2rem; cursor: pointer; opacity: .65; }

@media (max-width: 760px) {
	/* Card-ized table: no duplicate markup, just a CSS display-role swap driven by data-label */
	.diop-table-card table, .diop-table-card thead, .diop-table-card tbody, .diop-table-card th, .diop-table-card td, .diop-table-card tr { display: block; }
	.diop-table-card { min-width: 0; }
	.diop-table-card table { min-width: 0; }
	.diop-table-card thead { display: none; }
	.diop-table-card tr { border: 1px solid var(--line); border-radius: .75rem; margin: .6rem; padding: .3rem .75rem; }
	.diop-table-card td { display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .4rem 0; border-bottom: 1px dashed var(--line); text-align: right; }
	.diop-table-card td:last-child { border-bottom: 0; }
	.diop-table-card td:first-child { justify-content: flex-start; }
	.diop-table-card td[data-label]:before { content: attr(data-label); color: var(--muted); font-weight: 600; font-size: .66rem; text-transform: uppercase; letter-spacing: .04em; text-align: left; }
	.diop-col-sticky { position: static; box-shadow: none; }
}
@media (max-width: 850px) {
	.diop-statsbar { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 520px) {
	.diop-topbar-inner { padding: .9rem 1rem; }
	.diop-shell { padding-left: .8rem; padding-right: .8rem; }
}

/*
 * Exclusive CSS-only modal state. Tailwind's default `peer-checked:` variant
 * compiles to the GENERAL sibling combinator (":checked ~ .peer-checked\:X"),
 * which matches every later sibling, not just the paired one - that caused
 * multiple modals to appear stacked at once. This uses the ADJACENT sibling
 * combinator ("+") instead, combined with a single shared radio group so the
 * browser guarantees only one modal state can ever be checked at a time.
 */
.diop-modal-state { display: none; }
.diop-modal-state:checked + .diop-modal-overlay { display: flex; }
</style>
</head>
<body class="diop-body text-sm">

<header class="diop-topbar">
<div class="diop-topbar-inner">
<h1 class="diop-title diop-display">DID Optimizer</h1>
</div>
</header>

<main class="diop-shell">

<section id="diop-statsbar" class="diop-statsbar" aria-label="Pool summary">
<div class="diop-topbar-stat" title="<?php echo $total_enabled; ?> enabled in current view"><span class="diop-topbar-stat-value diop-display"><?php echo $total_pool; ?></span><span class="diop-topbar-stat-label">DIDs</span></div>
<div class="diop-topbar-stat" title="Across filtered DIDs"><span class="diop-topbar-stat-value diop-display"><?php echo $total_calls_today; ?></span><span class="diop-topbar-stat-label">Calls today</span></div>
<div class="diop-topbar-stat" title="Lifetime optimizer selections"><span class="diop-topbar-stat-value diop-display"><?php echo $total_assignments_sum; ?></span><span class="diop-topbar-stat-label">Assignments</span></div>
<div class="diop-topbar-stat" title="<?php echo $filter_campaign != '' ? 'Campaign '.htmlspecialchars($filter_campaign) : 'All visible campaigns'; ?>"><span class="diop-topbar-stat-value diop-display"><?php echo $total_limit_reached; ?></span><span class="diop-topbar-stat-label">At limit</span></div>
</section>
<?php if ($message != '') {
	$is_err = ($message_class == 'diop-err');
	$box_class = $is_err
		? 'bg-red-50 border-red-300 text-red-800'
		: 'bg-green-50 border-green-300 text-green-800';
	?>
<div id="diop-toast" class="diop-toast border text-xs <?php echo $box_class; ?>" role="status">
<button type="button" class="diop-toast-close" onclick="diopDismissToast()" aria-label="Dismiss">&times;</button>
<?php echo htmlspecialchars($message); ?>
</div>
<?php } ?>

<input type="radio" name="diop-modal" id="modal-none" class="diop-modal-state" checked>

<div class="diop-toolbar"><section class="diop-actions" aria-label="Pool actions">
<label for="modal-add" class="diop-btn diop-btn-primary"><span class="diop-btn-label">＋ Add a single DID</span><span class="diop-btn-desc">Create one campaign-owned caller ID.</span></label>
<label for="modal-csv" class="diop-btn"><span class="diop-btn-label">Upload CSV</span><span class="diop-btn-desc">Import a prepared inventory in bulk.</span></label>
<label for="modal-sync" class="diop-btn"><span class="diop-btn-label">Sync VICIdial CID group</span><span class="diop-btn-desc">Bring outbound CID-group DIDs into the pool.</span></label>
<label for="modal-bulk" class="diop-btn"><span class="diop-btn-label">Set campaign limits</span><span class="diop-btn-desc">Apply one daily cap across a campaign.</span></label>
<label for="modal-reputation" class="diop-btn"><span class="diop-btn-label">Reputation settings</span><span class="diop-btn-desc">Configure shared API access and cache lifetime.</span></label>
</section>

<!-- Modal: Add a single DID -->
<input type="radio" name="diop-modal" id="modal-add" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-4 pr-8">Add a single DID</h2>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="add">
<div>
<label class="block text-xs text-gray-500 mb-1">DID Number</label>
<input type="text" name="did_number" placeholder="12125550101" required
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Campaign</label>
<select name="campaign_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($campaign_rows as $c) {
	$len_warn = (strlen($c['campaign_id']) > 8) ? ' (over 8 chars!)' : '';
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'
		.htmlspecialchars($c['campaign_id']).' - '.htmlspecialchars($c['campaign_name']).$len_warn.'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Local Key (area code, optional)</label>
<input type="text" name="local_key" placeholder="auto-detected if blank"
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Enabled</label>
<select name="enabled" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="Y">Y</option><option value="N">N</option>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Admin Priority</label>
<input type="number" name="admin_priority" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit (0 = unlimited)</label>
<input type="number" name="daily_limit" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Add DID</button>
</form>
</div>
</div>

<!-- Modal: Upload CSV -->
<input type="radio" name="diop-modal" id="modal-csv" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-4 pr-8">Upload CSV</h2>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" enctype="multipart/form-data" class="space-y-3">
<input type="hidden" name="action" value="upload_csv">
<div>
<label class="block text-xs text-gray-500 mb-1">CSV File</label>
<input type="file" name="csv_file" accept=".csv,text/csv" required
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm bg-white">
<div class="text-xs text-gray-400 mt-1">Columns: did_number, campaign_id, local_key, enabled, admin_priority, daily_limit.
Only did_number is required per row &mdash; blank columns fall back to the defaults below. No header row needed.</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Default Campaign (used when a row has no campaign_id)</label>
<select name="csv_campaign_id" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- none --</option>
<?php foreach ($campaign_rows as $c) {
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Default Enabled</label>
<select name="csv_enabled" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="Y">Y</option><option value="N">N</option>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Default Priority</label>
<input type="number" name="csv_admin_priority" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Default Daily Limit</label>
<input type="number" name="csv_daily_limit" value="0" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Upload &amp; Import</button>
</form>
</div>
</div>

<!-- Modal: Sync from VICIdial -->
<input type="radio" name="diop-modal" id="modal-sync" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-2 pr-8">Sync from VICIdial CID group</h2>
<div class="text-xs text-gray-400 mb-4">
Pulls outbound numbers from <code class="font-mono">vicidial_campaign_cid_areacodes</code> for the selected
CID group. All group rows are eligible regardless of the source row's active flag; the optimizer's Enabled
status is set to Y with priority 0 and unlimited daily usage. Existing numbers in the target campaign are skipped.
</div>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="sync">
<div>
<label class="block text-xs text-gray-500 mb-1">Source CID Group</label>
<select name="sync_cid_group_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($cid_group_rows as $g) {
	$selected = ($g['cid_group_id'] == 'NORMAL') ? ' selected' : '';
	$group_label = $g['cid_group_id'] . ($g['cid_group_notes'] != '' ? ' - ' . $g['cid_group_notes'] : '');
	echo '<option value="'.htmlspecialchars($g['cid_group_id']).'"'.$selected.'>'.htmlspecialchars($group_label).'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Target Campaign</label>
<select name="sync_campaign_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($campaign_rows as $c) {
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Import all DIDs</button>
</form>
</div>
</div>

<!-- Modal: Bulk-set daily limit -->
<input type="radio" name="diop-modal" id="modal-bulk" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-2 pr-8">Bulk-set daily limit for a campaign</h2>
<div class="text-xs text-gray-400 mb-4">
Applies one daily limit to every DID currently in the selected campaign's pool
(overwrites each DID's individual limit &mdash; enabled and disabled DIDs alike).
</div>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="bulk_limit">
<div>
<label class="block text-xs text-gray-500 mb-1">Campaign</label>
<select name="bulk_campaign_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
<option value="">-- select --</option>
<?php foreach ($campaign_rows as $c) {
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'">'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit (0 = unlimited)</label>
<input type="number" name="bulk_daily_limit" value="10" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Apply to Campaign</button>
</form>
</div>
</div>

<!-- Modal: Shared reputation configuration -->
<input type="radio" name="diop-modal" id="modal-reputation" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-2 pr-8">Reputation settings</h2>
<div class="text-xs text-gray-400 mb-4">Stored once in the shared optimizer database and used by every web node. The API key is never rendered back into the page.</div>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="reputation_settings">
<div>
<label class="block text-xs text-gray-500 mb-1">API URL</label>
<input type="url" name="reputation_api_url" required
       value="<?php echo htmlspecialchars(isset($shared_settings['reputation_api_url']) ? $shared_settings['reputation_api_url'] : ''); ?>"
       placeholder="https://provider.example/lookup" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">API Key</label>
<input type="password" name="reputation_api_key"
       placeholder="<?php echo isset($shared_settings['reputation_api_key']) ? 'Configured - leave blank to keep' : 'Required'; ?>"
       autocomplete="new-password" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Cache lifetime (seconds)</label>
<input type="number" name="reputation_cache_ttl" min="60" max="86400"
       value="<?php echo (int)(isset($shared_settings['reputation_cache_ttl']) ? $shared_settings['reputation_cache_ttl'] : 900); ?>"
       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div class="text-xs <?php echo $reputation_config ? 'text-green-600' : 'text-amber-600'; ?>">
Status: <?php echo $reputation_config ? 'configured' : 'not configured (neutral reputation score is used)'; ?>
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Save shared settings</button>
</form>
</div>
</div>

<div class="diop-filter-card">
<form method="get" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="flex flex-wrap items-end gap-3 text-xs">
<div>
<label class="block text-gray-500 mb-1">Search DID</label>
<input type="text" name="q" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="digits..."
       class="border border-gray-300 rounded-md px-2 py-1.5 text-xs w-40">
</div>
<div>
<label class="block text-gray-500 mb-1">Campaign</label>
<select name="campaign_id" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
<option value="">-- all campaigns --</option>
<?php foreach ($campaign_rows as $c) {
	$sel = ($c['campaign_id'] == $filter_campaign) ? ' selected' : '';
	echo '<option value="'.htmlspecialchars($c['campaign_id']).'"'.$sel.'>'.htmlspecialchars($c['campaign_id']).'</option>';
} ?>
</select>
</div>
<div>
<label class="block text-gray-500 mb-1">Status</label>
<select name="status" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
<option value="">-- all --</option>
<option value="Y" <?php echo ($filter_status=='Y')?'selected':''; ?>>Enabled</option>
<option value="N" <?php echo ($filter_status=='N')?'selected':''; ?>>Disabled</option>
</select>
</div>
<div>
<label class="block text-gray-500 mb-1">Reputation</label>
<select name="reputation" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
<option value="">-- all --</option>
<option value="positive" <?php echo ($filter_reputation=='positive')?'selected':''; ?>>Positive</option>
<option value="negative" <?php echo ($filter_reputation=='negative')?'selected':''; ?>>Negative</option>
<option value="unknown" <?php echo ($filter_reputation=='unknown')?'selected':''; ?>>Unknown / unavailable</option>
</select>
</div>
<div>
<label class="block text-gray-500 mb-1">Area code</label>
<input type="text" name="local_key" value="<?php echo htmlspecialchars($filter_local_key); ?>" placeholder="212"
       class="border border-gray-300 rounded-md px-2 py-1.5 text-xs w-20">
</div>
<label class="flex items-center gap-1.5 pb-1.5 text-gray-600 cursor-pointer">
<input type="checkbox" name="at_limit" value="1" <?php echo $filter_at_limit ? 'checked' : ''; ?>
       class="rounded border-gray-300"> At daily limit
</label>
<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md font-medium">Filter</button>
<?php if ($filter_campaign != '' || $filter_search != '' || $filter_status != '' || $filter_reputation != '' || $filter_local_key != '' || $filter_at_limit) { ?>
<a href="<?php echo htmlspecialchars($PHP_SELF); ?>" class="text-gray-400 hover:text-gray-700 underline">Clear filters</a>
<?php } ?>
<div class="ml-auto flex items-end gap-3">
<div><label class="block text-gray-500 mb-1">Auto refresh</label>
<select id="diop-auto-refresh" class="border border-gray-300 rounded-md px-2 py-1.5 text-xs">
<option value="0">Off</option><option value="15">15 seconds</option><option value="30">30 seconds</option><option value="60">1 minute</option><option value="300">5 minutes</option>
</select></div>
<div id="diop-refresh-status" class="text-gray-400 pb-1.5"></div>
</div>
</form>
</div></div>

<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" id="diop-bulk-form">
<input type="hidden" name="action" value="bulk_action">
<input type="hidden" name="bulk_op" id="diop-bulk-op" value="">
<div id="diop-bulk-ids"></div>
</form>
<div id="diop-bulkbar" class="diop-bulkbar">
<span id="diop-bulk-count">0 selected</span>
<button type="button" data-op="enable">Enable</button>
<button type="button" data-op="disable">Disable</button>
<span class="flex items-center gap-1">
<input type="number" id="diop-bulk-limit-input" value="0" min="0" title="Daily limit">
<button type="button" data-op="set_limit">Set limit</button>
</span>
<button type="button" data-op="delete" class="diop-bulk-danger">Delete</button>
</div>
<div id="diop-table-card" class="diop-table-card">
<table class="w-full text-xs">
<thead>
<tr class="bg-gray-50 text-gray-500 uppercase text-[11px] tracking-wide">
<th class="px-3 py-2 text-left diop-col-sticky"><input type="checkbox" id="diop-select-all" class="rounded border-gray-300"></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'DID','did',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Campaign','campaign',$sort_key,$sort_dir,$qs_base); ?></th><th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Status','status',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Reputation','reputation',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left">Score</th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Priority','priority',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Daily Limit','limit',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Calls Today','calls_today',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Total Assignments','total',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left"><?php echo diop_sort_link($PHP_SELF,'Last Used','last_used',$sort_key,$sort_dir,$qs_base); ?></th>
<th class="px-3 py-2 text-left">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (count($pool_rows) == 0) { ?>
<tr><td colspan="12" class="diop-empty"><strong>No DIDs found</strong>Adjust the filters or add inventory to this pool.</td></tr>
<?php } ?>
<?php foreach ($pool_rows as $r) {
	$row_bg = ($r['enabled']=='N') ? 'bg-gray-50 text-gray-400' : '';
	$badge = ($r['enabled']=='Y')
		? '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700">Enabled</span>'
		: '<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-700">Disabled</span>';
	?>
<tr class="<?php echo $row_bg; ?> hover:bg-blue-50/40">
<td class="px-3 py-2 diop-col-sticky"><input type="checkbox" data-did-id="<?php echo (int)$r['did_id']; ?>" class="diop-row-check rounded border-gray-300"></td>
<td class="px-3 py-2 diop-mono" data-label="DID"><?php echo htmlspecialchars($r['did_number']); ?></td>
<td class="px-3 py-2" data-label="Campaign"><?php echo htmlspecialchars($r['campaign_id']); ?></td>
<td class="px-3 py-2" data-label="Status"><?php echo $badge; ?></td>
<td class="px-3 py-2" data-label="Reputation">
<?php
$rep = isset($r['reputation_data']) ? $r['reputation_data'] : null;
if (!$rep)
	{echo '<span class="text-gray-400">Unknown</span>';}
else
	{
	$rep_name = !empty($rep['reputation']) ? $rep['reputation'] : 'Unknown';
	$rep_lower = strtolower($rep_name);
	$rep_class = ($rep_lower == 'positive') ? 'text-green-700 bg-green-100'
		: (($rep_lower == 'negative') ? 'text-red-700 bg-red-100' : 'text-gray-600 bg-gray-100');
	$title = !empty($rep['lookup_error']) ? $rep['lookup_error'] : ('Checked '.$rep['checked_at']);
	echo '<span title="'.htmlspecialchars($title).'" class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold '.$rep_class.'">'.htmlspecialchars($rep_name).'</span>';
	}
?>
</td>
<td class="px-3 py-2" data-label="Score">
<?php
$perf = isset($r['performance_data']) ? $r['performance_data'] : null;
if (!$perf)
	{echo '<span class="text-gray-300">&mdash;</span>';}
else
	{
	$score_pct = number_format($perf['score'] * 100, 1);
	$bars_filled = min(5, max(0, (int)round($perf['score'] * 5)));
	$title = sprintf('Sample %d | Success %.1f%% | Human %.1f%% | Duration %.1fs | Reputation %s',
		$perf['sample'], $perf['good_rate'] * 100, $perf['human_rate'] * 100,
		$perf['duration'], $perf['reputation']);
	echo '<span title="'.htmlspecialchars($title).'" class="inline-flex items-center">';
	echo '<span class="diop-meter">';
	for ($bar_i = 1; $bar_i <= 5; $bar_i++)
		{echo '<span class="diop-meter-bar'.($bar_i <= $bars_filled ? ' is-on' : '').'"></span>';}
	echo '</span><span class="diop-score-pct">'.$score_pct.'%</span></span>';
	}
?>
</td>
<td class="px-3 py-2" data-label="Priority"><?php echo (int)$r['admin_priority']; ?></td>
<td class="px-3 py-2" data-label="Daily Limit"><?php echo (int)$r['daily_limit']; ?></td>
<td class="px-3 py-2" data-label="Calls Today">
<?php
$at_cap = $r['daily_limit'] > 0 && $r['calls_today_effective'] >= $r['daily_limit'];
echo (int)$r['calls_today_effective'];
if ($at_cap)
	{echo ' <span title="Reached today\'s daily limit - excluded from selection until it resets at midnight" class="inline-block px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700">capped</span>';}
?>
</td>
<td class="px-3 py-2" data-label="Total"><?php echo (int)$r['total_assignments']; ?></td>
<td class="px-3 py-2" data-label="Last Used"><?php echo htmlspecialchars($r['last_used'] ? $r['last_used'] : '-'); ?></td>
<td class="px-3 py-2" data-label="Actions">
<div class="flex items-center gap-1.5">
<label for="modal-view-<?php echo (int)$r['did_id']; ?>" title="View calls"
       class="cursor-pointer w-7 h-7 flex items-center justify-center rounded-md bg-gray-500 hover:bg-gray-600 text-white">
<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
<path d="M10 3.5c-4.5 0-8 3.6-8 6.5s3.5 6.5 8 6.5 8-3.6 8-6.5-3.5-6.5-8-6.5zm0 10.5a4 4 0 110-8 4 4 0 010 8z"/>
<path d="M10 8a2 2 0 100 4 2 2 0 000-4z"/>
</svg>
</label>
<label for="modal-row-<?php echo (int)$r['did_id']; ?>" title="Edit"
       class="cursor-pointer w-7 h-7 flex items-center justify-center rounded-md bg-blue-600 hover:bg-blue-700 text-white">
<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
<path d="M13.586 3.586a2 2 0 112.828 2.828l-9.9 9.9-3.535.707.707-3.535 9.9-9.9z"/>
</svg>
</label>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="inline"
      onsubmit="return confirm('Delete this DID from the pool?');">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="did_id" value="<?php echo (int)$r['did_id']; ?>">
<button type="submit" title="Delete"
        class="w-7 h-7 flex items-center justify-center rounded-md bg-red-600 hover:bg-red-700 text-white">
<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
<path fill-rule="evenodd" d="M8 2a1 1 0 00-1 1v1H4a1 1 0 000 2h12a1 1 0 100-2h-3V3a1 1 0 00-1-1H8zM5 7a1 1 0 011 1v8a2 2 0 002 2h4a2 2 0 002-2V8a1 1 0 112 0v8a4 4 0 01-4 4H8a4 4 0 01-4-4V8a1 1 0 011-1z" clip-rule="evenodd"/>
</svg>
</button>
</form>
</div>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

<div id="diop-pagination" class="flex items-center justify-between mt-3 text-xs text-gray-500">
<div>Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_filtered; ?> total)</div>
<div class="flex items-center gap-1">
<?php
$pg_qs = $qs_base != '' ? $qs_base . '&' : '';
if ($sort_key) {$pg_qs .= 'sort=' . urlencode($sort_key) . '&dir=' . urlencode($sort_dir) . '&';}
if ($page > 1) {
	echo '<a href="'.htmlspecialchars($PHP_SELF).'?'.$pg_qs.'page='.($page-1).'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">Prev</a>';
} else {
	echo '<span class="px-3 py-1.5 border border-gray-200 rounded-md text-gray-300">Prev</span>';
}
$window_start = max(1, $page - 3);
$window_end = min($total_pages, $page + 3);
for ($p = $window_start; $p <= $window_end; $p++) {
	if ($p == $page) {
		echo '<span class="px-3 py-1.5 border border-blue-600 bg-blue-600 text-white rounded-md">'.$p.'</span>';
	} else {
		echo '<a href="'.htmlspecialchars($PHP_SELF).'?'.$pg_qs.'page='.$p.'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">'.$p.'</a>';
	}
}
if ($page < $total_pages) {
	echo '<a href="'.htmlspecialchars($PHP_SELF).'?'.$pg_qs.'page='.($page+1).'" class="px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">Next</a>';
} else {
	echo '<span class="px-3 py-1.5 border border-gray-200 rounded-md text-gray-300">Next</span>';
}
?>
</div>
</div>

<div id="diop-row-modals">
<?php foreach ($pool_rows as $r) { ?>
<input type="radio" name="diop-modal" id="modal-row-<?php echo (int)$r['did_id']; ?>" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[92%] max-w-md max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-4 pr-8">
<?php echo htmlspecialchars($r['did_number']); ?>
<span class="text-xs text-gray-400 font-normal">(<?php echo htmlspecialchars($r['campaign_id']); ?>)</span>
</h2>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="space-y-3">
<input type="hidden" name="action" value="update">
<input type="hidden" name="did_id" value="<?php echo (int)$r['did_id']; ?>">
<div class="grid grid-cols-2 gap-3">
<div>
<label class="block text-xs text-gray-500 mb-1">Daily Limit</label>
<input type="number" name="daily_limit" value="<?php echo (int)$r['daily_limit']; ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
<div>
<label class="block text-xs text-gray-500 mb-1">Admin Priority</label>
<input type="number" name="admin_priority" value="<?php echo (int)$r['admin_priority']; ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
</div>
</div>
<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md py-2">Save</button>
</form>
<form method="post" action="<?php echo htmlspecialchars($PHP_SELF); ?>" class="mt-3">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="did_id" value="<?php echo (int)$r['did_id']; ?>">
<button type="submit" class="w-full bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-md py-2">
<?php echo ($r['enabled']=='Y') ? 'Disable this DID' : 'Enable this DID'; ?>
</button>
</form>
</div>
</div>

<?php
$calls = array();
$exact_match = diop_exact_log_match_sql();
$fallback_match = diop_fallback_log_match_sql();
$fallback_order = diop_fallback_log_order_sql();
$stmt = mysqli_prepare($link,
	"SELECT a.unique_call_id, a.server_ip, a.lead_id, a.destination, a.selection_reason, a.callerid_applied, a.assigned_at,
	        COALESCE(
	          (SELECT v.status FROM vicidial_log v WHERE $exact_match LIMIT 1),
	          (SELECT v.status FROM vicidial_log v
	            WHERE $fallback_match ORDER BY $fallback_order LIMIT 1)
	        ) AS status,
	        COALESCE(
	          (SELECT v.length_in_sec FROM vicidial_log v WHERE $exact_match LIMIT 1),
	          (SELECT v.length_in_sec FROM vicidial_log v
	            WHERE $fallback_match ORDER BY $fallback_order LIMIT 1)
	        ) AS length_in_sec
	   FROM did_optimizer_assignments a
	  WHERE a.did_number = ? AND a.campaign_id = ?
	  ORDER BY a.assigned_at DESC
	  LIMIT 100;");
mysqli_stmt_bind_param($stmt, 'ss', $r['did_number'], $r['campaign_id']);
mysqli_stmt_execute($stmt);
$calls_res = mysqli_stmt_get_result($stmt);
while ($crow = mysqli_fetch_assoc($calls_res)) {$calls[] = $crow;}
mysqli_stmt_close($stmt);
?>
<input type="radio" name="diop-modal" id="modal-view-<?php echo (int)$r['did_id']; ?>" class="diop-modal-state">
<div class="diop-modal-overlay hidden fixed inset-0 z-50 items-start justify-center pt-[6vh]">
<label for="modal-none" class="absolute inset-0"></label>
<div class="relative bg-white rounded-lg shadow-2xl w-[96%] max-w-6xl max-h-[86vh] overflow-y-auto p-6">
<label for="modal-none" class="absolute top-2.5 right-2.5 w-7 h-7 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 cursor-pointer text-lg">&times;</label>
<h2 class="text-base font-semibold mb-1 pr-8">
Calls placed using <?php echo htmlspecialchars($r['did_number']); ?>
<span class="text-xs text-gray-400 font-normal">(<?php echo htmlspecialchars($r['campaign_id']); ?>)</span>
</h2>
<div class="text-xs text-gray-400 mb-3"><?php echo count($calls); ?> call(s) shown (most recent 100).</div>
<?php if (count($calls) == 0) { ?>
<div class="text-xs text-gray-400 italic py-6 text-center">No calls have used this DID yet.</div>
<?php } else { ?>
<div class="border border-gray-200 rounded-md overflow-x-auto">
<table class="w-full text-xs">
<thead>
<tr class="bg-gray-50 text-gray-500 uppercase text-[10px] tracking-wide">
<th class="px-2 py-1.5 text-left">Lead ID</th>
<th class="px-2 py-1.5 text-left">Server</th>
<th class="px-2 py-1.5 text-left">Destination</th>
<th class="px-2 py-1.5 text-left">Reason</th>
<th class="px-2 py-1.5 text-left">CID Applied</th>
<th class="px-2 py-1.5 text-left">Status</th>
<th class="px-2 py-1.5 text-left">Duration</th>
<th class="px-2 py-1.5 text-left">Assigned At</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($calls as $c) { ?>
<tr class="hover:bg-blue-50/40">
<td class="px-2 py-1.5"><?php echo (int)$c['lead_id']; ?></td>
<td class="px-2 py-1.5 font-mono"><?php echo htmlspecialchars($c['server_ip']); ?></td>
<td class="px-2 py-1.5 font-mono"><?php echo htmlspecialchars($c['destination']); ?></td>
<td class="px-2 py-1.5"><?php echo htmlspecialchars($c['selection_reason']); ?></td>
<td class="px-2 py-1.5"><?php echo ($c['callerid_applied']=='Y') ? '<span class="text-green-600 font-semibold">Y</span>' : '<span class="text-red-600 font-semibold">N</span>'; ?></td>
<td class="px-2 py-1.5"><?php echo $c['status'] !== null ? htmlspecialchars($c['status']) : '<span class="text-gray-300">pending</span>'; ?></td>
<td class="px-2 py-1.5"><?php echo $c['length_in_sec'] !== null ? (int)$c['length_in_sec'].'s' : '-'; ?></td>
<td class="px-2 py-1.5"><?php echo htmlspecialchars($c['assigned_at']); ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<?php } ?>
</div>
</div>
<?php } ?>
</div>

</main>
<script>
function diopInitBulkBar() {
	var bar = document.getElementById('diop-bulkbar');
	var countEl = document.getElementById('diop-bulk-count');
	var selectAll = document.getElementById('diop-select-all');
	var rowChecks = document.querySelectorAll('.diop-row-check');
	if (!bar || !rowChecks.length) { return; }
	function checked() { return Array.prototype.filter.call(rowChecks, function (c) { return c.checked; }); }
	function refresh() {
		var n = checked().length;
		bar.classList.toggle('is-active', n > 0);
		countEl.textContent = n + ' selected';
		if (selectAll) { selectAll.checked = n === rowChecks.length; }
	}
	Array.prototype.forEach.call(rowChecks, function (c) { c.addEventListener('change', refresh); });
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			Array.prototype.forEach.call(rowChecks, function (c) { c.checked = selectAll.checked; });
			refresh();
		});
	}
	bar.querySelectorAll('button[data-op]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var ids = checked().map(function (c) { return c.getAttribute('data-did-id'); });
			if (!ids.length) { return; }
			var op = btn.getAttribute('data-op');
			if (op === 'delete' && !confirm('Delete ' + ids.length + ' selected DID(s) from the pool?')) { return; }
			var idsWrap = document.getElementById('diop-bulk-ids');
			idsWrap.innerHTML = '';
			ids.forEach(function (id) {
				var input = document.createElement('input');
				input.type = 'hidden'; input.name = 'did_ids[]'; input.value = id;
				idsWrap.appendChild(input);
			});
			document.getElementById('diop-bulk-op').value = op;
			if (op === 'set_limit') {
				var limitInput = document.createElement('input');
				limitInput.type = 'hidden'; limitInput.name = 'bulk_row_limit';
				limitInput.value = document.getElementById('diop-bulk-limit-input').value || '0';
				idsWrap.appendChild(limitInput);
			}
			document.getElementById('diop-bulk-form').submit();
		});
	});
}
diopInitBulkBar();

(function () {
	var select = document.getElementById('diop-auto-refresh');
	var status = document.getElementById('diop-refresh-status');
	var storageKey = 'did_optimizer_auto_refresh_seconds';
	var timer = null, secondsLeft = 0, fragmentIds = ['diop-statsbar', 'diop-table-card', 'diop-pagination', 'diop-row-modals'];
	if (!select || !status) { return; }
	function renderStatus(suffix) { status.textContent = secondsLeft > 0 ? 'Refresh in ' + secondsLeft + 's' + (suffix || '') : ''; }
	// ponytail: swaps known fragments from a fetched copy of the same URL instead of a full
	// navigation - reuses the existing PHP rendering, no JSON API to keep in sync separately.
	function ajaxRefresh() {
		var openModal = document.querySelector('input[name="diop-modal"]:checked');
		var openModalId = (openModal && openModal.id !== 'modal-none') ? openModal.id : null;
		fetch(window.location.pathname + window.location.search, { credentials: 'same-origin' })
			.then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
			.then(function (html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				fragmentIds.forEach(function (id) {
					var next = doc.getElementById(id), current = document.getElementById(id);
					if (next && current) { current.replaceWith(next); }
				});
				diopInitBulkBar();
				// Re-check whichever row modal (Edit / View calls) was open before the swap,
				// now pointing at the freshly-fetched radio+overlay so it keeps showing current data.
				if (openModalId) {
					var stillThere = document.getElementById(openModalId);
					if (stillThere) { stillThere.checked = true; }
				}
			})
			.catch(function () { renderStatus(' (failed, retrying next cycle)'); });
	}
	function startRefresh(seconds) {
		if (timer !== null) { window.clearInterval(timer); timer = null; }
		secondsLeft = seconds; renderStatus();
		if (seconds < 1) { return; }
		timer = window.setInterval(function () {
			secondsLeft--;
			if (secondsLeft <= 0) { ajaxRefresh(); secondsLeft = seconds; }
			renderStatus();
		}, 1000);
	}
	var saved = '0';
	try { saved = window.localStorage.getItem(storageKey) || '0'; } catch (ignore) {}
	if (!select.querySelector('option[value="' + saved + '"]')) { saved = '0'; }
	select.value = saved; startRefresh(parseInt(saved, 10) || 0);
	select.onchange = function () {
		var seconds = parseInt(select.value, 10) || 0;
		try { window.localStorage.setItem(storageKey, String(seconds)); } catch (ignore) {}
		startRefresh(seconds);
	};
}());

function diopDismissToast() {
	var toast = document.getElementById('diop-toast');
	if (!toast || toast.className.indexOf('is-hiding') !== -1) { return; }
	toast.className += ' is-hiding';
	window.setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 220);
}
if (document.getElementById('diop-toast')) { window.setTimeout(diopDismissToast, 5000); }
</script>
</body>
</html>
