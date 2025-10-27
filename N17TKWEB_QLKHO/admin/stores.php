<?php
// admin/stores.php - Trang quản lý cửa hàng 
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';

// Hàm tạo mã cửa hàng tự động
function generateMaCH($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCH, 3) AS UNSIGNED)) as max_id FROM CUAHANG");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'CH' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
}

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
            $tenCH = trim($_POST['TenCH'] ?? '');
            $diaChi = trim($_POST['DiaChi'] ?? '');
            $SoDienThoai = trim($_POST['SoDienThoai'] ?? '');

            // Kiểm tra các trường bắt buộc
            if (empty($tenCH) || empty($diaChi) || empty($SoDienThoai)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: stores.php");
                exit();
            }

            // Kiểm tra định dạng số điện thoại (10-11 số, bắt đầu bằng 0)
            if (!preg_match('/^0[0-9]{9,10}$/', $SoDienThoai)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Số điện thoại không hợp lệ! Phải có 10-11 số và bắt đầu bằng 0.'];
                header("Location: stores.php");
                exit();
            }

            if ($action == 'add') {
                $maCH = generateMaCH($pdo);
                $stmt = $pdo->prepare("INSERT INTO CUAHANG (MaCH, TenCH, DiaChi, SoDienThoai) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maCH, $tenCH, $diaChi, $SoDienThoai]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm cửa hàng thành công!'];
            } else {
                $maCH = $_POST['MaCH'] ?? '';
                $stmt = $pdo->prepare("UPDATE CUAHANG SET TenCH=?, DiaChi=?, SoDienThoai=? WHERE MaCH=?");
                $stmt->execute([$tenCH, $diaChi, $SoDienThoai, $maCH]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cập nhật cửa hàng thành công!'];
            }
        } elseif ($action == 'delete') {
            $maCH = $_POST['MaCH'];
            
            // Kiểm tra xem cửa hàng có phiếu xuất không (bất kỳ trạng thái nào)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM PHIEUXUAT WHERE MaCH = ?");
            $stmt->execute([$maCH]);
            $hasExports = $stmt->fetchColumn() > 0;
            
            if ($hasExports) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Đã có phiếu xuất về cửa hàng này, nên bạn không thể xóa.'];
                header("Location: stores.php");
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM CUAHANG WHERE MaCH=?");
            $stmt->execute([$maCH]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Xóa cửa hàng thành công!'];
        }
    } catch (Exception $e) {
        // Kiểm tra nếu là lỗi foreign key constraint
        if (strpos($e->getMessage(), 'foreign key constraint') !== false || strpos($e->getMessage(), '1451') !== false) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Không thể xóa cửa hàng này vì đã có dữ liệu liên quan trong hệ thống.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi xử lý: ' . $e->getMessage()];
        }
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
            <form method="POST" onsubmit="return validateStoreForm()">
                <input type="hidden" name="action" value="add">
                
                <label>Mã Cửa Hàng:</label>
                <?php 
                $nextMaCH = generateMaCH($pdo);
                ?>
                <input type="text" value="<?php echo $nextMaCH; ?>" disabled 
                       style="background-color: #f0f0f0;">
                
                <label>Tên Cửa Hàng: <span style="color: red;">*</span></label>
                <input type="text" name="TenCH" placeholder="Tên Cửa Hàng" required>
                
                <label>Địa Chỉ: <span style="color: red;">*</span></label>
                <input type="text" name="DiaChi" placeholder="Địa Chỉ" required>
                
                <label>Số điện thoại: <span style="color: red;">*</span></label>
                <input type="tel" name="SoDienThoai" placeholder="Số điện thoại (VD: 0901234567)" 
                       pattern="0[0-9]{9,10}" title="Số điện thoại phải có 10-11 số và bắt đầu bằng 0" required>
                
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
            <form method="POST" onsubmit="return validateStoreForm()">
                <input type="hidden" name="action" value="edit">
                
                <label>Mã Cửa Hàng:</label>
                <input type="text" name="MaCH" value="<?php echo htmlspecialchars($editStore['MaCH']); ?>" readonly 
                       style="background-color: #f0f0f0;">
                
                <label>Tên Cửa Hàng: <span style="color: red;">*</span></label>
                <input type="text" name="TenCH" value="<?php echo htmlspecialchars($editStore['TenCH']); ?>" required>
                
                <label>Địa Chỉ: <span style="color: red;">*</span></label>
                <input type="text" name="DiaChi" value="<?php echo htmlspecialchars($editStore['DiaChi']); ?>" required>
                
                <label>Số điện thoại: <span style="color: red;">*</span></label>
                <input type="tel" name="SoDienThoai" value="<?php echo htmlspecialchars($editStore['SoDienThoai']); ?>" 
                       pattern="0[0-9]{9,10}" title="Số điện thoại phải có 10-11 số và bắt đầu bằng 0" required>
                
                <button type="submit" class="btn btn-edit">Cập nhật</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <h2>Xác Nhận Xóa</h2>
            <p id="confirmDeleteMessage" style="margin: 20px 0; font-size: 16px;">Bạn có chắc chắn muốn xóa cửa hàng này?</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-cancel" onclick="closeModal('confirmDeleteModal')" style="background-color: #999;">Hủy</button>
                <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDelete()" style="background-color: #d32f2f;">Xóa</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function editStore(maCH) {
            location.href = `stores.php?edit=${maCH}`;
        }

        let deleteConfirmMaCH = null;

        function deleteStore(maCH) {
            deleteConfirmMaCH = maCH;
            document.getElementById('confirmDeleteMessage').innerText = `Bạn có chắc chắn muốn xóa cửa hàng "${maCH}"?`;
            openModal('confirmDeleteModal');
        }

        function confirmDelete() {
            if (deleteConfirmMaCH) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaCH" value="${deleteConfirmMaCH}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateStoreForm() {
            const tenCH = document.querySelector('input[name="TenCH"]').value.trim();
            const diaChi = document.querySelector('input[name="DiaChi"]').value.trim();
            const soDienThoai = document.querySelector('input[name="SoDienThoai"]').value.trim();
            
            if (!tenCH || !diaChi || !soDienThoai) {
                alert('Vui lòng điền đầy đủ tất cả các trường!');
                return false;
            }
            
            const phoneRegex = /^0[0-9]{9,10}$/;
            if (!phoneRegex.test(soDienThoai)) {
                alert('Số điện thoại không hợp lệ! Phải có 10-11 số và bắt đầu bằng 0.');
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