# SPEC-04: Message History Backfill and Usage Stats (backend-only)

## Objective

Two related backend-only capabilities that SPEC-03 explicitly deferred:

1. **History backfill** — on first SSE connect, a new joiner sees the
   last N messages of the chat instead of an empty list.
2. **Usage stats** — cumulative counters (total messages, total chars,
   total unique users ever) and a live "active users now" reading,
   available through service-layer functions that a future admin
   panel (C7) can consume.

A complementary operation — `cleanup_message_history` — physically
deletes chat history while preserving the stats counters, so the admin
can reset the visible chat without losing the cumulative totals.

Admin UI, admin auth, and runtime configuration of the thresholds are
**explicitly not in this spec** — they belong to C7.

## Out of scope

- Admin panel UI or HTML (C7)
- Admin auth (C7)
- Runtime configuration of `HISTORY_SIZE`, `ACTIVE_USER_WINDOW_MINUTES`,
  or any other threshold (C7)
- Multi-room routing (B5)
- Global-rhythm auto-slowdown (B4)
- Time-series snapshots of stats — only current cumulative counters
- Exposing `seen_users` contents in any endpoint (privacy)

## Design decisions (pinned)

- **Backfill size**: `HISTORY_SIZE = 50`, const in `chatService.php`.
  Moves to runtime config in C7.
- **Active-user window**: `ACTIVE_USER_WINDOW_MINUTES = 12`. Deliberately
  shorter than the 15 min session TTL to avoid counting sessions that
  are about to expire.
- **Stats update strategy**: incremental, per-insert, atomic with the
  insert inside a single DB transaction. No cron, no on-demand
  recomputation.
- **Unique-user semantics**: `total_users` counts distinct nicknames
  *ever seen*. A nickname that joins, expires, and rejoins counts
  once. Enforced by a dedicated `seen_users` table whose rows survive
  session TTL and history cleanup.
- **Physical cleanup**: `cleanup_message_history` issues a
  `DELETE FROM messages`. The `stats` and `seen_users` tables are
  never touched by cleanup — counters stay monotonic.
- **Reconnect semantics**: when the client reconnects with
  `HTTP_LAST_EVENT_ID` set, **no backfill**. This preserves the
  SPEC-02 resumption contract and prevents duplicates.
- **First-connect semantics**: supersedes SPEC-02's "empty on first
  connect" decision. First connect now emits the last N in ASC order
  before entering the live loop.

## Schema changes

Two new tables, appended to `db/schema.sql`. Idempotent — the file
can continue to be re-applied on every deploy.

```sql
CREATE TABLE IF NOT EXISTS stats (
    id TINYINT PRIMARY KEY DEFAULT 1,
    total_messages BIGINT NOT NULL DEFAULT 0,
    total_chars BIGINT NOT NULL DEFAULT 0,
    total_users BIGINT NOT NULL DEFAULT 0,
    CHECK (id = 1)
);

INSERT INTO stats (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS seen_users (
    nickname VARCHAR(32) PRIMARY KEY,
    first_seen_at DATETIME NOT NULL
);
```

The `stats` singleton row is seeded by the `INSERT ... ON DUPLICATE KEY`
so fresh installs start at all zeros and existing installs keep their
counters.

## New and modified functions in `chatService.php`

### New

```php
function fetch_last_n_messages(PDO $conn, int $n): array;
function cleanup_message_history(PDO $conn): int;
function get_stats(PDO $conn): array;
function active_users_now(PDO $conn): int;
```

- `fetch_last_n_messages`: `ORDER BY id DESC LIMIT ?` then
  `array_reverse` so the caller receives messages in ASC order.
- `cleanup_message_history`: single `DELETE FROM messages`; returns
  affected row count.
- `get_stats`: reads the singleton row, returns
  `['total_messages' => int, 'total_chars' => int, 'total_users' => int]`.
- `active_users_now`: `SELECT COUNT(*) FROM sessions WHERE last_seen_at
   >= NOW() - INTERVAL ACTIVE_USER_WINDOW_MINUTES MINUTE`.

