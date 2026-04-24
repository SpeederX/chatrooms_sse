# SPEC-05: Admin Panel — First Auth, Runtime Config, and Stats Surfacing

## Objective

Ship the first admin surface of the project. Three capabilities, wrapped in
the first real authentication the app has ever had:

1. **Auth** — single admin, password-hash env var, PHP-session gated pages,
   CSRF-protected POSTs.
2. **Stats surfacing** — reads SPEC-04 stats (`get_stats`,
   `active_users_now`) and presents them in a page, alongside a derived
   `avg_message_length`.
3. **Runtime config** — promotes seven constants from `chatService.php`
   into a `config` table, editable in the panel, read at request time.

Plus one operational action carried over from SPEC-04:

4. **History cleanup** — exposes `cleanup_message_history` as a button
   with a confirmation step. Counters stay monotonic (SPEC-04 guarantee).

## Out of scope

- Admin user table, admin registration, or role model — there is one admin.
- Rate-limit on failed login or IP banning — deferred to **C8** alongside
  kick/ban, which reuses this spec's auth.
- Audit log of config changes (deferred).
- Multi-factor auth.
- Moderation (kick / ban by session id) — **C8**.
- Time-series snapshots of stats.
- Auto-refresh on the stats page — manual reload in v1.

## Design decisions (pinned)

### Auth

- **Password**: `ADMIN_PASSWORD_HASH` env var, bcrypt hash produced by
  `password_hash($pw, PASSWORD_BCRYPT)`. No plaintext in repo, logs, or DB.
  `.env.example` carries a placeholder with a generation hint in a comment.
- **Session**: PHP native (`$_SESSION`), entirely independent of the
  chat's `sessions` table. On successful login,
  `session_regenerate_id(true)` and set:
  - `$_SESSION['admin_authenticated'] = true`
  - `$_SESSION['admin_last_activity'] = time()`
  - `$_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32))`
- **Cookie**: `HttpOnly`, `SameSite=Strict`, `Secure` when `APP_ENV=prod`
  (reuse `session_cookie_secure()`).
- **Idle TTL**: 1h. Every admin request refreshes `admin_last_activity`;
  if the delta exceeds 1h, the session is destroyed and the user is
  bounced to login.
- **CSRF**: single token per session. Every POST form carries it in a
  hidden field; server verifies with `hash_equals`. Token survives the
  whole session (no rotation per request — simpler, adequate for a
  single-admin surface).
- **Generic login errors**: wrong password returns the same error as
  missing password. No user-enumeration vector.

### Runtime config

- **Storage**: `config` table with `key`, `value`, `updated_at`. Schema
  is idempotent; defaults are seeded via `INSERT IGNORE`.
- **Seven keys** (current → default):

  | Key | Default | Bounds |
  | --- | --- | --- |
  | `message_max_length` | 200 | int 10..2000 |
  | `cooldown_base_seconds` | 3 | int 0..60 |
  | `history_size` | 50 | int 0..500 |
  | `nickname_min_length` | 2 | int 1..30 |
  | `nickname_max_length` | 20 | int 1..30 |
  | `session_ttl_minutes` | 15 | int 1..1440 |
  | `active_user_window_minutes` | 12 | int 1..1440 |

- **Character class** for nicknames (`[a-zA-Z0-9_-]`) stays hard-coded —
  live regex editing is a foot-gun.
- **Read API**: `get_config(PDO, string, int|string $default): int|string`.
  Per-request memoisation via a static cache; `$default` is used only
  when the row is missing (resilience against an incomplete schema).
- **Write API**: `set_config(PDO, string, string): void`. Validates
  against per-key bounds and cross-key invariants before applying.
- **Cross-key invariants** (enforced in the write path):
  - `nickname_min_length ≤ nickname_max_length`
  - `active_user_window_minutes ≤ session_ttl_minutes` — the
    "fresh vs about-to-expire" rationale pinned in SPEC-04.

### Client-side propagation

Config-driven UI attributes get rendered server-side in `index.php` on
every page load:

