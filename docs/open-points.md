# Open points

Concise tracker for what's left. Same tone as the chat: short, no fluff.
Detailed design lives in the specs (`docs/specs/spec-NN.md`).

## Active now

_(nothing queued — next item is SPEC-04)_

## Upcoming (ordered)

1. **SPEC-04 — History backfill + stats (backend-only)** → last N messages
   on first connect, monotonic stats counters, physical history cleanup,
   `HISTORY_SIZE` as a const. Admin UI deferred to C7.
2. **C7 — Admin panel** → first real auth of the project. Surfaces the
   SPEC-04 stats, exposes a cleanup button, lets the admin tune cooldown,
   char cap, nickname rules at runtime.
3. **C8 — Moderation (kick / ban by session id)** → after C7 because it
   reuses the admin auth.
4. **B4 — Global rhythm / auto-slowdown** → base cooldown 3s → 5s when
   the aggregate message rate crosses a threshold.
5. **B5 — Multi-room** → **only after B4**, so the room-split logic stays
   isolated and independently testable.

## Parked (low priority)

- Explicit logout (lazy TTL is enough for now)
- Bad-word filter
- CAPTCHA
- Cron-based session cleanup (lazy cleanup on join is sufficient as long
  as the join rate stays alive)

## Dropped

- **B6 — Duplicate-message dedup**: the 3s cooldown is enough. (2026-04-23)
