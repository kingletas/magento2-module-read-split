# Kingletas_ReadSplit

Mage-OS has no way to send reads to a database replica. Splitting reads from writes is an Adobe Commerce feature, and a Mage-OS store with a replica leaves it idle while the primary answers every query. This module sends a storefront's plain reads to the replica, and keeps every write, and every read that has to see one, on the primary.

**A page is never read-only, so it decides one statement at a time.** A storefront page that looks like a read still writes: a search logs its query, the section load after add to cart creates the cart's masked id, the start of checkout saves the visitor, and with sessions in the database every request writes its session row. Deciding per page would either send those writes to the replica or send the whole page to the primary. Deciding per statement lets the reads before the first write go to the replica and everything from that write on stay on the primary.

It adds no table, no data, no plugin and no admin setting. It is switched on and off in `env.php`, and when it is not configured the store runs Magento's own adapter unchanged.

## What goes where

Every connection Magento opens is built by its connection type. This module replaces that type with one that names its own adapter **only when `env.php` has a `db/read_split` block with a replica host and the switch on, and only for the connection that block splits**, the default one. Otherwise it builds Magento's own adapter from the same config, so a store that installs the module and configures nothing runs exactly as before. The connection's own config is handed to the adapter unchanged, and never carries anything of this module's.

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

Some tables are read by one request right after the request before it wrote them. The session table is the one every store has: with database sessions, each request reads the row the previous request wrote a moment earlier, and the session write comes at the end of the request, too late to hand anything on. The quote and the order are the others: an empty quote read from a lagging replica makes Magento's checkout session drop the visitor's cart for good, and the order success page reads the order it has just placed. So a read of a listed table goes to the primary **without pinning the rest of the request**.

**The list always holds `session`, every `quote*` table, `quote_id_mask` and every `sales_order*` table**, and `primary_only_tables` adds to it. A name ending in `*` matches every table that starts with it. The rule for adding one: **a table belongs on the list when a stale read of it can lead Magento to write something wrong.** The cost is a handful of pages that are not cached anyway. The match is on the name wherever it appears outside a string or comment, so a column that happens to be named like a listed table, such as `quote_id`, also sends its statement to the primary; that costs offload, never correctness.

## Reading your own writes across requests

A replica lags. A visitor who adds to cart and then opens the cart page would see an empty cart if the cart page read from a replica that had not yet applied the add.

**So a request that wrote hands the next request the primary's GTID position.** Just before the response is sent, if the request wrote and the response is not one a cache could store, the module reads the primary's `@@gtid_binlog_pos` and sets it in a cookie. The next request from that visitor asks the replica `MASTER_GTID_WAIT(position, 0)`, which answers at once whether the replica has applied everything up to that position. **Caught up means the replica; not yet means the primary** for that whole request. A position is exact, where a timer would be a guess.

The cookie:

- is **encrypted with the store's key**, so it gives away neither the primary's server id nor its running count of transactions, which is the store's write volume;
- is `HttpOnly`, `Secure` and `SameSite=Lax`, lives for `position_lifetime` seconds (as long as `max_lag` by default, and never shorter), and carries its own issue time, so a copy kept past its lifetime is ignored;
- is checked against the MariaDB GTID format after decryption and **bound as a parameter** to `MASTER_GTID_WAIT`, never put into SQL text. **A forged or malformed value sends that one request to the primary** and does nothing else.

If the primary cannot give a position, the cookie holds the visitor on the primary until it expires, which is always correct and costs only that visitor's offload.

**A write over REST hands on its position too.** Luma's checkout saves the shipping and payment steps and places the order through REST, and the success page then reads the new order. REST requests still read only from the primary; only the position is carried.

**A position never lives shorter than `max_lag`.** The replica may serve other visitors while up to `max_lag` behind, so a visitor's own write has to be protected at least that long. A `position_lifetime` below `max_lag` is raised to it, with a warning at most once an hour per node.

## When the replica is down or has stopped replicating