- `#nickname_input minlength={nickname_min_length} maxlength={nickname_max_length}`
- `#text_value maxlength={message_max_length}`
- `#char_counter` `/N` label reflects `message_max_length`

No JS-side config fetch. On the next page load after an admin edit the
client sees the new values.

## Schema changes

Append to `db/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS config (
    `key` VARCHAR(64) PRIMARY KEY,
    value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO config (`key`, value) VALUES
    ('message_max_length',          '200'),
    ('cooldown_base_seconds',       '3'),
    ('history_size',                '50'),
    ('nickname_min_length',         '2'),
    ('nickname_max_length',         '20'),
    ('session_ttl_minutes',         '15'),
    ('active_user_window_minutes',  '12');
```

`INSERT IGNORE` preserves admin-edited values on re-apply.

## New functions — `chatService.php`

```php
function get_config(PDO $conn, string $key, int|string $default): int|string;
function set_config(PDO $conn, string $key, string $value): void;
function get_all_config(PDO $conn): array;
function config_bounds(): array; // pure, no PDO
function verify_admin_password(string $candidate): bool;
function admin_is_authenticated(): bool;
function require_admin_auth(): void;   // 302 to /adminLogin.php if unauth
function admin_csrf_token(): string;
function verify_admin_csrf(string $submitted): bool;
function start_admin_session(): void;  // session_start + cookie params
```

## Modified functions — `chatService.php`

- **`validate_nickname(string $nickname, int $minLen, int $maxLen): ?string`**
  — signature widens. Implementation splits into a length check
  (`mb_strlen`) plus a constant character-class check
  (`/^[a-zA-Z0-9_-]+$/`). No dynamic regex interpolation.

## Modified endpoints

- **`joinChat.php`** — reads `nickname_min_length` / `nickname_max_length`
  and passes them to `validate_nickname`.
- **`sendMessage.php`** — reads `message_max_length` for the length cap
  and `cooldown_base_seconds` for the cooldown formula.
- **`chatPoll.php`** — reads `history_size`, `session_ttl_minutes`, and
  `active_user_window_minutes` wherever those constants were referenced.
- **`index.php`** — reads config, renders `minlength`, `maxlength`, and
  the char-counter max label from it.

## New endpoints

| File | Method | Purpose |
| --- | --- | --- |
| `adminLogin.php` | GET | Render login form. If already auth'd, 302 to `adminPanel.php`. |
| `authenticateAdmin.php` | POST | Verify password, regenerate session id, flag session as admin, redirect to panel. |
| `adminPanel.php` | GET | Auth-gated. Renders stats, config form, history-cleanup button, logout. |
| `adminUpdateConfig.php` | POST | Auth-gated + CSRF. Validates each field against bounds; rejects the whole form if any is out of range. |
| `adminCleanupHistory.php` | POST | Auth-gated + CSRF. Calls `cleanup_message_history`, redirects to panel with success flag. |
| `adminLogout.php` | POST | Destroys PHP session, redirects to `adminLogin.php`. |

## New assets

- `assets/adminStyles.css` — minimal styling, inherits the site palette.

## Environment

- `.env.example`: add `ADMIN_PASSWORD_HASH=` with a comment showing how
  to generate it:

  ```
  # ADMIN_PASSWORD_HASH=
  # generate with: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
  ```

- `docker-compose.yml`: pass `ADMIN_PASSWORD_HASH` through to the `app`
  service's environment (same pattern as `APP_ENV`).

## Testing strategy

### Unit — `tests/unit/`

**`AdminAuthTest.php`** — all auth helpers are in-process (session + env + crypto), so the entire surface is covered here as unit tests. Endpoint-level HTTP behaviour is covered by `e2e/tests/adminPanel.spec.ts`.

