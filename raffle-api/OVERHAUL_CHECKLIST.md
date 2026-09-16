# RaffleKings Overhaul Checklist

This is the build/migrate plan that follows from `../` root's audit artifact
("RaffleKings — Product, Feature, UX & Architecture Audit"). Each item below
is a **full, self-contained pass** — the kind of chunk one person or one
focused session can actually finish and ship — not a tiny incremental diff.
Doing them in order matters less than doing each one completely before
moving on; dependencies are noted where they're real.

Everything here builds on what already exists in `raffle-api/`: the
`Wallet`/`BankAccount` models, the guarded legacy-table migrations, and the
tested `TicketPurchaseService` — see `LEGACY_MIGRATION.md` for what's
already there and the ground rules for touching the shared database.

---

## Phase 0 — Stop the bleeding (on the *live* PHP app, no Laravel needed)

- [ ] **1. Ship the four critical live-site fixes as one reviewed patch set**: rate-limit the password-reset OTP, make `logout.php` actually end the server session, wrap wallet-debit + ticket-insert in a transaction with rollback on collision, and add CSRF protection to every money-moving action in `ajax-router.php`.
- [ ] **2. Verify and close the bank-transfer ticket-entry gap (TD-05)** against the live database, and fix the admin deposit-approval queue filter so it actually matches real deposit records (TD-28).
- [ ] **3. Build a real, working customer support ticketing system** to replace the fake one on `support.php` — this doesn't need to wait for the rewrite and is the single most user-harmful gap in the product today.
- [ ] **4. Stand up CI + staging + rollback** for the live PHP app: tests (even a first small set) must pass before a deploy, deploys go through a real pipeline instead of a raw `git pull` to production, and a bad deploy can be reverted in minutes.
- [ ] **5. Add an admin action audit log** (new table + a logging call wired into every mutating admin action in `admin-panel.php`) so every approval, ban, and balance edit is traceable to a person and a time.
- [ ] **6. Repository cleanup pass**: delete `currentapi.php`, `oldwithdraw`, `.htaccess.zip`, `.htaccess_bak`, the empty `promo-popup.php`, and the orphaned pages (`category-cash.php`, `category-gadgets.php`, `category-scholarships.php`, `marketing-ui.php`, `view-story.php`) or explicitly wire them up — don't leave dead, confusing code live.
- [ ] **7. Turn off the two worst trust-damaging dark patterns immediately**: make the spin-to-win popup's odds genuinely match what it displays, and remove every fabricated "X people viewing" / fake activity counter across `index.php`, `raffles.php`, and `checkout.php`.

## Phase 1 — Laravel backend foundation (`raffle-api/`)

