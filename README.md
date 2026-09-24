# Kingletas_ReadSplit

Mage-OS has no way to send reads to a database replica. Splitting reads from writes is an Adobe Commerce feature, and a Mage-OS store with a replica leaves it idle while the primary answers every query. This module sends a storefront's plain reads to the replica, and keeps every write, and every read that has to see one, on the primary.

**A page is never read-only, so it decides one statement at a time.** A storefront page that looks like a read still writes: a search logs its query, the section load after add to cart creates the cart's masked id, the start of checkout saves the visitor, and with sessions in the database every request writes its session row. Deciding per page would either send those writes to the replica or send the whole page to the primary. Deciding per statement lets the reads before the first write go to the replica and everything from that write on stay on the primary.

It adds no table, no data, no plugin and no admin setting. It is switched on and off in `env.php`, and when it is not configured the store runs Magento's own adapter unchanged.

## What goes where

Every connection Magento opens is built by its connection type. This module replaces that type with one that names its own adapter **only when the connection's `env.php` config has a `read_split` block with a replica host and the switch on**. Otherwise it builds Magento's own adapter from the same config, so a store that installs the module and configures nothing runs exactly as before.

The module's adapter is Magento's MySQL adapter, and it stays the primary. Beside it, it keeps a second connection to the replica, opened at the first statement that may go there. **A statement goes to the replica only when all of these hold; anything else goes to the primary:**

