# SPEC-03: Anti-Flood & Anonymous Sessions

## Objective

Prevent chat from becoming unreadable through spam or oversized paragraphs, without adding real authentication. Replace the client-generated random `user_id` with an anonymous server-issued session tied to a user-chosen nickname, enforce a 3-second per-session cooldown with escalating penalty, and cap message length at 200 characters. Wire validation and sanitization on all untrusted input.

## Out of scope

- Real authentication, passwords, account recovery
- Admin panel / runtime-configurable cooldown, message cap, nickname rules
- Global "chat rhythm" detection or automatic slowdown (chat-wide `3s → 5s` escalation)
- Multi-room / channel support (the chat list UI is a placeholder; only one room exists)
- Explicit logout button (sessions expire lazily after 15 min idle)
- Duplicate-message detection, bad-word filter, CAPTCHA
- `X-Forwarded-For` trust (deploy concern for Aruba, not app logic)

## UX flow

1. User opens `/index.php`. A modal dialog blocks the chat UI asking for a nickname.
2. User types nickname (2–20 chars, `[a-zA-Z0-9_-]`) and submits.
3. Client POSTs `joinChat.php`; server creates a session, sets a session cookie, responds `200 {ok:true}`.
   - Duplicate nickname (active session exists) → `409 {ok:false, error:"nickname_taken"}`, dialog shows error and stays open.
   - Invalid nickname → `400 {ok:false, error:"invalid_nickname"}`, same.
4. On success: dialog closes, chat UI becomes interactive, SSE opens automatically (no separate "Connect" click).
5. User types message; a live counter below the input reads `N/200`. Input is not blocked above 200 locally, but the send button is disabled until `1 ≤ N ≤ 200`.
6. User clicks **Send**. Client POSTs `sendMessage.php` with the session cookie.
   - `200` → message is inserted; will arrive via SSE just like everyone else's (no local echo).
   - `429 {error:"cooldown", wait_seconds:N}` → client appends a local `<li>` "Wait N seconds before sending another message" into `#message_container` (visible only to the offender, never routed through SSE).
   - `400 {error:"too_long" | "empty" | "invalid_chars"}` → client appends a local `<li>` with the matching user-visible error.
   - `401 {error:"no_session"}` → client reopens the nickname dialog (session expired server-side).

## Design decisions (pinned)

- **Session store**: MySQL table `sessions`, keyed by opaque token (`bin2hex(random_bytes(32))` → 64 hex chars). Not PHP native sessions — we need to query active nicknames from SQL.
- **Session cookie**: name `sid`, `HttpOnly; SameSite=Strict; Path=/`. No `Max-Age` (session cookie, dies with browser). `Secure` flag is a production-only concern, flagged in Boundaries.
- **Session TTL**: 15 minutes of inactivity (no SSE poll, no send, no join). Enforced lazily — expired rows are deleted at the next `joinChat.php` call before the new INSERT.
- **`last_seen_at` refresh**: updated on every request that presents a valid `sid` — `joinChat.php` (itself), `sendMessage.php`, and each iteration of `chatPoll.php`'s stream loop.
- **Nickname uniqueness**: enforced by a SQL `UNIQUE` constraint on `sessions.nickname`. Because expired rows are deleted before INSERT, an abandoned nickname is reusable after ≤15 min. Race between two joins is resolved by the DB (one wins, other gets 409).
- **Cooldown algorithm**: base 3 s between successful sends. Each attempt during an active block increments `cooldown_attempts` and **resets** `send_blocked_until` to `NOW() + (3 × cooldown_attempts)` seconds. `wait_seconds` in the 429 response is exactly `3 × cooldown_attempts`. The counter is load-bearing — it drives the penalty. Rationale: fixed linear growth on each attempt gives a heavier, more predictable penalty than the timing-dependent model, and the displayed wait is always the true wait.
- **Message validation (server, hard reject)**:
  - Trim leading/trailing whitespace.
  - Require `1 ≤ length ≤ 200` after trim.
  - Reject if contains `\r` or `\n` (CRLF injection vector into the SSE frame).
  - Reject any C0 control char `\x00–\x08`, `\x0B`, `\x0C`, `\x0E–\x1F`, or `\x7F`.
  - Reject if `mb_check_encoding($msg, 'UTF-8') === false`.
