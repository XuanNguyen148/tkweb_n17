<?php
// admin/stores.php - Trang quản lý cửa hàng 
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}
// ===============================
// LẤY THÔNG TIN CỬA HÀNG ĐỂ SỬA
// ===============================
$editStore = null;
if (isset($_GET['edit'])) {
    $maCH = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM CUAHANG WHERE MaCH = ?");
    $stmt->execute([$maCH]);
    $editStore = $stmt->fetch(PDO::FETCH_ASSOC);
}
// ============================
// XỬ LÝ THÊM / SỬA / XÓA
// ============================
if ($_POST['action'] ?? '') {
    $action = $_POST['action'];
    try {
        if ($action == 'add' || $action == 'edit') {
            $maCH = $_POST['MaCH'] ?? '';
            $tenCH = $_POST['TenCH'] ?? '';
            $diaChi = $_POST['DiaChi'] ?? '';
            $SoDienThoai = $_POST['SoDienThoai'] ?? '';

            if ($action == 'add') {
                $stmt = $pdo->prepare("INSERT INTO CUAHANG (MaCH, TenCH, DiaChi, SoDienThoai) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maCH, $tenCH, $diaChi, $SoDienThoai]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm cửa hàng thành công!'];
            } else {
                $stmt = $pdo->prepare("UPDATE CUAHANG SET TenCH=?, DiaChi=?, SoDienThoai=? WHERE MaCH=?");
                $stmt->execute([$tenCH, $diaChi, $SoDienThoai, $maCH]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cập nhật cửa hàng thành công!'];
            }
        } elseif ($action == 'delete') {
            $maCH = $_POST['MaCH'];
            $stmt = $pdo->prepare("DELETE FROM CUAHANG WHERE MaCH=?");
            $stmt->execute([$maCH]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Xóa cửa hàng thành công!'];
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi xử lý: ' . $e->getMessage()];
    }

    header("Location: stores.php"); // Reload trang
    exit();
}

// ============================
// LẤY DANH SÁCH CỬA HÀNG
// ============================
$search = $_GET['search'] ?? '';
$where = '';
$searchMessage = '';

if ($search) {
    $where = "WHERE TenCH LIKE '%$search%' OR MaCH LIKE '%$search%'";
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM CUAHANG $where");
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    if ($totalResults == 0) {
        $searchMessage = "Không tìm thấy cửa hàng nào với từ khóa: '$search'";
    } else {
        $searchMessage = "Tìm thấy $totalResults cửa hàng với từ khóa: '$search'";
    }
}

$stmt = $pdo->query("SELECT * FROM CUAHANG $where ORDER BY MaCH");
$stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Cửa Hàng - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="logo">Tink Jewelry</div>
        <ul class="nav-menu">
            <li><a href="../index.php">Trang chủ</a></li>
            <?php if ($userRole == 'Quản lý'): ?>
                <li><a href="accounts.php">Quản Lý Tài Khoản</a></li>
            <?php endif; ?>
            <li><a href="#">Quản Lý Cửa Hàng</a></li>
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

    <div class="container">
    <h1 style="text-align: center; margin-bottom: 20px; color: #d4af37;">Quản Lý Cửa Hàng</h1>

    <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form" style="display:inline;">
            <input type="text" class="search-box" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
            </form>
            <button class="btn btn-add" onclick="openModal('addModal')">Thêm Cửa Hàng</button>

        <!-- Thông báo kết quả tìm kiếm -->
        <?php if ($searchMessage): ?>
            <div style="margin: 15px 0; padding: 12px; background: <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#ffebee' : '#e8f5e8'; ?>; 
                        border: 1px solid <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#f44336' : '#4caf50'; ?>; 
                        border-radius: 8px; color: <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#c62828' : '#2e7d32'; ?>;">
                <?php echo htmlspecialchars($searchMessage); ?>
            </div>
        <?php endif; ?>

        <!-- Flash message sau khi thêm/sửa/xóa -->
        <?php if ($flash): ?>
            <div id="flashMessage" style="margin: 15px 0; padding: 12px; background: <?php echo ($flash['type'] ?? '') === 'error' ? '#ffebee' : '#e8f5e8'; ?>; 
                        border: 1px solid <?php echo ($flash['type'] ?? '') === 'error' ? '#f44336' : '#4caf50'; ?>; 
                        border-radius: 8px; color: <?php echo ($flash['type'] ?? '') === 'error' ? '#c62828' : '#2e7d32'; ?>; transition: opacity 0.4s ease;">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Hiển thị thông báo khi không có cửa hàng -->
        <?php if (empty($stores) && !$search): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <p style="font-size: 18px; margin-bottom: 10px;">Chưa có cửa hàng nào trong hệ thống</p>
                <button class="btn btn-add" onclick="openModal('addModal')">Thêm Cửa Hàng Đầu Tiên</button>
            </div>
        <?php else: ?>
            <div class="table-container">
                <!-- Bảng danh sách cửa hàng -->
                <table>
                    <thead>
                        <tr>
                            <th>Mã CH</th>
                            <th>Tên Cửa Hàng</th>
                            <th>Địa Chỉ</th>
                            <th>Số điện thoại</th>
                            <th class="actions-column">Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stores as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['MaCH']); ?></td>
                            <td><?php echo htmlspecialchars($s['TenCH']); ?></td>
                            <td><?php echo htmlspecialchars($s['DiaChi']); ?></td>
                            <td><?php echo htmlspecialchars($s['SoDienThoai']); ?></td>
                            <td class="actions-column">
                                <button class="btn btn-edit" onclick="editStore('<?php echo $s['MaCH']; ?>')">Sửa</button>
                                <button class="btn btn-delete" onclick="deleteStore('<?php echo $s['MaCH']; ?>')">Xóa</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>

    <!-- Modal Thêm -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Thêm Cửa Hàng</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <input type="text" name="MaCH" placeholder="Mã Cửa Hàng" required>
                <input type="text" name="TenCH" placeholder="Tên Cửa Hàng" required>
                <input type="text" name="DiaChi" placeholder="Địa Chỉ">
                <input type="text" name="SoDienThoai" placeholder="Số điện thoại">
                <button type="submit" class="btn btn-add">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Sửa -->
    <?php if ($editStore): ?>
    <div id="editModal" class="modal" style="display:block;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Sửa Cửa Hàng</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="text" name="MaCH" value="<?php echo htmlspecialchars($editStore['MaCH']); ?>" readonly>
                <input type="text" name="TenCH" value="<?php echo htmlspecialchars($editStore['TenCH']); ?>" required>
                <input type="text" name="DiaChi" value="<?php echo htmlspecialchars($editStore['DiaChi']); ?>">
                <input type="text" name="SoDienThoai" value="<?php echo htmlspecialchars($editStore['SoDienThoai']); ?>">
                <button type="submit" class="btn btn-edit">Cập nhật</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="../assets/js/script.js"></script>
    <script>
        function editStore(maCH) {
            location.href = `stores.php?edit=${maCH}`;
        }

        function deleteStore(maCH) {
            if (confirm('Xóa cửa hàng này?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaCH" value="${maCH}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Tự ẩn flash message sau 4s (nếu có)
        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flashMessage');
            if (!flash) return;
            setTimeout(function() {
                flash.style.opacity = '0';
                setTimeout(function() {
                    if (flash.parentNode) flash.parentNode.removeChild(flash);
                }, 500);
            }, 4000);
        });
    </script>
</body>
</html>