- **It is a plain `SELECT`.** Not `FOR UPDATE`, `LOCK IN SHARE MODE` or `FOR SHARE`, and it calls none of `GET_LOCK`, `RELEASE_LOCK`, `IS_USED_LOCK`, `LAST_INSERT_ID()`, `FOUND_ROWS()`, `SQL_CALC_FOUND_ROWS` or a server variable, and it sets no variable. Those answer from one connection's own state, so they have to run on the primary.
- **No transaction is open.**
- **The request has not been pinned.** The first statement that is not a plain `SELECT` pins the rest of the request to the primary: a write, a lock, a transaction, or a `SET` that is not on the allow-list below. So a request that writes a visitor row and then reads it back reads it from the primary.
- **The request is a storefront `GET` or `HEAD`**, in the `frontend` or `graphql` area. A `POST`, the admin, REST, SOAP, cron and the command line use the primary for everything.
- **The replica answered, and this node has not taken it out of use.** A refused connection or a failed statement sends that statement to the primary, and the rest of the request with it. The shopper never sees an error from the replica. See [When the replica is down or has stopped replicating](#when-the-replica-is-down-or-has-stopped-replicating).

**`SHOW`, `DESCRIBE`, `DESC` and `EXPLAIN` go to the primary without pinning the request.** They read metadata and write nothing. After a deploy or a cache flush nearly every request describes tables, and pinning them would switch the offload off exactly when load peaks.

A statement the module does not recognise is treated as a write. An unknown statement costs offload, never correctness.

`lastInsertId()` is always answered by the primary's connection, because the id comes back in the reply to the `INSERT` rather than as SQL, and every `INSERT` runs on the primary.

### Session setup is replayed, not treated as a write

Every connection opens with `SET SQL_MODE`, `SET time_zone` and `SET NAMES`. Read literally, "pin on the first statement that is not a `SELECT`" would pin every request at connect, and the replica would serve nothing.

So the replica is opened with Magento's own adapter and the primary's own config, and runs the same connection setup. After that, **a `SET` of session state runs on the primary as before, is recorded, and is replayed in order on the replica when it opens**; once the replica is open, such a `SET` runs on both. Only an allow-list counts as session state: `NAMES`, `sql_mode`, `time_zone`, the `character_set_*` and `collation_*` variables, and user variables whose value does not read a table. **Any other `SET` pins the request, `autocommit` and `SET TRANSACTION` included.**

### Tables that are never read from the replica

Some tables are read by one request right after the request before it wrote them. The session table is the one every store has: with database sessions, each request reads the row the previous request wrote a moment earlier, and the session write comes at the end of the request, too late to hand anything on. So a read of a listed table goes to the primary **without pinning the rest of the request**. The list always holds `session`, and `primary_only_tables` adds to it.

## Reading your own writes across requests

A replica lags. A visitor who adds to cart and then opens the cart page would see an empty cart if the cart page read from a replica that had not yet applied the add.

**So a request that wrote hands the next request the primary's GTID position.** Just before the response is sent, if the request wrote and the response is not one a cache could store, the module reads the primary's `@@gtid_binlog_pos` and sets it in a cookie. The next request from that visitor asks the replica `MASTER_GTID_WAIT(position, 0)`, which answers at once whether the replica has applied everything up to that position. **Caught up means the replica; not yet means the primary** for that whole request. A position is exact, where a timer would be a guess.

The cookie:

- is **encrypted with the store's key**, so it gives away neither the primary's server id nor its running count of transactions, which is the store's write volume;
- is `HttpOnly`, `Secure` and `SameSite=Lax`, lives for `position_lifetime` seconds (10 by default), and carries its own issue time, so a copy kept past its lifetime is ignored;
- is checked against the MariaDB GTID format after decryption and **bound as a parameter** to `MASTER_GTID_WAIT`, never put into SQL text. **A forged or malformed value sends that one request to the primary** and does nothing else.

If the primary cannot give a position, the cookie holds the visitor on the primary until it expires, which is always correct and costs only that visitor's offload.

## When the replica is down or has stopped replicating

**Each web node keeps a breaker: while it is open, no request on that node tries the replica, and every read goes to the primary.** It is two marker files in Magento's `var/` directory, `kingletas_read_split.breaker` and `kingletas_read_split.checked`, and the age of a file is what counts. It is deliberately not kept in Magento's cache: this module sits beneath the cache, and a cache backend may itself be down or kept in the database.

**These open it:**

- the replica refuses a connection, or fails while it is being opened: its replication check, the visitor's GTID check, or the session state being replayed, or later fails a session `SET` it is given;
- the replica fails a statement that the primary then answers. A statement that fails on both servers is the statement's fault, and does not open it;
- **replication has stopped or fallen too far behind.** At most once every 30 seconds on each node, the request that opens the replica asks it `SHOW REPLICA STATUS`. If the I/O or the SQL thread is not running, or the replica is more than `max_lag` seconds behind (30 by default), the breaker opens. **If the question cannot be answered**, because of an error, a missing privilege or an empty answer, **the breaker opens as well**: that costs offload, never correctness. A replica that answers queries while its replication has stopped would otherwise serve stale prices and stock with no end, and no cookie would be involved.

**After 30 seconds one request retries.** It claims the retry by touching the marker, so the other requests on that node keep to the primary meanwhile. If the replica opens, replication is running and within `max_lag`, the breaker closes. If not, it stays open for another 30 seconds.

**It logs one warning when it opens, saying why, and one notice when it closes.** It never logs once per request, and a retry that fails logs nothing more.

The user the store connects to the replica as needs the privilege to ask for replication status, which is `SLAVE MONITOR` on MariaDB 10.5 and later. Without it, the question fails and the breaker keeps the replica out of use, so check the log for the warning after turning the module on.

`max_lag` is compared with the replica's own `Seconds_Behind_Master` at each check, so it can be up to 30 seconds out of date between checks. A visitor who has just written is still protected by the GTID check whatever the lag; `max_lag` is for everyone else, and it says how stale a page they are willing to be shown.

### Neutral to full-page cache

**The cookie is set only on a response a cache could not store anyway:** a `POST`, or a `GET` whose `Cache-Control` says `private`, `no-store` or `no-cache`. It is never part of `X-Magento-Vary` or Varnish's hash. A cacheable page that happens to write, like a search results page logging its query, pins its own request to the primary and hands nothing on; otherwise nearly every visitor would carry a position and every cached page would gain a `Set-Cookie`.

## Setting it up

The block goes inside the connection it splits, which for a store is `db/connection/default` in `app/etc/env.php`. The replica inherits everything from the primary's config except what its block names:

```php
'db' => [
    'connection' => [
        'default' => [
            'host' => 'db-primary.example',
            'dbname' => 'store',
            'username' => 'store',
            'password' => '...',
            // Magento's own keys as before, then:
            'read_split' => [
                'enabled' => true,
                'replica' => [
                    'host' => 'db-replica.example',
                ],
            ],
        ],
    ],
],
```

| Key | Default | What it decides |
|---|---|---|
| `enabled` | `true` | The kill switch. `false` sends every query to the primary on the next request, with no deploy |
| `replica.host` | none | The replica's host, with its port as `host:port` the way Magento writes the primary's. Without it the module is off |
| `replica.dbname`, `replica.username`, `replica.password` | the primary's | Only when the replica's differ |
| `pooled` | `false` | Whether the replica host may move statements between backends. See below |
| `primary_only_tables` | `[]` | Tables whose reads always go to the primary, added to `session`. Names without the store's table prefix |
| `position_lifetime` | `10` | Seconds a visitor's read-after-write position is honoured, from 1 to 300 |
| `connect_timeout` | `2` | Seconds to wait for the replica to accept a connection before falling back, from 1 to 30 |
| `max_lag` | `30` | Seconds the replica may be behind the primary before this node stops reading from it, from 1 to 86400 |

**Turning it off:** set `'enabled' => false`, or remove the block. The next request runs Magento's own adapter. On a server where PHP caches compiled files without checking their timestamps (`opcache.validate_timestamps=0`), PHP only sees the changed `env.php` after its cache is reset, so reload PHP-FPM too. `bin/magento module:disable Kingletas_ReadSplit` removes it entirely.

### What `pooled` means

The GTID check is only true on the backend that then serves the reads. **With `pooled: false`, the default,** the replica host is one server, or a proxy that keeps each client connection on one backend for its life, so the check and the request's reads run on the same server.

**With `pooled: true`,** the replica host is a proxy or balancer that may send each statement to a different backend. A check answered by one backend proves nothing about the next, so the module skips the check, and **a visitor with a pending position reads from the primary until the position expires**, about ten seconds. That is always correct, and gives up offload for those visitors only. Visitors who have not just written read from the pool as usual.

**Behind a pool, the replication check sees only the backend that answered it**, so the proxy has to take a lagging or stopped backend out of the pool itself, with its own lag threshold. The module cannot see behind the proxy.

**No proxy is claimed as working with this module.** Neither setting has been proved behind a real proxy or balancer yet, and until one has, treat both as untested there.

## Before you turn it on

**Moving reads moves load; it does not remove it.** On a measured Mage-OS 3.5.0 storefront browse, about nine statements in ten were plain `SELECT`s the replica could take, so one replica serving every PHP worker carries roughly the read load the primary carries today. A read split built before on another store found exactly that: routing every read to one point made that point the bottleneck. Watch the replica the way you watch the primary, and measure `Threads_connected`, `Com_select` and CPU on both, with the module off and on, before calling it a win. Some read load grows with the store's own configuration: on that browse, every request that collected cart totals read `salesrule_coupon` once per cart price rule per address.

**Connections roughly double.** Every PHP worker that reads holds a replica connection as well as its primary one. The replica connection opens only at the first statement that may go there, so a request that writes first never opens it, but most storefront requests read first. Size the replica's `max_connections` for as many connections as the primary serves, and if that runs out, a pooling proxy in front of the replica is the fix, set with `pooled: true`.

**The read-after-write check needs MariaDB with GTID replication.** `@@gtid_binlog_pos` and `MASTER_GTID_WAIT` are MariaDB's. On a server without them, every position reads as unknown and holds the visitor on the primary for its lifetime, and a visitor carrying one is served by the primary, so nothing reads stale data, but visitors who have just written get no offload.

**The store has to be served over HTTPS.** The cookie is `Secure`, so a browser on plain HTTP never sends it back, and the next request after a write would read from the replica without a check.

**A replica that is down costs one request per node every 30 seconds its connect timeout.** The first request to find it down waits up to `connect_timeout` seconds and opens the breaker; after that, one retry every 30 seconds waits the same.

**The breaker is per node only if `var/` is.** A `var/` shared between web servers shares the breaker too, which still keeps every read correct.

**Only the connection with the block is split.** Another connection name in `env.php`, such as an `indexer` connection, runs Magento's own adapter, and a write made through it is not seen by this module.

**A read that writes cannot be seen from the SQL.** A `SELECT` that calls a stored function which writes would go to the replica, where it fails and falls back, or writes to the replica. Magento's own code does not do this; check a third-party module that uses stored functions before turning it on.

**A cold cache pins more requests.** After a cache flush, Magento takes `GET_LOCK` while it rebuilds its caches, and that pins the request to the primary. The table descriptions it also makes go to the primary without pinning. Offload returns as the caches fill.

## What is proved, and what is not

The unit, wiring, behaviour and performance suites prove every routing rule in both directions, the session-state replay and its order, the fallback, the breaker and the replication check, the cookie's validation and its cache rule, and that an unconfigured store runs Magento's own adapter. They run against doubles of the primary, the replica and the browser. **Nothing about a running store is proved yet:** that the reads arrive at a real replica, that the writes a storefront makes land on the primary, that browsing, add to cart and checkout pass with a replica lagging on purpose, that a stopped SQL thread opens the breaker, that the Varnish hit rate is unchanged, and what it does to load on either server.

## Installing it

Nothing here is on Packagist, and Composer only reads a `repositories` list from the package you're installing into. So add the Kingletas package feed to your store's own `composer.json`, beside the repository your store already installs Magento from. From the store's root:

```bash
composer config repositories.kingletas composer https://kingletas.github.io/packages
```

It serves this module. Don't add `repo.magento.com` if your store doesn't already use it: a Mage-OS store has no keys for it, and Composer stops with a 401 before it resolves anything.

Then:

```bash
composer require kingletas/module-read-split
```

```bash
bin/magento module:enable Kingletas_ReadSplit && bin/magento setup:upgrade
```

In production mode, also run `bin/magento setup:di:compile`. The module does nothing until `env.php` has a `read_split` block.

## Requirements

PHP 8.3 or 8.4, Mage-OS or Magento Open Source 2.4.8 or later, and a MariaDB 10.5 or later replica fed by GTID replication, for the read-after-write check and `SHOW REPLICA STATUS`.

## Working on it

```bash
make help
```

```bash
make check
```

`make check` runs the coding standard and every suite. The suites need a Magento vendor tree, so point `M2_VENDOR` at one or run the shared harness from the repository root.

The module has no public PHP API: every class is internal, and what it offers is its `env.php` block.

## Licence

OSL-3.0. See [LICENSE](LICENSE).
