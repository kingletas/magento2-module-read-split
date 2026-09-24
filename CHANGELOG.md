# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Sends a storefront's plain `SELECT`s to a database replica, deciding one statement at a time, and keeps every write, every locking or connection-bound read, and every read after the request's first write on the primary. Only storefront and GraphQL `GET` and `HEAD` requests use the replica; a `POST`, the admin, REST, cron and the command line do not.
- Switched on per connection by a `read_split` block in `env.php`. Without one, or with `enabled` set to `false`, the store runs Magento's own adapter from the same config, so installing the module changes nothing until it is configured.
- The replica connection opens at the first statement that may go there, runs the same connection setup as the primary, and replays the allow-listed session `SET`s in order: `NAMES`, `sql_mode`, `time_zone`, `character_set_*`, `collation_*` and user variables. Any other `SET` pins the request to the primary.
- A connect or query error on the replica sends that statement, and the rest of the request, to the primary.
- A 30-second breaker per web node, kept in marker files under `var/` rather than in the cache, or in the system temp directory when `var/` cannot be written. The markers are named by a hash of the installation's root and the replica host, so two stores on one node never share one. A refused connection, a failure while opening the replica, or a statement the replica fails and the primary answers opens it, and while it is open no request on that node tries the replica. After 30 seconds one request retries. One warning when it opens and one notice when it closes, never one per request.
- Replication health: at most once every 30 seconds per node, the replica is asked `SHOW REPLICA STATUS`, and a stopped I/O or SQL thread, lag over the new `max_lag` setting (30 seconds by default), or a question it cannot answer opens the same breaker. The replica user needs `SLAVE MONITOR` on MariaDB 10.5 and later.
- `SHOW`, `DESCRIBE`, `DESC` and `EXPLAIN` go to the primary without pinning the request, so a cold cache does not switch the offload off.
- Reads of the `session` table, and of any table named in `primary_only_tables`, always go to the primary without pinning the request.
- Read-after-write across requests by GTID: a request that wrote, and whose response a cache could not store, sets an encrypted, `HttpOnly`, `Secure`, `SameSite=Lax` cookie holding the primary's position, and the visitor's next request uses the replica only once `MASTER_GTID_WAIT` says it has caught up. A forged or malformed cookie sends that request to the primary and nothing else.
- `pooled: true`, for a replica host that may move statements between backends: the GTID check is skipped, and a visitor with a pending position reads from the primary until it expires.
- Requires PHP 8.3 or later; the suites and the syntax check run on 8.3 and 8.4.
