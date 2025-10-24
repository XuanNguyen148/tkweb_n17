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
if (isset($_GET['action']) && $_GET['action'] == 'get_details') {
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
        $export = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$export) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy phiếu xuất']);
            exit;
        }

        // Lấy chi tiết sản phẩm
        $stmt = $pdo->prepare("
            SELECT ct.*, sp.TenSP, sp.SLTK
            FROM CHITIETPHIEUXUAT ct
            LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
            WHERE ct.MaPX = ?
        ");
        $stmt->execute([$maPX]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'export' => $export,
                'items' => $items
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================
//  XỬ LÝ AJAX: LẤY DANH SÁCH SẢN PHẨM
// ============================
if (isset($_GET['action']) && $_GET['action'] == 'get_products') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT MaSP, TenSP, SLTK FROM SANPHAM WHERE TinhTrang = 'Còn hàng' ORDER BY TenSP");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $products]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================
//  XỬ LÝ AJAX: LẤY DANH SÁCH CỬA HÀNG
// ============================
if (isset($_GET['action']) && $_GET['action'] == 'get_stores') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT MaCH, TenCH FROM CUAHANG ORDER BY TenCH");
        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $stores]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================
//  XỬ LÝ THÊM / SỬA / XÓA
// ============================
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        $pdo->beginTransaction();
        
        if ($action == 'add') {
            $maPX = $_POST['MaPX'] ?? '';
            $ngayXuat = $_POST['NgayXuat'];
            $maCH = $_POST['MaCH'];
            $tinhTrang = 'Đang xử lý';
            
            // Thêm phiếu xuất
            $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$maPX, $ngayXuat, $maCH, $userId, $tinhTrang]);
            
            // Thêm chi tiết phiếu xuất
            $products = json_decode($_POST['products'], true);
            foreach ($products as $index => $product) {
                $maCTPX = 'CTPX' . str_pad(substr($maPX, 2) . ($index + 1), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maCTPX, $maPX, $product['MaSP'], $product['SLX']]);
            }
            
        } elseif ($action == 'edit') {
            $maPX = $_POST['MaPX'];
            
            // Kiểm tra quyền sửa
            $stmt = $pdo->prepare("SELECT TinhTrang_PX, MaTK FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $export = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($export['TinhTrang_PX'] != 'Đang xử lý') {
                throw new Exception('Chỉ có thể sửa phiếu xuất ở trạng thái Đang xử lý');
            }
            
            if ($userRole == 'Nhân viên' && $export['MaTK'] != $userId) {
                throw new Exception('Bạn không có quyền sửa phiếu xuất này');
            }
            
            $ngayXuat = $_POST['NgayXuat'];
            $maCH = $_POST['MaCH'];
            
            // Cập nhật phiếu xuất
            $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET NgayXuat=?, MaCH=? WHERE MaPX=?");
            $stmt->execute([$ngayXuat, $maCH, $maPX]);
            
            // Xóa chi tiết cũ
            $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
            // Thêm chi tiết mới
            $products = json_decode($_POST['products'], true);
            foreach ($products as $index => $product) {
                $maCTPX = 'CTPX' . str_pad(substr($maPX, 2) . ($index + 1), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maCTPX, $maPX, $product['MaSP'], $product['SLX']]);
            }
            
        } elseif ($action == 'delete') {
            $maPX = $_POST['MaPX'];
            
            // Kiểm tra quyền xóa
            if ($userRole != 'Quản lý') {
                throw new Exception('Chỉ Quản lý mới có quyền xóa phiếu xuất');
            }
            
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $export = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($export['TinhTrang_PX'] != 'Đang xử lý') {
                throw new Exception('Chỉ có thể xóa phiếu xuất ở trạng thái Đang xử lý');
            }
            
            // Xóa chi tiết trước
            $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
            // Xóa phiếu xuất
            $stmt = $pdo->prepare("DELETE FROM PHIEUXUAT WHERE MaPX=?");
            $stmt->execute([$maPX]);
            
        } elseif ($action == 'change_status') {
            $maPX = $_POST['MaPX'];
            $newStatus = $_POST['TinhTrang_PX'];
            
            // Kiểm tra quyền đổi trạng thái
            if ($userRole != 'Quản lý') {
                throw new Exception('Chỉ Quản lý mới có quyền đổi trạng thái phiếu xuất');
            }
            
            // Lấy thông tin phiếu xuất hiện tại
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $export = $stmt->fetch(PDO::FETCH_ASSOC);
            $oldStatus = $export['TinhTrang_PX'];
            
            // Cập nhật trạng thái
            $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET TinhTrang_PX=? WHERE MaPX=?");
            $stmt->execute([$newStatus, $maPX]);
            
            // Nếu chuyển sang "Hoàn thành" hoặc "Có thay đổi", trừ tồn kho
            if (($newStatus == 'Hoàn thành' || $newStatus == 'Có thay đổi') && 
                ($oldStatus != 'Hoàn thành' && $oldStatus != 'Có thay đổi')) {
                
                // Lấy chi tiết phiếu xuất
                $stmt = $pdo->prepare("SELECT MaSP, SLX FROM CHITIETPHIEUXUAT WHERE MaPX = ?");
                $stmt->execute([$maPX]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Trừ tồn kho cho từng sản phẩm
                foreach ($items as $item) {
                    $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ? WHERE MaSP = ?");
                    $stmt->execute([$item['SLX'], $item['MaSP']]);
                }
            }
        }
        
        $pdo->commit();
        header("Location: exports.php");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: exports.php");
        exit();
    }
}

