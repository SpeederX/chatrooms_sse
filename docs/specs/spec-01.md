# SPEC: Infrastructure Baseline

## Objective

Stand up a containerized dev environment plus two test harnesses (PHPUnit, Playwright) so anyone can clone the repo and run the chat end-to-end with a single command. Capture the current UI as a Playwright visual baseline before any bug fixes land.

**This spec delivers infrastructure only — no code fixes from the triage list.**

## Out of scope

- Any fix to `chatPoll.php` streaming, `main.js` bugs, `index.html` assets, SSE framing, auth, CSRF, XSS.
- CI automation on Aruba (separate track the user is building).
- Linting / formatters.
- Moving existing files into subdirectories.

## Environment targets

Two named configurations, switchable via `docker compose --env-file <file>`:

| Env file | DB target | Use case |
| --- | --- | --- |
| `.env.local` | Local compose MySQL service | Default dev loop — fast, isolated |
| `.env.prod` | Remote Aruba MySQL | Parity testing against prod data; also what gets shipped to Aruba |

Both files are **gitignored**. `.env.example` is committed as the template with safe local-dev defaults.

**Required variables** (identical keys across profiles):

```
DB_HOST=
DB_NAME=
DB_USER=
DB_PASS=
```

### Dual env-loading path

- **In Docker**: `docker compose --env-file .env.local up` substitutes `${DB_*}` into `docker-compose.yml`, which passes them into the `app` service via an `environment:` block. PHP reads via `getenv('DB_HOST')`.
- **On Aruba bare PHP**: `vlucas/phpdotenv` (added via Composer) reads a `.env` file in the project root at request time, populating the same env vars. PHP code path is identical — `getenv('DB_HOST')` — regardless of which mechanism populated it.

Deploy convention for Aruba: ship `.env.prod` renamed to `.env` alongside the PHP files (gitignored, copied manually).

## Commands

```bash
# Start full stack with local MySQL (default)
docker compose --env-file .env.local up --build

# Start pointed at remote Aruba MySQL
docker compose --env-file .env.prod up --build

# Run PHPUnit inside the app container
docker compose run --rm app vendor/bin/phpunit

# Run Playwright tests (dedicated container)
docker compose run --rm playwright npx playwright test

# Regenerate Playwright baseline screenshots (explicit developer action)
docker compose run --rm playwright npx playwright test --update-snapshots

# Shut down
docker compose down
```

## Deliverables

### Config & tooling
1. `.env.example` — committed, documents required vars with safe local-dev defaults
2. `.gitignore` — excludes `.env.local`, `.env.prod`, `.env`, `vendor/`, `node_modules/`, `e2e/test-results/`, `e2e/playwright-report/`
3. `composer.json` + `composer.lock` — `phpunit/phpunit` + `vlucas/phpdotenv`
4. `phpunit.xml` — minimal PHPUnit config

### Containers
5. `docker-compose.yml` — three services: `app` (php-apache), `db` (mysql), `playwright`
6. `docker/app/Dockerfile` — `php:8.3-apache` + `pdo_mysql` + Composer + `composer install` during build
7. `docker/playwright/Dockerfile` — `mcr.microsoft.com/playwright:<pinned-version>` + project deps

### Database
8. `db/schema.sql` — `messages` table, auto-loaded by the mysql image via `/docker-entrypoint-initdb.d/`

### PHP code — minimal extraction only
9. `bootstrap.php` — new file: loads Composer autoload + `Dotenv::createImmutable(__DIR__)->safeLoad()` so env vars are populated in non-Docker paths without overriding already-set vars
10. `access.php` — rewritten to `require_once __DIR__ . '/bootstrap.php';` and replace hardcoded literals with `getenv('DB_HOST')` etc.
11. `sendMessage.php` — remove inline hardcoded credentials; `require_once __DIR__ . '/access.php';` instead (which brings in the bootstrap transitively)

### Tests
12. `tests/unit/SmokeTest.php` — single `assertTrue(true)` assertion. Proves the PHPUnit harness runs in the container.
13. `e2e/package.json` + `e2e/playwright.config.ts` — Playwright TS config, targets `http://app` inside the compose network
14. `e2e/tests/baseline.spec.ts` — navigates to `http://app/index.php`, waits for network idle, captures full-page screenshot
15. `e2e/tests/baseline.spec.ts-snapshots/*.png` — the committed baseline image artifact

## Testing strategy

| Harness | Scope in this spec | Notes |
| --- | --- | --- |
| **PHPUnit** | One smoke test (`assertTrue(true)`) | Proves the container can run phpunit. Real unit tests arrive with the bug-fix specs. |
| **Playwright** | One baseline test on `index.php` | Captures the *current* rendered UI as a committed snapshot. Future fixes diff against this. |

No integration tests in this spec. No test of `chatPoll.php` SSE streaming yet (that's the first thing to fix in the next spec).

## Code style

- PHP: `declare(strict_types=1);` at the top of any **new** file (`bootstrap.php`). Existing files stay untouched beyond the env extraction.
- Env access: always via `getenv('DB_*')` — never hardcoded.
- PHP target version: 8.3.
- Test naming: `<ClassUnderTest>Test.php` for PHPUnit, `<feature>.spec.ts` for Playwright.

## Boundaries

### Always
- Keep real `.env.*` files out of git.
- PHP reads DB config via `getenv()` only.
- Regenerating baseline screenshots is an explicit developer action (`--update-snapshots`), never automatic.

### Ask first
- Adding dependencies beyond `phpunit/phpunit`, `vlucas/phpdotenv` (PHP), `@playwright/test` (Node).
- Moving existing files into subdirectories (spec keeps the flat layout).
- Touching application logic beyond the credential extraction listed above.

### Never
- Commit `.env.local`, `.env.prod`, or `.env` with real values.
- Fix any bug from the triage list in this spec — they belong to later specs.
- Modify `index.html` (kept as-is per user decision).

## Open questions (flagged, not blocking)

1. **Which Aruba DB host is the real one?** The original `access.php` and `sendMessage.php` held different host values. User to pin the correct one when populating the local-only `.env.prod`.
2. **Composer on Aruba shared hosting**: not all Aruba tiers allow `composer install`. If the deploy host can't run it, we'll commit `vendor/` (or ship a built bundle) in the future deploy spec. Not blocking now.

## Acceptance criteria

- `docker compose --env-file .env.local up --build` starts the stack; browser loads `http://localhost:<mapped-port>/index.php` without PHP errors.
- `docker compose run --rm app vendor/bin/phpunit` exits 0, reports `OK (1 test, 1 assertion)`.
- `docker compose run --rm playwright npx playwright test` passes on a clean repo (baseline snapshot generated + committed on first run).
- No hardcoded DB credentials remain in any tracked PHP file (`access.php`, `sendMessage.php`, or elsewhere).
- `.env.example` documents every required variable; real env files are gitignored and absent from `git status`.
- Switching `--env-file` between `.env.local` and `.env.prod` changes the DB target **without any code changes**.
- `index.html`, `index.php`, `main.js`, `chatPoll.php` remain behaviorally unchanged (no fixes smuggled in).