**Each web node keeps a breaker: while it is open, no request on that node tries the replica, and every read goes to the primary.** It is two marker files in Magento's `var/` directory, `kingletas_read_split-<hash>.breaker` and `kingletas_read_split-<hash>.checked`, and the age of a file is what counts. The hash is of the installation's root path and the replica host, so two stores, or two Magento trees, on one node never share a breaker. It is deliberately not kept in Magento's cache: this module sits beneath the cache, and a cache backend may itself be down or kept in the database.

**When `var/` cannot be written, the markers go to the system temp directory instead**, which is still per node, outside the cache, and kept between requests; the warning when the breaker opens says so. Under systemd's `PrivateTmp`, that directory is private to the PHP service, which is still one per node. **Only when neither can be written is there no breaker:** every request then tries the replica, and each failed attempt logs a warning saying there is no breaker. Nothing throws.

**These open it:**

- the replica refuses a connection, or fails while it is being opened: its replication check, the visitor's GTID check, or the session state being replayed, or later fails a session `SET` it is given;
- the replica fails a statement that the primary then answers. A statement that fails on both servers is the statement's fault, and does not open it;
- **replication has stopped or fallen too far behind.** At most once every 30 seconds on each node, the request that opens the replica asks it `SHOW REPLICA STATUS`. If the I/O or the SQL thread is not running, or the replica is more than `max_lag` seconds behind (30 by default), the breaker opens. **If the question cannot be answered**, because of an error, a missing privilege or an empty answer, **the breaker opens as well**: that costs offload, never correctness. A replica that answers queries while its replication has stopped would otherwise serve stale prices and stock with no end, and no cookie would be involved.

**After 30 seconds one request retries.** It claims the retry by touching the marker, so the other requests on that node keep to the primary meanwhile. If the replica opens, replication is running and within `max_lag`, the breaker closes. If not, it stays open for another 30 seconds.

**It logs one warning when it opens, saying why, and one notice when it closes.** It never logs once per request, and a retry that fails logs nothing more.

The user the store connects to the replica as needs the privilege to ask for replication status, which is `SLAVE MONITOR` on MariaDB 10.5 and later. Without it, the question fails and the breaker keeps the replica out of use, so check the log for the warning after turning the module on.

**While replication is stopped, reads can be up to 30 seconds stale** until the next status check opens the breaker: the check runs at most once every 30 seconds per node, and between checks nothing looks. `max_lag` is compared with the replica's own `Seconds_Behind_Master` at each check, so it can be up to 30 seconds out of date between checks. A visitor who has just written is still protected by the GTID check whatever the lag; `max_lag` is for everyone else, and it says how stale a page they are willing to be shown.

### Neutral to full-page cache

**The cookie is set only on a response a cache could not store anyway:** a `POST`, or a `GET` whose `Cache-Control` says `private`, `no-store` or `no-cache`. It is never part of `X-Magento-Vary` or Varnish's hash. A cacheable page that happens to write, like a search results page logging its query, pins its own request to the primary and hands nothing on; otherwise nearly every visitor would carry a position and every cached page would gain a `Set-Cookie`.

## Setting it up

The block goes at `db/read_split` in `app/etc/env.php`, beside the connections, **never inside one**. Setup builds its database adapter straight from the connection's config, past every preference, so a key inside the connection breaks `bin/magento setup:upgrade` with *Array to string conversion*, even with the switch off. The replica inherits everything from the connection it splits except what the block names:

