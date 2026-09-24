# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Sends a storefront's plain `SELECT`s to a database replica, deciding one statement at a time, and keeps every write, every locking or connection-bound read, and every read after the request's first write on the primary. Only storefront and GraphQL `GET` and `HEAD` requests use the replica; a `POST`, the admin, REST, cron and the command line do not.
- Switched on per connection by a `read_split` block in `env.php`. Without one, or with `enabled` set to `false`, the store runs Magento's own adapter from the same config, so installing the module changes nothing until it is configured.
- The replica connection opens at the first statement that may go there, runs the same connection setup as the primary, and replays the allow-listed session `SET`s in order: `NAMES`, `sql_mode`, `time_zone`, `character_set_*`, `collation_*` and user variables. Any other `SET` pins the request to the primary.
- A connect or query error on the replica sends that statement, and the rest of the request, to the primary, with one warning in the log.
- Reads of the `session` table, and of any table named in `primary_only_tables`, always go to the primary without pinning the request.
- Read-after-write across requests by GTID: a request that wrote, and whose response a cache could not store, sets an encrypted, `HttpOnly`, `Secure`, `SameSite=Lax` cookie holding the primary's position, and the visitor's next request uses the replica only once `MASTER_GTID_WAIT` says it has caught up. A forged or malformed cookie sends that request to the primary and nothing else.
- `pooled: true`, for a replica host that may move statements between backends: the GTID check is skipped, and a visitor with a pending position reads from the primary until it expires.
- Requires PHP 8.3 or later; the suites and the syntax check run on 8.3 and 8.4.