### Modified

`insert_message` wraps the existing INSERT plus a counters UPDATE in a
transaction:

```sql
BEGIN;
INSERT INTO messages (...) VALUES (...);
UPDATE stats
  SET total_messages = total_messages + 1,
      total_chars    = total_chars + <len>
  WHERE id = 1;
COMMIT;
```

`create_session` wraps the existing sessions INSERT plus a conditional
seen_users / counters update in a transaction:

```sql
BEGIN;
INSERT INTO sessions (...) VALUES (...);
INSERT IGNORE INTO seen_users (nickname, first_seen_at) VALUES (?, ?);
-- If the IGNORE actually inserted a row (rowCount == 1):
UPDATE stats SET total_users = total_users + 1 WHERE id = 1;
COMMIT;
```

If the `sessions` INSERT throws on duplicate nickname, the transaction
rolls back and neither `seen_users` nor `stats` is touched — existing
uniqueness behavior is preserved.

## Modified endpoint

### `chatPoll.php`

The first-connect branch changes from seeding the cursor at
`max_message_id()` to emitting the last N events:

```php
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
if ($lastEventId !== null) {
    $cursor = (int) $lastEventId;
} else {
    $history = fetch_last_n_messages($conn, HISTORY_SIZE);
    foreach ($history as $rs) {
        echo "id: {$rs['id']}\n";
        echo "data: {$rs['timestamp']} - {$rs['user_id']}: {$rs['message']}\n\n";
    }
    $cursor = !empty($history) ? (int) end($history)['id'] : 0;
}
```

No other change to the stream loop. `max_message_id` is left in
`chatService.php` (covered by an existing test, keeps the surface
stable for any future caller).

## Deliverables

### New files
1. `tests/integration/HistoryTest.php`
2. `tests/integration/StatsTest.php`
3. `e2e/tests/historyBackfill.spec.ts`

### Modified files
4. `chatService.php` — constants, 4 new functions, modified
   `insert_message` + `create_session`.
5. `chatPoll.php` — first-connect branch emits the backfill.
6. `db/schema.sql` — append `stats` and `seen_users` tables.

### Also touched (minor, backfill-driven)
7. `e2e/tests/sendAndReceive.spec.ts` and
   `e2e/tests/antiFlood.spec.ts` — assertions narrowed with
   `filter({ hasText }) + toHaveCount(1)` on a unique suffix-tagged
   string. The existing `toContainText(string)` on
   `#message_container li` hit strict-mode violations once the
   backfill started populating `<li>` elements before the live send.

### Unchanged
- All other PHP endpoints, `main.js`, `index.php`,
  `baseline.spec.ts`, Docker / Compose config.

## Testing strategy

### Integration — `tests/integration/HistoryTest.php`

| Test | Asserts |
| --- | --- |
| `testFetchLastNReturnsEmptyOnEmptyTable` | Empty table → `[]` |
| `testFetchLastNReturnsAllWhenFewerThanN` | 3 inserts, N=50 → 3 rows in ASC id order |
| `testFetchLastNReturnsLatestNInAscOrder` | 60 inserts, N=50 → ids 11..60 in ASC |
| `testCleanupMessageHistoryDeletesAll` | 3 inserts → cleanup returns 3, table empty |
| `testCleanupPreservesStats` | 3 inserts (so counters advance) → cleanup → `total_messages` still 3, `total_chars` unchanged |

### Integration — `tests/integration/StatsTest.php`

| Test | Asserts |
| --- | --- |
| `testStatsStartAtZero` | After truncate + seed row: all three counters zero |
| `testInsertMessageIncrementsCounters` | After one insert of `"hello"` (5 chars): `total_messages=1`, `total_chars=5` |
| `testInsertMessageAccumulatesAcrossCalls` | 3 inserts of varied lengths → counters are the sum |
| `testCreateSessionIncrementsTotalUsersOnNewNickname` | First create of `alice` → `total_users=1` |
| `testCreateSessionDoesNotIncrementOnRepeatedNickname` | Create `alice` → cleanup her session → create `alice` again → `total_users` still `1` |
| `testActiveUsersNowCountsWithin12Min` | 2 sessions with fresh `last_seen_at` + 1 stale (>12 min) → returns `2` |
| `testActiveUsersNowIgnoresStaleSessions` | All sessions stale → returns `0` |
| `testGetStatsReturnsSingletonShape` | Returns associative array with the three expected keys |

