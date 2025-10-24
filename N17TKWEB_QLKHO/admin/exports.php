<?php
// admin/exports.php - Trang quản lý xuất kho
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';
$userId = $_SESSION['user_id'];


// ============================
//  XỬ LÝ AJAX: LẤY CHI TIẾT PHIẾU XUẤT
// ============================
if (isset($_GET['action']) && $_GET['action'] == 'get_detail') {
    header('Content-Type: application/json');
    $maPX = $_GET['maPX'] ?? '';

    if (!$maPX) {
        echo json_encode(['success' => false, 'message' => 'Thiếu mã phiếu xuất']);
        exit;
    }

    try {
        // Lấy thông tin phiếu xuất
        $stmt = $pdo->prepare("
            SELECT px.*, ch.TenCH, tk.TenTK 
            FROM PHIEUXUAT px 
            LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
            LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
            WHERE px.MaPX = ?
        ");
        $stmt->execute([$maPX]);
        $phieu = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$phieu) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy phiếu xuất']);
            exit;
        }

        // Lấy chi tiết sản phẩm
        $stmt = $pdo->prepare("
            SELECT ct.*, sp.TenSP, sp.TheLoai, sp.SLTK
            FROM CHITIETPHIEUXUAT ct
            JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
            WHERE ct.MaPX = ?
        ");
        $stmt->execute([$maPX]);
        $chiTiet = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'phieu' => $phieu,
                'chiTiet' => $chiTiet
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// ============================
//  XỬ LÝ THÊM / SỬA / XÓA / CẬP NHẬT TRẠNG THÁI
// ============================
if ($_POST['action'] ?? '') {
    $action = $_POST['action'];
    
    try {
        if ($action == 'add') {
            // Thêm phiếu xuất mới
            $maPX = $_POST['MaPX'] ?? '';
            $ngayXuat = $_POST['NgayXuat'];
            $maCH = $_POST['MaCH'];
            
            // Tạo phiếu xuất với trạng thái "Đang xử lý" (tương đương "Chờ duyệt")
            $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, 'Đang xử lý')");
            $stmt->execute([$maPX, $ngayXuat, $maCH, $userId]);
            
            // Thêm chi tiết phiếu xuất
            if (!empty($_POST['products'])) {
                $products = json_decode($_POST['products'], true);
                
                // Lấy mã CTPX lớn nhất hiện có
                $stmt = $pdo->query("SELECT MaCTPX FROM CHITIETPHIEUXUAT ORDER BY MaCTPX DESC LIMIT 1");
                $lastCTPX = $stmt->fetchColumn();
                $nextNumber = 1;
                if ($lastCTPX) {
                    $nextNumber = intval(substr($lastCTPX, 4)) + 1;
                }
                
                foreach ($products as $index => $product) {
                    $maCTPX = 'CTPX' . str_pad($nextNumber + $index, 3, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$maCTPX, $maPX, $product['MaSP'], $product['SLX']]);
                }
            }
            
        } elseif ($action == 'edit') {
            // Sửa phiếu xuất (chỉ được sửa khi trạng thái "Đang xử lý")
            $maPX = $_POST['MaPX'];
            
            // Kiểm tra trạng thái
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $tinhTrang = $stmt->fetchColumn();
            
            if ($tinhTrang != 'Đang xử lý') {
                header("Location: exports.php?error=Không thể sửa phiếu đã được xử lý");
                exit();
            }
            
            // Kiểm tra quyền: Nhân viên chỉ được sửa phiếu của mình
            if ($userRole == 'Nhân viên') {
                $stmt = $pdo->prepare("SELECT MaTK FROM PHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $maTK = $stmt->fetchColumn();
                if ($maTK != $userId) {
                    header("Location: exports.php?error=Bạn không có quyền sửa phiếu này");
                    exit();
                }
            }
            
            $ngayXuat = $_POST['NgayXuat'];
            $maCH = $_POST['MaCH'];
            
            // Cập nhật phiếu xuất
            $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET NgayXuat=?, MaCH=? WHERE MaPX=?");
            $stmt->execute([$ngayXuat, $maCH, $maPX]);
            
            // Xóa chi tiết cũ và thêm mới
            $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
            if (!empty($_POST['products'])) {
                $products = json_decode($_POST['products'], true);
                
                // Lấy mã CTPX lớn nhất hiện có
                $stmt = $pdo->query("SELECT MaCTPX FROM CHITIETPHIEUXUAT ORDER BY MaCTPX DESC LIMIT 1");
                $lastCTPX = $stmt->fetchColumn();
                $nextNumber = 1;
                if ($lastCTPX) {
                    $nextNumber = intval(substr($lastCTPX, 4)) + 1;
                }
                
                foreach ($products as $index => $product) {
                    $maCTPX = 'CTPX' . str_pad($nextNumber + $index, 3, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$maCTPX, $maPX, $product['MaSP'], $product['SLX']]);
                }
            }
            
        } elseif ($action == 'delete') {
            // Xóa phiếu xuất (chỉ được xóa khi trạng thái "Đang xử lý")
            $maPX = $_POST['MaPX'];
            
            // Kiểm tra trạng thái
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $tinhTrang = $stmt->fetchColumn();
            
            if ($tinhTrang != 'Đang xử lý') {
                header("Location: exports.php?error=Không thể xóa phiếu đã được xử lý");
                exit();
            }
            
            // Kiểm tra quyền: Nhân viên chỉ được xóa phiếu của mình
            if ($userRole == 'Nhân viên') {
                $stmt = $pdo->prepare("SELECT MaTK FROM PHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $maTK = $stmt->fetchColumn();
                if ($maTK != $userId) {
                    header("Location: exports.php?error=Bạn không có quyền xóa phiếu này");
                    exit();
                }
            }
            
            // Xóa chi tiết trước
            $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
            // Xóa phiếu
            $stmt = $pdo->prepare("DELETE FROM PHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
        } elseif ($action == 'update_status') {
            // Cập nhật trạng thái (chỉ Quản lý mới được phép)
            if ($userRole != 'Quản lý') {
                header("Location: exports.php?error=Bạn không có quyền cập nhật trạng thái");
                exit();
            }
            
            $maPX = $_POST['MaPX'];
            $tinhTrangMoi = $_POST['TinhTrang'];
            
            // Lấy trạng thái cũ
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $tinhTrangCu = $stmt->fetchColumn();
            
            // Cập nhật trạng thái
            $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET TinhTrang_PX=? WHERE MaPX=?");
            $stmt->execute([$tinhTrangMoi, $maPX]);
            
            // Nếu chuyển sang "Hoàn thành" hoặc "Có thay đổi", trừ tồn kho
            if (($tinhTrangMoi == 'Hoàn thành' || $tinhTrangMoi == 'Có thay đổi') && 
                ($tinhTrangCu != 'Hoàn thành' && $tinhTrangCu != 'Có thay đổi')) {
                
                // Lấy danh sách sản phẩm trong phiếu
                $stmt = $pdo->prepare("SELECT MaSP, SLX FROM CHITIETPHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $chiTiet = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Trừ tồn kho cho từng sản phẩm
                foreach ($chiTiet as $sp) {
                    $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ? WHERE MaSP = ?");
                    $stmt->execute([$sp['SLX'], $sp['MaSP']]);
                }
            }
            
            // Nếu từ "Hoàn thành" hoặc "Có thay đổi" chuyển về trạng thái khác, cộng lại tồn kho
            if (($tinhTrangCu == 'Hoàn thành' || $tinhTrangCu == 'Có thay đổi') && 
                ($tinhTrangMoi != 'Hoàn thành' && $tinhTrangMoi != 'Có thay đổi')) {
                
                // Lấy danh sách sản phẩm trong phiếu
                $stmt = $pdo->prepare("SELECT MaSP, SLX FROM CHITIETPHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $chiTiet = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Cộng lại tồn kho cho từng sản phẩm
                foreach ($chiTiet as $sp) {
                    $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK + ? WHERE MaSP = ?");
                    $stmt->execute([$sp['SLX'], $sp['MaSP']]);
                }
            }
        }
        
        header("Location: exports.php");
        exit();
        
    } catch (Exception $e) {
        header("Location: exports.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}


// ============================
//  LẤY DANH SÁCH PHIẾU XUẤT
// ============================
$search = $_GET['search'] ?? '';
$where = $search ? "WHERE px.MaPX LIKE '%$search%' OR ch.TenCH LIKE '%$search%'" : '';
$stmt = $pdo->query("
    SELECT px.*, ch.TenCH, tk.TenTK 
    FROM PHIEUXUAT px
    LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
    LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
    $where
    ORDER BY px.NgayXuat DESC, px.MaPX DESC
");
$phieuXuats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách cửa hàng cho dropdown
$stmtCH = $pdo->query("SELECT MaCH, TenCH FROM CUAHANG ORDER BY TenCH");
$cuaHangs = $stmtCH->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách sản phẩm cho dropdown
$stmtSP = $pdo->query("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE TinhTrang = 'Còn hàng' ORDER BY TenSP");
$sanPhams = $stmtSP->fetchAll(PDO::FETCH_ASSOC);

// Mapping trạng thái database sang hiển thị
function getTrangThaiDisplay($tinhTrang) {
    $mapping = [
        'Đang xử lý' => 'Chờ duyệt',
        'Bị từ chối' => 'Bị từ chối',
        'Đã duyệt' => 'Đã duyệt',
        'Hoàn thành' => 'Hoàn thành',
        'Có thay đổi' => 'Có thay đổi'
    ];
    return $mapping[$tinhTrang] ?? $tinhTrang;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Xuất Kho - Hệ Thống Quản Lý Kho Tink</title>
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
            <li><a href="products.php">Quản Lý Sản Phẩm</a></li>
            <?php if ($userRole == 'Quản lý'): ?>
                <li><a href="imports.php">Quản Lý Nhập Kho</a></li>
                <li><a href="#">Quản Lý Xuất Kho</a></li>
                <li><a href="reports.php">Quản Lý Báo Cáo</a></li>
            <?php else: ?>
                <li><a href="imports.php">Quản Lý Nhập Kho</a></li>
                <li><a href="#">Quản Lý Xuất Kho</a></li>
            <?php endif; ?>
        </ul>
        <button class="logout-btn" onclick="location.href='../logout.php'">Đăng Xuất</button>
    </header>

    <div class="container">
        <h1 style="text-align: center; margin-bottom: 20px; color: #d4af37;">Quản Lý Xuất Kho</h1>
        
        <?php if (isset($_GET['error'])): ?>
            <div style="background: #f44336; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form" style="display: inline;">
            <input type="text" class="search-box" placeholder="Tìm kiếm phiếu xuất..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
        </form>
        
        <!-- Nút thêm -->
        <button class="btn btn-add" onclick="openAddModal()">Thêm Phiếu Xuất</button>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Mã Phiếu</th>
                        <th>Ngày Xuất</th>
                        <th>Cửa Hàng</th>
                        <th>Người Lập</th>
                        <th>Trạng Thái</th>
                        <th class="actions-column">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($phieuXuats as $phieu): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($phieu['MaPX']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($phieu['NgayXuat'])); ?></td>
                            <td><?php echo htmlspecialchars($phieu['TenCH'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($phieu['TenTK'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($phieu['TinhTrang_PX']); ?></td>
                            <td class="actions-column">
                                <?php 
                                $canEdit = ($phieu['TinhTrang_PX'] == 'Đang xử lý') && 
                                          ($userRole == 'Quản lý' || $phieu['MaTK'] == $userId);
                                $canDelete = ($phieu['TinhTrang_PX'] == 'Đang xử lý') && 
                                            ($userRole == 'Quản lý' || $phieu['MaTK'] == $userId);
                                ?>
                                
                                <button class="btn btn-status" 
                                        onclick="viewDetail('<?php echo $phieu['MaPX']; ?>', <?php echo $canEdit ? 'true' : 'false'; ?>, <?php echo $canDelete ? 'true' : 'false'; ?>)">Xem Chi Tiết</button>
                                
                                <?php if ($userRole == 'Quản lý'): ?>
                                    <button class="btn btn-add" onclick="openStatusModal('<?php echo $phieu['MaPX']; ?>', '<?php echo $phieu['TinhTrang_PX']; ?>')">Cập Nhật TT</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Thêm/Sửa Phiếu Xuất -->
    <div id="addModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 id="modalTitle">Thêm Phiếu Xuất</h2>
            <form method="POST" id="phieuForm">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="text" name="MaPX" id="MaPX" placeholder="Mã Phiếu Xuất" required>
                <input type="date" name="NgayXuat" id="NgayXuat" required>
                <select name="MaCH" id="MaCH" required>
                    <option value="">Chọn Cửa Hàng</option>
                    <?php foreach ($cuaHangs as $ch): ?>
                        <option value="<?php echo $ch['MaCH']; ?>"><?php echo htmlspecialchars($ch['TenCH']); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <h3>Chi Tiết Sản Phẩm</h3>
                <div id="productsList">
                    <div class="product-row" style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <select class="product-select" style="flex: 2;" required>
                            <option value="">Chọn Sản Phẩm</option>
                            <?php foreach ($sanPhams as $sp): ?>
                                <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                    <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" class="product-quantity" placeholder="Số lượng" min="1" style="flex: 1;" required>
                        <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="flex: 0;">Xóa</button>
                    </div>
                </div>
                <button type="button" class="btn btn-add" onclick="addProductRow()" style="margin-bottom: 10px;">Thêm Sản Phẩm</button>
                
                <input type="hidden" name="products" id="productsData">
                <button type="submit" class="btn btn-add" onclick="return validateForm()">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Xem Chi Tiết -->
    <div id="detailModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeModal('detailModal')">&times;</span>
            <h2>Chi Tiết Phiếu Xuất</h2>
            <div id="detailInfo"></div>
        </div>
    </div>

    <!-- Modal Cập Nhật Trạng Thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2>Cập Nhật Trạng Thái</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="MaPX" id="statusMaPX">
                <select name="TinhTrang" id="statusTinhTrang" required>
                    <option value="">Chọn Trạng Thái</option>
                    <option value="Đang xử lý">Đang xử lý</option>
                    <option value="Bị từ chối">Bị từ chối</option>
                    <option value="Đã duyệt">Đã duyệt</option>
                    <option value="Hoàn thành">Hoàn thành</option>
                    <option value="Có thay đổi">Có thay đổi</option>
                </select>
                <button type="submit" class="btn btn-add">Cập Nhật</button>
            </form>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Thêm Phiếu Xuất';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('phieuForm').reset();
            document.getElementById('MaPX').readOnly = false;
            
            // Reset danh sách sản phẩm về 1 dòng
            document.getElementById('productsList').innerHTML = `
                <div class="product-row" style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <select class="product-select" style="flex: 2;" required>
                        <option value="">Chọn Sản Phẩm</option>
                        <?php foreach ($sanPhams as $sp): ?>
                            <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" class="product-quantity" placeholder="Số lượng" min="1" style="flex: 1;" required>
                    <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="flex: 0;">Xóa</button>
                </div>
            `;
            
            openModal('addModal');
        }

        function editPhieu(maPX) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const phieu = data.data.phieu;
                        const chiTiet = data.data.chiTiet;
                        
                        document.getElementById('modalTitle').innerText = 'Sửa Phiếu Xuất';
                        document.getElementById('modalAction').value = 'edit';
                        document.getElementById('MaPX').value = phieu.MaPX;
                        document.getElementById('MaPX').readOnly = true;
                        document.getElementById('NgayXuat').value = phieu.NgayXuat;
                        document.getElementById('MaCH').value = phieu.MaCH;
                        
                        // Hiển thị chi tiết sản phẩm
                        let html = '';
                        chiTiet.forEach((sp, index) => {
                            html += `
                                <div class="product-row" style="display: flex; gap: 10px; margin-bottom: 10px;">
                                    <select class="product-select" style="flex: 2;" required>
                                        <option value="">Chọn Sản Phẩm</option>
                                        <?php foreach ($sanPhams as $sp): ?>
                                            <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>" ${sp.MaSP == '<?php echo $sp['MaSP']; ?>' ? 'selected' : ''}>
                                                <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="product-quantity" placeholder="Số lượng" min="1" style="flex: 1;" value="${sp.SLX}" required>
                                    <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="flex: 0;">Xóa</button>
                                </div>
                            `;
                        });
                        document.getElementById('productsList').innerHTML = html;
                        
                        openModal('addModal');
                    } else {
                        alert('Không thể tải thông tin phiếu xuất');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra');
                });
        }

        function deletePhieu(maPX) {
            if (confirm('Bạn có chắc chắn muốn xóa phiếu xuất này?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaPX" value="${maPX}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewDetail(maPX, canEdit, canDelete) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const phieu = data.data.phieu;
                        const chiTiet = data.data.chiTiet;
                        
                        let html = `
                            <div class="stock-info">
                                <p><strong>Mã phiếu xuất:</strong> ${phieu.MaPX}</p>
                                <p><strong>Ngày xuất:</strong> ${new Date(phieu.NgayXuat).toLocaleDateString('vi-VN')}</p>
                                <p><strong>Cửa hàng:</strong> ${phieu.TenCH || 'N/A'}</p>
                                <p><strong>Người lập:</strong> ${phieu.TenTK || 'N/A'}</p>
                                <p><strong>Trạng thái:</strong> ${phieu.TinhTrang_PX}</p>
                                <h3>Chi tiết sản phẩm:</h3>
                                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                                    <thead>
                                        <tr style="background: #004080; color: white;">
                                            <th style="padding: 10px; border: 1px solid #ddd;">Mã SP</th>
                                            <th style="padding: 10px; border: 1px solid #ddd;">Tên SP</th>
                                            <th style="padding: 10px; border: 1px solid #ddd;">Thể loại</th>
                                            <th style="padding: 10px; border: 1px solid #ddd;">SL Xuất</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        chiTiet.forEach(sp => {
                            html += `
                                <tr>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.MaSP}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.TenSP}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.TheLoai}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd;">${sp.SLX}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                    </tbody>
                                </table>
                        `;
                        
                        // Thêm các nút hành động vào modal
                        if (canEdit || canDelete) {
                            html += `<div style="margin-top: 20px; text-align: center;">`;
                            if (canEdit) {
                                html += `<button class="btn btn-edit" onclick="closeModal('detailModal'); editPhieu('${phieu.MaPX}');" style="margin-right: 10px;">Sửa Phiếu</button>`;
                            }
                            if (canDelete) {
                                html += `<button class="btn btn-delete" onclick="closeModal('detailModal'); deletePhieu('${phieu.MaPX}');">Xóa Phiếu</button>`;
                            }
                            html += `</div>`;
                        }
                        
                        html += `</div>`;
                        
                        document.getElementById('detailInfo').innerHTML = html;
                        openModal('detailModal');
                    } else {
                        alert('Không thể tải chi tiết phiếu xuất');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra');
                });
        }

        function openStatusModal(maPX, currentStatus) {
            document.getElementById('statusMaPX').value = maPX;
            document.getElementById('statusTinhTrang').value = currentStatus;
            openModal('statusModal');
        }

        function addProductRow() {
            const productsList = document.getElementById('productsList');
            const newRow = document.createElement('div');
            newRow.className = 'product-row';
            newRow.style.cssText = 'display: flex; gap: 10px; margin-bottom: 10px;';
            newRow.innerHTML = `
                <select class="product-select" style="flex: 2;" required>
                    <option value="">Chọn Sản Phẩm</option>
                    <?php foreach ($sanPhams as $sp): ?>
                        <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                            <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" class="product-quantity" placeholder="Số lượng" min="1" style="flex: 1;" required>
                <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="flex: 0;">Xóa</button>
            `;
            productsList.appendChild(newRow);
        }

        function removeProductRow(button) {
            const productsList = document.getElementById('productsList');
            if (productsList.children.length > 1) {
                button.parentElement.remove();
            } else {
                alert('Phải có ít nhất 1 sản phẩm');
            }
        }

        function validateForm() {
            const productRows = document.querySelectorAll('.product-row');
            const products = [];
            
            for (let row of productRows) {
                const select = row.querySelector('.product-select');
                const quantity = row.querySelector('.product-quantity');
                
                if (!select.value || !quantity.value) {
                    alert('Vui lòng điền đầy đủ thông tin sản phẩm');
                    return false;
                }
                
                const sltk = parseInt(select.options[select.selectedIndex].getAttribute('data-sltk'));
                if (parseInt(quantity.value) > sltk) {
                    alert(`Số lượng xuất vượt quá tồn kho (${sltk}) của sản phẩm ${select.options[select.selectedIndex].text}`);
                    return false;
                }
                
                products.push({
                    MaSP: select.value,
                    SLX: quantity.value
                });
            }
            
            document.getElementById('productsData').value = JSON.stringify(products);
            return true;
        }

        function getTrangThaiDisplay(tinhTrang) {
            const mapping = {
                'Đang xử lý': 'Chờ duyệt',
                'Bị từ chối': 'Bị từ chối',
                'Đã duyệt': 'Đã duyệt',
                'Hoàn thành': 'Hoàn thành',
                'Có thay đổi': 'Có thay đổi'
            };
            return mapping[tinhTrang] || tinhTrang;
        }
    </script>
</body>
</html>
