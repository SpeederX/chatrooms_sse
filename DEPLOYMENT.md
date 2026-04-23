# Deployment notes

Sintesi delle cose da sapere per il deploy su Aruba (o qualsiasi altro
target di produzione). Non è una guida passo-passo — è il contratto tra
il codice e l'ambiente.

## Env vars richieste

| Nome       | Valore prod      | Effetto                                                               |
| ---------- | ---------------- | --------------------------------------------------------------------- |
| `DB_HOST`  | host MySQL Aruba | PDO connection                                                        |
| `DB_NAME`  | nome DB          | PDO connection                                                        |
| `DB_USER`  | utente DB        | PDO connection                                                        |
| `DB_PASS`  | password DB      | PDO connection                                                        |
| `APP_ENV`  | `prod`           | Abilita flag `Secure` sul cookie `sid`. **Richiede HTTPS** sul vhost. |

Senza `APP_ENV=prod` il cookie di sessione viene emesso senza `Secure` —
va bene in dev HTTP, ma in prod espone il cookie a MITM su downgrade.

## Schema

`db/schema.sql` è idempotente (`CREATE TABLE IF NOT EXISTS`). Applicalo
al DB al primo deploy:

```bash
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME < db/schema.sql
```

Le versioni successive possono rieseguirlo senza effetti. Quando una
futura spec modifica una tabella esistente, il changelog dello schema
vivrà in questo file.

## Reverse proxy / `X-Forwarded-For`

Su Aruba il traffico passa da un front che riassegna `REMOTE_ADDR` al
proprio IP. Oggi **non** abbiamo feature IP-based (anti-flood si basa su
session cookie, non su IP), quindi nessuna configurazione è richiesta.

Quando in futuro si introducesse qualcosa che dipende dall'IP client
reale (rate-limit IP, audit, geo-fencing, ban list), prima di scrivere
la feature bisogna:

1. Confermare quali header inoltra il front Aruba (`X-Forwarded-For`?
   `X-Real-IP`? `CF-Connecting-IP`?).
2. Capire su quale subnet vivono le loro edge node, per validare che
   l'header venga accettato solo se il request arriva da un proxy fidato.
3. Introdurre un env var `TRUSTED_PROXIES` (lista CIDR) e una helper
   `client_ip()` in `chatService.php` che ritorni `REMOTE_ADDR` se il
   request non arriva da un trusted proxy, altrimenti l'ultimo hop
   "non-trusted" della catena `X-Forwarded-For`.

Trust-by-default del header è un vettore di spoofing — attenzione.

## Build stabile → pull su Aruba

L'automazione di release (pianificata) dovrà:

1. Essere triggered solo da commit che hanno la pipeline CI verde
   (phpunit + playwright), vedi `.github/workflows/ci.yml`.
2. Buildare un artefatto (tar o branch "release") con `vendor/`
   pre-installato — Aruba shared hosting potrebbe non avere composer.
3. Pullare l'artefatto nel document root via git o SFTP.

I dettagli del meccanismo (webhook, tag, manual trigger, ecc.) sono un
open point separato.
