# PHP SSE Chat

A minimal real-time chat demo built with **PHP + Server-Sent Events** on top of a **MySQL** backend. Two pages, a handful of scripts, no framework — meant as a small experimentation playground for SSE-based messaging.

## How it works

1. The browser opens `index.php` and is assigned a random `NN-NN-NN` user id.
2. Clicking **Connect SSE** opens an `EventSource` against `chatPoll.php`, which streams chat messages from the database as `text/event-stream`.
3. Clicking **Send Message** POSTs `{ message, user_id }` as JSON to `sendMessage.php`, which inserts a row into the `messages` table.
4. The SSE stream delivers messages back to the client, which appends them to the message list.

## File layout

| File | Role |
| --- | --- |
| `index.php` | Chat UI — user id field, message input, send/connect buttons, message list |
| `main.js` | Client-side logic: `HttpRequest` (XHR wrapper), `Logger`, `SSEhandler` (EventSource wrapper), `sendMessage()`, `generateID()` |
| `sendMessage.php` | POST endpoint — inserts `{ message, user_id, timestamp }` into `messages` via PDO |
| `chatPoll.php` | SSE endpoint — reads from `messages` and emits rows as `data:` events |
| `access.php` | Shared DB credentials (`$servername`, `$username`, `$password`, `$dbname`) |
| `materialIcons.css` + `.woff2` | Locally bundled Material Icons font |
| `index.html` | Unrelated landing page — leftover scaffolding, not part of the chat |

## Database

Single table:

```sql
CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message TEXT,
  user_id VARCHAR(32),
  timestamp DATETIME
);
```

## Requirements

- PHP with PDO + `pdo_mysql`
- A MySQL/MariaDB instance reachable from the PHP host
- Any static web server capable of executing PHP (Apache, Nginx + PHP-FPM, `php -S`, etc.)

## Running locally

```bash
php -S localhost:8000
```

Then open `http://localhost:8000/index.php`, click **Connect SSE**, and send messages from a second tab to see them stream in.
