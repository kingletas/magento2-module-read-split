# Kingletas_ReadSplit

Mage-OS has no way to send reads to a database replica. Splitting reads from writes is an Adobe Commerce feature, and a Mage-OS store with a replica leaves it idle while the primary answers every query. This module sends a storefront's plain reads to the replica, and keeps every write, and every read that has to see one, on the primary.

**A page is never read-only, so it decides one statement at a time.** A storefront page that looks like a read still writes: a search logs its query, the section load after add to cart creates the cart's masked id, the start of checkout saves the visitor, and with sessions in the database every request writes its session row. Deciding per page would either send those writes to the replica or send the whole page to the primary. Deciding per statement lets the reads before the first write go to the replica and everything from that write on stay on the primary.

It adds no table, no data, no plugin and no admin setting. It is switched on and off in `env.php`, and when it is not configured the store runs Magento's own adapter unchanged.

**New to it?** [From nothing to a working read split](docs/from-nothing.md) goes from an installed store to reads on the replica, with what each step should print.

## What goes where

Every connection Magento opens is built by its connection type. This module replaces that type with one that names its own adapter **only when `env.php` has a `db/read_split` block with a replica host and the switch on, and only for the connection that block splits**, the default one. Otherwise it builds Magento's own adapter from the same config, so a store that installs the module and configures nothing runs exactly as before. The connection's own config is handed to the adapter unchanged, and never carries anything of this module's.

The module's adapter is Magento's MySQL adapter, and it stays the primary. Beside it, it keeps a second connection to the replica, opened at the first statement that may go there. **A statement goes to the replica only when all of these hold; anything else goes to the primary:**

