# Contributing

This is a showcase repository with one owner. It is not open to outside changes, but these are the habits the project follows, and they help anyone working on it.

- **Work on a branch, open a pull request.** Do not commit straight to `main`. Pushes to `main` that change `raffle-api/` deploy the live site automatically.
- **Run the tests first.** See [docs/setup.md](docs/setup.md). Money-related changes also need a test that fires several requests at once.
- **Never commit secrets.** Keys and passwords go in `.env` (which git ignores) or in GitHub's secret store.
- **Explain changes in plain English.** Say what will behave differently for a player or for staff.
- **Do not delete `wp_` database tables.** The live site still uses them. See [docs/architecture.md](docs/architecture.md).