// ============================
//  LẤY DANH SÁCH PHIẾU XUẤT
// ============================
$search = $_GET['search'] ?? '';
$where = $search ? "WHERE px.MaPX LIKE '%$search%' OR ch.TenCH LIKE '%$search%' OR tk.TenTK LIKE '%$search%'" : '';
$stmt = $pdo->query("
    SELECT px.*, ch.TenCH, tk.TenTK 
    FROM PHIEUXUAT px
    LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
    LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
    $where 
    ORDER BY px.MaPX DESC
");
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Xuất Kho - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .status-pending { color: #ff9800; font-weight: bold; }
        .status-approved { color: #4caf50; font-weight: bold; }
        .status-rejected { color: #f44336; font-weight: bold; }
        .status-completed { color: #2196f3; font-weight: bold; }
        .status-changed { color: #9c27b0; font-weight: bold; }
        .status-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }
        .btn-status-option {
            padding: 10px 20px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-status-pending { background: #ff9800; }
        .btn-status-approved { background: #4caf50; }
        .btn-status-rejected { background: #f44336; }
        .btn-status-completed { background: #2196f3; }
        .btn-status-changed { background: #9c27b0; }
        .btn-status-option:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }
        .product-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        .product-row select,
        .product-row input {
            margin: 0;
        }
        .btn-remove-product {
            background: #f44336;
            color: #fff;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
        }
        #productsList {
            max-height: 300px;
            overflow-y: auto;
        }
        .detail-info {
            margin: 10px 0;
        }
        .detail-info strong {
            display: inline-block;
            width: 150px;
        }
        .detail-items {
            margin-top: 20px;
        }
        .detail-items table {
            width: 100%;
            border-collapse: collapse;
        }
        .detail-items th,
        .detail-items td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .detail-items th {
            background: #f5f5f5;
        }
    </style>
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
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div style="background: #f44336; color: #fff; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                <?php 
                echo htmlspecialchars($_SESSION['error_message']); 
                unset($_SESSION['error_message']);
                ?>
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
                        <th>Mã PX</th>
                        <th>Ngày Xuất</th>
                        <th>Cửa Hàng</th>
                        <th>Người Lập</th>
                        <th>Tình Trạng</th>
                        <th class="actions-column">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exports as $export): 
                        $statusClass = '';
                        switch($export['TinhTrang_PX']) {
                            case 'Đang xử lý': $statusClass = 'status-pending'; break;
                            case 'Đã duyệt': $statusClass = 'status-approved'; break;
                            case 'Bị từ chối': $statusClass = 'status-rejected'; break;
                            case 'Hoàn thành': $statusClass = 'status-completed'; break;
                            case 'Có thay đổi': $statusClass = 'status-changed'; break;
                        }
                        
                        $canEdit = ($export['TinhTrang_PX'] == 'Đang xử lý') && 
                                   ($userRole == 'Quản lý' || $export['MaTK'] == $userId);
                        $canDelete = ($export['TinhTrang_PX'] == 'Đang xử lý') && ($userRole == 'Quản lý');
                        $canChangeStatus = ($userRole == 'Quản lý');
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($export['MaPX']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($export['NgayXuat'])); ?></td>
                            <td><?php echo htmlspecialchars($export['TenCH']); ?></td>
                            <td><?php echo htmlspecialchars($export['TenTK']); ?></td>
                            <td class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($export['TinhTrang_PX']); ?></td>
                            <td class="actions-column">
                                <?php if ($canEdit): ?>
                                    <button class="btn btn-edit" onclick="editExport('<?php echo $export['MaPX']; ?>')">Sửa</button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <button class="btn btn-delete" onclick="deleteExport('<?php echo $export['MaPX']; ?>')">Xóa</button>
                                <?php endif; ?>
                                <button class="btn btn-status" onclick="viewDetails('<?php echo $export['MaPX']; ?>')">Xem Chi Tiết</button>
                                <?php if ($canChangeStatus): ?>
                                    <button class="btn btn-status" onclick="changeStatus('<?php echo $export['MaPX']; ?>', '<?php echo $export['TinhTrang_PX']; ?>')">Đổi Trạng Thái</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Thêm/Sửa -->
    <div id="addModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 id="modalTitle">Thêm Phiếu Xuất</h2>
            <form method="POST" onsubmit="return submitForm(event)">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="text" name="MaPX" id="MaPX" placeholder="Mã phiếu xuất (VD: PX00001)" required>
                <input type="date" name="NgayXuat" id="NgayXuat" required>
                <select name="MaCH" id="MaCH" required>
                    <option value="">Chọn cửa hàng</option>
                </select>
                
                <h3>Danh sách sản phẩm</h3>
                <div id="productsList"></div>
                <button type="button" class="btn btn-add" onclick="addProductRow()">Thêm Sản Phẩm</button>
                
                <input type="hidden" name="products" id="productsData">
                <button type="submit" class="btn btn-add" style="margin-top: 20px;">Lưu</button>
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

    <!-- Modal Đổi Trạng Thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2>Đổi Trạng Thái Phiếu Xuất</h2>
            <p>Chọn trạng thái mới cho phiếu xuất:</p>
            <div class="status-buttons">
                <button class="btn-status-option btn-status-pending" onclick="confirmStatusChange('Đang xử lý')">Đang xử lý</button>
                <button class="btn-status-option btn-status-approved" onclick="confirmStatusChange('Đã duyệt')">Đã duyệt</button>
                <button class="btn-status-option btn-status-rejected" onclick="confirmStatusChange('Bị từ chối')">Bị từ chối</button>
                <button class="btn-status-option btn-status-completed" onclick="confirmStatusChange('Hoàn thành')">Hoàn thành</button>
                <button class="btn-status-option btn-status-changed" onclick="confirmStatusChange('Có thay đổi')">Có thay đổi</button>
            </div>
            <input type="hidden" id="statusChangeMaPX" value="">
            <input type="hidden" id="statusChangeOld" value="">
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        let availableProducts = [];
        let availableStores = [];
        let productCounter = 0;

        // Load danh sách cửa hàng và sản phẩm
        function loadStoresAndProducts() {
            fetch('exports.php?action=get_stores')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        availableStores = data.data;
                        updateStoreOptions();
                    }
                });
            
            fetch('exports.php?action=get_products')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        availableProducts = data.data;
                    }
                });
        }

        function updateStoreOptions() {
            const select = document.getElementById('MaCH');
            select.innerHTML = '<option value="">Chọn cửa hàng</option>';
            availableStores.forEach(store => {
                const option = document.createElement('option');
                option.value = store.MaCH;
                option.textContent = store.TenCH;
                select.appendChild(option);
            });
        }

        function openAddModal() {
            loadStoresAndProducts();
            document.getElementById('modalTitle').textContent = 'Thêm Phiếu Xuất';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('MaPX').disabled = false;
            document.getElementById('MaPX').value = '';
            document.getElementById('NgayXuat').value = '';
            document.getElementById('MaCH').value = '';
            document.getElementById('productsList').innerHTML = '';
            productCounter = 0;
            addProductRow();
            openModal('addModal');
        }

        function addProductRow() {
            productCounter++;
            const row = document.createElement('div');
            row.className = 'product-row';
            row.id = 'product-' + productCounter;
            
            let productOptions = '<option value="">Chọn sản phẩm</option>';
            availableProducts.forEach(product => {
                productOptions += `<option value="${product.MaSP}">${product.TenSP} (Tồn: ${product.SLTK})</option>`;
            });
            
            row.innerHTML = `
                <select class="product-select" style="flex: 2;" required>
                    ${productOptions}
                </select>
                <input type="number" class="product-quantity" placeholder="Số lượng" min="1" style="flex: 1;" required>
                <button type="button" class="btn-remove-product" onclick="removeProductRow(${productCounter})">Xóa</button>
            `;
            
            document.getElementById('productsList').appendChild(row);
        }

        function removeProductRow(id) {
            const row = document.getElementById('product-' + id);
            if (row) {
                row.remove();
            }
        }

        function submitForm(event) {
            event.preventDefault();
            
            const products = [];
            const rows = document.querySelectorAll('.product-row');
            
            rows.forEach(row => {
                const select = row.querySelector('.product-select');
                const quantity = row.querySelector('.product-quantity');
                
                if (select.value && quantity.value) {
                    products.push({
                        MaSP: select.value,
                        SLX: parseInt(quantity.value)
                    });
                }
            });
            
            if (products.length === 0) {
                alert('Vui lòng thêm ít nhất một sản phẩm');
                return false;
            }
            
            document.getElementById('productsData').value = JSON.stringify(products);
            event.target.submit();
            return true;
        }

        function editExport(maPX) {
            loadStoresAndProducts();
            
            fetch(`exports.php?action=get_details&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const exportData = data.data.export;
                        const items = data.data.items;
                        
                        document.getElementById('modalTitle').textContent = 'Sửa Phiếu Xuất';
                        document.getElementById('modalAction').value = 'edit';
                        document.getElementById('MaPX').value = exportData.MaPX;
                        document.getElementById('MaPX').disabled = true;
                        document.getElementById('NgayXuat').value = exportData.NgayXuat;
                        
                        setTimeout(() => {
                            document.getElementById('MaCH').value = exportData.MaCH;
                        }, 100);
                        
                        document.getElementById('productsList').innerHTML = '';
                        productCounter = 0;
                        
                        setTimeout(() => {
                            items.forEach(item => {
                                productCounter++;
                                const row = document.createElement('div');
                                row.className = 'product-row';
                                row.id = 'product-' + productCounter;
                                
                                let productOptions = '<option value="">Chọn sản phẩm</option>';
                                availableProducts.forEach(product => {
                                    const selected = product.MaSP === item.MaSP ? 'selected' : '';
                                    productOptions += `<option value="${product.MaSP}" ${selected}>${product.TenSP} (Tồn: ${product.SLTK})</option>`;
                                });
                                
                                row.innerHTML = `
                                    <select class="product-select" style="flex: 2;" required>
                                        ${productOptions}
                                    </select>
                                    <input type="number" class="product-quantity" placeholder="Số lượng" min="1" value="${item.SLX}" style="flex: 1;" required>
                                    <button type="button" class="btn-remove-product" onclick="removeProductRow(${productCounter})">Xóa</button>
                                `;
                                
                                document.getElementById('productsList').appendChild(row);
                            });
                        }, 200);
                        
                        openModal('addModal');
                    } else {
                        alert('Không thể tải thông tin phiếu xuất');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra khi tải thông tin phiếu xuất');
                });
        }

        function deleteExport(maPX) {
            if (confirm('Bạn có chắc chắn muốn xóa phiếu xuất này?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="MaPX" value="${maPX}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewDetails(maPX) {
            fetch(`exports.php?action=get_details&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    const detailInfo = document.getElementById('detailInfo');
                    if (data.success) {
                        const exportData = data.data.export;
                        const items = data.data.items;
                        
                        let itemsHtml = '<table><thead><tr><th>Mã SP</th><th>Tên SP</th><th>SL Xuất</th><th>Tồn Kho Hiện Tại</th></tr></thead><tbody>';
                        items.forEach(item => {
                            itemsHtml += `
                                <tr>
                                    <td>${item.MaSP}</td>
                                    <td>${item.TenSP}</td>
                                    <td>${item.SLX}</td>
                                    <td>${item.SLTK}</td>
                                </tr>
                            `;
                        });
                        itemsHtml += '</tbody></table>';
                        
                        detailInfo.innerHTML = `
                            <div class="detail-info">
                                <p><strong>Mã phiếu xuất:</strong> ${exportData.MaPX}</p>
                                <p><strong>Ngày xuất:</strong> ${new Date(exportData.NgayXuat).toLocaleDateString('vi-VN')}</p>
                                <p><strong>Cửa hàng:</strong> ${exportData.TenCH}</p>
                                <p><strong>Người lập:</strong> ${exportData.TenTK}</p>
                                <p><strong>Tình trạng:</strong> ${exportData.TinhTrang_PX}</p>
                            </div>
                            <div class="detail-items">
                                <h3>Chi tiết sản phẩm</h3>
                                ${itemsHtml}
                            </div>
                        `;
                    } else {
                        detailInfo.innerText = 'Không thể lấy thông tin chi tiết';
                    }
                    openModal('detailModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('detailInfo').innerText = 'Có lỗi xảy ra khi lấy thông tin chi tiết';
                    openModal('detailModal');
                });
        }

        function changeStatus(maPX, currentStatus) {
            document.getElementById('statusChangeMaPX').value = maPX;
            document.getElementById('statusChangeOld').value = currentStatus;
            openModal('statusModal');
        }

        function confirmStatusChange(newStatus) {
            const maPX = document.getElementById('statusChangeMaPX').value;
            const oldStatus = document.getElementById('statusChangeOld').value;
            
            if (newStatus === oldStatus) {
                alert('Trạng thái mới giống trạng thái hiện tại');
                return;
            }
            
            let confirmMessage = `Bạn có chắc chắn muốn đổi trạng thái phiếu xuất từ "${oldStatus}" sang "${newStatus}"?`;
            
            if (newStatus === 'Hoàn thành' || newStatus === 'Có thay đổi') {
                confirmMessage += '\n\nLưu ý: Hệ thống sẽ tự động trừ số lượng tồn kho!';
            }
            
            if (confirm(confirmMessage)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="MaPX" value="${maPX}">
                    <input type="hidden" name="TinhTrang_PX" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Load dữ liệu khi trang được tải
        document.addEventListener('DOMContentLoaded', function() {
            loadStoresAndProducts();
        });
    </script>
</body>
</html>
