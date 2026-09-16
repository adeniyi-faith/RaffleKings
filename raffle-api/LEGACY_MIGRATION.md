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

- `app/Models/WalletLedgerEntry.php` + the `wallet_ledger_entries` table,
  `app/Services/WalletLedgerService.php` — a real, append-only ledger.
  `TicketPurchaseService` now records a ledger entry for every debit in
  the same database transaction as the balance mutation (including
  rolling the ledger entry back on the TD-06 collision path). Run
  `php artisan legacy:reconcile-wallet-ledger [--dry-run]` once after
  `legacy:backfill-wallets` so every wallet has an honest opening-balance
  entry — it does not invent a transaction history that doesn't exist,
  it just marks "this was the balance when the ledger started."

- `app/Services/BankAccountService.php` + `BankAccountController` — bank
  account add/list/set-primary/delete on the real `bank_accounts` table,
  at `/api/bank-accounts`. Keeps the legacy site's own rules (max 2
  accounts, 10-digit Nigerian account numbers, auto-promoting a
  replacement primary on delete) so nothing changes for users at cutover.
- **Fixed a real bug in the auth bridge** (item 8): `WordPressSessionGuard`
  was caching the first request's resolved user for the lifetime of the
  guard instance, which Laravel's AuthManager can reuse across more than
  one HTTP request — harmless under classic PHP-FPM (one process per
  request), but a genuine risk under a long-running worker like Octane,
  and an actual bug surfaced by the bank-account tests (deleting another
  user's account "worked" because the guard still thought it was the
  first user). Now re-checks which request it last resolved for on every
  call — see the regression test in `WordPressSessionGuardTest`.

- `app/Services/ProvablyFairDrawService.php` + the `raffle_draws` table —
  a real provably-fair draw: a random server seed is committed (only its
  hash shown) before the draw runs, a client seed is derived from the
  actual eligible pool itself so neither side can steer the outcome, and
  everything is recomputable afterward at `GET /api/raffles/{id}/draw`
  (public). This replaces the legacy draw's cosmetic "verification hash"
  (a hash of public fields with a hardcoded salt — audit TD-09) with
  something an outsider can actually check. Winners are written to the
  SAME `wp_raffle_winners` table the legacy draw uses, so nothing else
  (Hall of Fame, the admin winner manager) needs to change or care which
  engine ran the draw. Commit/run are admin-gated via a new, minimal
  `App\Models\Legacy\WpUser::isAdministrator()` helper (reads the same
  WordPress `administrator` capability every legacy admin check already
  uses) and an `admin` middleware alias — a real role/permission system
  is still Phase 1 item 19, this is just enough to gate money/fairness-
  critical actions honestly until then.

- `app/Services/ReferralCommissionService.php` + the `referral_commissions`
  table — pays a referrer's commission on a referee's first deposit,
  same rule as the legacy site (`rk_process_referral_commission()` in
  `api-financials.php`) but with the rate in `config/referrals.php`
  instead of hardcoded, and paid/pending status answered by one real row
  per referee instead of a usermeta flag read under a different key than
  it's written (audit TD-33 — structurally impossible to reintroduce now,
  not just patched). `GET /api/referrals/stats` (authenticated) exposes
  the corrected pending/paid breakdown. **Not yet wired to a live
  trigger** — there's no deposit code path in Laravel yet (item 13);
  `referrerOf()` still reads the legacy `referred_by` usermeta, since
  referral links themselves are still created by the legacy registration
  flow.

- `app/Services/PointsService.php` (+ `PointsLedgerService`, `DailyClaimService`,
  `TaskClaimService`, `SpinService`, `PointRedemptionService`) and the
  `user_points`/`point_ledger_entries`/`completed_tasks` tables — the
  full points/streak/Spin & Win/redemption system, at `/api/rewards/*`.
  Same reward schedule and rules as the legacy `rk_handle_daily_claim()`/
  `rk_handle_task_claim()`/`rk_execute_spin_logic()`/`rk_handle_redeem_points()`
  (`api-gamification.php`), with two real fixes: Spin & Win uses
  `random_int()` (cryptographically strong) instead of `rand()`, and its
  odds are a public endpoint (`GET /api/rewards/spin/odds`) instead of a
  number buried in server code. Redemption credits the NEW `wallets`
  table via `WalletLedgerService`, same pattern as everywhere else money
  moves in this app.