- **Nickname validation (server, hard reject)**:
  - `preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $nickname) === 1`.
- **Client-side character counter**: pure UX affordance. Server is the source of truth — never trust the client's length.
- **XSS via DOM**: `main.js` already uses `textContent`, which does not interpret HTML. Contract: **never** introduce `innerHTML` for user-sourced strings (nickname, message, system-message copy). Called out in Boundaries.
- **SQL injection**: PDO prepared statements throughout (existing discipline). No SQL string concatenation with user data. Not a new risk — stated for the record.
- **System messages (cooldown / validation errors)**: rendered client-side as local `<li>` elements prefixed with `[system]`, never broadcast over SSE. Keeps offender's penalty private and avoids polluting other users' chat.

## Schema changes

New `sessions` table:

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(64) PRIMARY KEY,
    nickname VARCHAR(32) NOT NULL,
    cooldown_attempts INT NOT NULL DEFAULT 0,
    send_blocked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    UNIQUE KEY uk_sessions_nickname (nickname)
);
```

`messages` table is unchanged. `messages.user_id` continues to hold the user's current nickname (copied at insert time). No FK to `sessions.id` — historical messages must survive session expiry.

Migration path:
- `db/schema.sql`: append the `CREATE TABLE sessions ...` block above.
- Running container: one-shot `ALTER`/`CREATE` via `docker compose exec db mysql -u… -p… sse_chat < db/schema.sql`, which is idempotent thanks to `IF NOT EXISTS`.

## New functions in `chatService.php`

```php
function validate_nickname(string $nickname): ?string
function validate_message(string $message): array  // ['ok'=>bool, 'error'=>string|null, 'trimmed'=>string]
function create_session(PDO $conn, string $nickname): string  // returns session id; throws on duplicate
function get_session(PDO $conn, string $sid): ?array  // null if absent/expired
function touch_session(PDO $conn, string $sid): void  // updates last_seen_at = NOW()
function cleanup_expired_sessions(PDO $conn): int  // DELETE WHERE last_seen_at < NOW() - INTERVAL 15 MINUTE
function advance_cooldown(PDO $conn, string $sid): array  // ['allowed'=>bool, 'wait_seconds'=>int]
```

`advance_cooldown` performs the single atomic SQL state transition:
- `SELECT send_blocked_until, cooldown_attempts FROM sessions WHERE id = ? FOR UPDATE` (inside a transaction).
- If `send_blocked_until IS NULL OR NOW() >= send_blocked_until`: set `send_blocked_until = NOW() + INTERVAL 3 SECOND`, `cooldown_attempts = 0`, commit, return `allowed=true, wait_seconds=0`.
- Else: set `cooldown_attempts = cooldown_attempts + 1`, set `send_blocked_until = NOW() + INTERVAL (3 * new_attempts) SECOND`, commit, return `allowed=false, wait_seconds=(3 * new_attempts)`.

`insert_message` signature stays the same; callers pass the nickname as `user_id`.

## Deliverables

### New files
1. `joinChat.php` — accept nickname, cleanup expired sessions, validate, insert session row, set `sid` cookie
2. `tests/unit/ValidationTest.php` — `validate_nickname` + `validate_message` pure-function tests (no DB)
3. `tests/integration/SessionTest.php` — session CRUD, nickname uniqueness with cleanup, cooldown algorithm at boundaries
4. `e2e/tests/antiFlood.spec.ts` — nickname dialog, duplicate rejection, cooldown system-message, char-cap rejection

### Modified files
5. `chatService.php` — add the seven new functions above
6. `sendMessage.php` — require valid `sid`; `validate_message`; `advance_cooldown` → either insert (with nickname from session) or 429; on any validation failure, 400 with typed `error`
7. `chatPoll.php` — require valid `sid` (401 if absent/expired); `touch_session` each loop iteration
8. `main.js` — nickname-dialog flow, live char counter, system-message rendering, 401 → reopen dialog; remove `generateID` and the random user_id assignment; join button is gone, SSE opens on successful join
9. `index.php` — replace `#user_id`/`#join_chat` with a `<dialog>` element; add `<span id="char_counter">0/200</span>` below the message input; add a `<ul id="chat_list">` placeholder listing the single current room (so "list of chats" exists as DOM per the UX spec even if it's a single static entry)
10. `db/schema.sql` — append `sessions` table
11. `e2e/tests/sendAndReceive.spec.ts` — update to go through nickname dialog instead of `#user_id` + `#join_chat`
12. `e2e/tests/baseline.spec.ts` — regenerate snapshot (initial render now includes the blocking dialog)