| Test | Asserts |
| --- | --- |
| `testVerifyAdminPasswordAcceptsCorrect` | Given a known hash, `verify_admin_password(<correct>)` returns `true` |
| `testVerifyAdminPasswordRejectsWrong` | Wrong candidate returns `false` |
| `testVerifyAdminPasswordReturnsFalseWhenHashMissing` | Unset env returns `false`, no warnings emitted |
| `testVerifyAdminPasswordReturnsFalseWhenHashEmpty` | Explicit empty env returns `false` |
| `testAuthenticateAdminRequestSetsSessionOnSuccess` | Valid password → `$_SESSION['admin_authenticated']` + fresh 64-hex CSRF token |
| `testAuthenticateAdminRequestLeavesSessionOnFailure` | Wrong password → session untouched, no auth flag, no CSRF token |
| `testAdminIsAuthenticatedTrueWhenFresh` / `RefreshesLastActivity` | Within-idle-TTL session counts as authenticated and refreshes the timestamp |
| `testAdminIsAuthenticatedFalseWhenStale` / `WhenEmpty` | Over-TTL or empty session → unauthenticated |
| `testAdminCsrfTokenGeneratesIfMissing` / `ReturnsExistingIfSet` | Lazy creation vs idempotent retrieval |
| `testVerifyAdminCsrfAcceptsMatch` / `RejectsMismatch` / `RejectsWhenSessionMissingToken` | `hash_equals` over the session token; missing session rejects |
| `testAdminLogoutClearsSession` | `admin_logout()` empties `$_SESSION` |

**`ConfigBoundsTest.php`**

| Test | Asserts |
| --- | --- |
| `testBoundsContainAllSeededKeys` | `config_bounds()` has a row per seeded key |
| `testMessageMaxLengthAcceptedInRange` | Mid-range value accepted |
| `testMessageMaxLengthRejectedTooLow` / `TooHigh` | Out-of-bounds rejected |
| (one representative accepted + one rejected per key) | |
| `testCrossKeyNicknameMinMaxInvariant` | `min > max` combo rejected |
| `testCrossKeyActiveWindowVsTtlInvariant` | `window > ttl` combo rejected |

**`ValidationTest.php`** (modified)

- Existing 5 tests → pass `2, 20` explicitly to `validate_nickname`.
- Add `testValidateNicknameHonorsDynamicBounds` → with `(5, 8)`, short
  and long inputs rejected, in-range accepted.

### Integration — `tests/integration/`

**`RuntimeConfigTest.php`** — named to avoid a class-name collision with the
pre-existing `tests/unit/ConfigTest.php` (which covers `session_cookie_secure`).

| Test | Asserts |
| --- | --- |
| `testDefaultsAreSeeded` | `get_all_config` returns all 7 keys with spec defaults |
| `testGetConfigReturnsIntWhenDefaultIsInt` / `StringWhenDefaultIsString` | Return type tracks the default's type |
| `testGetConfigFallsBackToDefaultOnMissingRow` | Missing row → `get_config` returns passed default |
| `testSetConfigUpdatesRow` | Happy-path write reflected on the next read |
| `testSetConfigRejectsOutOfBoundsHigh` / `Low` / `NonInteger` / `UnknownKey` | Each rejection mode throws `InvalidArgumentException` |
| `testSetConfigEnforcesNicknameInvariant` / `ActiveWindowInvariant` | Single-key writes that would break a cross-key invariant are rejected |
| `testSetAllConfigRollsBackOnBoundsViolation` / `InvariantViolation` | Atomicity: a bad value in the batch aborts the whole write |
| `testSetAllConfigAppliesCoherentLoweringAtomically` | Batch of two linked keys succeeds when the final state is valid, even if a serial application would transiently violate the invariant |

### E2E — `e2e/tests/adminPanel.spec.ts`

Scenarios:

1. **Login fail** — wrong password → error message visible, still on
   login page.
2. **Login success → panel visible** — correct password → stats cards
   render with numeric content, config form pre-populated with current
   values.
3. **History cleanup** — seed 3 messages from a secondary context, admin
   logs in, clicks "Clean up history" and confirms → a fresh chat
   visitor sees empty history (backfill is empty) → stats
   `total_messages` still reflects the prior total.
4. **Config change reflected** — admin sets `cooldown_base_seconds=0` →
   a chat visitor can send two messages back-to-back without a cooldown
   system message. `afterEach` resets config to defaults.
