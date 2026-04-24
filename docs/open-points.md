# Open points

Concise tracker for what's left. Same tone as the chat: short, no fluff.
Detailed design lives in the specs (`docs/specs/spec-NN.md`).

## Active now

_(nothing queued — next item is C8)_

## Upcoming (ordered)

1. **C8 — Moderation (kick / ban by session id)** → reuses the admin
   auth and CSRF plumbing shipped in SPEC-05 (v0.5.0). Adds per-session
   kick / ban actions and the UI to issue them.
2. **B4 — Global rhythm / auto-slowdown** → base cooldown 3s → 5s when
   the aggregate message rate crosses a threshold. The threshold itself
   becomes a `config` row (SPEC-05 makes this trivial).
3. **B5 — Multi-room** → **only after B4**, so the room-split logic stays
   isolated and independently testable.

## Shipped

- **SPEC-04 (v0.4.0)** — history backfill + cumulative usage stats
  (backend-only). Stats counters, `seen_users`, `cleanup_message_history`.
- **SPEC-05 (v0.5.0)** — admin panel: first real auth, runtime config
  table with 7 tunable keys, stats surfacing, history cleanup button.

## Parked (low priority)

- Explicit logout (lazy TTL is enough for now)
- Bad-word filter
- CAPTCHA
- Cron-based session cleanup (lazy cleanup on join is sufficient as long
  as the join rate stays alive)

## Dropped

- **B6 — Duplicate-message dedup**: the 3s cooldown is enough. (2026-04-23)
