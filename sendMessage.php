<?php

require_once __DIR__ . '/access.php';

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
  // set the PDO error mode to exception
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  echo "Connected successfully";

} catch(PDOException $e) {
  echo "Connection failed: " . $e->getMessage();
}
$request_body = file_get_contents('php://input');
$request_body = json_decode($request_body);
var_dump($request_body->message);
try {
  $timestamp = date("Y-m-d H:i:s");
  $message = $request_body->message;
  $user = $request_body->user_id;
  
  $stmt = $conn->prepare("INSERT INTO messages(message,user_id,timestamp) VALUES (?,?,?);");

  $stmt->bindValue(1, $message);
  $stmt->bindValue(2, $user);
  $stmt->bindValue(3, $timestamp);
  $stmt->execute();
} catch(PDOException $e){
  echo "Insert: " . $e->getMessage();
}
?>