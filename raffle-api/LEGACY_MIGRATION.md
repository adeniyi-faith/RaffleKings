# Migrating onto Laravel — status and next steps

This app is being built **alongside** the existing flat-PHP/WordPress site
(`../` — the rk-core mu-plugin and root PHP pages), not replacing it yet.
It reads and writes the **same MySQL database**. See the audit
(`rafflekings-audit` artifact) for full context — this file just tracks
where the migration actually is.

## Setup on the real server / your local machine

1. `composer install`
2. `cp .env.example .env` and fill in `DB_HOST` / `DB_DATABASE` /
   `DB_USERNAME` / `DB_PASSWORD` with the **same values** WordPress uses
   (find them in `wp/wp-config.php` on the cPanel host — do not create a
   new database). Set `WP_TABLE_PREFIX` to match `$table_prefix` there too
   (usually `wp_`).
3. `php artisan key:generate`
4. `php artisan migrate` — safe to run against the live database. The
   legacy-table migration only creates tables that don't already exist
   (see the guard in `2024_06_01_000001_create_legacy_raffle_tables.php`);
   it will not touch `wp_raffle_transactions`, `wp_raffle_entries`, etc. if
   they're already there. The two new tables (`wallets`, `bank_accounts`)
   will be created for real.

## What exists so far

- `app/Models/Legacy/*` — Eloquent models mapped onto the existing
  `wp_users`, `wp_usermeta`, and all nine `wp_raffle_*` tables. **Read from
  these; don't build new write paths against `WpUserMeta` for balances or
  bank accounts** — use the new models below instead.
- `app/Models/Wallet.php`, `app/Models/BankAccount.php` — the real,
  typed replacements for the `wallet_balance` / `earnings_balance` /
  `rk_bank_accounts` values currently stored as loose `usermeta` rows
  (audit §12/§21, TD-26).
- `php artisan legacy:backfill-wallets [--dry-run]` — copies the current
  `usermeta` values into the new tables. **Read-only against
  `wp_usermeta`** — safe to run repeatedly, does not delete or modify
  anything WordPress/rk-core still relies on.

## What is NOT done yet (do not assume otherwise)

- The old PHP code (`api-financials.php`, etc.) still reads and writes
  `usermeta` directly. Backfilling the new tables does **not** switch
  anything over — the two data sources will drift apart the moment real
  traffic hits both. Do not point any new feature at `Wallet`/
  `BankAccount` for real money until the old code's write paths are
  migrated to use the same tables (module by module, per the audit's
  roadmap §27/§28) — otherwise you'll have two different balances.
- No authentication is wired up yet. Auth still belongs to WordPress
  (`wp_signon`, session cookies). Decide whether this API will (a) verify
  the same WP session cookie, or (b) issue its own tokens (Sanctum) after
  checking credentials against `wp_users` — before building on top of it.
- No controllers/routes for the raffle/payment logic itself exist yet —
  only the data layer (models + migrations). Next real step per the
  roadmap is the payments settlement path (audit §11, TD-05/TD-06), since
  that's the highest-risk module.

## Running tests

`php artisan test` works out of the box against SQLite (see
`phpunit.xml`) and does not need the production database — write tests
here before wiring up anything that touches real money.
