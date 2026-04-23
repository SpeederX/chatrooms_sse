# PHP SSE Chat

Minimal real-time chat built on **PHP 8.3 + Server-Sent Events** and
**MySQL 8**. Anonymous sessions with a user-chosen nickname, 3 s
per-user cooldown with escalating penalty, 200-char message cap,
containerized dev stack, and a layered test harness (PHPUnit +
Playwright).

Current version: see `CHANGELOG.md` and `git tag -l`.

## Quickstart

```bash
git clone <repo>
cd sse_chat
cp .env.example .env.local

docker compose --env-file .env.local up -d --build --wait db app
```

App is served at `http://localhost:8080/index.php`.

## Tests

```bash
# PHPUnit — unit + integration
docker compose --env-file .env.local exec -T app vendor/bin/phpunit

# Playwright — E2E
docker compose --env-file .env.local --profile e2e run --rm playwright npx playwright test
```

## Documentation

| Document                        | What it covers                                              |
| ------------------------------- | ----------------------------------------------------------- |
| `docs/architecture.md`          | Stack, methodology (TDD, spec-driven), testing layers, folder layout, branch model, versioning |
| `docs/deployment.md`            | Prod env vars, schema application, reverse-proxy notes, release shape |
| `docs/open-points.md`           | Concise roadmap: active items, upcoming specs, parked and dropped items |
| `docs/specs/spec-NN.md`         | Per-spec design: objectives, out-of-scope, deliverables, acceptance criteria |
| `CHANGELOG.md`                  | Per-release entries (Keep a Changelog format)               |

## Contributing

Work lands on `dev`. Release cycles merge `dev` into `master` and tag
the new version. Specs are written first, signed off, then implemented
via the Prove-It TDD loop. Full detail in `docs/architecture.md`.
