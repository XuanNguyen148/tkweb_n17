<?php
session_start();
require_once '../config/db.php';

// ==========================
// KIỂM TRA PHIÊN LÀM VIỆC
// ==========================
if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}
$userRole = $_SESSION['role'];

// ==========================
// XỬ LÝ CRUD NGAY TRÊN FILE NÀY
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $ma = $_POST['MaTK'];
        $ten = $_POST['TenTK'];
        $mk = $_POST['MatKhau'];
        $role = $_POST['VaiTro'];
        $stmt = $pdo->prepare("INSERT INTO TAIKHOAN (MaTK, TenTK, MatKhau, VaiTro) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ma, $ten, $mk, $role]);
        header("Location: accounts.php");
        exit();
    }

    if ($action === 'edit') {
        $ma = $_POST['MaTK'];
        $ten = $_POST['TenTK'];
        $mk = $_POST['MatKhau'];
        $role = $_POST['VaiTro'];
        $stmt = $pdo->prepare("UPDATE TAIKHOAN SET TenTK=?, MatKhau=?, VaiTro=? WHERE MaTK=?");
        $stmt->execute([$ten, $mk, $role, $ma]);
        header("Location: accounts.php");
        exit();
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $ma = $_GET['MaTK'] ?? '';
    if ($ma) {
        $stmt = $pdo->prepare("DELETE FROM TAIKHOAN WHERE MaTK=?");
        $stmt->execute([$ma]);
    }
    header("Location: accounts.php");
    exit();
}

// ==========================
// HÀM SINH MÃ TỰ ĐỘNG
// ==========================
function generateMaTK($pdo) {
    $stmt = $pdo->query("SELECT MaTK FROM TAIKHOAN ORDER BY MaTK DESC LIMIT 1");
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $num = (int)substr($last['MaTK'], 2) + 1;
        return 'TK' . str_pad($num, 5, '0', STR_PAD_LEFT);
    }
    return 'TK00001';
}
$newMaTK = generateMaTK($pdo);

// ==========================
// LẤY DANH SÁCH TÀI KHOẢN
// ==========================
$search = $_GET['search'] ?? '';
$where = $search ? "WHERE TenTK LIKE '%$search%' OR MaTK LIKE '%$search%'" : '';
$stmt = $pdo->query("SELECT * FROM TAIKHOAN $where ORDER BY MaTK ASC");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Tài Khoản - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- ========== HEADER MENU ========== -->
    <header class="header">
        <div class="logo">Tink Jewelry</div>
        <ul class="nav-menu">
            <li><a href="../index.php">Trang chủ</a></li>
            <?php if ($userRole == 'Quản lý'): ?>
                <li><a href="accounts.php" class="active">Quản Lý Tài Khoản</a></li>
            <?php endif; ?>
            <li><a href="stores.php">Quản Lý Cửa Hàng</a></li>
            <li><a href="products.php">Quản Lý Sản Phẩm</a></li>
            <?php if ($userRole == 'Quản lý'): ?>
                <li><a href="imports.php">Quản Lý Nhập Kho</a></li>
                <li><a href="exports.php">Quản Lý Xuất Kho</a></li>
                <li><a href="reports.php">Quản Lý Báo Cáo</a></li>
            <?php else: ?>
                <li><a href="imports.php">Quản Lý Nhập Kho</a></li>
                <li><a href="exports.php">Quản Lý Xuất Kho</a></li>
            <?php endif; ?>
        </ul>
        <button class="logout-btn" onclick="location.href='../logout.php'">Đăng Xuất</button>
    </header>

    <!-- ========== NỘI DUNG TRANG ========== -->
    <div class="container" style="background:#fff; border-radius:16px; padding:25px; box-shadow:0 4px 15px rgba(0,0,0,0.1); margin-top:30px;">
        <h1 style="text-align: center; margin-bottom: 25px; color: #d4af37;">Quản Lý Tài Khoản</h1>

        <!-- Thanh tìm kiếm & nút thêm -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <form method="GET" class="search-form" style="display: flex; gap: 10px;">
                <input type="text" class="search-box" placeholder="Tìm kiếm tài khoản..." name="search"
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-search">Tìm</button>
            </form>
            <button class="btn btn-add" onclick="openModal()">+ Thêm Tài Khoản</button>
        </div>

        <!-- Bảng danh sách tài khoản -->
        <table class="table">
            <thead>
                <tr>
                    <th>Mã TK</th>
                    <th>Tên Tài Khoản</th>
                    <th>Mật Khẩu</th>
                    <th>Vai Trò</th>
                    <th>Thao Tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($accounts): ?>
                    <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td><?= htmlspecialchars($acc['MaTK']); ?></td>
                        <td><?= htmlspecialchars($acc['TenTK']); ?></td>
                        <td><?= htmlspecialchars($acc['MatKhau']); ?></td>
                        <td><?= htmlspecialchars($acc['VaiTro']); ?></td>
                        <td>
                            <button class="btn btn-edit"
                                onclick="editAccount('<?= $acc['MaTK']; ?>','<?= $acc['TenTK']; ?>','<?= $acc['MatKhau']; ?>','<?= $acc['VaiTro']; ?>')">
                                Sửa
                            </button>
                            <button class="btn btn-delete" onclick="deleteAccount('<?= $acc['MaTK']; ?>')">Xóa</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center;">Không có tài khoản nào</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ========== MODAL FORM ========== -->
    <div id="accountModal" class="modal" style="display:none;">
        <div class="modal-content" style="border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.15);">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle" style="text-align:center; color:#003366;">Thêm Tài Khoản</h3>
            <form method="POST" action="accounts.php">
                <input type="hidden" name="action" id="action" value="add">

                <div class="form-group">
                    <label>Mã TK</label>
                    <input type="text" name="MaTK" id="MaTK" value="<?= $newMaTK ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Tên Tài Khoản</label>
                    <input type="text" name="TenTK" id="TenTK" required>
                </div>
                <div class="form-group">
                    <label>Mật Khẩu</label>
                    <input type="text" name="MatKhau" id="MatKhau" required>
                </div>
                <div class="form-group">
                    <label>Vai Trò</label>
                    <select name="VaiTro" id="VaiTro" required>
                        <option value="Nhân viên">Nhân viên</option>
                        <option value="Quản lý">Quản lý</option>
                    </select>
                </div>
                <div style="text-align:center; margin-top:15px;">
                    <button type="submit" class="btn btn-add">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========== SCRIPT ========== -->
    <script src="../assets/js/script.js"></script>
    <script>
        function openModal() {
            document.getElementById('modalTitle').innerText = "Thêm Tài Khoản";
            document.getElementById('action').value = "add";
            document.getElementById('accountModal').style.display = "flex";
        }

        function editAccount(ma, ten, mk, role) {
            document.getElementById('modalTitle').innerText = "Chỉnh Sửa Tài Khoản";
            document.getElementById('action').value = "edit";
            document.getElementById('MaTK').value = ma;
            document.getElementById('TenTK').value = ten;
            document.getElementById('MatKhau').value = mk;
            document.getElementById('VaiTro').value = role;
            document.getElementById('MaTK').readOnly = true;
            document.getElementById('accountModal').style.display = "flex";
        }

        function closeModal() {
            document.getElementById('accountModal').style.display = "none";
        }

        function deleteAccount(ma) {
            if (confirm("Bạn có chắc muốn xóa tài khoản " + ma + " không?")) {
                window.location.href = "accounts.php?action=delete&MaTK=" + ma;
            }
        }
    </script>
</body>
</html>