5. **Logout** — click logout → back on login page; re-navigating to
   `/adminPanel.php` redirects to login.

### Test isolation

All new tests follow the project rule (recorded in memory):

- Integration truncates `config` and re-seeds defaults in `setUp`.
- E2E uses a unique per-run suffix for any chat-side data and resets
  config via `afterEach` so the next spec starts from defaults.

## Commands

Password hash generation (dev):

```bash
docker compose --env-file .env.local exec -T app \
    php -r "echo password_hash('mypassword', PASSWORD_BCRYPT), PHP_EOL;"
```

Schema apply (unchanged):

```bash
docker compose --env-file .env.local exec -T db \
    mysql -u root -proot sse_chat < db/schema.sql
```

## Code style

- `declare(strict_types=1);` on all new files.
- Admin endpoints stay procedural, matching the project's existing style.
- Admin HTML is inline in the PHP files — no templating engine.

## Boundaries

### Always

- Every admin-modifying endpoint requires both `require_admin_auth()` and
  `verify_admin_csrf($_POST['csrf_token'])`.
- Config writes go through `set_config`, which runs bounds + invariants
  before the UPDATE.
- Admin session id is regenerated on successful login
  (`session_regenerate_id(true)`).
- Admin cookie is `HttpOnly`, `SameSite=Strict`, and `Secure` in prod.

### Ask first

- Raising any bound beyond the documented cap (e.g., `history_size` > 500).
- Adding a new config key.
- Exposing admin endpoints publicly (they must stay auth-gated).
- Storing anything in `config` that isn't a tunable integer or short
  string.

### Never

- Commit a plaintext admin password (env file or otherwise).
- Log the submitted password on failed login.
- Return the CSRF token in JSON or any JS-accessible channel.
- Allow `set_config` from any non-admin code path.
- Change `config` values from migrations destructively — only
  `INSERT IGNORE` for defaults.

## Acceptance criteria

- PHPUnit passes all suites (unit + integration) including
  `AdminAuthTest`, `ConfigBoundsTest`, `RuntimeConfigTest`, and the
  updated `ValidationTest`.
- Playwright passes all specs including `adminPanel.spec.ts`.
- Wrong password at `authenticateAdmin.php` redirects `303 See Other` to
  `/adminLogin.php?error=1` with no admin session flag set (generic
  error, no user-enumeration distinction).
- Correct password sets `$_SESSION['admin_authenticated']` and redirects
  `302` to `adminPanel.php`.
- `adminPanel.php` without an admin session redirects to
  `/adminLogin.php`.
- Editing `cooldown_base_seconds` in the panel changes the cooldown on
  the next `sendMessage.php` call — no PHP restart.
- Editing `history_size` changes the number of rows emitted on the next
  first-connect SSE request.
- `cleanup_message_history` from the panel empties `messages` and leaves
  all three stats counters untouched.
- `active_user_window_minutes` cannot be set above `session_ttl_minutes`
  through the panel.
- Defaults are re-applied idempotently on `schema.sql` re-run without
  overwriting admin-edited values.

## Implementation notes (post-ship)

- **303 on failed login** instead of the 401 the early draft proposed —
  a pure POST-redirect-GET flow avoids the resubmit-on-refresh footgun
  when the user hits reload on a failed attempt.
- **`AdminAuthEndpointTest` was not created.** All admin-auth logic is
  in-process PHP (session + env + crypto) and is fully covered by unit
  tests in `AdminAuthTest.php`; the HTTP layer is covered by
  `adminPanel.spec.ts` end-to-end.
- **`workers: 1`** was added to `e2e/playwright.config.ts`. The history
  cleanup test deletes messages while other specs seed their own —
  parallel workers would let the deletion race with seeding. The suite
  is small enough that fully-serial execution is the right default.
- **`$$` escaping** is required for the `ADMIN_PASSWORD_HASH` value in
  any compose `env-file` — compose re-expands `$`-sequences during env
  interpolation, so an un-escaped bcrypt hash gets mangled (a
  `$a9x…`-shaped substring is seen as a missing variable reference).
  Documented inline in `.env.example`.