### Unchanged
- `access.php`, `bootstrap.php`, Docker / Compose, `phpunit.xml`, `.env.*`

## Per-file change detail

### `joinChat.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/chatService.php';

header('Content-Type: application/json');

// PDO bootstrap — same pattern as sendMessage.php
// ...

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body) || !isset($body['nickname'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_nickname']);
    exit;
}

$err = validate_nickname((string) $body['nickname']);
if ($err !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}

try {
    cleanup_expired_sessions($conn);
    $sid = create_session($conn, (string) $body['nickname']);
} catch (PDOException $e) {
    if ($e->errorInfo[1] ?? 0 === 1062) { // ER_DUP_ENTRY
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'nickname_taken']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

setcookie('sid', $sid, [
    'httponly' => true,
    'samesite' => 'Strict',
    'path' => '/',
]);
echo json_encode(['ok' => true]);
```

### `sendMessage.php` (rewrite)

- Read `$_COOKIE['sid']`; if missing or `get_session` returns null → 401 `{error:"no_session"}`.
- `validate_message`; on failure → 400 with typed error (`too_long` / `empty` / `invalid_chars`).
- `advance_cooldown`; if `!allowed` → 429 `{error:"cooldown", wait_seconds:N}`.
- Else `touch_session` + `insert_message($conn, $trimmed, $session['nickname'])` → 200 `{ok:true, id:N}`.

The client no longer sends `user_id` in the body; it's derived server-side from the session.

### `chatPoll.php` (minimal edits)

At the top, before the stream loop:
- Read `$_COOKIE['sid']`; if missing or expired → `http_response_code(401); exit;`
- Otherwise proceed as today.

Inside the loop, after the `foreach`:
- `touch_session($conn, $sid);`

No other changes.

### `main.js` (rewrite of the top-level orchestration)

- Remove `generateID()` and the `userIdInput.value = generateID()` line.
- On `DOMContentLoaded`, show the nickname `<dialog>` modally.
- Dialog submit → `fetch('joinChat.php', {method:'POST', body: JSON.stringify({nickname})})`:
  - `200` → close dialog, enable send UI, `sseHandler.connect()`
  - `409` → show "Nickname already in use"
  - `400` → show "Use 2–20 chars: letters, digits, `-`, `_`"
- `#text_value` `input` listener updates `#char_counter` text and toggles `#send_message[disabled]`.
- `sendMessage()` POST body: `{message}` only (no `user_id`).
- `afterRequest` switches on `this.status`:
  - `200` → no-op (message comes back via SSE naturally)
  - `400` → `appendSystemLi('Message rejected: ' + body.error)`
  - `401` → reopen the nickname dialog (session expired)
  - `429` → `appendSystemLi('Wait ' + body.wait_seconds + ' seconds before sending another message')`
- `HttpRequest` class stays; the request still goes through it (the correctness fixes from SPEC-02 remain).
- `appendSystemLi(text)`: creates `<li>` with `textContent = '[system] ' + text`, appends to `#message_container`. Never touches `innerHTML`.

### `index.php`

Add before `<script>`:

```html
<dialog id="join_dialog">
  <form method="dialog" id="join_form">
    <label>Nickname:
      <input type="text" id="nickname_input" minlength="2" maxlength="20"
             pattern="[a-zA-Z0-9_-]{2,20}" required autofocus>
    </label>
    <button type="submit">Join</button>
    <p id="join_error" hidden></p>
  </form>
</dialog>

<ul id="chat_list">
  <li>General (current)</li>
</ul>
```

Remove the `#user_id` input and `#join_chat` button. Add `<span id="char_counter">0/200</span>` next to `#text_value` and keep `#send_message` disabled by default (`disabled` attribute on page load).

### `db/schema.sql`

Append the `CREATE TABLE IF NOT EXISTS sessions ...` block. File remains idempotent.

## Testing strategy

### Unit tests — `tests/unit/ValidationTest.php`

| Test | Asserts |
| --- | --- |
| `testValidateNicknameAcceptsCanonical` | `alice_01`, `Bob-2`, `xy` return null |
| `testValidateNicknameRejectsTooShort` | `a` returns `invalid_nickname` |
| `testValidateNicknameRejectsTooLong` | 21-char string returns `invalid_nickname` |
| `testValidateNicknameRejectsBadChars` | `a b`, `a.b`, `a/b`, emoji return `invalid_nickname` |
| `testValidateMessageTrimsAndAccepts` | `"  hi  "` → ok with `trimmed='hi'` |
| `testValidateMessageRejectsEmpty` | `""`, `"   "` → `empty` |
| `testValidateMessageRejects201Chars` | 201-char string → `too_long` |
| `testValidateMessageRejectsCRLF` | `"a\nb"`, `"a\rb"` → `invalid_chars` |
| `testValidateMessageRejectsControlChars` | `"a\x00b"`, `"a\x07b"` → `invalid_chars` |
| `testValidateMessageRejectsInvalidUtf8` | `"\xC3\x28"` → `invalid_chars` |

### Integration tests — `tests/integration/SessionTest.php`

| Test | Asserts |
| --- | --- |
| `testCreateSessionReturnsOpaqueHex` | 64-char hex id, row in DB |
| `testCreateSessionRejectsDuplicateNickname` | Second create with same nickname throws PDOException with SQLSTATE 23000 / 1062 |
| `testCleanupExpiredSessionsDeletesOldRows` | Row with `last_seen_at = NOW() - 16 MINUTE` gone; fresh row kept |
| `testCleanupThenCreateFreesAbandonedNickname` | Expire alice, cleanup, create alice again → succeeds |
| `testGetSessionReturnsNullWhenExpired` | Row older than 15 min → null (even before cleanup runs) |
| `testAdvanceCooldownAllowsFirstSend` | Fresh session → `allowed=true, wait_seconds=0`, `send_blocked_until ≈ NOW()+3s`, `attempts=0` |
| `testAdvanceCooldownBlocksSecondSendWithin3s` | Immediately after the first → `allowed=false, wait_seconds=3`, `attempts=1` |
| `testAdvanceCooldownEscalatesPenaltyLinearly` | Three rapid blocked attempts → `wait_seconds` is exactly 3, 6, 9 in order |
| `testAdvanceCooldownResetsAfterExpiry` | Manually set `send_blocked_until` to the past → next call allows, `attempts` resets to 0 |

### End-to-end — `e2e/tests/antiFlood.spec.ts`

| Scenario | Assertion |
| --- | --- |
| Join with valid nickname | Dialog closes, `#text_value` enabled, `#message_container` empty |
| Join with duplicate nickname | Second context's dialog shows `Nickname already in use`, dialog stays open |
| Char counter reflects input | Typing `"abc"` → `#char_counter` reads `3/200` |
| Send button disabled at 0 and at 201 | `[disabled]` on empty and on 201-char pasted input |
| Rapid double send | First succeeds; second produces a `<li>` containing `[system]` and `Wait` within 2s |
| 201-char send bypassing client | `page.evaluate` to force-enable the button + stub value → POST returns 400 and system-li appears |

Existing `sendAndReceive.spec.ts` is updated: it now joins via the dialog (fills `#nickname_input`, submits the form) instead of setting `#user_id` and clicking `#join_chat`. The `waitForResponse` on `chatPoll.php` still works — SSE opens on successful join. `baseline.spec.ts` snapshot is regenerated because the dialog is now part of initial render.

### Prove-It order

Per bug / feature slice:
1. Add the failing test.
2. Land the minimal code to make it pass.
3. Commit (either per-test or per-feature — at author's discretion).

## Commands

No new top-level commands.

```bash
# Apply schema changes to the running stack
docker compose exec -T db mysql -u root -proot sse_chat < db/schema.sql

# PHPUnit (unit + integration)
docker compose run --rm app vendor/bin/phpunit

# Playwright (baseline + sendAndReceive + antiFlood)
docker compose --env-file .env.local run --rm playwright npx playwright test
```

## Code style

- New files: `declare(strict_types=1);` at top.
- Free functions in `chatService.php` — consistent with existing procedural style.
- Error strings in JSON responses are stable machine-readable keys (`nickname_taken`, `cooldown`, `too_long`, `invalid_chars`, `no_session`). User-facing copy lives only in `main.js`.
- No inline SQL in `sendMessage.php` / `joinChat.php` / `chatPoll.php` — always delegate to `chatService.php`.
- Naming: `tests/integration/SessionTest.php`, `tests/unit/ValidationTest.php`, `e2e/tests/antiFlood.spec.ts` — matches existing `test<WhatIsAsserted>` convention.

## Boundaries

### Always
- Every new rule (cooldown, char cap, nickname shape, CRLF reject, control-char reject) has at least one test that would fail without it.
- Prepared statements everywhere — no string concatenation of user data into SQL.
- User-sourced strings render via `textContent`, never `innerHTML`.
- Session rows are deleted before a conflicting `INSERT`, never during an active user's session.

### Ask first
- Changing any parameter that the user pinned (3s cooldown, 200 char cap, 15 min TTL, 2–20 nickname range, allowed nickname charset).
- Adding a `Secure` flag to the cookie (belongs to prod deploy on Aruba, separate concern).
- Introducing PHP native `session_start()` alongside the MySQL session table.
- Exposing cooldown_attempts in any response (it's internal diagnostic).
- Cron-based session cleanup (lazy-only is the spec's choice).

### Never
- Pass nickname or message through any string that eventually becomes `innerHTML`.
- Echo `$e->getMessage()` or any stack trace to the HTTP response.
- Trust the client's char count or the client-sent nickname after join — server is the source of truth.
- Broadcast cooldown / validation messages to other users via SSE.
- Add a fallback that "silently truncates" an over-200 message instead of rejecting it.

## Acceptance criteria

- Fresh user at `/index.php` is blocked by the nickname dialog until they pick a valid 2–20 char nickname; the chat UI only becomes interactive after a `200` from `joinChat.php`.
- Two browser contexts attempting the same nickname get exactly one success and one `409`; after 15 min idle on the winner, the loser can retry and succeed.
- `docker compose run --rm app vendor/bin/phpunit` passes all three testsuites: existing `unit`, existing `integration`, and the new tests (`ValidationTest`, `SessionTest`).
- `docker compose --env-file .env.local run --rm playwright npx playwright test` passes `baseline.spec.ts` (regenerated), `sendAndReceive.spec.ts` (rewritten), and `antiFlood.spec.ts`.
- Sending twice within 3 s produces exactly one message in `#message_container` from SSE plus one `[system] Wait …` line locally on the sender.
- Sending a 201-char string via devtools / curl returns `400 {"error":"too_long"}` and never reaches the `messages` table.
- Sending `"hello\nworld"` returns `400 {"error":"invalid_chars"}` — SSE framing can never be corrupted by user input.
- `grep -rE "innerHTML" main.js` returns nothing.
- `grep -rE "session_start" .` returns nothing in tracked PHP.
