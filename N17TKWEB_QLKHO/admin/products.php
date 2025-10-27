<?php
// admin/products.php - Trang quản lý sản phẩm
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';

// Hàm tạo mã sản phẩm tự động
function generateMaSP($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaSP, 3) AS UNSIGNED)) as max_id FROM SANPHAM");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'SP' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
}

// Lấy flash message (nếu có) và xóa khỏi session
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
}

// ============================
//  XỬ LÝ AJAX: LẤY TỒN KHO
// ============================
if (isset($_GET['action']) && $_GET['action'] == 'get_stock') {
    header('Content-Type: application/json');
    $maSP = $_GET['maSP'] ?? '';

    if (!$maSP) {
        echo json_encode(['success' => false, 'message' => 'Thiếu mã sản phẩm']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE MaSP = ?");
        $stmt->execute([$maSP]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy sản phẩm']);
            exit;
        }

        // (Nếu chưa có bảng nhập/xuất thì đặt tongNhap, tongXuat = 0)
        echo json_encode([
            'success' => true,
            'data' => [
                'maSP' => $product['MaSP'],
                'tenSP' => $product['TenSP'],
                'tonKho' => $product['SLTK'],
                'tongNhap' => 0,
                'tongXuat' => 0
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================
//  LẤY THÔNG TIN SẢN PHẨM ĐỂ SỬA
// ============================
$editProduct = null;
if (isset($_GET['edit'])) {
    $maSP = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM SANPHAM WHERE MaSP = ?");
    $stmt->execute([$maSP]);
    $editProduct = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================
//  XỬ LÝ THÊM / SỬA / XÓA
// ============================
if ($_POST['action'] ?? '') {
    $action = $_POST['action'];
    try {
        if ($action == 'add' || $action == 'edit') {
            $tenSP = trim($_POST['TenSP'] ?? '');
            $theLoai = trim($_POST['TheLoai'] ?? '');
            $mauSP = trim($_POST['MauSP'] ?? '');
            $tinhTrang = trim($_POST['TinhTrang'] ?? '');
            $sltk = $_POST['SLTK'] ?? '';
            $giaBan = $_POST['GiaBan'] ?? '';

            // Kiểm tra các trường bắt buộc
            if (empty($tenSP) || empty($theLoai) || empty($mauSP) || empty($tinhTrang) || $sltk === '' || $giaBan === '') {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vui lòng điền đầy đủ tất cả các trường!'];
                header("Location: products.php");
                exit();
            }

            // Kiểm tra số lượng tồn kho và giá bán phải là số không âm
            if ($sltk < 0 || $giaBan < 0) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Số lượng tồn kho và giá bán phải là số không âm!'];
                header("Location: products.php");
                exit();
            }

            if ($action == 'add') {
                $maSP = generateMaSP($pdo);
                $stmt = $pdo->prepare("INSERT INTO SANPHAM (MaSP, TenSP, TheLoai, MauSP, TinhTrang, SLTK, GiaBan) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$maSP, $tenSP, $theLoai, $mauSP, $tinhTrang, $sltk, $giaBan]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Thêm sản phẩm thành công!'];
            } else {
                $maSP = $_POST['MaSP'] ?? '';
                $stmt = $pdo->prepare("UPDATE SANPHAM SET TenSP=?, TheLoai=?, MauSP=?, TinhTrang=?, SLTK=?, GiaBan=? WHERE MaSP=?");
                $stmt->execute([$tenSP, $theLoai, $mauSP, $tinhTrang, $sltk, $giaBan, $maSP]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cập nhật sản phẩm thành công!'];
            }
        } elseif ($action == 'delete') {
            $maSP = $_POST['MaSP'];
            
            // Kiểm tra xem sản phẩm có trong phiếu xuất không (bất kỳ trạng thái nào)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM CHITIETPHIEUXUAT ct
                WHERE ct.MaSP = ?
            ");
            $stmt->execute([$maSP]);
            $hasExports = $stmt->fetchColumn() > 0;
            
            // Kiểm tra xem sản phẩm có trong phiếu nhập không (bất kỳ trạng thái nào)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM CHITIETPHIEUNHAP ct
                WHERE ct.MaSP = ?
            ");
            $stmt->execute([$maSP]);
            $hasImports = $stmt->fetchColumn() > 0;
            
            if ($hasExports || $hasImports) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Đã có phiếu xuất về sản phẩm này, nên bạn không thể xóa. Bạn có thể đổi trạng thái sản phẩm thành \'Ngừng kinh doanh\'.'];
                header("Location: products.php");
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM SANPHAM WHERE MaSP=?");
            $stmt->execute([$maSP]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Xóa sản phẩm thành công!'];
        }
    } catch (Exception $e) {
        // Kiểm tra nếu là lỗi foreign key constraint
        if (strpos($e->getMessage(), 'foreign key constraint') !== false || strpos($e->getMessage(), '1451') !== false) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Không thể xóa sản phẩm này vì đã có dữ liệu liên quan trong hệ thống. Bạn có thể đổi trạng thái sang \'Ngừng kinh doanh\'.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Lỗi khi xử lý: ' . $e->getMessage()];
        }
    }

    header("Location: products.php"); // Reload trang
    exit();
}

// ============================
//  LẤY DANH SÁCH SẢN PHẨM
// ============================
$search = $_GET['search'] ?? '';
$where = '';
$searchMessage = '';

if ($search) {
    $where = "WHERE TenSP LIKE '%$search%' OR MaSP LIKE '%$search%'";
    
    // Kiểm tra xem có sản phẩm nào khớp không
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM SANPHAM $where");
    $totalResults = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($totalResults == 0) {
        $searchMessage = "Không tìm thấy sản phẩm nào với từ khóa: '$search'";
    } else {
        $searchMessage = "Tìm thấy $totalResults sản phẩm với từ khóa: '$search'";
    }
}

$stmt = $pdo->query("SELECT * FROM SANPHAM $where ORDER BY MaSP");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Sản Phẩm - Hệ Thống Quản Lý Kho Tink</title>
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
            <li><a href="stores.php">Quản Lý Cửa Hàng</a></li>
            <li><a href="#">Quản Lý Sản Phẩm</a></li>
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
        <h1 style="text-align: center; margin-bottom: 20px; color: #d4af37;">Quản Lý Sản Phẩm</h1>
        
        <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form" style="display: inline;">
            <input type="text" class="search-box" placeholder="Tìm kiếm..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
            </form>
            <button class="btn btn-add" onclick="openModal('addModal')">Thêm Sản Phẩm</button>

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

        <!-- Hiển thị thông báo khi không có sản phẩm -->
        <?php if (empty($products) && !$search): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <p style="font-size: 18px; margin-bottom: 10px;">Chưa có sản phẩm nào trong hệ thống</p>
                <button class="btn btn-add" onclick="openModal('addModal')">Thêm Sản Phẩm Đầu Tiên</button>
            </div>
        <?php else: ?>
            <div class="table-container">
                <!-- Bảng sản phẩm -->
                <table>
                    <thead>
                        <tr>
                            <th>Mã SP</th>
                            <th>Tên SP</th>
                            <th>Thể Loại</th>
                            <th>Màu SP</th>
                            <th>Tình Trạng</th>
                            <th>Tồn Kho</th>
                            <th>Giá Bán</th>
                            <th class="actions-column">Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['MaSP']); ?></td>
                                <td><?php echo htmlspecialchars($product['TenSP']); ?></td>
                                <td><?php echo htmlspecialchars($product['TheLoai']); ?></td>
                                <td><?php echo htmlspecialchars($product['MauSP']); ?></td>
                                <td><?php echo htmlspecialchars($product['TinhTrang']); ?></td>
                                <td><?php echo $product['SLTK']; ?></td>
                                <td><?php echo number_format($product['GiaBan'], 0, ',', '.'); ?> VNĐ</td>
                                <td class="actions-column">
                                    <button class="btn btn-edit" onclick="editProduct('<?php echo $product['MaSP']; ?>')">Sửa</button>
                                        <button class="btn btn-delete" onclick="deleteProduct('<?php echo $product['MaSP']; ?>')">Xóa</button>
                                <button class="btn btn-status" onclick="viewStock('<?php echo $product['MaSP']; ?>')">Xem Tồn Kho</button>
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
            <h2>Thêm Sản Phẩm</h2>
            <form method="POST" onsubmit="return validateProductForm()">
                <input type="hidden" name="action" value="add">
                
                <label>Mã Sản Phẩm:</label>
                <?php 
                $nextMaSP = generateMaSP($pdo);
                ?>
                <input type="text" value="<?php echo $nextMaSP; ?>" disabled 
                       style="background-color: #f0f0f0;">
                
                <label>Tên Sản Phẩm: <span style="color: red;">*</span></label>
                <input type="text" name="TenSP" placeholder="Tên SP" required>
                
                <label>Thể Loại: <span style="color: red;">*</span></label>
                <select name="TheLoai" required>
                    <option value="">Chọn Thể Loại</option>
                    <option value="Vòng tay">Vòng tay</option>
                    <option value="Vòng cổ">Vòng cổ</option>
                    <option value="Khuyên tai">Khuyên tai</option>
                    <option value="Nhẫn">Nhẫn</option>
                </select>
                
                <label>Màu Sản Phẩm: <span style="color: red;">*</span></label>
                <input type="text" name="MauSP" placeholder="Màu SP" required>
                
                <label>Tình Trạng: <span style="color: red;">*</span></label>
                <select name="TinhTrang" required>
                    <option value="">Chọn Tình Trạng</option>
                    <option value="Còn hàng">Còn hàng</option>
                    <option value="Hết hàng">Hết hàng</option>
                    <option value="Ngừng kinh doanh">Ngừng kinh doanh</option>
                </select>
                
                <label>Số Lượng Tồn Kho: <span style="color: red;">*</span></label>
                <input type="number" name="SLTK" placeholder="Số Lượng Tồn Kho" min="0" required>
                
                <label>Giá Bán: <span style="color: red;">*</span></label>
                <input type="number" name="GiaBan" placeholder="Giá Bán" step="0.01" min="0" required>
                
                <button type="submit" class="btn btn-add">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Sửa -->
    <?php if ($editProduct): ?>
    <div id="editModal" class="modal" style="display: block;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Sửa Sản Phẩm</h2>
            <form method="POST" onsubmit="return validateProductForm()">
                <input type="hidden" name="action" value="edit">
                
                <label>Mã Sản Phẩm:</label>
                <input type="text" name="MaSP" value="<?php echo htmlspecialchars($editProduct['MaSP']); ?>" readonly 
                       style="background-color: #f0f0f0;">
                
                <label>Tên Sản Phẩm: <span style="color: red;">*</span></label>
                <input type="text" name="TenSP" value="<?php echo htmlspecialchars($editProduct['TenSP']); ?>" required>
                
                <label>Thể Loại: <span style="color: red;">*</span></label>
                <select name="TheLoai" required>
                    <option value="">Chọn Thể Loại</option>
                    <option value="Vòng tay" <?php echo $editProduct['TheLoai'] == 'Vòng tay' ? 'selected' : ''; ?>>Vòng tay</option>
                    <option value="Vòng cổ" <?php echo $editProduct['TheLoai'] == 'Vòng cổ' ? 'selected' : ''; ?>>Vòng cổ</option>
                    <option value="Khuyên tai" <?php echo $editProduct['TheLoai'] == 'Khuyên tai' ? 'selected' : ''; ?>>Khuyên tai</option>
                    <option value="Nhẫn" <?php echo $editProduct['TheLoai'] == 'Nhẫn' ? 'selected' : ''; ?>>Nhẫn</option>
                </select>
                
                <label>Màu Sản Phẩm: <span style="color: red;">*</span></label>
                <input type="text" name="MauSP" value="<?php echo htmlspecialchars($editProduct['MauSP']); ?>" required>
                
                <label>Tình Trạng: <span style="color: red;">*</span></label>
                <select name="TinhTrang" required>
                    <option value="">Chọn Tình Trạng</option>
                    <option value="Còn hàng" <?php echo $editProduct['TinhTrang'] == 'Còn hàng' ? 'selected' : ''; ?>>Còn hàng</option>
                    <option value="Hết hàng" <?php echo $editProduct['TinhTrang'] == 'Hết hàng' ? 'selected' : ''; ?>>Hết hàng</option>
                    <option value="Ngừng kinh doanh" <?php echo $editProduct['TinhTrang'] == 'Ngừng kinh doanh' ? 'selected' : ''; ?>>Ngừng kinh doanh</option>
                </select>
                
                <label>Số Lượng Tồn Kho: <span style="color: red;">*</span></label>
                <input type="number" name="SLTK" value="<?php echo $editProduct['SLTK']; ?>" min="0" required>
                
                <label>Giá Bán: <span style="color: red;">*</span></label>
                <input type="number" name="GiaBan" value="<?php echo $editProduct['GiaBan']; ?>" step="0.01" min="0" required>
                
                <button type="submit" class="btn btn-edit">Cập nhật</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Xem Tồn Kho -->
    <div id="stockModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('stockModal')">&times;</span>
            <h2>Xem Tồn Kho</h2>
            <p id="stockInfo"></p>
        </div>
    </div>

    <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <h2>Xác Nhận Xóa</h2>
            <p id="confirmDeleteMessage" style="margin: 20px 0; font-size: 16px;">Bạn có chắc chắn muốn xóa sản phẩm này?</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-cancel" onclick="closeModal('confirmDeleteModal')" style="background-color: #999;">Hủy</button>
                <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDelete()" style="background-color: #d32f2f;">Xóa</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function editProduct(maSP) {
            location.href = `products.php?edit=${maSP}`;
        }

        let deleteConfirmMaSP = null;

        function deleteProduct(maSP) {
            deleteConfirmMaSP = maSP;
            document.getElementById('confirmDeleteMessage').innerText = `Bạn có chắc chắn muốn xóa sản phẩm "${maSP}"?`;
            openModal('confirmDeleteModal');
        }

        function confirmDelete() {
            if (deleteConfirmMaSP) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaSP" value="${deleteConfirmMaSP}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateProductForm() {
            const tenSP = document.querySelector('input[name="TenSP"]').value.trim();
            const theLoai = document.querySelector('select[name="TheLoai"]').value;
            const mauSP = document.querySelector('input[name="MauSP"]').value.trim();
            const tinhTrang = document.querySelector('select[name="TinhTrang"]').value;
            const sltk = document.querySelector('input[name="SLTK"]').value;
            const giaBan = document.querySelector('input[name="GiaBan"]').value;
            
            if (!tenSP || !theLoai || !mauSP || !tinhTrang || sltk === '' || giaBan === '') {
                alert('Vui lòng điền đầy đủ tất cả các trường!');
                return false;
            }
            
            if (parseFloat(sltk) < 0 || parseFloat(giaBan) < 0) {
                alert('Số lượng tồn kho và giá bán phải là số không âm!');
                return false;
            }
            
            return true;
        }

        function viewStock(maSP) {
            fetch(`products.php?action=get_stock&maSP=${encodeURIComponent(maSP)}`)
                .then(response => response.json())
                .then(data => {
                    const stockInfo = document.getElementById('stockInfo');
                    if (data.success) {
                        stockInfo.innerHTML = `
                            <div class="stock-info">
                                <p><strong>Mã sản phẩm:</strong> ${data.data.maSP}</p>
                                <p><strong>Tên sản phẩm:</strong> ${data.data.tenSP}</p>
                                <p><strong>Số lượng tồn kho:</strong> ${data.data.tonKho}</p>
                                <p><strong>Tổng nhập:</strong> ${data.data.tongNhap}</p>
                                <p><strong>Tổng xuất:</strong> ${data.data.tongXuat}</p>
                            </div>`;
                    } else {
                        stockInfo.innerText = 'Không thể lấy thông tin tồn kho';
                    }
                    openModal('stockModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('stockInfo').innerText = 'Có lỗi xảy ra khi lấy thông tin tồn kho';
                    openModal('stockModal');
                });
        }

        // Tự ẩn flash message sau 4s (nếu có)
        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flashMessage');
            if (!flash) return;
            // Hiện opacity mặc định (1) -> chuyển xuống 0 rồi display none
            setTimeout(function() {
                flash.style.opacity = '0';
                setTimeout(function() {
                    if (flash.parentNode) flash.parentNode.removeChild(flash);
                }, 500); // khớp với transition
            }, 4000); // 4 giây trước khi ẩn
        });
    </script>
</body>
</html>