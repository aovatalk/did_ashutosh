# Cluster DID Optimizer for VICIdial

DID Optimizer selects an outbound caller ID from a campaign-owned DID pool using
local area-code matching, availability limits, Bayesian-smoothed call performance,
and least-recently-used balancing among similarly performing numbers.

## Architecture

This build supports one shared database and any number of Asterisk/web nodes.
Each dialer runs the optimizer as a one-shot Asterisk AGI script (`did_optimizer.agi`),
invoked directly from the dialplan per call. There is no standalone daemon or
FastAGI service to manage — the script connects, selects a DID, applies the
caller ID, and disconnects within the same AGI invocation.

Selection counters, assignment history, and the per-campaign concurrency lock
live in the shared database. Live-call discovery, idempotency, and performance
correlation are scoped by the originating VICIdial `VARserver_ip`, keeping
identical Asterisk unique IDs from different dialers from crossing node
boundaries during selection.

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

- `/var/lib/asterisk/agi-bin/did_optimizer.agi` (owner `asterisk:asterisk`, mode `0750`);
- `admin_did_optimizer_pool.php` in the detected VICIdial web directory
  (`/srv/www/htdocs/vicidial`, `/var/www/html/vicidial`, or `/var/www/vicidial`); and
- `/usr/local/share/did-optimizer/`, holding maintenance copies of the AGI, the
  PHP admin page, and `quick-test.sh`.

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
  rate (24%), average answered duration (16%), and reputation (20%);
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
not configured or a DID has no current result.

### Data safety

Normal installation creates missing tables and upgrades the original schema
without deleting optimizer data. Do not pass `--clean` during an upgrade.
The `--clean` option intentionally drops all optimizer tables — DID pools,
assignment history, and campaign state — before recreating them.

## Dialplan

Add the optimizer after VICIdial's `call_log` AGI and before the carrier `Dial()`:

```asterisk
exten => _YOURPATTERN,1,AGI(call_log)
exten => _YOURPATTERN,2,AGI(did_optimizer.agi,${campaign_id},${dialed_number},${UNIQUEID},${lead_id})
exten => _YOURPATTERN,3,NoOp(DIDOPT server=${DIDOPT_SERVER_IP} status=${DIDOPT_STATUS} did=${DIDOPT_SELECTED} reason=${DIDOPT_REASON})
exten => _YOURPATTERN,4,Dial(...)
```

Persist the lines in the VICIdial carrier Dialplan Entry rather than editing a
generated Asterisk configuration file directly. Rebuild and reload the
dialplan on every Asterisk node in the cluster.

## Verify

```bash
sudo /usr/local/share/did-optimizer/quick-test.sh
```

Run the test on every dialer/web node. It reads the shared database connection
and local server identity from `/etc/astguiclient.conf`, then validates the Perl
AGI and PHP deployments, all six tables and indexes, geo prefix population,
and active plus persistent dialplan integration.

The database node does not need the dialer health test. Its schema is verified
during `sudo ./install_did_optimizer.sh --role database`.

## Uninstall

```bash
sudo ./uninstall.sh --role dialer
```

Removes the deployed AGI, the admin PHP page from every supported VICIdial web
root, and the `/usr/local/share/did-optimizer/` maintenance copies. The shared
database schema and dialplan are left unchanged; remove the optimizer line
from the VICIdial carrier Dialplan Entry and rebuild/reload the dialplan
separately.

There is no `--role database` uninstall path — dropping the shared schema is a
manual, deliberate action against the database node.

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
