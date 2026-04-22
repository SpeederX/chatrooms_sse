<html>
  <head></head>
  <body>
    <h1>PHP SSE test</h1>
      Username: <input type="text" id="user_id" readonly>
      Message: <input type="text" id="text_value">
      <button id="send_message">Send Message</button>
    <button id="join_chat">Connect SSE</button>
    <h2>Server responses</h2>
    <ol id="message_container">
      <!-- here messages from the SSE stream will appear -->
    </ol>

    <script type="text/javascript" src="main.js"></script>
  </body>
</html>