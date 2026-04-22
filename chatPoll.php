<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

include('access.php');

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
  // set the PDO error mode to exception
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $stmt = $conn->prepare("SELECT * FROM messages;");
  $stmt->execute();
  // fetch all rows into an array.
   $rows = $stmt->fetchAll();
   foreach ($rows as $rs) 
  {
    var_dump($rs['message']);
    echo "data: {$rs['timestamp']} - {$rs['user_id']}: {$rs['message']}\n\n";
  }

} catch(PDOException $e) {
  echo "Connection failed: " . $e->getMessage();
}


ob_flush();
flush();
if(connection_aborted()){
  exit();
}
sleep(5);
?> 