- [x] **8. Build the WordPress-session auth bridge**: a custom Laravel guard that reads the existing `wp_users`/session cookie, so a user's login keeps working identically while both systems run side by side. This unblocks every other Laravel route. *(Done — `app/Auth/WordPressAuthCookieValidator.php`, `app/Auth/WordPressSessionGuard.php`, the `wordpress` guard, and a working `/api/me` proof-of-concept route, all covered by tests.)*
- [x] **9. Wire `TicketPurchaseService` to real controllers and routes**, plus build the raffle-listing and raffle-detail read endpoints (replacing the WordPress CPT read path for the frontend). This is the first real, live Laravel feature. *(Done — `GET /api/raffles`, `GET /api/raffles/{id}` (public, ticket counts always derived from real entries, fixing TD-13), and `POST /api/tickets/purchase` (auth required). Not yet pointed at by the live frontend — see the controller's docblock and Phase 3.)*
- [x] **10. Model raffles and prize structures natively in Laravel**, replacing the WordPress custom-post-type + external ACF field dependency the current draw logic relies on. Include a migration that reads today's raffle/prize data out of WordPress once, correctly, for a one-time cutover. *(Done — `raffles`/`raffle_prize_tiers` tables, `App\Models\Raffle`/`RafflePrizeTier`, and `php artisan legacy:import-raffles [--dry-run]`, which also recovers the undocumented ACF "prize_structure" repeater fields the draw engine silently depends on. Not yet the live read/draw path — see item 12.)*
- [x] **11. Build the real ledger + wallet service**: an append-only debit/credit ledger table with the `wallets` table as a fast-read balance cache derived from it, plus a migration/backfill that reconciles every existing `usermeta` balance into it exactly once. *(Done — `wallet_ledger_entries` table, `App\Models\WalletLedgerEntry`, `App\Services\WalletLedgerService` (`recordDebit`/`recordCredit`/`reconstructBalance`), and `php artisan legacy:reconcile-wallet-ledger [--dry-run]` to give every backfilled wallet an honest opening-balance entry. `TicketPurchaseService` now writes a ledger entry for every debit inside the same atomic transaction as the balance mutation — including the TD-06 rollback path, which now rolls the ledger entry back too.)*
- [x] **12. Build bank account management** (add/remove/set-primary) fully on the new `bank_accounts` table, replacing the serialized-array `usermeta` version. *(Done — `App\Services\BankAccountService` + `BankAccountController`, at `GET/POST /api/bank-accounts`, `PATCH /api/bank-accounts/{id}/primary`, `DELETE /api/bank-accounts/{id}`. Keeps the legacy site's own rules — max 2 accounts, 10-digit Nigerian account numbers, auto-promoting a replacement primary on delete — so behaviour won't change for users at cutover. Also fixed a real bug this work turned up: the WordPress-session guard from item 8 was caching the first resolved user across separate requests sharing one guard instance — safe under classic PHP-FPM, but a real risk under a long-running worker like Octane, and a real bug in tests. Fixed and covered by a regression test.)*
- [ ] **13. Integrate a real Nigerian payment gateway** (Paystack or Flutterwave) for deposits, with webhook-confirmed settlement feeding the same `TicketPurchaseService`/ledger path — keep the Gemini AI screenshot check only as a manual-review fallback for edge cases the gateway can't handle.
- [ ] **14. Build the provably-fair draw engine**: commit a hashed random seed before ticket sales close, reveal it after the draw, and expose a page/endpoint where anyone can independently recompute the result — this replaces the current cosmetic "verification hash" and the non-cryptographic shuffle.
- [ ] **15. Build the referral & commission engine** on the new ledger, fixing the paid/pending status bug found in the audit, and make the commission rate a configurable value rather than hardcoded.
- [ ] **16. Build the points, daily-streak, and Spin & Win engine** with a cryptographically strong random source and publicly documented odds (turning the existing hidden house edge into a disclosed one).
- [ ] **17. Build the queued notification system**: every email/push/SMS/Telegram send goes through a Redis-backed queue with retries and a dead-letter list for anything that keeps failing, replacing today's silent, unretried, synchronous sends.
- [ ] **18. Build the withdrawal engine** on the new ledger, including a clear, *upfront-disclosed* version of any account-verification requirement — not one a user discovers only when trying to cash out.
- [ ] **19. Build the admin console** (recommend Filament) covering, as one complete pass: deposits & withdrawals queue, user management, raffle & prize management, winners & payouts, referrals & rewards, financial reconciliation, system health, and the audit log from item 5 — replacing both the WordPress admin panel and the half-built `/admin` rebuild with one real system.
- [ ] **20. Build the support/disputes console** for admins, tied to the ticketing system from item 3, so support requests have a real internal queue and response workflow.

## Phase 2 — Frontend rebuild

- [ ] **21. Set up a real frontend build pipeline**: a bundler, pinned dependency versions, no more Tailwind Play CDN or `@latest` icon packages — the site's appearance and behavior can no longer change without a deploy.
- [ ] **22. Build a shared component library** (buttons, modals, cards, form inputs, price displays) to replace the copy-pasted markup and triple-duplicated pricing math currently spread across `checkout.php`, `raffle-details.php`, and `register-special.php`.
- [ ] **23. Rebuild registration, login, and password reset** against the new Laravel API, with the existing (currently disabled) bot-protection widget actually wired on and working.
- [ ] **24. Rebuild raffle discovery**: browsing, search, and real filters (price, prize type, closing time), with a real, derived, always-accurate ticket-sold count — no manually-toggled sold-out flag.
- [ ] **25. Rebuild the raffle details → number selection → checkout flow** as one coherent, single-source-of-truth experience against the new settlement API, removing the dead `localStorage`-token login check that currently misroutes logged-in users.
- [ ] **26. Rebuild the account section**: My Tickets, Transactions, Wallet, Withdraw, Bank Accounts — all reading from the new ledger/wallet/bank-account APIs.
- [ ] **27. Rebuild Winners, Hall of Fame, and Live Draw**, including a real, user-facing "verify this draw yourself" view built on the provably-fair engine from item 14.
- [ ] **28. Rebuild the Rewards hub**: daily streak, tasks, Spin & Win, and Referrals as one honest, self-consistent page — fixing the current bug where two already-built features are falsely shown as "Coming Soon."
- [ ] **29. Build the real support/help center UI** against the ticketing backend from item 3/20, plus the existing tutorials/help content migrated over.
- [ ] **30. Add real-time live ticket counters and draw countdowns** (WebSocket-based), replacing every fabricated "people viewing" / fake urgency element identified in the audit with an honest version that does the same job.
- [ ] **31. Rebuild the PWA layer** (install prompts, service worker, manifest, push permission flow) cleanly against the new stack, keeping what already works well (the iOS install prompt logic) and removing the "push-permission trap" pattern found in the daily-claim flow.

## Phase 3 — Cutover, hardening, and decommissioning

- [ ] **32. Run a shadow-traffic period**: real ticket-purchase requests are also run (without effect) through the new Laravel settlement path and compared against the old PHP path's output, catching any mismatch before it can affect a real user.
- [ ] **33. Cut over ticket purchase, wallet, and payments to Laravel first** (highest financial risk, and the module already furthest along), with a fast per-module rollback path to the old PHP code if anything misbehaves.
- [ ] **34. Cut over authentication to Laravel-issued tokens (Sanctum)**, retiring the WordPress-session dependency built in item 8.
- [ ] **35. Cut over the draw engine, referrals, rewards, and notifications**, in that order, each validated against its own test suite before going live.
- [ ] **36. Cut over admin operations fully** to the new console from item 19, and formally retire both the WordPress admin panel and the `/admin` placeholder rebuild.
- [ ] **37. Add production monitoring**: error tracking (e.g. Sentry), structured request-traced logging, and a queue-health dashboard (e.g. Horizon) — so incidents are caught automatically instead of discovered from user complaints.
- [ ] **38. Add responsible-participation controls** (self-exclusion, spending limits, clear odds disclosure) before the new system becomes the primary way users interact with the product.
- [ ] **39. Run a full security pass** on the finished Laravel app (CSRF, auth, rate limiting, admin permissions, webhook signature verification) before final public cutover.
- [ ] **40. Decommission the legacy PHP/WordPress codebase** once the Laravel system has run correctly, unmodified, through at least one full billing/reporting cycle — remove the old `wp/` install, the root PHP pages, and the `/admin` folder from the live server and the repository.

---

*Source: the full audit artifact linked from the project ("RaffleKings — Product, Feature, UX & Architecture Audit"). Each numbered item here maps to specific findings and technical-debt IDs (TD-01…TD-45) documented there — consult it for the full reasoning, evidence, and file references behind any item on this list.*