- `app/Notifications/Channels/OneSignalChannel.php` + `TelegramChannel.php`
  — queued notification channels for the same two providers the legacy
  site already uses. Two real fixes over the legacy versions: Laravel's
  HTTP client verifies TLS certificates by default (the legacy OneSignal
  calls disable verification entirely — audit TD-37), and a failed send
  throws instead of being silently discarded, so a queued job retries
  (3 attempts, backoff) and — if it keeps failing — lands in Laravel's
  own `failed_jobs` table (the "dead-letter list" item 17 calls for; no
  bespoke table needed). Wired into two real triggers: `WinnerAnnounced`
  (mail + push, sent to every winner right after `ProvablyFairDrawService::runDraw()`
  commits — this also fixes the legacy winner-push bug, TD-31, by being
  built against the real `wp_raffle_winners` schema from scratch) and
  `DrawCompletedAdminAlert` (Telegram). `TicketPurchaseReceipt` (mail)
  fires after `TicketPurchaseService`'s transaction commits. `WpUser` is
  now `Notifiable` (`routeNotificationForMail()`/`routeNotificationForOneSignal()`
  read the real `user_email` column / `rk_onesignal_id` usermeta).
  `QUEUE_CONNECTION` defaults to `database` (works with zero extra
  setup); switching to `redis` for production is a config change only.

- `app/Services/WithdrawalService.php` + the `withdrawal_requests` table
  — same rules as the legacy `rk_handle_withdrawal()`
  (`api-financials.php`): ₦2,000 minimum, and a ₦1,000 one-time
  "account verification" fee for anyone whose lifetime deposits are
  below ₦1,000 (same "smart balance" deduction logic if the balance
  can't cover both amount and fee). `GET /api/withdrawals/requirements`
  is the real fix here — it lets a client know whether the fee applies
  *before* the user submits, instead of finding out only at the moment
  they try to cash out. "Lifetime deposits" reads `WalletLedgerEntry`
  rows with `reason = 'deposit'` — that's the contract any future
  payment-gateway integration (item 13) needs to follow for this to
  keep working correctly.

- `app/Services/AdminAuditLogService.php` + the `admin_audit_logs` table
  — the audit log the legacy site has never had (audit TD-15). Every
  mutating admin action below writes exactly one row here.
- `app/Services/WinnerManagementService.php` — admin credit/visibility
  actions on `wp_raffle_winners`, with the winner row locked for the
  duration of the check-and-credit (fixes TD-12, the legacy double-
  credit race) and every action logged.
- `WithdrawalService::markPaid()`/`reject()` — admin actions on
  withdrawal requests, also logged. Rejecting refunds exactly what was
  deducted for that specific request; it does not reverse a
  verification-fee credit, which represents the account having been
  verified independent of any one request's outcome.
- Admin API routes live under `/api/admin/*`, gated by the same `admin`
  middleware from item 14: `GET /withdrawals`, `POST
  /withdrawals/{id}/mark-paid`, `POST /withdrawals/{id}/reject`, `POST
  /winners/{id}/credit`, `PATCH /winners/{id}/visibility`, `GET
  /audit-logs`. **This is a JSON API, not a UI** — installing Filament
  (the audit's recommendation) hit repeated proxy timeouts pulling its
  dependency tree in this environment; try again with better network
  conditions, or build a different admin frontend against these same
  endpoints. User management, raffle/prize management, referral/rewards
  views, and financial reconciliation aren't built yet.

- `app/Services/SupportTicketService.php` + the `support_tickets`/
  `support_ticket_messages` tables — a real support ticket system,
  replacing the legacy site's `support.php`, whose "Submit Ticket"
  handler literally has a comment reading `// Simulate submission` and
  never makes a network call at all. This was the single most
  user-harmful gap the whole audit found: a user believes their message
  was sent, and it goes nowhere. User-facing routes at
  `GET/POST /api/support/tickets`, `GET /api/support/tickets/{id}`,
  `POST /api/support/tickets/{id}/reply` — a user can only see or reply
  to their own tickets (checked by comparing the ticket's owner, and a
  mismatch returns 404 rather than 403 so a user can't even tell someone
  else's ticket ID exists). Admin routes at
  `GET /api/admin/support/tickets` (paginated, filterable by
  `?status=`), `GET .../{id}`, `POST .../{id}/reply`,
  `PATCH .../{id}/status` — every admin reply and status change is
  written to the same audit log as item 19's withdrawal/winner actions.
  A new ticket queues `NewSupportTicketAdminAlert`; an admin reply queues
  `SupportTicketReply` to the ticket's owner — both go through the same
  queued-notification system as item 17 (retries, dead-letter list),
  not a synchronous, easy-to-lose send.

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
