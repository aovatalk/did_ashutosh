# Cluster DID Optimizer for VICIdial

DID Optimizer selects an outbound caller ID from a campaign-owned DID pool using
local area-code matching, availability limits, and a Bayesian-smoothed call
performance score, ranking by score then least-recently-used.

## Architecture

This build supports one shared database and any number of Asterisk/web nodes.
Each dialer runs the optimizer as two one-shot Asterisk AGI scripts invoked
directly from the dialplan, no standalone daemon or FastAGI service to manage:

- `did_optimizer.agi` runs before `Dial()` and selects/reserves a DID. It never
  computes a score or aggregates call history - it only reads each candidate's
  already-current `performance_score` off `did_optimizer_pool` and reserves the
  best-ranked eligible row with `FOR UPDATE SKIP LOCKED`, so concurrent calls
  each grab a different row instead of queueing behind a shared lock.
- `did_optimizer_hangup.agi` runs on the dialplan's `h` (hangup) extension and
  is the only thing that updates a DID's counters and `performance_score`,
  incrementally, once per completed call. See **Call performance scoring**
  below.

Selection counters, assignment history, and per-DID/per-campaign running call
stats live in the shared database. Live-call discovery, idempotency, and
performance correlation are scoped by the originating VICIdial `VARserver_ip`,
keeping identical Asterisk unique IDs from different dialers from crossing
node boundaries during selection.

The package contains no server addresses or database credentials. Each dialer
uses its existing VICIdial configuration (`/etc/astguiclient.conf`), while
schema installation connects through the database node's local MySQL socket.

## Manual cluster install (recommended)

Download only the bootstrap installer on every server. It automatically fetches
the files required for the selected role. Install the database schema first,
then install the runtime files on each dialer/web node.

### Download with verified HTTPS (recommended)

```bash
sudo mkdir -p /usr/src/did-optimizer-cluster
cd /usr/src/did-optimizer-cluster
sudo curl -fLO \
  https://raw.githubusercontent.com/aovatalk/did_ashutosh/refs/heads/main/install_did_optimizer.sh
sudo chmod 0755 install_did_optimizer.sh
```

### Download without SSL certificate verification

Use this only when the server's CA certificates are unavailable or broken:

```bash
sudo mkdir -p /usr/src/did-optimizer-cluster
cd /usr/src/did-optimizer-cluster
sudo curl -kfLO \
  https://raw.githubusercontent.com/aovatalk/did_ashutosh/refs/heads/main/install_did_optimizer.sh
sudo chmod 0755 install_did_optimizer.sh
```

`--insecure` disables TLS certificate verification and can expose the download
to interception or modification. Repairing the server's CA trust and using the
verified HTTPS command is strongly preferred. GitHub redirects plain HTTP to
HTTPS, so an HTTP URL is not a true non-SSL download method.

When CA verification is unavailable, pass `DIDOPT_CURL_INSECURE=1` to the
installer so its role-specific file downloads use the same fallback:

```bash
sudo DIDOPT_CURL_INSECURE=1 ./install_did_optimizer.sh --role database
```

or, on a dialer/web node:

```bash
sudo DIDOPT_CURL_INSECURE=1 ./install_did_optimizer.sh --role dialer
```

### 1. Database node

Run this once on the database-only server:

```bash
chmod +x install_did_optimizer.sh
sudo ./install_did_optimizer.sh --role database
```

Expected result:

```text
Shared database schema ready (6 tables).
```

The database role connects through the local MySQL socket. It does not require
or discover a database IP address and does not store database credentials. It
also downloads and imports `NPA_dataset.zip` into `did_optimizer_geo_prefixes`
for NPA-NXX, city, state, and area-code matching.

Pass `--clean` to drop and recreate the optimizer tables (see **Data safety**
below).

### 2. Dialer/web nodes

Run this separately on every Asterisk/web node:

```bash
chmod +x install_did_optimizer.sh
sudo ./install_did_optimizer.sh --role dialer
```

The dialer role installs:

- `/var/lib/asterisk/agi-bin/did_optimizer.agi` and
  `/var/lib/asterisk/agi-bin/did_optimizer_hangup.agi` (owner `asterisk:asterisk`,
  mode `0750`);
- `admin_did_optimizer_pool.php` and `did_optimizer_reputation.inc.php` in the
  detected VICIdial web directory (`/srv/www/htdocs/vicidial`,
  `/var/www/html/vicidial`, or `/var/www/vicidial`);
- `reputation_cron.php` in the same VICIdial web directory, plus
  `/etc/cron.d/did-optimizer-reputation` running it every 5 minutes - unless
  `--reputation no` is passed (see **Reputation configuration** below); and
- `/usr/local/share/did-optimizer/`, holding maintenance copies of both AGI
  scripts, the PHP admin page and its reputation include, `reputation_cron.php`
  (unless skipped), and `quick-test.sh`.

The installer prints the dialplan lines to add for both AGI scripts (see
**Call performance scoring** below for why `did_optimizer_hangup.agi` on the
`h` extension is required, not optional).

