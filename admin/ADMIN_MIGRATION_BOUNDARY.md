# ADMIN MIGRATION BOUNDARY

This document outlines the foundation of the Phase 8 custom PHP admin backend for RaffleKings. This system is designed as a standalone entry point (`/admin/index.php`) that securely bootstraps WordPress (`WP_USE_THEMES=false`) and restricts access to users with the `administrator` capability.

## Screens Created
- **Dashboard:** Overview stats and placeholder chart.
- **Deposits:** UI for reviewing pending manual and automated deposits.
- **Withdrawals:** UI for managing payout requests.
- **Placeholders for:** Users, Wallets & Ledger, Raffles, Tickets/Entries, Winners, Referrals, Rewards, Notifications, Site Notices, Tutorials, Support/Disputes, System Logs, Settings, Admin Audit Logs.

## Missing Actions & Codex Phase Dependencies
The backend domain logic heavily relies on the core platform logic (which is currently handled by Codex in Phase 7 operations). Therefore, actual data mutation actions have not been duplicated here.

### Pending Integrations:
1.  **Deposits & Withdrawals:** Must be wired to existing WP functions (e.g., `rk_send_deposit_receipt`, `rk_send_withdrawal_confirmation`) that reside in `rk-core/api-financials.php`.
2.  **Raffles & Tickets:** Needs integration with custom tables and native CPT meta created by Codex (e.g. `raffle_meta`).
3.  **Gamification (Points, Winners):** Relies on Codex endpoints and cron functions inside `rk-core/api-gamification.php` and `rk-core/cron-system.php`.
4.  **Audit Logs:** All admin actions (approving deposits, manually drawing winners, etc.) *must* log to an admin audit table or log file. Currently pending core support.
5.  **Site Notices:** Must integrate with `raffle_site_notices` table.

## Data Tables
This admin panel is designed to interface with the custom WordPress tables defined in `rk-core/database.php`, including but not limited to:
- `wp_raffle_tickets`
- `wp_raffle_transactions`
- `wp_raffle_wallets`
- `wp_raffle_live_comments`
- `wp_raffle_notification_templates`
- `wp_raffle_error_logs`
- `wp_raffle_point_logs`
- `wp_raffle_site_notices`

## Security Requirements
- **Authentication:** Ensured via `is_user_logged_in()` and `current_user_can('administrator')` check at the router level.
- **CSRF:** All forms and asynchronous API calls triggered from the admin UI *must* use WordPress nonces before processing state changes.
- **Confirmation Modals:** Deployed globally using a unified JS component in `footer.php` to prevent accidental destructive actions.