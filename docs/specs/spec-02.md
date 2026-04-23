# SPEC-02: Correctness Baseline

## Objective

Make the chat actually work end-to-end: a message sent from one browser tab shows up in another tab's message list in real time, and reconnecting picks up where we left off without dropping or duplicating messages. Fix the correctness bugs from the original triage list — nothing more.

## Out of scope

- Authentication, CSRF protection, XSS hardening (next spec: security baseline)
- `index.html` restoration (kept as-is per user)
- UI redesign or styling changes
- Historical-message backfill on first connect (explicitly **empty on first connect**)
- Schema changes (existing `id` auto-increment already supports SSE resumption)
- Error-response payload shape beyond a simple JSON status (next spec can formalize this)

## Bugs being fixed

| # | File | Bug | Fix |
| --- | --- | --- | --- |
| 1 | `chatPoll.php` | Dumps full table once then `sleep(5)` and exits — not actually streaming | Real SSE loop, polls for new rows each interval, honors `Last-Event-ID` |
| 2 | `chatPoll.php` | `var_dump($rs['message'])` inside the stream corrupts `text/event-stream` framing | Removed |
| 3 | `main.js` | `HttpRequest.send()` references free variables `complete` and `body`; POST body ends up `undefined` | Use `this.complete` and `this.body` |
| 4 | `main.js` | `sseHandler.connect` passed as click listener loses `this`, `EventSource` never created | Wrap in arrow: `() => sseHandler.connect()` |
| 5 | `main.js` | `sendMessage()`'s success handler targets `#list` which doesn't exist in the DOM | Target `#message_container` (the actual id) |

## Design decisions (pinned)

- **SSE resumption via `Last-Event-ID`**: server emits `id: <row.id>` on each event; on reconnect, reads `$_SERVER['HTTP_LAST_EVENT_ID']` and resumes.
- **Empty on first connect**: when `Last-Event-ID` is absent, cursor = `SELECT MAX(id) FROM messages` at connection start. Clients see only messages that arrive after they connect.
- **Poll interval**: `1000 ms` inside the stream loop.
- **Connection lifetime**: bounded at `60 seconds`. Server closes cleanly, `EventSource` auto-reconnects (carrying `Last-Event-ID`), stream picks up seamlessly.
- **"Message sent" DOM confirmation kept**: append the `<li>` to `#message_container` (barebone, mixed with chat messages). Dedicated feedback area is a later concern.

## Refactor — extract testable functions

Streaming and inserting logic moves into a new `chatService.php` with three free functions:

```php
fetch_messages_since(PDO $conn, int $cursor): array
insert_message(PDO $conn, string $message, string $user_id): int
max_message_id(PDO $conn): int
```

`chatPoll.php` and `sendMessage.php` shrink to: (1) build PDO, (2) call the appropriate function(s). The functions are unit-testable with a live test DB; the scripts keep the HTTP-layer concerns.

## Deliverables

### New files
1. `chatService.php` — the three extracted functions (+ `require_once __DIR__ . '/access.php';` at top to expose DB creds as globals)
2. `tests/integration/ChatServiceTest.php` — PHPUnit integration tests against the live `db` service
3. `e2e/tests/sendAndReceive.spec.ts` — Playwright two-browser-context test

### Modified files
4. `chatPoll.php` — full rewrite as real SSE loop (details below)
5. `sendMessage.php` — thin wrapper: validate JSON body, call `insert_message()`, respond with JSON `{ok, id}` (no more `echo "Connected successfully"`, no more `var_dump`)
6. `main.js` — three bug fixes (details below)
7. `phpunit.xml` — add `integration` testsuite pointing at `tests/integration/`

### Unchanged
- `index.php`, `index.html`, `bootstrap.php`, `access.php`, CSS/font, Docker, Compose, schema
- `e2e/tests/baseline.spec.ts` + its snapshot (initial page render is unchanged)

## Per-file change detail

### `chatPoll.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/chatService.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException) {
    http_response_code(500);
    exit;
}

$cursor = isset($_SERVER['HTTP_LAST_EVENT_ID'])
    ? (int) $_SERVER['HTTP_LAST_EVENT_ID']
    : max_message_id($conn);

$deadline = time() + 60;
while (time() < $deadline) {
    foreach (fetch_messages_since($conn, $cursor) as $rs) {
        echo "id: {$rs['id']}\n";
        echo "data: {$rs['timestamp']} - {$rs['user_id']}: {$rs['message']}\n\n";
        $cursor = (int) $rs['id'];
    }
    if (ob_get_level() > 0) { ob_flush(); }
    flush();
    if (connection_aborted()) { break; }
    sleep(1);
}
```

### `sendMessage.php`

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/chatService.php';

header('Content-Type: application/json');

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['message'], $body['user_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $id = insert_message($conn, (string) $body['message'], (string) $body['user_id']);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
```

### `main.js`

Three surgical edits:

- `HttpRequest.send()`:
  - `xhttp.onreadystatechange = complete;` → `xhttp.onreadystatechange = this.complete;`
  - `xhttp.send(JSON.stringify(body));` → `xhttp.send(JSON.stringify(this.body));`
  - Add `xhttp.setRequestHeader('Content-Type', 'application/json');` before `send()`