No server address or database credential is passed to the installer. Dialer
nodes read `VARserver_ip` and all `VARDB_*` settings from their existing
`/etc/astguiclient.conf`; the PHP page uses VICIdial's existing database layer.

## Admin features

The VICIdial admin page (`admin_did_optimizer_pool.php`, requires full admin
login with `user_level > 7`) provides:

- single and CSV DID imports;
- full synchronization of outbound DIDs from a selected VICIdial CID group
  (`vicidial_campaign_cid_areacodes`), defaulting to `NORMAL`;
- campaign-wide and per-DID daily limits, with enable/disable and bulk actions;
- reputation filtering and cached provider results;
- Bayesian DID performance scores weighted by good-call rate (40%), human-answer
  rate (24%), average answered duration (16%), and reputation (20%) - computed
  live from `vicidial_log` for display here, independently of the AGI's own
  precomputed `performance_score` used for selection (see **Call performance
  scoring**);
- per-DID call history (most recent 100 assignments); and
- browser-persisted automatic refresh intervals with dismissible toast
  notifications for every action.

### Completed-call correlation

Call history and the PHP Bayesian score first match an optimizer assignment to
`vicidial_log` by exact call `uniqueid`. VICIdial can write the completed call
under a different Asterisk channel-leg ID, so unmatched assignments fall back
to the same campaign, lead, destination, and closest call time within a bounded
window. A row is shown as pending only when neither correlation finds a
completed log record.

### Reputation configuration

Open **Reputation settings** from the DID Optimizer admin page. Enter the API
URL, API key, and cache lifetime once. They are stored in the shared
`did_optimizer_settings` table, so all web nodes use the same configuration.
The API key is never rendered back into the page.

For stale or missing entries, the page sends an HTTP POST with an `x-api-key`
header and a JSON body such as:

```json
{"numbers":["+12125550101","+13125550102"]}
```

The provider response must contain a `results` array. Each result can include
`number`, `rk_reputation`, `rk_status`, and `error`. Results are shared through
`did_optimizer_reputation_cache`. The AGI excludes DIDs with a fresh negative
reputation result and uses a neutral reputation component when the provider is
not configured or a DID has no current result. A DID whose lookup comes back
negative also has its pool row disabled (`enabled='N'`).

Every lookup call is split into fixed-size batches of 200 numbers (each with
its own 10-second timeout) so one slow or failed batch does not block the
rest of a large request.

`reputation_cron.php`, installed alongside the admin page and registered in
`/etc/cron.d/did-optimizer-reputation` on every dialer/web node, sweeps the
entire `did_optimizer_pool` every 5 minutes so reputation stays current for
DIDs that are never opened in the admin page - the admin page itself only
ever checks the rows it renders (25 at a time, or the full filtered set on
demand via **Recheck Reputation**), so without the cron sweep any DID never
viewed would stay `Unknown` indefinitely. The cron run and the admin page
share the same cache and TTL, so a DID checked by either one is skipped by
the other until it goes stale again.

Pass `--reputation no` to `install_did_optimizer.sh --role dialer` to skip
deploying and registering the cron sweep on that node. The reputation cache
and admin UI (including manual/bulk recheck) still work either way; only the
automatic background sweep is skipped, so any DID not opened in the admin
page stays `Unknown`.

### Call performance scoring

Scores are precomputed and updated incrementally, never recomputed in the
selection request path:

- `did_optimizer_pool` carries each DID's running counters (`sample_size`,
  `human_answered_calls`, `good_calls`, `answered_seconds_sum`) and its
  current `performance_score`.
- `did_optimizer_hangup.agi`, bound to the dialplan's `h` extension, is the
  only thing that changes them. When a call completes it applies that one
  call's outcome as a `+1`-style delta to its DID's counters (and to the
  campaign-wide running totals in `did_optimizer_campaign_state`, which serve
  as the Bayesian smoothing prior), then recomputes and stores just that DID's
  `performance_score`. This is a deliberate simplification versus computing
  the prior fresh per call from whichever candidate subset happened to be
  compared: the prior is now a stable, cheap-to-maintain campaign-wide running
  total instead of a per-call aggregate query.
- `did_optimizer.agi` (the selection side) only ever reads `performance_score`
  off `did_optimizer_pool` — it does no scoring math and touches `vicidial_log`
  never. Candidates are reserved with `SELECT ... ORDER BY performance_score
  DESC, last_used ASC LIMIT 1 FOR UPDATE SKIP LOCKED`: concurrent calls each
  grab a different eligible row instead of queueing behind a shared lock, and
  a row someone else is mid-reservation on is simply skipped rather than
  blocked on.

Two consequences worth knowing:

- **Without `did_optimizer_hangup.agi` wired into the `h` extension, scores
  never update.** Every DID keeps `performance_score = 0` forever and
  selection degrades to ordering purely by `last_used` (plain round-robin).
  `quick-test.sh` warns (does not fail) if the hangup AGI isn't found in the
  dialplan, since this is easy to forget when adding the optimizer to a route.
