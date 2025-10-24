<?php
// login.php - Trang đăng nhập
session_start();
session_destroy(); // Xóa session cũ nếu có
session_start(); // Bắt đầu session mới
require_once 'config/db.php';

// Xử lý đăng nhập
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenTK = $_POST['TenTK'] ?? '';
    $matKhau = $_POST['MatKhau'] ?? '';

    if (!empty($tenTK) && !empty($matKhau)) {
        $stmt = $pdo->prepare("SELECT MaTK, TenTK, MatKhau, VaiTro FROM TAIKHOAN WHERE TenTK = ? AND MatKhau = ?");
        $stmt->execute([$tenTK, $matKhau]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {  // So sánh trực tiếp mật khẩu (chưa hash)
            $_SESSION['user_id'] = $user['MaTK'];
            $_SESSION['username'] = $user['TenTK'];
            $_SESSION['role'] = $user['VaiTro'];
            header("Location: index.php");
            exit();
        } else {
            $error = 'Tên tài khoản hoặc mật khẩu không đúng!';
        }
    } else {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ========== GIAO DIỆN LOGIN - TONE XANH ĐẬM ========== */
        body {
            margin: 0;
            font-family: "Segoe UI", sans-serif;
        }
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #001f3f, #003366, #004080);
            background-size: 200% 200%;
            animation: bgMove 8s ease infinite;
        }
        @keyframes bgMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .login-form {
            background: #fff;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-title {
            color: #003366;
            margin-bottom: 30px;
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .error {
            color: #ff4c4c;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .input-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .input-group label {
            display: block;
            margin-bottom: 5px;
            color: #003366;
            font-weight: bold;
        }
        .input-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .input-group input:focus {
            border-color: #004d99;
            box-shadow: 0 0 5px rgba(0, 77, 153, 0.3);
        }
        .login-btn {
            width: 100%;
            background: linear-gradient(135deg, #004080, #0074D9);
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            font-weight: bold;
            transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s;
            margin-top: 10px;
        }
        .login-btn:hover {
            background: linear-gradient(135deg, #0055b3, #339CFF);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.2);
        }
        .login-btn:active {
            transform: translateY(0);
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <form class="login-form" method="POST">
            <h2 class="login-title">Tink Jewelry - Đăng Nhập</h2>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="input-group">
                <label for="TenTK">Tên Tài Khoản:</label>
                <input type="text" id="TenTK" name="TenTK" required value="<?php echo htmlspecialchars($_POST['TenTK'] ?? ''); ?>">
            </div>
            <div class="input-group">
                <label for="MatKhau">Mật Khẩu:</label>
                <input type="password" id="MatKhau" name="MatKhau" required>
            </div>
            <button type="submit" class="login-btn">Đăng Nhập</button>
        </form>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>
