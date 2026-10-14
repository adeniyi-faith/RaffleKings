# How RaffleKings is put together

This page explains the parts in plain English. For a day-to-day list of what was built and when, see `raffle-api/OVERHAUL_CHECKLIST.md`.

## The big picture

```
 Player's phone or computer
          |
          v
 +--------------------------------------------+
 |  The website (Laravel, in raffle-api/)      |
 |   - Pages (React, sent by Laravel/Inertia)  |
 |   - Player features: wallet, tickets, draws |
 |   - Admin panel (Filament) for staff        |
 |   - Scheduled jobs and a job queue          |
 +--------+-----------------+------------------+
          |                 |
          v                 v
   MySQL database     Outside services
   (all money,        Paystack, Flutterwave (payments)
    tickets, users)   OneSignal (push), email, Telegram
                      Sentry (errors), Gemini (AI helpers)
```

## The parts

**The website.** One Laravel application does everything: it sends the pages, answers the buttons people tap, runs the admin panel and runs background jobs. Pages are written in React. Laravel hands each page its data directly (this is what [Inertia](https://inertiajs.com) does), so there is no separate server for the pages.

**The database.** MySQL holds everything that matters: accounts, wallets, tickets, draws, winners, withdrawals and the record of every balance change. Money-moving code locks the rows it is changing while it works, so two taps at the same moment cannot spend the same money twice. The tests check this with real parallel requests on MySQL.

**Money.** Top-ups go through Paystack or Flutterwave, or by bank transfer that staff approve. A payment is only counted once the provider confirms it. Wallet changes are written as ledger lines, and scheduled checks compare the ledger with the wallet totals and with the payment providers' own records.

**Draws.** Winners are picked by a "provably fair" method: the system commits to a secret seed before looking at who entered, and reveals it afterwards, so anyone can check the result was not chosen to suit someone.

**The admin panel.** Staff sign in to a Filament panel to run raffles, review deposits and withdrawals, answer support tickets, send messages and see reports. Staff actions are written to an audit log, and sensitive actions (such as changing a balance) need a specific staff role.

**Background work.** A scheduler runs jobs on a timetable (nightly member groupings, reminders, backups, checks). A queue sends emails and push notifications without making players wait.

## Why does the database still say "wp_"?

RaffleKings first ran on WordPress with custom PHP pages. In 2026 it was rebuilt as the Laravel website above, which took over the same web address. To keep every player's account, balance and ticket, the new site was built to read and write the **same MySQL database**, and some of its tables keep their old `wp_` names (for example the users table). The new site can also read the sign-in cookie that WordPress used to issue, so people stayed signed in during the move.

The old WordPress and PHP code is no longer part of this repository and is no longer run. It is kept in the git history and in a backup. The database tables with the `wp_` prefix are still in use and must not be deleted.

## Where to look in the code

| You want to find | Look in |
| --- | --- |
| A page players see | `raffle-api/resources/js/Pages/` |
| What a button does | `raffle-api/app/Http/Controllers/` and `raffle-api/routes/` |
| Money and ticket rules | `raffle-api/app/Services/` |
| The admin panel | `raffle-api/app/Filament/` |
| Database tables | `raffle-api/database/migrations/` |
| Scheduled jobs | `raffle-api/routes/console.php` |
| Tests | `raffle-api/tests/` |