- **It is a plain `SELECT`.** Not `FOR UPDATE`, `LOCK IN SHARE MODE` or `FOR SHARE`, and it calls nothing that answers from one connection's own state: `GET_LOCK`, `RELEASE_LOCK`, `IS_USED_LOCK`, `LAST_INSERT_ID()`, `FOUND_ROWS()`, `CONNECTION_ID()`, `CURRENT_USER`, `SQL_CALC_FOUND_ROWS`, a server variable and their like, and it sets no variable. Those answer from one connection's own state, so they have to run on the primary.
- **No transaction is open.**
- **The request has not been pinned.** The first statement that is not a plain `SELECT` pins the rest of the request to the primary: a write, a lock, a transaction, or a `SET` that is not on the allow-list below. So does the first read of the cart's own tables, for the reason given [below](#tables-that-are-never-read-from-the-replica). So a request that writes a visitor row and then reads it back reads it from the primary.
- **The request is a storefront `GET` or `HEAD`**, in the `frontend` or `graphql` area. A `POST`, the admin, REST, SOAP, cron and the command line use the primary for everything, and so does any statement run before Magento knows the request's area or while another area is being emulated.
- **The replica answered, and this node has not taken it out of use.** A refused connection or a failed statement sends that statement to the primary, and the rest of the request with it. The shopper never sees an error from the replica. See [When the replica is down or has stopped replicating](#when-the-replica-is-down-or-has-stopped-replicating).

**`SHOW`, `DESCRIBE`, `DESC` and `EXPLAIN` go to the primary without pinning the request.** They read metadata and write nothing. After a deploy or a cache flush nearly every request describes tables, and pinning them would switch the offload off exactly when load peaks.

A statement the module does not recognise is treated as a write, and a query that opens with `WITH` is one of them: it pins its request to the primary. An unknown statement costs offload, never correctness.

`lastInsertId()` is always answered by the primary's connection, because the id comes back in the reply to the `INSERT` rather than as SQL, and every `INSERT` runs on the primary.

### Session setup is replayed, not treated as a write

Every connection opens with `SET SQL_MODE`, `SET time_zone` and `SET NAMES`. Read literally, "pin on the first statement that is not a `SELECT`" would pin every request at connect, and the replica would serve nothing.

So the replica is opened with Magento's own adapter and the primary's own config, and runs the same connection setup. After that, **a `SET` of session state runs on the primary as before, is recorded, and is replayed in order on the replica when it opens**; once the replica is open, such a `SET` runs on both. Only an allow-list counts as session state: `NAMES`, `sql_mode`, `time_zone`, the `character_set_*` and `collation_*` variables, and user variables given a plain value: a number, a string, a bound value, `NULL`, `TRUE`, `FALSE` or another user variable. A user variable set from anything computed, a subquery, a function, arithmetic or a server variable such as `@@hostname`, is not one of them: those can answer differently on each server, and a lock taken that way is held on one of them. What one of the named server variables is set to is not examined, beyond refusing a subquery. **Any other `SET` pins the request, `autocommit` and `SET TRANSACTION` included**, and so does a session `SET` that reaches the connection through `exec()`, a prepared statement or a query hook, which is never replayed. A read of the cart that arrives one of those ways pins as it does anywhere.

### Tables that are never read from the replica

Some tables are read by one request right after the request before it wrote them. The session table is the one every store has: with database sessions, each request reads the row the previous request wrote a moment earlier, and the session write comes at the end of the request, too late to hand anything on. The quote and the order are the others: an empty quote read from a lagging replica makes Magento's checkout session drop the visitor's cart for good, and the order success page reads the order it has just placed. So a read of a listed table goes to the primary **without pinning the rest of the request**, with one exception.

**A read of the cart does pin the rest of its request.** Once a request has read any `quote*` table, everything it reads afterwards comes from the primary too. A cart is totalled from its own rows and from its products' prices, stock and price rules, and Magento saves the totals it works out. With the cart read from the primary and the prices from a replica that is behind, a request could save a total that was true on neither server. **The cost is nearly all of the replica's share for a visitor who has a cart.** Luma reads the cart near the start of every page PHP renders for such a visitor, the category and product pages too, not only the cart and the checkout. On a measured store the replica answered three reads of each of those requests and the primary the rest; the figures are under [What is proved](#what-is-proved-and-what-is-not). A visitor with no cart is not affected, and neither is a page served from the page cache, which never reaches PHP. The pin is kept by each connection the block splits: a cart read through `default` does not pin a stock `indexer` connection of the same request, where a write pins both. A table a store adds through `primary_only_tables` never pins.

**The tables that say whether a session or a token still stands are on the list for a different reason.** When a customer changes their password, Magento writes a cutoff on `customer_entity`, and every other browser's session is checked against it. Read from a lagging replica, that check would keep an old session open until the replica caught up. The same goes for `customer_visitor`, for `oauth_token`, for `jwt_auth_revoked` and for `persistent_session`. **`login_as_customer` is there for the same reason:** an admin's session as a customer has to end when the admin logs out, not when the replica hears of it. **So is every `downloadable_link_purchased*` table:** the download controller reads how many downloads are used, adds one and stops, so a link bought for one download could be fetched again and again from a replica that had not counted the first.

**The list always holds `session`, every `quote*` table, `quote_id_mask`, every `sales_order*` table, `customer_entity`, `customer_visitor`, `oauth_token`, `jwt_auth_revoked`, `persistent_session`, `login_as_customer` and every `downloadable_link_purchased*` table**, and `primary_only_tables` adds to it. A name ending in `*` matches every table that starts with it; one without matches that table only, so `customer_entity_varchar` is still read from the replica. The rule for adding one: **a table belongs on the list when a stale read of it can lead Magento to write something wrong, or to let in someone the primary has already shut out.** The cost is a handful of pages that are not cached anyway, and a read or two by primary key on a logged-in page. The match is on the name wherever it appears outside a string or comment, so a column that happens to be named like a listed table, such as `quote_id`, also sends its statement to the primary; that costs offload, never correctness.

## Reading your own writes across requests

A replica lags. A visitor who adds to cart and then opens the cart page would see an empty cart if the cart page read from a replica that had not yet applied the add.

**So a request that wrote hands the next request the primary's GTID position.** Just before the response is sent, if the request wrote and the response is not one a cache could store, the module reads the primary's `@@gtid_binlog_pos` and sets it in a cookie. The next request from that visitor asks the replica `MASTER_GTID_WAIT(position, 0)`, which answers at once whether the replica has applied everything up to that position. **Caught up means the replica; not yet means the primary** for that whole request. A position is exact, where a timer would be a guess.

The cookie:

- is **encrypted with the store's key**, so it shows neither the primary's server id nor its running count of transactions, which is the store's write volume. Its length still grows with the number of digits in that count;
- is `HttpOnly`, `Secure` and `SameSite=Lax`, lives for `position_lifetime` seconds (`max_lag` plus 30 by default, and never shorter), and carries its own issue time, so a copy kept past its lifetime is ignored;
- is checked against the MariaDB GTID format after decryption and **bound as a parameter** to `MASTER_GTID_WAIT`, never put into SQL text. **A forged or malformed value sends that one request to the primary** and does nothing else.

If the primary cannot give a position, the cookie holds the visitor on the primary until it expires, which is always correct and costs only that visitor's offload. If the cookie itself cannot be set as the response leaves, a warning says so, and that visitor's next request is not protected.

**A position's age is judged by the clock of the node that reads it.** One dated more than 5 seconds in the future is refused, which sends that request to the primary, and a node whose clock runs ahead ends the protection that much early. Keep the nodes' clocks in step.

**A write over REST hands on its position too, when it is a `POST`, a `PUT` or a `DELETE`.** Luma's checkout saves the shipping and payment steps and places the order through REST, and the success page then reads the new order. REST requests still read only from the primary; only the position is carried. A REST `GET` that writes hands nothing on: its answer carries no `Cache-Control`, and without one the module cannot tell it from a response a cache may store.

**A position never lives shorter than `max_lag` plus 30 seconds.** The replica may serve other visitors while up to `max_lag` behind, and its lag is asked only once every 30 seconds, so between two checks it can be that much further behind and still in use. A visitor's own write has to be protected at least that long. A shorter `position_lifetime` is raised to it, with a warning at most once an hour per node.

**These get no position, and so no read-after-write across requests:**

- **one who moves between hostnames.** The cookie belongs to the host that set it, so a store spread over `www.shop.example` and `checkout.shop.example` hands nothing from one to the other;
- **a GraphQL client that keeps no cookies.** Its `GET` queries read from the replica whatever it wrote a moment ago. Send the cookie back, or send the query that has to see the write as a `POST`;
- **code that writes through a PDO handle it took from the connection.** That write goes past the adapter, so nothing sees it, and the request is not pinned either;
- **a request that writes and then stops without sending its response the usual way.** The position is set as the response leaves, so a controller that writes and calls `exit` hands nothing on. Magento's download controllers do, which is why their table is always read from the primary;
- **a write over SOAP, and a REST `GET` that writes.** The module listens for the response on the storefront, over GraphQL and over REST, and nowhere else; and a REST `GET` is judged as above;
- **a cacheable page that writes, behind Varnish**, and **a write through a connection the block does not split.** See [Neutral to full-page cache](#neutral-to-full-page-cache) and [Before you turn it on](#before-you-turn-it-on).

## When the replica is down or has stopped replicating

**Each web node keeps a breaker: while it is open, no request on that node tries the replica, and every read goes to the primary.** It is two marker files in Magento's `var/` directory, `kingletas_read_split-<hash>.breaker` and `kingletas_read_split-<hash>.checked`, and the age of a file is what counts. Up to four more only space the hourly warnings out: `.lifetime`, `.slow` and `.refused` under the same name, and an `.inactive` whose hash leaves the replica out, since it is written when no replica is in use. The hash is of the installation's root path and the replica's host and port, so two stores, or two Magento trees, on one node never share a breaker. A deploy that changes the root path, as a release switched by symlink does, therefore starts a new breaker and can repeat an hourly warning. It is deliberately not kept in Magento's cache: this module sits beneath the cache, and a cache backend may itself be down or kept in the database.

**When `var/` cannot be written, the markers go under the system temp directory instead**, in `kingletas_read_split-<uid>/`, a directory the store's own user makes for itself with nobody else allowed in. It is still per node, outside the cache, and kept between requests; the warning when the breaker opens says so. **Every account on a host can write the system temp directory, so a marker is believed only in that directory, and only while it is a real directory, owned by the user PHP runs as, that no one else can write.** A marker anywhere else is ignored, and so is one dated in the future, wherever it is. Telling whose a directory is needs PHP's `posix` extension; without it the temp directory is not used at all. Under systemd's `PrivateTmp`, the temp directory is private to the PHP service, which is still one per node. **Only when neither place can be used is there no breaker:** every request then tries the replica and asks it about replication first, since nobody can record that it was asked, and each failed attempt logs a warning saying there is no breaker. The hourly warnings are then logged every time too, since nothing records the hour. **A breaker that is already open when both places stop being writable stays open:** its marker can no longer be claimed, so nothing retries and nothing more is logged until that marker is removed or one of the two places can be written again, and the deploy check shows it open with 0 seconds left. Nothing throws.

**These open it:**

- the replica refuses a connection, or fails while it is being opened: its replication check, the visitor's GTID check, or the session state being replayed, or later fails a session `SET` it is given;
- the replica fails a statement that the primary then answers. A statement that fails on both servers is the statement's fault, and does not open it. **Neither does a statement the replica stops for running too long** (MariaDB's error 1969, from the `max_statement_time` the module sets): that says something about the query, so the request reads from the primary from there on, the replica stays in use for everyone else, and a warning says so at most once an hour per node. A page that slow costs each visitor who asks for it the wait and then the primary's time as well, so it wants a rate limit in front of it whatever the module does. **Nor does a statement the replica refuses as wrong for it**, SQLSTATE class 42: a table it does not have, a column it has not got yet, a grant its user lacks. When the primary answers it, the request reads from the primary from there on, the replica stays in use, and a warning says so at most once an hour per node. It carries the server's own words where they name only a database, a table, a column, a routine or a grant (errors 1044, 1049, 1054, 1142, 1143, 1146, 1305 and 1370), and the error number and SQLSTATE alone for any other refusal, since a message such as a syntax error's quotes the statement, and a statement can carry a shopper's data. A refusal while the replica is being opened still opens the breaker;
- **replication has stopped or fallen too far behind.** About once every 30 seconds on each node, the request that opens the replica asks it `SHOW REPLICA STATUS`; the turn is claimed by a look at a marker and then a touch, so under load a few requests can ask in the same instant, and every retry of an open breaker asks as well. If the I/O or the SQL thread is not running, or the replica is more than `max_lag` seconds behind (30 by default), the breaker opens. **If the question cannot be answered**, because of an error, a missing privilege or an empty answer, **the breaker opens as well**: that costs offload, never correctness. A replica that answers queries while its replication has stopped would otherwise serve stale prices and stock with no end, and no cookie would be involved.

**After 30 seconds one request retries, or under load a few.** It claims the retry by touching the marker, so the other requests on that node keep to the primary meanwhile. The claim is a look at the marker and then a touch, so workers that look in the same instant can each retry once. If the replica opens, replication is running and within `max_lag`, the breaker closes. If not, it stays open for another 30 seconds.

**It logs one warning when it opens, saying why, and one notice when it closes.** It never logs once per request, and a retry that fails logs nothing more. So a replica that stays down is in the log once, when it went, however long it stays down; the deploy check below shows the open breaker, and still passes.

**One case does repeat, on a store that is already broken.** If `var/` turns read-only while the breaker is open, the retry reaches the replica and then cannot remove its marker. The breaker stays open: one request every 30 seconds uses the replica, the rest stay on the primary, and each of those retries logs a warning naming the file. Make `var/` writable again, or remove that file.

The user the store connects to the replica as needs to read the store's tables and to ask for replication status, which is `SLAVE MONITOR` on MariaDB 10.5 and later. Without the second, the question fails and the breaker keeps the replica out of use, so check the log for the warning after turning the module on. **Give the replica a user of its own that can do nothing else**, and run the replica with `read_only=ON`; left alone, the module connects to it as the primary's user, with that user's write privileges:

```sql
CREATE USER 'store_reader'@'%' IDENTIFIED BY '...';
GRANT SELECT ON store.* TO 'store_reader'@'%';
GRANT SLAVE MONITOR ON *.* TO 'store_reader'@'%';
```

Then name it in the block as `replica.username` and `replica.password`.

**What the log says.** When the breaker opens, once:

```text
Read split: the replica is out of use on this node, and is retried every 30 seconds: its replication SQL thread is not running.
```

The reason is one of: `opening it failed (<exception class> <code>)`, which is also what a missing `SLAVE MONITOR` looks like, with code 42000; `it gave no replication status`; `its replication IO thread is not running`, or `SQL`; `its replication lag is unknown`; `it is 45 seconds behind, over max_lag of 30`; `it failed a statement the primary answered (<exception class> <code>)`; `it failed a session SET (...)`. When it closes, once:

```text
Read split: the replica answered again and is back in use on this node.
```

**While replication is stopped, reads can be up to 30 seconds stale** until the next status check opens the breaker: the check runs at most once every 30 seconds per node, and between checks nothing looks. `max_lag` is compared with the replica's own `Seconds_Behind_Master` at each check, so it can be up to 30 seconds out of date between checks. A visitor who has just written is still protected by the GTID check whatever the lag; `max_lag` is for everyone else, and it says how stale a page they are willing to be shown.

**The replica's health is the replica's own word.** The check asks the replica whether its threads run and how far behind it thinks it is. A replica left pointing at an old or idle primary after a failover answers that everything is fine, and serves what it has for as long as it is left there. Only visitors who have just written are protected, because the position they carry never arrives. The module does not compare the two servers' positions, so repoint or remove a replica as part of the failover itself.

### Neutral to full-page cache

**The cookie is set only on a response a cache could not store anyway:** a `POST`, or a `GET` whose `Cache-Control` says `private`, `no-store` or `no-cache`. It is never part of `X-Magento-Vary` or Varnish's hash, and it holds nothing about the visitor, so a store's cookie notice can list `kingletas_read_split` as strictly necessary.

**A cacheable page that happens to write**, like a search results page logging its query, pins its own request to the primary. Behind Varnish it hands nothing on; otherwise nearly every visitor would carry a position and every cached page would gain a `Set-Cookie`. **With Magento's built-in page cache it is different for one visitor:** the request that fills the cache is answered with `no-store` after its page has been saved, so that visitor does get the cookie. The saved copy does not carry it, and everyone served from the cache gets none.

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
| `replica.host` | none | The replica's host, with its port the way Magento writes the primary's: `db-replica.example:3307`, or the host alone for 3306. Without it the module is off |
| `replica.dbname`, `replica.username`, `replica.password` | the primary's | Only when the replica's differ |
| `pooled` | `false` | Whether the replica host may move statements between backends. See below |
| `connection` | `default` | Leave it out. Only the default connection can be split, and a block naming any other is refused |
| `primary_only_tables` | `[]` | Tables whose reads always go to the primary, added to the defaults above. Names without the store's table prefix; a trailing `*` matches every table that starts with the name |
| `position_lifetime` | `max_lag` + 30 | Seconds a visitor's read-after-write position is honoured, up to 300, or `max_lag` + 30 where that is longer. A shorter value is raised to `max_lag` + 30 |
| `connect_timeout` | `2` | Seconds to wait for the replica to accept a TCP connection before falling back, from 1 to 30. Ignored when the store's own `driver_options` already set a timeout |
| `read_timeout` | `5` | Seconds the replica may take to answer, from its greeting on, before the request falls back to the primary and the breaker opens, from 2 to 60. The server also stops any replica query after one second less |
| `max_lag` | `30` | Seconds the replica may be behind the primary before this node stops reading from it, from 1 to 86400. It is also how stale a page a visitor can be shown, so keep it as low as the replica can hold |

**The block is checked when the settings are read, not at connect.** It splits the connection whose server and database match the one it names: host and port, with `db:3306` and `db` plus port 3306 counted as the same, and the database name. Nothing else in the connection is compared, since Magento adds keys of its own before its adapter sees the config. A block with no replica host, a replica with no database name (neither `replica.dbname` nor the connection's `dbname`), or a `connection` other than `default` is refused: every read goes to the primary, and a warning with the reason is logged at most once an hour per node.

**A setting of the wrong kind is refused, never guessed.** `enabled` and `pooled` take `true` or `false` (`1`, `0`, `'true'` and `'false'` are read as those), the numbers take whole numbers, `primary_only_tables` takes a list of table names, and the block and `replica` take only the keys in the table above. Anything else, `'enabled' => 'off'`, `'max_lag' => '2s'`, a `replica.port`, a key spelled wrong, is refused with its key and what it got, because reading it as a default would mean something nobody chose. A `replica.password` of the wrong kind is refused without being shown, since a password written without its quotes is still a password. **A whole number outside its range is not refused: it is brought to the nearest end of the range, with no warning.** `'max_lag' => 0` is read as 1, `'read_timeout' => 1` as 2, a `connect_timeout` of 60 as 30, and a `position_lifetime` over 300 as 300. The deploy check's `Settings:` line shows what is in force. **The kill switch is read first and on its own**, so `'enabled' => false` switches the module off whatever else in the block is wrong.

**The replica is opened with the primary's `driver_options`**, its TLS settings among them, since the block takes only a host, a database name and credentials for the replica. A replica that needs different TLS options from the primary's cannot be given them yet.

### The deploy check

```bash
bin/magento kingletas:read-split:status
```

It prints whether the split is active, the replica it resolved (host, port and database, never the password), and the breaker's state: closed, or open with the seconds until the next retry and where its marker is kept. **It exits non-zero when a `db/read_split` block is present but not in use, with the reason**, so a deploy can refuse a store where the module is installed and doing nothing. A store with no block, or with the kill switch off, passes: the kill switch is how an incident is handled, and a deploy that fixes the incident must not be refused for it.

What it prints, one state each. In use:

```text
Read split: active
Replica: db-replica.example, port 3306, database store
Settings: max_lag 30 s, position lifetime 60 s, pooled no, read timeout 5 s, connect timeout 2 s
Breaker: closed
```

In use, with the replica taken out on this node:

```text
Read split: active
Replica: db-replica.example, port 3306, database store
Settings: max_lag 30 s, position lifetime 60 s, pooled no, read timeout 5 s, connect timeout 2 s
Breaker: open, retried in 20 seconds, kept in var/
```

Run by a user other than the web server's, on a node where the web server keeps its markers under the system temp directory, or by a user who cannot read `var/`:

```text
Read split: active
Replica: db-replica.example, port 3306, database store
Settings: max_lag 30 s, position lifetime 60 s, pooled no, read timeout 5 s, connect timeout 2 s
Breaker: unknown from here. The web server may keep its markers in /tmp/kingletas_read_split-33, where this user does not look. Run this as the user PHP serves requests as; if that is this user, that directory is left over and can be removed.
```

**The command looks where its own user would keep markers: in `var/`, and in that user's own directory under the system temp directory.** A web server that can write `var/` keeps its markers there, and a deploy user who can read `var/` sees them without being able to write it. The command says unknown only when it finds a place it does not look, and names it: a `var/` it cannot read, or a `kingletas_read_split-<uid>` directory under the temp directory that is named for another user. A directory left from a time `var/` was unwritable has the same effect until it is removed. **Where the web server's temp directory is private to its service**, as under systemd's `PrivateTmp`, no shell can see it at all: on such a node with an unwritable `var/`, the command prints `closed` whatever the web server holds, and the warning logged when the breaker opened is the record.

No block in `env.php`, which passes:

```text
Read split: not configured
```

The kill switch, which passes:

```text
Read split: switched off in env.php
```

A block that is doing nothing, which exits non-zero:

```text
Read split: configured but not in use
Reason: db/read_split has no replica host
```

The other reasons are `db/read_split is not a list of settings`, `db/read_split names a connection other than "default", and only the default connection can be split`, `db/connection/default, the connection db/read_split splits, does not exist`, `db/connection/default has no host` and `the replica has no database name: set replica.dbname, or dbname on the connection`. A mistyped setting gives a reason that names its key, such as `pooled takes true or false, and got "yes"`. The breaker line is this node's, since each node keeps its own.

**Turning it off:** set `'enabled' => false`, or remove the block. The next request runs Magento's own adapter. On a server where PHP caches compiled files without checking their timestamps (`opcache.validate_timestamps=0`), PHP only sees the changed `env.php` after its cache is reset, so reload PHP-FPM too. `bin/magento module:disable Kingletas_ReadSplit` removes it entirely.

**What it leaves behind:** the marker files, `kingletas_read_split-*` in `var/` and, if `var/` was ever unwritable, the directory `kingletas_read_split-<uid>` under the system temp directory. They are a few empty files and can be deleted. Visitors who wrote in the last minute still carry the `kingletas_read_split` cookie until it expires; with the module off nothing reads it.

### What `pooled` means

The GTID check is only true on the backend that then serves the reads. **With `pooled: false`, the default,** the replica host is one server, or a proxy that keeps each client connection on one backend for its life, so the check and the request's reads run on the same server.

**With `pooled: true`,** the replica host is a proxy or balancer that may send each statement to a different backend. A check answered by one backend proves nothing about the next, so the module skips the check, and **a visitor with a pending position reads from the primary until the position expires**, 60 seconds with the defaults. That is always correct, and gives up offload for those visitors only. Visitors who have not just written read from the pool as usual.

**Behind a pool, the replication check sees only the backend that answered it**, so the proxy has to take a lagging or stopped backend out of the pool itself, with its own lag threshold. The module cannot see behind the proxy.

**No proxy is claimed as working with this module.** Neither setting has been proved behind a real proxy or balancer yet, and until one has, treat both as untested there.

## Before you turn it on

**Moving reads moves load; it does not remove it.** On a measured Mage-OS 3.5.0 storefront browse, about nine statements in ten were plain `SELECT`s the replica could take, so one replica serving every PHP worker carries about nine tenths of the statements the primary answers today. A read split built before on another store found exactly that: routing every read to one point made that point the bottleneck. Watch the replica the way you watch the primary, and measure `Threads_connected`, `Com_select` and CPU on both, with the module off and on, before calling it a win. Some read load grows with the store's own configuration: on that browse, every request that collected cart totals read `salesrule_coupon` once per cart price rule per address.

**Connections can double.** Every PHP worker that reads holds a replica connection as well as its primary one, so a store with 50 PHP-FPM workers per node and three nodes can hold 150 connections to the primary and 150 to the replica. The replica connection opens only at the first statement that may go there, so a request that writes first never opens it, but most storefront requests read first. A request that reads through two connections the block splits, `default` and a stock `indexer`, holds a replica connection for each. Size the replica's `max_connections` for as many connections as the primary serves, and if that runs out, a pooling proxy in front of the replica is the fix, set with `pooled: true`.

**A page a cache stores can be stored stale.** A price or a stock figure changes on the primary and Magento purges the pages that show it. The next visitor to ask for one of them gets a fresh render, and if that render reads from a replica that has not applied the change yet, the old page goes back into Varnish or the built-in page cache for its full lifetime. Nobody's cookie helps: the visitor who filled the cache wrote nothing. **The module does not prevent this in this version.** What bounds it is how far behind the replica is at that moment, and lag is worst during a full reindex, which is also when most purges happen. So on a store with a page cache, **set `max_lag` to a second or two**, which takes a replica that falls further behind out of use at the next check, and expect a purged page to be re-rendered from the primary while it is out. A replica that cannot stay within that is not ready for cached pages. The same holds for any cache a storefront `GET` fills after an invalidation, block output and translations among them; the page cache is the one a visitor sees.

**The read-after-write check needs MariaDB with GTID replication.** `@@gtid_binlog_pos` and `MASTER_GTID_WAIT` are MariaDB's. When the primary cannot give a position, with binary logging off for one, every position reads as unknown and holds the visitor on the primary for its lifetime, so nothing reads stale data, but visitors who have just written get no offload. MySQL does not get that far: see [Requirements](#requirements).

**The store has to be served over HTTPS.** The cookie is `Secure`, so a browser on plain HTTP never sends it back, and the next request after a write would read from the replica without a check.

**A replica that is down costs one request per node every 30 seconds its connect timeout.** The first request to find it down waits up to `connect_timeout` seconds and opens the breaker; after that, one retry every 30 seconds waits the same.

**A replica that stalls costs one request per node every 30 seconds its read timeout.** A replica that accepts the connection and then never answers, a frozen host or a full disk, would otherwise hold each request, and its PHP worker, for as long as it hangs. mysqlnd takes its read timeout when a connection is made and keeps it for that connection, so the module sets `mysqlnd.net_read_timeout` to `read_timeout` only while the replica connection opens and restores it at once; the primary's connections keep their own. A read that times out drops the replica connection, which is not reopened within that request, and the breaker opens. `max_statement_time` on the replica session stops a slow query a second earlier, as a second guard for slow queries only.

**The breaker is per node only if `var/` is.** A `var/` shared between web servers shares the breaker too, which still keeps every read correct.

**Only the default connection is split, and every connection that reaches the same server and database counts as it.** A stock `env.php` gives the `indexer` connection the same host and database as `default`, so its reads are split by the same rules, which on a storefront `GET` changes nothing. A connection to another server or database runs Magento's own adapter, and a write made through it is not seen by this module.

**A runtime that calls itself the command line is never split.** The module leaves the command line alone, and knows it by PHP's own name for how it was started. An application server such as RoadRunner or Swoole starts PHP as the command line, so under one every read stays on the primary while the status command says active.

**A read that writes cannot be seen from the SQL.** A `SELECT` that calls a stored function which writes would go to the replica, where it fails and falls back, or writes to the replica. Magento's own code does not do this; check a third-party module that uses stored functions before turning it on.

**A cold cache pins more requests.** After a cache flush, Magento takes `GET_LOCK` while it rebuilds its caches, and that pins the request to the primary. The table descriptions it also makes go to the primary without pinning. Offload returns as the caches fill.

## What is proved, and what is not

**The suites** prove every routing rule in both directions, the session-state replay and its order, the fallback, the breaker and its marker files, the replication check, the cookie's validation and its cache rule, and that an unconfigured store runs Magento's own adapter. They run against doubles of the primary, the replica and the browser.

**On a store**, Mage-OS 3.5.0 with MariaDB 11.8, one replica and Varnish in front, on Magento's generated VCL wherever the cache was measured, in runs between 23 September and 6 October 2026:

- **Where statements go.** On this version's code, a 27-request browse with the replica 10 seconds behind sent 179 reads to the replica, against 182 for the same browse on the code before it: nothing the last changes did took reads from a visitor with no cart. All 68 writes, 44 statements inside a transaction and 88 lock calls ran on the primary, and no request read the replica after its own first write, lock or read of the cart. With Magento's generated VCL in Varnish the replica's count was 230, because each page Varnish misses brings a second PHP request.
- **What the cart pin costs.** A visitor with a cart, with the replica level with the primary: every page PHP rendered read the cart as its fourth statement. The replica answered 3 reads of each request, and the pin kept on the primary 21 of a section load's 31 reads, 143 of a category page's 159, 93 of a product page's 109, 45 of the cart page's 56 and 30 of the checkout page's 42. Those are counted from one run's log as the reads that followed the cart read and named no table on the list, not from a run with the pin and one without.
- **Reading your own write.** Add to cart and then the cart page, at 10 and at 20 seconds of lag. An order placed over REST, with the success page showing it. Wishlist, compare, signup and logout, at 10 seconds of lag and at none: 0 of 15 requests read the replica after their first write.
- **The page cache.** The Varnish hit rate was the same with the module on and off, 51 hits of 66. A visitor carrying the cookie got 11 hits of 11, and the cookie was never set on a response Varnish stored.
- **The breaker.** A replica that is down or paused costs one slow request per 30-second window, and a stall in the middle of a request finishes on the primary. A replica more than `max_lag` behind, and one whose SQL thread is stopped, each gave one warning, no replica reads while out of use, and one notice when back.
- **Setup and the deploy check.** `setup:upgrade` passes with the block in place, and the status command tells its states apart with the exit codes the README gives.
- **Load.** With five visitors and the page cache on, the replica took 28 of every 100 reads, in two runs.
- **This package's own store proof**, `packaging/store-proof.sh`, on this version's code: an installed module with no block changes nothing; with the README's block, a storefront request's plain reads go to the replica; after its first write its reads stay on the primary and see the write; and with a replica that does not answer, the primary answers every read and the breaker opens.

**One thing was shown to go wrong, and is not fixed in this version.** With the replica in use and held 20 seconds behind, a product's price was changed on the primary and its page was asked for a second later. The page was rendered from the replica with the old price, Varnish stored it, and went on serving the old price after the replica had the new one. With the module switched off, the same steps showed the new price. That is the limit under [Before you turn it on](#before-you-turn-it-on), measured.

**Not proved on a store.** Apart from the browse, the cart pin's cost and the package's own store proof, the runs were on the code before this version's last changes, and these parts of it have been through the suites only: the customer and token tables read from the primary; a statement the replica stops for running too long; the breaker's own directory under the system temp directory, where the earlier runs proved the fallback in the temp directory itself; and the position's longer lifetime. Never run on a store at all: database sessions, the replay of session `SET`s after connect, a REST `GET`, several PHP-FPM workers sharing a fallback breaker, lag under a heavier load, a real browser, a pooling proxy, Magento's built-in page cache, MySQL, and installing from the feed.

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

PHP 8.3 or later (the suites run on 8.3 and 8.4), Mage-OS or Magento Open Source 2.4.8 or later (the package asks Composer only for `magento/framework` 103, which every 2.4 release carries, so Composer does not refuse an earlier one; the module has not been run there), and a MariaDB 10.5 or later replica fed by GTID replication, for the read-after-write check and `SHOW REPLICA STATUS`. PHP's `posix` extension is needed only for the breaker's fallback when `var/` cannot be written.

**It is built for MariaDB, and it has not been run on MySQL.** Read from the code: the replica's connection is set up with `max_statement_time`, a MariaDB variable MySQL does not have, so on MySQL opening the replica would fail, the breaker would keep it out of use, and every read would stay on the primary with a warning in the log. The position and the check for it are MariaDB's too.

## Working on it

```bash
make help
```

```bash
make check
```

`make check` runs the coding standard and every suite. In a checkout of the modules repository, the shared harness at its root supplies the tools and a Magento vendor tree. In the package on its own, run `make install` first, which needs repo.magento.com credentials: the checks refuse to run on whatever phpcs or PHPUnit is on the `PATH`. `M2_VENDOR` points the suites at another Magento vendor tree once the tools are installed.

The module has no public PHP API: every class is internal, and what it offers is its `env.php` block.

## Licence

OSL-3.0. See [LICENSE](LICENSE).
