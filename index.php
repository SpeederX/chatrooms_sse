<html>
  <head></head>
  <body>
    <h1>PHP SSE test</h1>

    <dialog id="join_dialog">
      <form id="join_form">
        <label>Nickname:
          <input type="text" id="nickname_input" minlength="2" maxlength="20"
                 pattern="[a-zA-Z0-9_-]{2,20}" required autofocus>
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
      Message: <input type="text" id="text_value" maxlength="200" disabled>
      <span id="char_counter">0/200</span>
      <button id="send_message" disabled>Send Message</button>

    <h2>Server responses</h2>
    <ol id="message_container">
      <!-- here messages from the SSE stream will appear -->
    </ol>

    <script type="text/javascript" src="main.js"></script>
  </body>
</html>