```php
'db' => [
    'connection' => [
        'default' => [
            'host' => 'db-primary.example',
            'dbname' => 'store',
            'username' => 'store',
            'password' => '...',
            // Magento's own keys, unchanged.
        ],
    ],
    'read_split' => [
        'enabled' => true,
        'replica' => [
            'host' => 'db-replica.example',
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
| `connection` | `default` | The connection in `db/connection` the block splits |
| `primary_only_tables` | `[]` | Tables whose reads always go to the primary, added to the defaults above. Names without the store's table prefix; a trailing `*` matches every table that starts with the name |
| `position_lifetime` | `max_lag` | Seconds a visitor's read-after-write position is honoured, up to 300. A value below `max_lag` is raised to it |
| `connect_timeout` | `2` | Seconds to wait for the replica to accept a TCP connection before falling back, from 1 to 30 |
| `read_timeout` | `5` | Seconds the replica may take to answer, from its greeting on, before the request falls back to the primary and the breaker opens, from 1 to 60. The server also stops any replica query after one second less |
| `max_lag` | `30` | Seconds the replica may be behind the primary before this node stops reading from it, from 1 to 86400 |

**The block is checked when the settings are read, not at connect.** It splits the connection whose server and database match the one it names: host and port, with `db:3306` and `db` plus port 3306 counted as the same, and the database name. Nothing else in the connection is compared, since Magento adds keys of its own before its adapter sees the config. A block with no replica host, a replica with no database name (neither `replica.dbname` nor the connection's `dbname`), or a `connection` that does not exist is refused: every read goes to the primary, and a warning with the reason is logged at most once an hour per node.

### The deploy check

```bash
bin/magento kingletas:read-split:status
```

It prints whether the split is active, the replica it resolved (host, port and database, never the password), and the breaker's state: closed, or open with the seconds until the next retry and where its marker is kept. **It exits non-zero when a `db/read_split` block is present but not in use, with the reason**, so a deploy can refuse a store where the module is installed and doing nothing. A store with no block, or with the kill switch off, passes: the kill switch is how an incident is handled, and a deploy that fixes the incident must not be refused for it.

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

**A replica that stalls costs one request per node every 30 seconds its read timeout.** A replica that accepts the connection and then never answers, a frozen host or a full disk, would otherwise hold each request, and its PHP worker, for as long as it hangs. mysqlnd takes its read timeout when a connection is made and keeps it for that connection, so the module sets `mysqlnd.net_read_timeout` to `read_timeout` only while the replica connection opens and restores it at once; the primary's connections keep their own. A read that times out drops the replica connection, which is not reopened within that request, and the breaker opens. `max_statement_time` on the replica session stops a slow query a second earlier, as a second guard for slow queries only.

**The breaker is per node only if `var/` is.** A `var/` shared between web servers shares the breaker too, which still keeps every read correct.

**Only the connection with the block is split.** Another connection name in `env.php`, such as an `indexer` connection, runs Magento's own adapter, and a write made through it is not seen by this module.

**A read that writes cannot be seen from the SQL.** A `SELECT` that calls a stored function which writes would go to the replica, where it fails and falls back, or writes to the replica. Magento's own code does not do this; check a third-party module that uses stored functions before turning it on.

**A cold cache pins more requests.** After a cache flush, Magento takes `GET_LOCK` while it rebuilds its caches, and that pins the request to the primary. The table descriptions it also makes go to the primary without pinning. Offload returns as the caches fill.

## What is proved, and what is not

The unit, wiring, behaviour and performance suites prove every routing rule in both directions, the session-state replay and its order, the fallback, the breaker and the replication check, the cookie's validation and its cache rule, and that an unconfigured store runs Magento's own adapter. They run against doubles of the primary, the replica and the browser.

**One store proof has run**, on Mage-OS 3.5.0 with MariaDB 11.8 and a replica held 10 seconds behind. Every write and lock landed on the primary and none reached the replica; browse, add to cart, the cart page, wishlist, compare, signup, logout, GraphQL and a cold cache passed; the Varnish hit rate was the same with the module on and off; the breaker opened on a dead replica and a stopped SQL thread, and kept working from the temp directory with `var/` unwritable. **It also found four failures, which this version addresses and which have not yet been proved again on a store:** the block inside the connection broke `setup:upgrade`; an order placed over REST was read from the lagging replica; a stalled replica hung every request; and a position shorter than `max_lag` lost a cart.

Not proved on a store yet: database sessions, a trip on lag over `max_lag`, the replay of session `SET`s after connect, a REST `GET`, several PHP-FPM workers sharing a temp-directory breaker, lag under load, a real browser, and a load mix heavy in catalog pages.

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
