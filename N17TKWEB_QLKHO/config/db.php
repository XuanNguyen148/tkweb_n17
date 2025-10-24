<?php
// config/db.php - Kết nối database
$servername = "localhost";
$username = "root"; // Thay đổi theo config MySQL của bạn
$password = ""; // Thay đổi theo config MySQL của bạn
$dbname = "quanlykhotrangsuc";
$port = 3306;

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>