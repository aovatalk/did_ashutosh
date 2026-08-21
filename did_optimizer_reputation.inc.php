<?php
# did_optimizer_reputation.inc.php
#
# Reputation lookup/cache logic shared by the admin pool page
# (admin_did_optimizer_pool.php) and the background sweep
# (reputation_cron.php), so both go through one lookup/cache/auto-disable
# implementation instead of two copies drifting apart.

define('DIOP_REPUTATION_CHUNK_SIZE', 200);

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

function diop_reputation_api_call($config, $plus_numbers)
	{
	$ch = curl_init($config['api_url']);
	curl_setopt_array($ch, array(
		CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
		CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-api-key: ' . $config['api_key']),
		CURLOPT_POSTFIELDS => json_encode(array('numbers' => $plus_numbers)),
	));
	$body = curl_exec($ch);
	$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curl_error = curl_error($ch);
	curl_close($ch);
	$payload = ($body !== false && $http_code >= 200 && $http_code < 300) ? json_decode($body, true) : null;
	return array('payload' => $payload, 'error' => $curl_error ? $curl_error : "HTTP $http_code");
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
		if (!isset($results[$digits]) || !$results[$digits]['is_fresh']) {$stale[] = $digits;}
		}
	if (!$config || !count($stale) || !function_exists('curl_init')) {return $results;}

	$stmt = mysqli_prepare($link,
		"INSERT INTO did_optimizer_reputation_cache
		    (did_number, reputation, lookup_status, lookup_error, checked_at)
		 VALUES (?, ?, ?, ?, NOW())
		 ON DUPLICATE KEY UPDATE reputation=VALUES(reputation), lookup_status=VALUES(lookup_status),
		                         lookup_error=VALUES(lookup_error), checked_at=NOW()");
	$disable_stmt = mysqli_prepare($link,
		"UPDATE did_optimizer_pool SET enabled='N' WHERE did_number=? AND enabled='Y'");

	# A single request covering thousands of numbers against a 10-second
	# timeout is unreliable, so the stale set is split into fixed-size
	# chunks - one slow/failed chunk no longer blocks every other number
	# from being checked.
	$last_error = null;
	foreach (array_chunk($stale, DIOP_REPUTATION_CHUNK_SIZE) as $chunk)
		{
		$plus_chunk = array_map(function ($d) {return '+' . $d;}, $chunk);
		$call = diop_reputation_api_call($config, $plus_chunk);
		if (!is_array($call['payload']) || !isset($call['payload']['results']) || !is_array($call['payload']['results']))
			{
			$last_error = $call['error'];
			continue;
			}
		foreach ($call['payload']['results'] as $item)
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
		}
	mysqli_stmt_close($stmt);
	mysqli_stmt_close($disable_stmt);
	if ($last_error !== null && !count($results)) {$results['_request_error'] = array('lookup_error' => $last_error);}
	return $results;
	}