### E2E — `e2e/tests/historyBackfill.spec.ts`

One scenario:

1. Three independent browser contexts act as seeders (`s1_<suffix>`,
   `s2_<suffix>`, `s3_<suffix>`). Each joins and sends one message
   (`one_<suffix>`, `two_<suffix>`, `three_<suffix>`). Using three
   separate sessions avoids the 3 s per-session cooldown, so no
   `waitForTimeout` is needed between sends.
2. A fresh reader context joins (first connect, no `Last-Event-ID`).
3. Assert the reader's `#message_container` — filtered to `<li>`
   elements containing the unique run suffix — contains exactly three
   entries with `one_<suffix>`, `two_<suffix>`, `three_<suffix>` in
   that order. The filter keeps the test robust against rows left by
   earlier runs.

### Existing tests — no change expected

`sendAndReceive.spec.ts` still passes: both clients join before any
message is sent, so B's backfill is empty (history is empty at connect
time), identical to the pre-SPEC-04 behavior in that test.

`baseline.spec.ts` still passes: initial render doesn't depend on
stream content.

PHPUnit tests that touch `insert_message` / `create_session` (notably
`ChatServiceTest::testInsertMessageWritesRow` and
`SessionTest::testCreateSession*`) must still pass because the new
transactional wrapping is transparent — unchanged behavior plus the
counters side-effect.

## Commands

No new top-level commands. Schema application is unchanged:

```bash
docker compose --env-file .env.local exec -T db \
    mysql -u root -proot sse_chat < db/schema.sql
```

## Code style

- `declare(strict_types=1);` on new files.
- New functions stay in the existing `chatService.php`, consistent with
  the procedural style of the rest of the service layer.
- Integration tests mirror the `tests/integration/SessionTest.php`
  pattern: `setUp()` builds PDO from `getenv()`, truncates the tables
  the test touches, no mocks.

## Boundaries

### Always
- Every `insert_message` and `create_session` call leaves the
  database in a state where `stats` is consistent with
  `messages` / `seen_users` (enforced by the transaction wrapping).
- `cleanup_message_history` only touches the `messages` table.
- `HISTORY_SIZE` is read from the const; no env var, no DB row (until
  C7 makes it runtime-configurable).

### Ask first
- Raising `HISTORY_SIZE` above 500 (client render and scroll cost).
- Exposing `seen_users` contents through any endpoint.
- Making `stats` writable by any path other than the two we define here.
- Adding time-series snapshots of stats.

### Never
- Hard-delete rows from `seen_users`.
- Emit the backfill when `HTTP_LAST_EVENT_ID` is present — would
  duplicate events on the client after an SSE reconnect.
- Truncate `stats` from production code.
- Trust the client's nickname string when updating `seen_users`: always
  use the nickname tied to the `sessions` row that the server just
  created.

## Acceptance criteria

- PHPUnit passes all testsuites (unit, integration) including new
  `HistoryTest` and `StatsTest`.
- Playwright passes all specs including `historyBackfill.spec.ts`.
- A fresh client opening `/index.php` after several messages have been
  sent sees those messages pre-populated in `#message_container`,
  ordered oldest-first.
- An existing client whose `EventSource` reconnects (carrying
  `Last-Event-ID`) does not receive duplicate events.
- Calling `cleanup_message_history` deletes every row from `messages`
  and leaves `stats.total_messages`, `stats.total_chars`, and
  `stats.total_users` exactly as before.
- A user joining, leaving (session expiring), and rejoining with the
  same nickname increments `total_users` exactly once.
- `get_stats` returns non-negative integers on every call, including
  immediately after schema creation.