- `afterRequest` in `sendMessage()`:
  - `document.getElementById("list")` → `document.getElementById("message_container")`
- Listener binding:
  - `joinChatButton.addEventListener('click', sseHandler.connect, false);` → `joinChatButton.addEventListener('click', () => sseHandler.connect(), false);`

No structural rework, no class refactor, no added logic.

## Testing strategy

### Integration tests (PHPUnit) — run in the `app` container against the live `db`

`tests/integration/ChatServiceTest.php` covers the three functions:

| Test | Asserts |
| --- | --- |
| `testMaxMessageIdOnEmptyTable` | Returns `0` after `TRUNCATE` |
| `testInsertMessageWritesRow` | Row appears with matching message/user_id, returns non-zero id |
| `testFetchMessagesSinceReturnsOnlyNewer` | With 3 rows, `fetch_messages_since($id1)` returns exactly rows 2 and 3 in order |
| `testFetchMessagesSinceEmptyWhenCursorAtLatest` | Cursor = latest id → empty array |

Tests `TRUNCATE messages` in `setUp()` to isolate from each other. Each test builds its own PDO via `getenv()`.

### End-to-end (Playwright) — two browser contexts hit the live stack

`e2e/tests/sendAndReceive.spec.ts` — the golden path:

1. Open context A (sender) and context B (receiver), both load `/index.php`
2. Freeze user_ids: A=`aaa`, B=`bbb`
3. B clicks **Connect SSE** first; synchronize with `page.waitForResponse(r => r.url().includes('chatPoll.php'))` — response headers arriving guarantees the server-side cursor has been set (PHP flushes the empty first frame after `max_message_id()` runs), so A's send can't race B's subscription
4. A types `hello from A`, clicks **Send Message**
5. Assert: B's `#message_container` contains a `<li>` whose text matches `aaa: hello from A` — relying on `expect(...).toContainText(...)`'s built-in auto-retry (default 5s), no arbitrary sleeps
6. Assert: A's own `#message_container` contains a `<li>` with `Message sent` — same auto-retry

No `page.waitForTimeout()` anywhere in the suite. Synchronization is always on either a real network event or an `expect()` assertion's auto-retry.

### Prove-It order

Each bug fix lands as: **failing test first → fix → passing test**. Commit boundary per bug is a nice-to-have but not required — logical grouping is fine.

### Baseline snapshot

Unchanged. The initial page render of `index.php` doesn't differ after these fixes (no DOM touched until user interacts). If `baseline.spec.ts` fails, regenerate with `--update-snapshots` after confirming the diff is expected.

## Commands

No new top-level commands. Reuse what SPEC-01 established:

```bash
# PHPUnit (runs unit + integration testsuites)
docker compose run --rm app vendor/bin/phpunit

# Playwright (runs baseline + sendAndReceive)
docker compose --env-file .env.local run --rm playwright npx playwright test
```

## Code style

- New files (`chatService.php`, test/spec files): `declare(strict_types=1);` at top.
- Keep existing flat layout — no new subdirectories beyond `tests/integration/`.
- Free functions over classes for `chatService.php` — consistent with the existing procedural style of `chatPoll.php`/`sendMessage.php`.
- Test naming: `test<WhatIsAsserted>` — imperative, descriptive.

## Boundaries

### Always
- Every bug fix has a test (integration or E2E) that would fail against the pre-fix code. Prove the bug before fixing it.
- `chatPoll.php` and `sendMessage.php` echo only what the protocol requires. No debug dumps, no "Connection successful" text bleeding into clients.
- `TRUNCATE messages` inside integration-test `setUp()` only — never from production code.

### Ask first
- Any schema change (none expected).
- Moving existing files into subdirectories (spec keeps flat).
- Adding new PHP or Node dependencies.
- Changing the EventSource contract (data format) in a way that would break a pre-spec-02 client.

### Never
- Catch a `PDOException` and echo `$e->getMessage()` to the client (info leak — security spec territory).
- Delete or restructure `index.html` as a side effect.
- Add authentication, CSRF tokens, or input sanitization here — save for security spec so this one stays focused.
- Update the Playwright baseline snapshot silently — only after reviewing the diff.

## Acceptance criteria

- `docker compose run --rm app vendor/bin/phpunit` passes both `unit` and `integration` testsuites. The integration suite includes at least the four `ChatServiceTest` cases.
- `docker compose --env-file .env.local run --rm playwright npx playwright test` passes `baseline.spec.ts` and `sendAndReceive.spec.ts`.
- Manual smoke: open two tabs at `http://localhost:8080/index.php`, click **Connect SSE** on both, send a message from one — appears in both within a couple seconds; each tab shows "Message sent" locally.
- Manual smoke: close one tab's DevTools network and reconnect — no duplicate messages when it comes back. (`Last-Event-ID` kicks in.)
- `chatPoll.php` streams the correct `id: N` + `data: ...` framing with no `var_dump` output anywhere in the response body.
- `grep -rE "var_dump|Connection successful"` returns nothing in tracked PHP.
