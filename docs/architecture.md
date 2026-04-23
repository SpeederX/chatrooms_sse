# Architecture

How the project is built, tested, versioned, and evolved. This is the
handbook a new contributor should read before touching code.

## Stack

| Layer          | Choice                                                | Why                                                         |
| -------------- | ----------------------------------------------------- | ----------------------------------------------------------- |
| Runtime        | PHP 8.3 on Apache (`php:8.3-apache`)                  | Target host is Aruba shared hosting — classic LAMP-ish      |
| Database       | MySQL 8.0                                             | Matches Aruba's offering; utf8mb4 by default                |
| Dev harness    | Docker Compose with `app`, `db`, `playwright` services | Single command spins up a parity-enough local environment  |
| Config         | `.env.example` → `.env.local` / `.env.prod` + `vlucas/phpdotenv` | Same `getenv()` code path works in Docker and bare PHP |
| Real-time      | Server-Sent Events (`text/event-stream`)              | One-way server→client; trivial on top of HTTP               |
| Unit + integration tests | PHPUnit 11                                  | Mature, no-surprises, covers both pure funcs and live DB    |
| End-to-end tests | Playwright 1.48 (TypeScript)                        | Two browser contexts in one test → cross-client scenarios   |
| CI             | GitHub Actions, docker compose based                  | Same commands locally and in CI — no drift                  |

## Methodology

### Spec-driven development

Every non-trivial change starts as a `docs/specs/spec-NN.md` before any
code is written. The spec pins the objective, the out-of-scope items,
the design decisions, the deliverables, and the acceptance criteria.
The user signs off on the spec; only then does implementation start.

Small fixes / housekeeping don't need a spec — they land as patch
versions with a focused commit message.

### TDD — the Prove-It pattern

For each behavioral change:

1. Write a failing test that captures the expected behavior.
2. Make the minimal change that turns it green.
3. Commit.

For bug fixes the first step is always to reproduce the bug as a
failing test. We "prove it" before we fix it.

### Incremental slices

Large work is broken into vertical slices (schema → service function →
endpoint → UI → test). Each slice is kept end-to-end testable on its
own. A slice ships only when its tests are green; the next slice
starts only after the previous is committed.

## Testing layers

| Layer       | Location                  | Runs against                       | What it covers                                        |
| ----------- | ------------------------- | ---------------------------------- | ----------------------------------------------------- |
| Unit        | `tests/unit/`             | Pure PHP (no DB, no HTTP)          | Validation, config helpers, algorithm math            |
| Integration | `tests/integration/`      | A live MySQL (via Docker Compose)  | `chatService.php` functions, SQL behavior, boundaries |
| E2E         | `e2e/tests/`              | Full stack + real browser contexts | Nickname flow, cooldown UX, SSE delivery, char cap    |

Commands (all wrapped by `docker compose`):

```bash
# Unit + integration (PHPUnit)
docker compose --env-file .env.local exec -T app vendor/bin/phpunit

# E2E (Playwright)
docker compose --env-file .env.local --profile e2e run --rm playwright npx playwright test

# Regenerate the baseline snapshot — explicit developer action only
docker compose --env-file .env.local --profile e2e run --rm playwright npx playwright test --update-snapshots baseline.spec.ts
```

## Folder layout

```
/
├── README.md                            Project overview and quickstart
├── CHANGELOG.md                         Keep-a-Changelog, one entry per tag
├── composer.json / composer.lock        PHP deps (PHPUnit, phpdotenv)
├── phpunit.xml                          Test suite config (unit + integration)
├── docker-compose.yml                   app, db, playwright services
├── .github/workflows/ci.yml             CI pipeline (phpunit + playwright)
├── .gitattributes                       LF normalization
├── .env.example                         Committed template for env vars
├── access.php                           Reads DB_* from env into $servername, $username, …
├── bootstrap.php                        Composer autoload + dotenv load for bare-PHP runs
├── chatService.php                      All DB-touching functions (unit-testable)
├── chatPoll.php                         SSE endpoint
├── sendMessage.php                      POST message endpoint
├── joinChat.php                         POST join endpoint, issues session cookie
├── index.php                            Chat UI (PHP passthrough)
├── main.js                              Client-side: HttpRequest, SSEhandler, dialog flow
├── db/schema.sql                        Schema — idempotent, loaded on first db boot
├── docker/                              Dockerfiles for app and playwright images
├── docs/
│   ├── architecture.md                  This document
│   ├── deployment.md                    Prod env contract and release shape
│   ├── open-points.md                   Roadmap and backlog
│   └── specs/
│       ├── spec-01.md                   Infra baseline
│       ├── spec-02.md                   Correctness baseline
│       ├── spec-03.md                   Anti-flood
│       └── spec-NN.md                   …
├── tests/
│   ├── unit/                            PHPUnit pure-function tests
│   └── integration/                     PHPUnit tests against live MySQL
└── e2e/
    ├── playwright.config.ts             baseURL: http://app (Docker network)
    └── tests/                           Playwright specs + committed snapshots
```

## Branch model (lightweight Gitflow)

| Branch    | Purpose                                                       |
| --------- | ------------------------------------------------------------- |
| `master`  | Stable, tag-only. No direct commits.                          |
| `dev`     | Integration branch. Spec work and housekeeping land here.     |
| `feature/*` | Optional. Used for experimental or risky slices kept off dev. |

Flow for a spec cycle:

1. Start work on `dev` (or a `feature/` branch for risky work).
2. Commit as slices complete, keeping the suite green on each commit.
3. When the spec is done and the suite is green, open a PR from `dev`
   into `master`. After review and merge, tag `master` with the new
   version.

For housekeeping patches (CI tweaks, doc edits, dependency bumps) the
same flow applies, but the version bump is a patch instead of a minor.

## Versioning

Semantic Versioning. While we're pre-1.0, the scheme is `0.MINOR.PATCH`:

| Bump     | When                                                        |
| -------- | ----------------------------------------------------------- |
| `minor`  | A spec ships (new capability, user-visible change)          |
| `patch`  | Housekeeping, docs-only changes, CI, refactors without behavior shift |

Tags are applied on `master` at the commit that becomes the release.

| Tag     | Commit    | Release                                               |
| ------- | --------- | ----------------------------------------------------- |
| v0.1.0  | 6832acd   | Initial infra, Docker harness, PHPUnit + Playwright   |
| v0.2.0  | f4fe42c   | Correctness baseline (SSE streaming + main.js bugs)   |
| v0.3.0  | 16a6390   | Anti-flood: sessions, cooldown, char cap              |
| v0.3.1  | 456aefc   | Prod readiness + housekeeping: CI, Secure cookie, LF  |

Full per-version detail lives in `CHANGELOG.md`.

## Onboarding

```bash
git clone <repo>
cd sse_chat
cp .env.example .env.local
docker compose --env-file .env.local up -d --build --wait db app
docker compose --env-file .env.local exec -T app vendor/bin/phpunit
docker compose --env-file .env.local --profile e2e run --rm playwright npx playwright test
```

App is served at `http://localhost:8080/index.php`.

Start a new spec by creating `docs/specs/spec-NN.md` from the closest
prior spec as a template, get user sign-off, then branch off `dev` (or
commit directly) and follow the Prove-It loop.
