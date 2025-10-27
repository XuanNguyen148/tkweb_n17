<?php
session_start();
session_unset();    // Xóa tất cả biến session
session_destroy();  // Hủy session
header("Location: login.php");
exit();
?>
