# Set up and run RaffleKings

Everything lives in the `raffle-api/` folder. Run the commands below from inside it.

## What you need

- PHP 8.3 or newer (the live server uses 8.4)
- [Composer](https://getcomposer.org) (installs the PHP parts)
- Node.js and npm (builds the pages people see)
- An empty MySQL database

## First time

1. Copy the example settings file: `cp .env.example .env`
2. Open `.env` and set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` to your MySQL details.
3. Run `composer setup`. This installs the PHP and JavaScript parts, makes the app's secret key, creates the database tables, and builds the front end.
4. Run `composer dev`. The site is now at http://localhost:8000.

Payment, email, push-notification and AI keys in `.env` are empty by default. The site runs without them, but those features (paying with a card, sending email, and so on) will say they are unavailable.

## Run the tests

```bash
php artisan test
```

Two things to know:

- The tests need the front end built first (`npm run build`), because many of them load real pages.
- Some people find the full run stops part-way on a small machine. Running one file at a time works: `php artisan test tests/Feature/ConcurrencyTest.php`.

The tests normally use a quick in-memory database. A second set of checks runs the same tests against a real MySQL database, because money safety relies on database row locks that an in-memory database ignores. That runs automatically on GitHub (see `.github/workflows/raffle-api-tests-mysql.yml`).

## Automatic checks and deploy

| Workflow | When | What it does |
| --- | --- | --- |
| CI | every push and pull request | Looks for passwords committed by mistake, and checks every PHP file for typing errors |
| Raffle API tests (MySQL) | changes under `raffle-api/` | Runs the tests against real MySQL |
| Deploy Raffle API | push to `main` that changes `raffle-api/` | Runs the tests, builds the site and uploads it to the live server |

The other workflows in `.github/workflows/` are manual tools for the live server (checking disk space, shrinking old pictures, copying legacy data). They only run when someone clicks them.

## Common problems

- **"No application encryption key"**: run `php artisan key:generate`.
- **Pages show a blank screen or a missing-file error**: run `npm run build`.
- **Tests fail with "Class not found" after pulling new code**: run `composer install`.
