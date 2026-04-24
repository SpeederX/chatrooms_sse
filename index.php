<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

$nickMin = 2;
$nickMax = 20;
$msgMax = 200;
try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $nickMin = (int) get_config($conn, 'nickname_min_length', 2);
    $nickMax = (int) get_config($conn, 'nickname_max_length', 20);
    $msgMax  = (int) get_config($conn, 'message_max_length', 200);
} catch (PDOException) {
    // Fall through with spec defaults if the DB is momentarily unreachable.
}
?>
<html>
  <head></head>
  <body>
    <h1>PHP SSE test</h1>

    <dialog id="join_dialog">
      <form id="join_form">
        <label>Nickname:
          <input type="text" id="nickname_input"
                 minlength="<?= $nickMin ?>" maxlength="<?= $nickMax ?>"
                 pattern="[a-zA-Z0-9_-]{<?= $nickMin ?>,<?= $nickMax ?>}"
                 required autofocus>
        </label>
        <button type="submit">Join</button>
        <p id="join_error" hidden></p>
      </form>
    </dialog>

    <h2>Rooms</h2>
    <ul id="chat_list">
      <li>General (current)</li>
    </ul>

    <h2>Chat</h2>
      Message: <input type="text" id="text_value" maxlength="<?= $msgMax ?>" disabled>
      <span id="char_counter">0/<?= $msgMax ?></span>
      <button id="send_message" disabled>Send Message</button>

    <h2>Server responses</h2>
    <ol id="message_container">
      <!-- here messages from the SSE stream will appear -->
    </ol>

    <script>
      window.SSE_CONFIG = {
        messageMaxLength: <?= $msgMax ?>,
        nicknameMinLength: <?= $nickMin ?>,
        nicknameMaxLength: <?= $nickMax ?>
      };
    </script>
    <script type="text/javascript" src="main.js"></script>
  </body>
</html>
