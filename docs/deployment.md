# Deployment notes

What the code expects from the environment when it runs on Aruba (or
any other production target). Not a step-by-step guide — this is the
contract between the codebase and the host.

## Required env vars

| Name       | Prod value       | Effect                                                                      |
| ---------- | ---------------- | --------------------------------------------------------------------------- |
| `DB_HOST`  | Aruba MySQL host | PDO connection                                                              |
| `DB_NAME`  | DB name          | PDO connection                                                              |
| `DB_USER`  | DB user          | PDO connection                                                              |
| `DB_PASS`  | DB password      | PDO connection                                                              |
| `APP_ENV`  | `prod`           | Emits the `sid` cookie with the `Secure` flag. **Requires HTTPS** on vhost. |

Without `APP_ENV=prod` the session cookie is emitted without `Secure` —
fine in local HTTP dev, but in production it exposes the cookie to MITM
on HTTPS downgrade.

## Schema

`db/schema.sql` is idempotent (`CREATE TABLE IF NOT EXISTS`). Apply it
at first deploy:

```bash
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME < db/schema.sql
```

Re-running it on subsequent deploys has no effect. When a future spec
alters an existing table, the schema changelog will live in this file.

## Reverse proxy / `X-Forwarded-For`

On Aruba, traffic flows through a front that rewrites `REMOTE_ADDR` to
its own IP. Today we have **no** IP-based features (anti-flood is keyed
on the session cookie, not on IP), so no configuration is required.

If a future feature depends on the real client IP (IP rate-limit, audit,
geo-fencing, ban list), before implementing it:

1. Confirm which header the Aruba front forwards (`X-Forwarded-For`?
   `X-Real-IP`? `CF-Connecting-IP`?).
2. Identify the CIDR ranges of their edge nodes so the header is only
   accepted when the request arrives from a trusted proxy.
3. Introduce a `TRUSTED_PROXIES` env var (CIDR list) and a `client_ip()`
   helper in `chatService.php` that returns `REMOTE_ADDR` when the
   request does not come from a trusted proxy, otherwise the last
   non-trusted hop of the `X-Forwarded-For` chain.

Trust-by-default of the forwarded header is a spoofing vector — don't.

## Stable build → Aruba pull

The release automation (planned) should:

1. Trigger only on commits with a green CI pipeline (phpunit +
   playwright), see `.github/workflows/ci.yml`.
2. Build an artifact (tar or release branch) with `vendor/` already
   installed — Aruba shared hosting may not have Composer available.
3. Pull the artifact into the document root via git or SFTP.

The exact mechanism (webhook, tag trigger, manual) is tracked as a
separate open point.
