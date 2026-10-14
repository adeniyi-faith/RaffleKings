# Project history: moving off WordPress

RaffleKings began in May 2026 as a WordPress site with a set of custom PHP pages. Over the following months it was rebuilt as the Laravel website in `raffle-api/`, one piece at a time, while the old site kept running. The step-by-step plan and what was done at each step is in `raffle-api/OVERHAUL_CHECKLIST.md`, and the technical notes on sharing the database are in `raffle-api/LEGACY_MIGRATION.md`.

In October 2026 the old WordPress and PHP files were removed from the repository because the new site had replaced them at rafflekings.com.ng and nothing used them any more.

## Getting the old code back

Nothing was lost:

- It is still in git history. For example, `git show 60803956b4b37a9962505fe65e5871a877617c79:index.php` shows the old home page, and `git checkout 60803956b4b37a9962505fe65e5871a877617c79 -- wp` restores the old WordPress plugin folder. (If the repository's history is later rewritten, the commit id changes; use the `archive/original-main` branch instead.)
- A full backup of the repository from 14 October 2026 was saved separately by the project owner.
