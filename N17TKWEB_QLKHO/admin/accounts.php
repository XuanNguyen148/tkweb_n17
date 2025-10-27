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
    try {
        if ($action === 'add') {
            $ma = $_POST['MaTK'];
            $ten = trim($_POST['TenTK']);
            $mk = $_POST['MatKhau'];
            $role = $_POST['VaiTro'];
            
            // Kiểm tra các trường bắt buộc
            if (empty($ten) || empty($mk) || empty($role)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: accounts.php");
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO TAIKHOAN (MaTK, TenTK, MatKhau, VaiTro) VALUES (?, ?, ?, ?)");
            $stmt->execute([$ma, $ten, $mk, $role]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm tài khoản thành công!'];
            header("Location: accounts.php");
            exit();
        }

        if ($action === 'edit') {
            $ma = $_POST['MaTK'];
            $ten = trim($_POST['TenTK']);
            $mk = $_POST['MatKhau'];
            $role = $_POST['VaiTro'];
            
            // Kiểm tra các trường bắt buộc
            if (empty($ten) || empty($mk) || empty($role)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: accounts.php");
                exit();
            }
            
            $stmt = $pdo->prepare("UPDATE TAIKHOAN SET TenTK=?, MatKhau=?, VaiTro=? WHERE MaTK=?");
            $stmt->execute([$ten, $mk, $role, $ma]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cập nhật tài khoản thành công!'];
            header("Location: accounts.php");
            exit();
        }
        
        if ($action === 'delete') {
            $ma = $_POST['MaTK'];
            $stmt = $pdo->prepare("DELETE FROM TAIKHOAN WHERE MaTK=?");
            $stmt->execute([$ma]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Xóa tài khoản thành công!'];
            header("Location: accounts.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi xử lý: ' . $e->getMessage()];
        header("Location: accounts.php");
        exit();
    }
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

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}

// ==========================
// LẤY DANH SÁCH TÀI KHOẢN
// ==========================
$search = $_GET['search'] ?? '';
$where = '';
$searchMessage = '';

if ($search) {
    $where = "WHERE TenTK LIKE '%$search%' OR MaTK LIKE '%$search%'";
    
    // Kiểm tra xem có tài khoản nào khớp không
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM TAIKHOAN $where");
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($totalResults == 0) {
        $searchMessage = "Không tìm thấy tài khoản nào với từ khóa: '$search'";
    } else {
        $searchMessage = "Tìm thấy $totalResults tài khoản với từ khóa: '$search'";
    }
}

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

    <div class="container">
        <h1 style="text-align: center; margin-bottom: 20px; color: #d4af37;">Quản Lý Tài Khoản</h1>

        <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form" style="display: inline;">
            <input type="text" class="search-box" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
            </form>
            <button class="btn btn-add" onclick="openModal()">Thêm Tài Khoản</button>

        <!-- Thông báo kết quả tìm kiếm -->
        <?php if ($searchMessage): ?>
            <div style="margin: 15px 0; padding: 12px; background: <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#ffebee' : '#e8f5e8'; ?>; 
                        border: 1px solid <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#f44336' : '#4caf50'; ?>; 
                        border-radius: 8px; color: <?php echo strpos($searchMessage, 'Không tìm thấy') !== false ? '#c62828' : '#2e7d32'; ?>;">
                <?php echo htmlspecialchars($searchMessage); ?>
                <?php if ($search && strpos($searchMessage, 'Không tìm thấy') !== false): ?>
                    <br><small>Gợi ý: Thử tìm kiếm với từ khóa khác hoặc kiểm tra chính tả</small>
                <?php endif; ?>
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

        <!-- Hiển thị thông báo khi không có tài khoản -->
        <?php if (empty($accounts) && !$search): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <p style="font-size: 18px; margin-bottom: 10px;">Chưa có tài khoản nào trong hệ thống</p>
                <button class="btn btn-add" onclick="openModal()">Thêm Tài Khoản Đầu Tiên</button>
            </div>
        <?php else: ?>
            <div class="table-container">
                <!-- Bảng danh sách tài khoản -->
                <table>
                    <thead>
                        <tr>
                            <th>Mã TK</th>
                            <th>Tên Tài Khoản</th>
                            <th>Mật Khẩu</th>
                            <th>Vai Trò</th>
                            <th class="actions-column">Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $acc): ?>
                            <tr>
                                <td><?= htmlspecialchars($acc['MaTK']); ?></td>
                                <td><?= htmlspecialchars($acc['TenTK']); ?></td>
                                <td><?= htmlspecialchars($acc['MatKhau']); ?></td>
                                <td><?= htmlspecialchars($acc['VaiTro']); ?></td>
                                <td class="actions-column">
                                    <button class="btn btn-edit"
                                        onclick="editAccount('<?= $acc['MaTK']; ?>','<?= $acc['TenTK']; ?>','<?= $acc['MatKhau']; ?>','<?= $acc['VaiTro']; ?>')">
                                        Sửa
                                    </button>
                                    <button class="btn btn-delete" onclick="deleteAccount('<?= $acc['MaTK']; ?>')">Xóa</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <h2>Xác Nhận Xóa</h2>
            <p id="confirmDeleteMessage" style="margin: 20px 0; font-size: 16px;">Bạn có chắc chắn muốn xóa tài khoản này?</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-cancel" onclick="closeModal('confirmDeleteModal')" style="background-color: #999;">Hủy</button>
                <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDelete()" style="background-color: #d32f2f;">Xóa</button>
            </div>
        </div>
    </div>

    <!-- ========== MODAL FORM ========== -->
    <div id="accountModal" class="modal" style="display:none;">
        <div class="modal-content" style="border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.15);">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle" style="text-align:center; color:#003366;">Thêm Tài Khoản</h3>
            <form method="POST" action="accounts.php" onsubmit="return validateAccountForm()">
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
        function openModal(modalId = 'accountModal') {
            if (modalId === 'accountModal') {
                document.getElementById('modalTitle').innerText = "Thêm Tài Khoản";
                document.getElementById('action').value = "add";
            }
            document.getElementById(modalId).style.display = "flex";
        }

        function closeModal(modalId = 'accountModal') {
            document.getElementById(modalId).style.display = "none";
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


        let deleteConfirmMaTK = null;

        function deleteAccount(ma) {
            deleteConfirmMaTK = ma;
            document.getElementById('confirmDeleteMessage').innerText = `Bạn có chắc chắn muốn xóa tài khoản "${ma}"?`;
            openModal('confirmDeleteModal');
        }

        function confirmDelete() {
            if (deleteConfirmMaTK) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaTK" value="${deleteConfirmMaTK}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateAccountForm() {
            const tenTK = document.querySelector('input[name="TenTK"]').value.trim();
            const matKhau = document.querySelector('input[name="MatKhau"]').value.trim();
            const vaiTro = document.querySelector('select[name="VaiTro"]').value;
            
            if (!tenTK || !matKhau || !vaiTro) {
                alert('Vui lòng điền đầy đủ tất cả các trường!');
                return false;
            }
            
            return true;
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