<?php
# reputation_cron.php
#
# Background sweep that keeps did_optimizer_reputation_cache warm for the
# whole DID pool, instead of relying on the admin page to only check
# whichever 25 rows happen to be displayed. diop_load_reputations() skips
# any DID whose cache entry is still within the configured TTL, so once the
# pool is warm a run only re-checks numbers actually due for a recheck.
# Intended to run from cron every few minutes (see install_did_optimizer.sh).

if (php_sapi_name() != 'cli') {fwrite(STDERR, "reputation_cron.php is CLI-only.\n"); exit(1);}

require(__DIR__ . "/dbconnect_mysqli.php");
require(__DIR__ . "/did_optimizer_reputation.inc.php");

mysqli_query($link, "SET NAMES utf8 COLLATE utf8_unicode_ci");

# ponytail: OFFSET pagination over did_optimizer_pool - fine at pool sizes in
# the thousands, switch to keyset pagination (WHERE did_number > ?) if a pool
# ever grows large enough for OFFSET to be slow.
$sweep_batch = 500;
$offset = 0;
$total = 0;
while (true)
	{
	$rslt = mysqli_query($link,
		"SELECT DISTINCT did_number FROM did_optimizer_pool
		  ORDER BY did_number LIMIT $sweep_batch OFFSET $offset;");
	$numbers = array();
	while ($row = mysqli_fetch_assoc($rslt)) {$numbers[] = $row['did_number'];}
	if (!count($numbers)) {break;}
	diop_load_reputations($link, $numbers);
	$total += count($numbers);
	$offset += $sweep_batch;
	}

printf("Reputation sweep complete: %d DID(s) checked/refreshed.\n", $total);
