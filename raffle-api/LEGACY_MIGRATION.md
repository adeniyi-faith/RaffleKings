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
- `app/Services/TicketPricingService.php`, `app/Services/TicketPurchaseService.php`
  — a tested, atomic "settle a payment and hand out tickets" path against
  the new `wallets` table (fixes TD-06). Not yet reachable from any route
  — see below.
- **The WordPress-session auth bridge** (`app/Auth/WordPressAuthCookieValidator.php`,
  `app/Auth/WordPressSessionGuard.php`, registered as the `wordpress`
  guard in `config/auth.php`). This answers the open question below:
  option (a) was chosen — it verifies the exact same "logged in" cookie
  WordPress already sets on `wp_signon()`, re-implementing WordPress's own
  cookie algorithm (including checking the user's live `session_tokens`
  usermeta, so a real WordPress logout also invalidates access here) —
  it does **not** bootstrap WordPress itself, since WP core isn't even
  present in this repository (only `wp-content`). Copy `WP_LOGGED_IN_KEY`,
  `WP_LOGGED_IN_SALT`, and `WP_COOKIEHASH` from the real server into
  `.env` (see `.env.example` for exactly how to find each one). Protect
  any new route with `Route::middleware('auth:wordpress')`; see
  `routes/api.php` → `AuthBridgeController::me()` for a working example
  that returns the real `App\Models\Legacy\WpUser` behind the cookie.
  This guard is meant to be replaced by Sanctum-issued tokens once
  WordPress is no longer the source of truth for auth — not before.

- `app/Models/Legacy/WpPost.php`, `WpPostMeta.php`, `app/Services/RaffleReadService.php`
  — read-only raffle listing/detail, exposed at `GET /api/raffles` and
  `GET /api/raffles/{id}` (public, no auth). Raffles are still WordPress
  posts of type `raffle` with price/max/prize/expiry as postmeta (see
  `wp/wp-content/mu-plugins/rk-core/database.php`'s raffle metabox for the
  canonical key list) — this only reads that, it doesn't replace it yet
  (that's item 10). One deliberate behaviour change: `sold_tickets` /
  `remaining_tickets` / `is_closed` are always derived from real
  `wp_raffle_entries` rows, never trusted from the manually-set `sold`/
  `is_sold_out` postmeta alone (fixes TD-13).
- `POST /api/tickets/purchase` (`app/Http/Controllers/Api/TicketPurchaseController.php`)
  — `TicketPurchaseService` is now reachable over real HTTP, behind
  `auth:wordpress`. **Still not connected to the live frontend** — read
  the controller's docblock before pointing anything at it; it settles
  against the NEW `wallets` table, same caveat as always.

- `app/Models/Raffle.php`, `app/Models/RafflePrizeTier.php` + the
  `raffles`/`raffle_prize_tiers` tables — a real, native replacement for
  raffles-as-WordPress-posts. `php artisan legacy:import-raffles
  [--dry-run]` pulls raffles across from `wp_posts`/`wp_postmeta`,
  **including the "prize_structure" ACF repeater field the legacy draw
  depends on** (`rk_run_raffle_draw()` in `api-gamification.php`) — that
  field isn't registered anywhere in this codebase (database.php
  disables ACF for the raffle CPT on purpose), so it only exists because
  someone configured it directly in the ACF plugin UI. The import command
  reconstructs it from postmeta directly, without needing ACF loaded.
  Read-only against WordPress, safe to re-run. **Not yet the live read or
  draw path** — `RaffleReadService`/`GET /api/raffles` still reads the
  legacy CPT directly, and the draw engine doesn't exist in Laravel yet
  (item 12).

## What is NOT done yet (do not assume otherwise)

- The old PHP code (`api-financials.php`, etc.) still reads and writes
  `usermeta` directly. Backfilling the new tables does **not** switch
  anything over — the two data sources will drift apart the moment real
  traffic hits both. Do not point any new feature at `Wallet`/
  `BankAccount` for real money until the old code's write paths are
  migrated to use the same tables (module by module, per the audit's
  roadmap §27/§28) — otherwise you'll have two different balances.
- No raffle/prize-structure model exists in Laravel yet — raffles are
  still read-only here, and the draw engine still depends entirely on the
  WordPress custom-post-type + ACF field described in the audit (§4.4).
  Next real step per `OVERHAUL_CHECKLIST.md` is Phase 1 item 10.

## Running tests

`php artisan test` works out of the box against SQLite (see
`phpunit.xml`) and does not need the production database — write tests
here before wiring up anything that touches real money.
