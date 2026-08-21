# DID Optimizer Cluster

A custom DID (caller ID) optimizer add-on for [VICIdial](https://www.vicidial.org/). Not part of stock VICIdial — this is a project-specific extension that picks the best outbound caller ID per call using local presence matching and call-performance scoring, and ships an admin UI to manage the DID pool.

## How it works

- **`did_optimizer.agi`** — a Perl AGI script invoked from the dialplan just before `Dial()`. For each outbound call it:
  1. Identifies the current call via `vicidial_auto_calls`.
  2. Finds the local-presence pool nearest the destination's NPA-NXX (via precomputed NPA centroids), falling back to the full campaign pool.
  3. Scores eligible DIDs with a Bayesian-smoothed weighted composite of good-call rate, human-answer rate, average answered duration, and third-party reputation.
  4. Picks the best-scoring DID via LRU rotation among near-top performers, records the assignment transactionally (idempotent per call), and sets `CALLERID(num)`.
  5. Fails safe — any error leaves the existing caller ID untouched and exits 0 so the dialplan continues normally.

- **`admin_did_optimizer_pool.php`** — single-file admin page (requires full VICIdial admin login, `user_level > 7`) for managing the pool: add DIDs individually or via CSV, sync DIDs from a VICIdial CID group, view per-DID call history and live performance scores, and configure the shared reputation API.

- **`did_optimizer.sql`** — shared schema: DID pool, assignments, per-campaign rotation state, NPA-NXX geo data, NPA centroid cache, reputation cache, and shared settings.

## Cluster roles

The installer supports two roles, matching a typical VICIdial cluster split:

- **`database`** — applies/upgrades the shared schema and loads the NPA-NXX geographic dataset. Run once against the shared database.
- **`dialer`** — installs the AGI and admin PHP page on a web/dialer node. Run on every node that places calls or serves the admin UI.

## Install

```bash
# on the shared database host
./install_did_optimizer.sh --role database [--clean]

# on each dialer/web node
./install_did_optimizer.sh --role dialer
```

The installer downloads any missing source files (schema, AGI, PHP, geo dataset) from this repository's `main` branch, or from `$DIDOPT_SOURCE_BASE_URL` / `$DIDOPT_GEO_DATASET_URL` if set. It verifies Perl/PHP syntax and installed-file hashes before finishing.

After installing on a dialer node, add this to the dialplan on every node, right after `call_log` and immediately before `Dial()`:

```
same => n,AGI(did_optimizer.agi,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})
same => n,NoOp(DID optimizer: ${DIDOPT_STATUS} ${DIDOPT_SELECTED} ${DIDOPT_REASON})
```

## Verify

```bash
./quick-test.sh
```

Checks required commands, file/permission/hash integrity between source and deployed copies, database connectivity, schema/index completeness, and dialplan wiring.

## Uninstall

```bash
./uninstall.sh --role dialer
```

Removes the AGI, admin PHP page, and maintenance copies from a dialer node. The shared database schema and dialplan are left unchanged.

## Files

| File | Purpose |
|---|---|
| `did_optimizer.agi` | Perl AGI — selects and applies the caller ID per call |
| `admin_did_optimizer_pool.php` | Admin UI for managing the DID pool |
| `did_optimizer.sql` | Shared database schema |
| `install_did_optimizer.sh` | Installer (`--role database` \| `--role dialer`) |
| `quick-test.sh` | Post-install health check |
| `uninstall.sh` | Dialer-node uninstaller |
| `NPA_dataset.zip` | NPA-NXX geographic dataset loaded by the database role |