- `did_optimizer_hangup.agi` prefers channel variables over querying
  `vicidial_log` when the dialplan passes them: `${DIALSTATUS}` alone settles
  every unanswered hangup (`NOANSWER`/`BUSY`/`CONGESTION`/...) with no
  database dependency, and `${AMDSTATUS}` (if you run AMD) settles answered
  ones too. Only an answered call with no `AMDSTATUS` still needs the
  exact-uniqueid-then-fallback `vicidial_log` correlation the admin page also
  uses, for VICIdial's own human/machine classification. If VICIdial hasn't
  written that call's log row by the time the `h` extension runs, that one
  call's outcome is silently not counted (no retry) — the DID's score simply
  reflects its other completed calls until its next one lands cleanly. Passing
  only `${UNIQUEID}` (no `DIALSTATUS`/`ANSWEREDTIME`/`AMDSTATUS`) falls back to
  this `vicidial_log` path for every call.
- Anti-repeat (avoiding immediately reusing the same DID on a campaign) is
  best-effort under heavy concurrency: it reads `last_did` without a lock, so
  two simultaneous calls can rarely both dodge a stale value rather than each
  other's pick. Traded deliberately for not serializing every call behind one
  row lock.

`FOR UPDATE ... SKIP LOCKED` requires MySQL 8.0+ or MariaDB 10.6+.

### Data safety

Normal installation creates missing tables and upgrades the original schema
without deleting optimizer data. Do not pass `--clean` during an upgrade.
The `--clean` option intentionally drops all optimizer tables — DID pools,
assignment history, and campaign state — before recreating them.

## Dialplan

Add the optimizer after VICIdial's `call_log` AGI and before the carrier `Dial()`,
and add `did_optimizer_hangup.agi` on the same route's `h` extension:

```asterisk
exten => _YOURPATTERN,1,AGI(call_log)
exten => _YOURPATTERN,2,AGI(did_optimizer.agi,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})
exten => _YOURPATTERN,3,NoOp(DIDOPT server=${DIDOPT_SERVER_IP} status=${DIDOPT_STATUS} did=${DIDOPT_SELECTED} reason=${DIDOPT_REASON})
exten => _YOURPATTERN,4,Dial(...)
exten => h,1,AGI(did_optimizer_hangup.agi,${UNIQUEID},${DIALSTATUS},${ANSWEREDTIME},${AMDSTATUS})
```

The `h` extension line is not optional: without it, DID performance scores
never update (see **Call performance scoring**). The last three args are
optional (`did_optimizer_hangup.agi,${UNIQUEID}` alone still works) but
recommended — drop `${AMDSTATUS}` if the route doesn't run AMD. Persist the lines in the
VICIdial carrier Dialplan Entry rather than editing a generated Asterisk
configuration file directly. Rebuild and reload the dialplan on every
Asterisk node in the cluster.

## Verify

```bash
sudo /usr/local/share/did-optimizer/quick-test.sh
```

Run the test on every dialer/web node. It reads the shared database connection
and local server identity from `/etc/astguiclient.conf`, then validates both
Perl AGI deployments and the PHP deployment, all six tables and indexes, geo
prefix population, the reputation sweep cron, and active plus persistent
dialplan integration for both the selection AGI and the hangup AGI (the
latter only warns, since it's easy to forget when wiring up a new route).

The database node does not need the dialer health test. Its schema is verified
during `sudo ./install_did_optimizer.sh --role database`.

## Uninstall

```bash
sudo ./uninstall.sh --role dialer
```

Removes both deployed AGI scripts, the admin PHP page (and its reputation
include and cron sweep) from every supported VICIdial web root, the
`/etc/cron.d/did-optimizer-reputation` cron entry, and the
`/usr/local/share/did-optimizer/` maintenance copies. The shared database
schema and dialplan are left unchanged; remove the optimizer AGI/NoOp/h-extension
lines from the VICIdial carrier Dialplan Entry and rebuild/reload the dialplan
separately.

```bash
sudo ./uninstall.sh --role database --purge-data
```

Drops the shared optimizer tables. Requires the explicit `--purge-data` flag
and typing `DROP asterisk` at the confirmation prompt; anything else cancels
the purge. Irreversible — deployed dialer/web files and the dialplan are left
unchanged.

The NPA-NXX dataset is redistributed by the original DID Optimizer project
from [djbelieny/geoinfo-dataset](https://github.com/djbelieny/geoinfo-dataset)
under the MIT License.

## Runtime configuration

The AGI reads VICIdial database settings from `/etc/astguiclient.conf` on every
invocation. The installer deploys it to
`/var/lib/asterisk/agi-bin/did_optimizer.agi`.

Required dialer configuration keys are:

```text
VARserver_ip
VARDB_server
VARDB_database
VARDB_user
VARDB_pass
VARDB_port
```

Each dialer has its own `VARserver_ip`; all dialers use the shared database
settings already maintained by VICIdial. Do not add credentials to the
optimizer scripts.
