<?php
// admin/products.php - Trang quản lý sản phẩm
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';


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
//  XỬ LÝ THÊM / SỬA / XÓA
// ============================
if ($_POST['action'] ?? '') {
    $action = $_POST['action'];
    if ($action == 'add' || $action == 'edit') {
        $maSP = $_POST['MaSP'] ?? '';
        $tenSP = $_POST['TenSP'];
        $theLoai = $_POST['TheLoai'];
        $mauSP = $_POST['MauSP'];
        $tinhTrang = $_POST['TinhTrang'];
        $sltk = $_POST['SLTK'];
        $giaBan = $_POST['GiaBan'];

        if ($action == 'add') {
            $stmt = $pdo->prepare("INSERT INTO SANPHAM (MaSP, TenSP, TheLoai, MauSP, TinhTrang, SLTK, GiaBan) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$maSP, $tenSP, $theLoai, $mauSP, $tinhTrang, $sltk, $giaBan]);
        } else {
            $stmt = $pdo->prepare("UPDATE SANPHAM SET TenSP=?, TheLoai=?, MauSP=?, TinhTrang=?, SLTK=?, GiaBan=? WHERE MaSP=?");
            $stmt->execute([$tenSP, $theLoai, $mauSP, $tinhTrang, $sltk, $giaBan, $maSP]);
        }
    } elseif ($action == 'delete') {
        $maSP = $_POST['MaSP'];
        $stmt = $pdo->prepare("DELETE FROM SANPHAM WHERE MaSP=?");
        $stmt->execute([$maSP]);
    }
    header("Location: products.php"); // Reload trang
    exit();
}


// ============================
//  LẤY DANH SÁCH SẢN PHẨM
// ============================
$search = $_GET['search'] ?? '';
$where = $search ? "WHERE TenSP LIKE '%$search%' OR MaSP LIKE '%$search%'" : '';
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
            <input type="text" class="search-box" placeholder="Tìm kiếm sản phẩm..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
        </form>
        
        <!-- Nút thêm -->
        <button class="btn btn-add" onclick="openModal('addModal')">Thêm Sản Phẩm</button>

        <div class="table-container">
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
    </div>

    <!-- Modal Thêm/Sửa -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 id="modalTitle">Thêm Sản Phẩm</h2>
            <form method="POST">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="text" name="MaSP" placeholder="Mã SP" required>
                <input type="text" name="TenSP" placeholder="Tên SP" required>
                <select name="TheLoai" required>
                    <option value="">Chọn Thể Loại</option>
                    <option value="Vòng tay">Vòng tay</option>
                    <option value="Vòng cổ">Vòng cổ</option>
                    <option value="Khuyên tai">Khuyên tai</option>
                    <option value="Nhẫn">Nhẫn</option>
                </select>
                <input type="text" name="MauSP" placeholder="Màu SP">
                <select name="TinhTrang" required>
                    <option value="Còn hàng">Còn hàng</option>
                    <option value="Hết hàng">Hết hàng</option>
                    <option value="Ngừng kinh doanh">Ngừng kinh doanh</option>
                </select>
                <input type="number" name="SLTK" placeholder="Số Lượng Tồn Kho" min="0" required>
                <input type="number" name="GiaBan" placeholder="Giá Bán" step="0.01" min="0" required>
                <button type="submit" class="btn btn-add">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Xem Tồn Kho -->
    <div id="stockModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('stockModal')">&times;</span>
            <h2>Xem Tồn Kho</h2>
            <p id="stockInfo"></p>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function editProduct(maSP) {
            location.href = `products.php?edit=${maSP}`;
        }

        function deleteProduct(maSP) {
            if (confirm('Xóa sản phẩm này?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaSP" value="${maSP}">`;
                document.body.appendChild(form);
                form.submit();
            }
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
    </script>
</body>
</html>
