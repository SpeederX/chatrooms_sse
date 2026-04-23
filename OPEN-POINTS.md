# Open points

Tracker sintetico di ciò che resta. Stessa attitudine dei messaggi di chat:
stringato, niente fluff. Le spec (`SPEC-NN.md`) sono il posto per il dettaglio.

## Attive ora

_(tutto chiuso in questo batch)_

## Prossime fasi (ordinate)

1. **C7 — Admin panel** → prima spec, poi implementazione. Introduce la prima auth reale del progetto. Espone runtime-config di cooldown, char cap, regole nickname.
2. **C8 — Moderation (kick/ban per session id)** → dopo C7 (riusa l'auth admin). Scrittura spec, poi implementazione.
3. **B4 — Global rhythm / auto-slowdown** → spec + implementazione. Cooldown base 3s → 5s quando il ritmo aggregato supera una soglia.
4. **B5 — Multi-room** → spec + implementazione, **solo dopo B4** così la logica di split resta scorporata e testabile in isolamento.

## Parcheggiati (bassa priorità)

- Logout esplicito (TTL lazy attualmente basta)
- Bad-word filter
- CAPTCHA
- Cron-based session cleanup (lazy attuale è sufficiente finché join rate è vivo)

## Rimossi

- **B6 — Dedup messaggi identici**: cooldown 3s è sufficiente. (2026-04-23)
