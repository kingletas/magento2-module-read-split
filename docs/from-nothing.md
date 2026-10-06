# From nothing to a working read split

By the end of this, a store that has a database replica will be answering its storefront reads from it, and you will have watched it do so and watched it cope when the replica goes away.

It starts from a store that already replicates. Setting up replication itself is not covered here.

## Contents

- [What this is](#what-this-is)
- [What you need first](#what-you-need-first)
- [Step 1: install it](#step-1-install-it)
- [Step 2: give the replica a user of its own](#step-2-give-the-replica-a-user-of-its-own)
- [Step 3: tell the store where the replica is](#step-3-tell-the-store-where-the-replica-is)
- [Step 4: ask the store what it resolved](#step-4-ask-the-store-what-it-resolved)
- [Step 5: watch reads arrive on the replica](#step-5-watch-reads-arrive-on-the-replica)
- [Step 6: take the replica away](#step-6-take-the-replica-away)
- [What you get for free](#what-you-get-for-free)
- [Before you rely on it](#before-you-rely-on-it)
- [Where to go next](#where-to-go-next)

## What this is

A Mage-OS store with a replica leaves it idle: the primary answers every query, reads included. This module sends the storefront's plain `SELECT`s to the replica and keeps every write, and every read that has to see a write, on the primary. It decides one statement at a time, because a page that looks like a read still writes.

You switch it on in `app/etc/env.php`. There is nothing to set in the admin.

## What you need first

- **A store on Mage-OS or Magento Open Source 2.4.8 or later**, PHP 8.3 or later, **served over HTTPS**. The module's cookie is `Secure`, and on plain HTTP a browser never sends it back.
- **A MariaDB 10.5 or later replica of the store's database, fed by GTID replication.** On the replica, this should show both threads running and a GTID mode:

```sql
SHOW REPLICA STATUS\G
```

```text
Slave_IO_Running: Yes
Slave_SQL_Running: Yes
Using_Gtid: Slave_Pos
```

- **A way to run SQL on both servers and `bin/magento` on the store.**

MySQL has not been tried and, read from the code, would leave every read on the primary. The [README](../README.md#requirements) says why.

## Step 1: install it

From the store's root:

```bash
composer config repositories.kingletas composer https://kingletas.github.io/packages
```

```bash
composer require kingletas/module-read-split
```

```bash
bin/magento module:enable Kingletas_ReadSplit && bin/magento setup:upgrade
```

In production mode, also run `bin/magento setup:di:compile`.

**Nothing has changed yet.** Until `env.php` says where the replica is, the store runs Magento's own database adapter exactly as before. Ask it:

```bash
bin/magento kingletas:read-split:status
```

```text
Read split: not configured
```

## Step 2: give the replica a user of its own

Left alone, the store connects to the replica as the primary's user, with that user's right to write. Give it one that can only read and ask about replication. Run this **on the primary**, so it replicates; change the name, the password and `store` to your database's name:

```sql
CREATE USER 'store_reader'@'%' IDENTIFIED BY '...';
GRANT SELECT ON store.* TO 'store_reader'@'%';
GRANT SLAVE MONITOR ON *.* TO 'store_reader'@'%';
```

`SLAVE MONITOR` is what lets the store ask the replica whether replication is running. **The likely mistake is leaving it out**: the store then cannot tell a healthy replica from a stopped one, so it refuses to use the replica at all, and step 5 shows the breaker open.

Run the replica with `read_only=ON` as well, so that nothing but replication can write to it.

## Step 3: tell the store where the replica is

Add a `read_split` block to `app/etc/env.php`, **beside** `connection`, never inside it. A key inside a connection breaks `bin/magento setup:upgrade`.

```php
'db' => [
    'connection' => [
        'default' => [
            // Your store's own settings, unchanged.
        ],
    ],
    'read_split' => [
        'replica' => [
            'host' => 'db-replica.example', // or 'db-replica.example:3307' for another port
            'username' => 'store_reader',
            'password' => '...',
        ],
    ],
],
```

Write a port the way Magento writes the primary's, `db-replica.example:3307`. Everything the block does not name is taken from the default connection.

If PHP caches compiled files without checking their timestamps (`opcache.validate_timestamps=0`), reload PHP-FPM so it reads the changed file. The status command in the next step runs on the command line and always reads the file as it is now, so it can say *active* while PHP-FPM is still serving pages by the old one.

## Step 4: ask the store what it resolved

```bash
bin/magento kingletas:read-split:status
```

```text
Read split: active
Replica: db-replica.example, port 3306, database store
Settings: max_lag 30 s, position lifetime 60 s, pooled no, read timeout 5 s, connect timeout 2 s
Breaker: closed
```

That is the working state. **If it says something else:**

| It prints | What it means |
|---|---|
| `Read split: configured but not in use`, then a `Reason:` line | The block is there and cannot be used. The reason names what is missing, and the command exits non-zero, so a deploy can stop on it |
| `Read split: not configured` | The store did not find the block. It is inside a connection, or its key is not `read_split` under `db` |
| `Read split: switched off in env.php` | The block has `'enabled' => false` |
| `Breaker: unknown from here` | The command found a place the web server may keep its markers where your user does not look, and names it. Run it as the web server's user to see that node's breaker |

The breaker line only changes once a storefront request has tried the replica, which is the next step.

## Step 5: watch reads arrive on the replica

On the replica, note how many `SELECT`s it has answered:

```sql
SHOW GLOBAL STATUS LIKE 'Com_select';
```

Now load a storefront page the page cache does not hold. A page served from Varnish or the built-in cache runs no query at all, so clear it first or pick a page nobody has asked for:

```bash
bin/magento cache:clean full_page
```

Open a category page in a browser, then ask the replica again. **`Com_select` has gone up by a few dozen or more.** Those are the page's reads. Its writes, and anything it read after its first write, went to the primary.

**A healthy run logs nothing.** The module writes to Magento's log when the replica is taken out of use and when it comes back, and it warns, at most once an hour, about a setting it refused or raised, a statement the replica refused or stopped for running too long, and a read-after-write position it could not set.

Then run the status command once more. If the breaker is open now, the store reached the replica and did not like what it found:

```text
Breaker: open, retried in 24 seconds, kept in var/
```

`var/log/system.log` has one line saying why. With `opening it failed` and code 42000 in it, the replica's user is missing `SLAVE MONITOR` from step 2.

## Step 6: take the replica away

This is the part worth seeing before you rely on it. Stop the replica's database, or block the store's way to it, and load an uncached page.

**The page still loads.** The first request waits up to two seconds for the replica (longer if your store's own `driver_options` set a longer connect timeout), gives up, and reads from the primary. The log gets one line:

```text
Read split: the replica is out of use on this node, and is retried every 30 seconds: opening it failed (...).
```

Every request after it on that web server goes straight to the primary without waiting, and once every 30 seconds one request tries again. Start the replica, wait half a minute, load a page, and the log gets one more line:

```text
Read split: the replica answered again and is back in use on this node.
```

To switch the module off yourself, set `'enabled' => false` in the block. The next request runs Magento's own adapter.

## What you get for free

- **Your own write is never hidden from you.** A request that wrote hands the next one the primary's position, and that visitor reads from the primary until the replica has caught up to it. Add to cart, then the cart page, shows the cart.
- **The carts, the orders and the sign-in checks never read from the replica at all**, so a lagging replica cannot drop a cart or keep a signed-out session open.
- **A replica that is down, stalled, stopped or too far behind is taken out of use by itself**, per web server, and put back by itself.
- **One warning when that happens and one notice when it ends**, not one per request.
- **A deploy check**, `kingletas:read-split:status`, which fails when the block is configured and doing nothing.

## Before you rely on it

Three things to know before this carries a real store, each explained under [Before you turn it on](../README.md#before-you-turn-it-on):

- **A cached page can be stored stale** if it is rendered from a replica that is behind. On a store with a page cache, set `max_lag` to a second or two.
- **Load moves to the replica; it does not disappear.** Watch the replica the way you watch the primary.
- **Database connections can double**, one to each server per PHP worker.

## Where to go next

- [README](../README.md), for every rule, every setting and every limit.
- [What is proved, and what is not](../README.md#what-is-proved-and-what-is-not).
- [CONTRIBUTING.md](../CONTRIBUTING.md), to work on the module.
