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

// Hàm tạo mã phiếu xuất tự động
function generateMaPX($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaPX, 3) AS UNSIGNED)) as max_id FROM PHIEUXUAT");
    $result = $stmt->fetch();
    $next_id = $result['max_id'] + 1;
    return 'PX' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

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

        // Lấy chi tiết sản phẩm (thêm SLX_MOI)
        $stmt = $pdo->prepare("
            SELECT ct.MaCTPX, ct.MaSP, ct.SLX, ct.SLX_MOI, sp.TenSP, sp.TheLoai, sp.SLTK
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
            $maPX = generateMaPX($pdo); // Tạo mã phiếu xuất tự động
            $ngayXuat = $_POST['NgayXuat'];
            $maCH = $_POST['MaCH'];
            $maTK = $_POST['MaTK'] ?? $userId;
            $tinhTrang = $_POST['TinhTrang_PX'] ?? 'Đang xử lý';
            
            // Tạo phiếu xuất
            $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$maPX, $ngayXuat, $maCH, $maTK, $tinhTrang]);
            
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
            
            $maCH = $_POST['MaCH'];
            
            // Cập nhật phiếu xuất (chỉ sửa Cửa Hàng, không sửa Ngày Xuất và Người Xuất)
            $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET MaCH=? WHERE MaPX=?");
            $stmt->execute([$maCH, $maPX]);
            
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
                header("Location: exports.php?error=Phiếu đã được xử lí không thể xóa");
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
            
        } elseif ($action == 'edit_detail') {
            // Sửa chi tiết phiếu xuất - Cho phép sửa: Cửa hàng, SP, SLX
            $maPX = $_POST['MaPX'] ?? '';
            $maCH = $_POST['MaCH'] ?? '';
            $maCTPXs = $_POST['MaCTPX'] ?? [];
            $maSPs = $_POST['MaSP'] ?? [];
            $slxs = $_POST['SLX'] ?? [];
            
            // Kiểm tra trạng thái phiếu xuất - chỉ cho sửa khi "Đang xử lý"
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $phieu = $stmt->fetch();
            
            if ($phieu && $phieu['TinhTrang_PX'] === 'Đang xử lý') {
                // Cập nhật Cửa hàng của phiếu xuất
                if (!empty($maCH)) {
                    $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET MaCH = ? WHERE MaPX = ?");
                    $stmt->execute([$maCH, $maPX]);
                }
                
                // Cập nhật chi tiết sản phẩm (SP và SLX)
                foreach ($maCTPXs as $index => $maCTPX) {
                    $maSP = $maSPs[$index] ?? '';
                    $slx = $slxs[$index] ?? '';
                    if (!empty($maSP) && !empty($slx)) {
                        $stmt = $pdo->prepare("UPDATE CHITIETPHIEUXUAT SET MaSP = ?, SLX = ? WHERE MaCTPX = ? AND MaPX = ?");
                        $stmt->execute([$maSP, $slx, $maCTPX, $maPX]);
                    }
                }
            }
            
        } elseif ($action == 'adjustment') {
            // Xử lý nhập SLX_MOI và trừ khỏi SLTK
            header('Content-Type: application/json');
            $maPX = $_POST['MaPX'] ?? '';
            $maCTPXs = $_POST['MaCTPX'] ?? [];
            $slx_mois = $_POST['SLX_MOI'] ?? [];

            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $phieu = $stmt->fetch();

            if ($phieu && $phieu['TinhTrang_PX'] === 'Có thay đổi') {
                try {
                    $pdo->beginTransaction();
                    
                    // Kiểm tra tồn kho trước khi trừ
                    foreach ($maCTPXs as $index => $maCTPX) {
                        $slx_moi = $slx_mois[$index] ?? 0;
                        
                        $stmt_sp = $pdo->prepare("SELECT ct.MaSP, sp.SLTK FROM CHITIETPHIEUXUAT ct JOIN SANPHAM sp ON ct.MaSP = sp.MaSP WHERE ct.MaCTPX = ?");
                        $stmt_sp->execute([$maCTPX]);
                        $sp = $stmt_sp->fetch();
                        
                        if ($sp && $sp['SLTK'] < $slx_moi) {
                            throw new Exception("Sản phẩm {$sp['MaSP']} không đủ tồn kho. Hiện tại: {$sp['SLTK']}, cần: {$slx_moi}");
                        }
                    }
                    
                    // Nếu đủ tồn kho, tiến hành cập nhật
                    foreach ($maCTPXs as $index => $maCTPX) {
                        $slx_moi = $slx_mois[$index] ?? 0;
                        $stmt = $pdo->prepare("UPDATE CHITIETPHIEUXUAT SET SLX_MOI = ? WHERE MaCTPX = ? AND MaPX = ?");
                        $stmt->execute([$slx_moi, $maCTPX, $maPX]);

                        // Trừ SLX_MOI khỏi SLTK (không phải SLX ban đầu)
                        $stmt_sp = $pdo->prepare("SELECT MaSP FROM CHITIETPHIEUXUAT WHERE MaCTPX = ?");
                        $stmt_sp->execute([$maCTPX]);
                        $sp = $stmt_sp->fetch();
                        if ($sp) {
                            $stmt_update = $pdo->prepare("
                                UPDATE SANPHAM 
                                SET SLTK = SLTK - ?, 
                                    TinhTrang = CASE WHEN SLTK - ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END 
                                WHERE MaSP = ?
                            ");
                            $stmt_update->execute([$slx_moi, $slx_moi, $sp['MaSP']]);
                        }
                    }
                    $pdo->commit();
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Không hợp lệ']);
            }
            exit();
            
        } elseif ($action == 'update_status') {
            // Cập nhật trạng thái - chuyển thành AJAX
            header('Content-Type: application/json');
            
            if ($userRole != 'Quản lý') {
                echo json_encode(['success' => false, 'error' => 'Bạn không có quyền cập nhật trạng thái']);
                exit();
            }
            
            $maPX = $_POST['MaPX'] ?? '';
            $tinhTrangMoi = $_POST['TinhTrang'] ?? '';
            
            // Danh sách trạng thái hợp lệ
            $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];
            $finalStatuses = ['Hoàn thành', 'Có thay đổi']; // Chỉ 2 trạng thái này không được đổi
            
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $currentStatus = $stmt->fetchColumn();
            
            // Nếu trạng thái hiện tại là trạng thái cuối cùng, không cho phép đổi
            if (in_array($currentStatus, $finalStatuses)) {
                echo json_encode(['success' => false, 'error' => 'Phiếu xuất này đã được khóa và không thể thay đổi trạng thái']);
                exit();
            }
            
            if (in_array($tinhTrangMoi, $validStatuses)) {
                try {
                    $pdo->beginTransaction();
                    
                    // Lấy trạng thái cũ của phiếu xuất
                    $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
                    $stmt->execute([$maPX]);
                    $oldStatus = $stmt->fetchColumn();
                    
                    // Cập nhật trạng thái mới cho phiếu xuất
                    $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET TinhTrang_PX = ? WHERE MaPX = ?");
                    $stmt->execute([$tinhTrangMoi, $maPX]);

                    // Nếu chuyển sang trạng thái "Hoàn thành"
                    if ($tinhTrangMoi == 'Hoàn thành' && !in_array($oldStatus, ['Hoàn thành', 'Có thay đổi'])) {
                        // Cập nhật số lượng tồn kho cho từng sản phẩm trong phiếu xuất (trừ SLX gốc)
                        $stmt = $pdo->prepare("
                            UPDATE SANPHAM sp
                            INNER JOIN CHITIETPHIEUXUAT ct ON sp.MaSP = ct.MaSP
                            SET 
                                sp.SLTK = sp.SLTK - ct.SLX,
                                sp.TinhTrang = CASE 
                                    WHEN (sp.SLTK - ct.SLX) > 0 THEN 'Còn hàng'
                                    ELSE 'Hết hàng'
                                END
                            WHERE ct.MaPX = ?
                        ");
                        $stmt->execute([$maPX]);
                    }
                    
                    $pdo->commit();
                    echo json_encode(['success' => true, 'newStatus' => $tinhTrangMoi, 'needsAdjustment' => ($tinhTrangMoi == 'Có thay đổi')]);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Trạng thái không hợp lệ']);
            }
            exit();
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
$where = $search ? "WHERE px.MaPX LIKE '%$search%' OR ch.TenCH LIKE '%$search%' OR sp.TenSP LIKE '%$search%' OR tk.TenTK LIKE '%$search%' OR px.NgayXuat LIKE '%$search%' OR px.TinhTrang_PX LIKE '%$search%'" : '';
$stmt = $pdo->query("
    SELECT 
        px.MaPX,
        px.NgayXuat,
        px.TinhTrang_PX,
        ch.MaCH,
        ch.TenCH,
        tk.TenTK,
        tk.MaTK,
        sp.TenSP,
        ct.SLX,
        ct.SLX_MOI,
        ct.MaCTPX,
        ct.MaSP
    FROM PHIEUXUAT px
    LEFT JOIN CUAHANG ch ON px.MaCH = ch.MaCH
    LEFT JOIN TAIKHOAN tk ON px.MaTK = tk.MaTK
    LEFT JOIN CHITIETPHIEUXUAT ct ON px.MaPX = ct.MaPX
    LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
    $where
    ORDER BY px.MaPX DESC, sp.TenSP
");
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nhóm dữ liệu theo phiếu xuất
$groupedExports = [];
foreach ($exports as $row) {
    if (!isset($groupedExports[$row['MaPX']])) {
        $groupedExports[$row['MaPX']] = [
            'info' => [
                'MaPX' => $row['MaPX'],
                'NgayXuat' => $row['NgayXuat'],
                'TenCH' => $row['TenCH'],
                'TenTK' => $row['TenTK'],
                'MaTK' => $row['MaTK'],
                'TinhTrang_PX' => $row['TinhTrang_PX']
            ],
            'details' => []
        ];
    }
    if ($row['TenSP']) {  // Chỉ thêm vào details nếu có sản phẩm
        $groupedExports[$row['MaPX']]['details'][] = [
            'TenSP' => $row['TenSP'],
            'SLX' => $row['SLX'],
            'SLX_MOI' => $row['SLX_MOI'] ?? null,
            'MaCTPX' => $row['MaCTPX'],
            'MaSP' => $row['MaSP']
        ];
    }
}

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
    <style>
        /* CSS để căn giữa modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 5px;
            position: relative;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
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
                        <th style="width: 100px">Mã PX</th>
                        <th style="width: 120px">Ngày Xuất</th>
                        <th style="width: 150px">Cửa Hàng</th>
                        <th style="width: 150px">Người Xuất</th>
                        <th>Sản Phẩm</th>
                        <th style="width: 100px">Số Lượng</th>
                        <th style="width: 120px">Số lượng mới</th>
                        <th style="width: 120px">Tình Trạng</th>
                        <th class="actions-column">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $finalStatuses = ['Hoàn thành', 'Có thay đổi']; // Chỉ 2 trạng thái này không được đổi
                    $mutableStatuses = ['Đang xử lý']; // Chỉ cho sửa khi trạng thái 'Đang xử lý'
                    ?>
                    <?php foreach ($groupedExports as $maPX => $export): ?>
                        <?php 
                        $rowspan = max(1, count($export['details']));
                        $canEdit = in_array($export['info']['TinhTrang_PX'], $mutableStatuses) && 
                                  ($userRole == 'Quản lý' || $export['info']['MaTK'] == $userId);
                        $isLocked = in_array($export['info']['TinhTrang_PX'], $finalStatuses);
                        ?>
                        <?php if (empty($export['details'])): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($export['info']['MaPX']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($export['info']['NgayXuat'])); ?></td>
                                <td><?php echo htmlspecialchars($export['info']['TenCH']); ?></td>
                                <td><?php echo htmlspecialchars($export['info']['TenTK']); ?></td>
                                <td><em>Chưa có sản phẩm</em></td>
                                <td><em>0</em></td>
                                <td><em>-</em></td>
                                <td><?php echo htmlspecialchars($export['info']['TinhTrang_PX']); ?></td>
                                <td class="actions-column">
                                    <?php if ($canEdit): ?>
                                        <button class="btn btn-edit" onclick="editExport('<?php echo $export['info']['MaPX']; ?>')">Sửa</button>
                                    <?php else: ?>
                                        <button class="btn btn-edit" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                    <?php endif; ?>
                                    <button class="btn btn-delete" onclick="deleteExport('<?php echo $export['info']['MaPX']; ?>')">Xóa</button>
                                    <?php if ($userRole == 'Quản lý'): ?>
                                        <?php if ($isLocked): ?>
                                            <button class="btn btn-status" disabled title="Phiếu xuất này đã bị khóa">Đổi trạng thái</button>
                                        <?php else: ?>
                                            <button class="btn btn-status" onclick="changeStatus('<?php echo $export['info']['MaPX']; ?>')">Đổi trạng thái</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($export['details'] as $index => $detail): ?>
                                <tr>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($export['info']['MaPX']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo date('d/m/Y', strtotime($export['info']['NgayXuat'])); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($export['info']['TenCH']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($export['info']['TenTK']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($detail['TenSP']); ?></td>
                                    <td><?php echo htmlspecialchars($detail['SLX']); ?> cái</td>
                                    <td><?php echo ($detail['SLX_MOI'] !== null) ? $detail['SLX_MOI'] . ' cái' : (($export['info']['TinhTrang_PX'] === 'Có thay đổi') ? 'Chưa cập nhật' : '-'); ?></td>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($export['info']['TinhTrang_PX']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>" class="actions-column">
                                            <?php if ($canEdit): ?>
                                                <button class="btn btn-edit" onclick="editExportDetail('<?php echo $export['info']['MaPX']; ?>')">Sửa</button>
                                            <?php else: ?>
                                                <button class="btn btn-edit" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                            <?php endif; ?>
                                            <button class="btn btn-delete" onclick="deleteExport('<?php echo $export['info']['MaPX']; ?>')">Xóa</button>
                                            <?php if ($userRole == 'Quản lý'): ?>
                                                <?php if ($isLocked): ?>
                                                    <button class="btn btn-status" disabled title="Phiếu xuất này đã bị khóa">Đổi trạng thái</button>
                                                <?php else: ?>
                                                    <button class="btn btn-status" onclick="changeStatus('<?php echo $export['info']['MaPX']; ?>')">Đổi trạng thái</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <label>Mã Phiếu Xuất:</label>
                        <?php 
                        $nextMaPX = generateMaPX($pdo);
                        ?>
                        <input type="text" id="MaPX" value="<?php echo $nextMaPX; ?>" disabled 
                               style="width: 100%; padding: 8px; background-color: #f0f0f0;">
                    </div>
                    <div>
                        <label>Ngày Xuất:</label>
                        <input type="date" name="NgayXuat" id="NgayXuat" required style="width: 100%; padding: 8px;">
                    </div>
                    <div>
                        <label>Cửa Hàng:</label>
                        <select name="MaCH" id="MaCH" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn cửa hàng</option>
                            <?php foreach ($cuaHangs as $ch): ?>
                                <option value="<?php echo $ch['MaCH']; ?>"><?php echo htmlspecialchars($ch['TenCH']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Người Xuất:</label>
                        <select name="MaTK" id="MaTK" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn người xuất</option>
                            <?php
                            $users = $pdo->query("SELECT MaTK, TenTK FROM TAIKHOAN")->fetchAll();
                            foreach ($users as $user) {
                                $selected = ($user['MaTK'] == $userId) ? 'selected' : '';
                                echo "<option value='{$user['MaTK']}' {$selected}>{$user['TenTK']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label>Tình Trạng:</label>
                        <select name="TinhTrang_PX" id="TinhTrang_PX" required style="width: 100%; padding: 8px;">
                            <option value="Đang xử lý" selected>Đang xử lý</option>
                            <option value="Đã duyệt">Đã duyệt</option>
                            <option value="Bị từ chối">Bị từ chối</option>
                            <option value="Hoàn thành">Hoàn thành</option>
                            <option value="Có thay đổi">Có thay đổi</option>
                        </select>
                    </div>
                </div>

                <h3>Chi Tiết Sản Phẩm</h3>
                <div id="productsList">
                    <div class="product-row" style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <select class="product-select" required style="width: 100%; padding: 8px;">
                                <option value="">Chọn sản phẩm</option>
                                <?php foreach ($sanPhams as $sp): ?>
                                    <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                        <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <input type="number" class="product-quantity" placeholder="Số lượng" min="1" required style="width: 100%; padding: 8px;">
                        </div>
                        <div>
                            <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="padding: 8px;">×</button>
                        </div>
                    </div>
                </div>
                
                <button type="button" onclick="addProductRow()" class="btn btn-add" style="margin: 10px 0;">+ Thêm sản phẩm</button>
                
                <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                    <input type="hidden" name="products" id="productsData">
                    <button type="submit" class="btn btn-add" onclick="return validateForm()">Lưu phiếu xuất</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('addModal')">Hủy</button>
                </div>
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

    <!-- Modal sửa chi tiết phiếu xuất -->
    <div id="editDetailModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editDetailModal')">&times;</span>
            <h2>Sửa Chi Tiết Phiếu Xuất</h2>
            
            <div style="background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label>Mã Phiếu Xuất:</label>
                        <input type="text" id="editMaPX" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Ngày Xuất:</label>
                        <input type="text" id="editNgayXuat" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Cửa Hàng: <span style="color: red;">*</span></label>
                        <select id="editMaCH" name="MaCH" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn cửa hàng</option>
                            <?php foreach ($cuaHangs as $ch): ?>
                                <option value="<?php echo $ch['MaCH']; ?>"><?php echo htmlspecialchars($ch['TenCH']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tình Trạng:</label>
                        <input type="text" id="editTinhTrang" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                </div>
            </div>

            <h3>Chi Tiết Sản Phẩm</h3>
            <div id="detailsList" style="max-height: 400px; overflow-y: auto;">
                <!-- Sẽ được load bằng JavaScript -->
            </div>
        </div>
    </div>

    <!-- Modal đổi trạng thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2 style="color: #1976d2; margin-bottom: 10px; font-size: 24px;">Đổi Trạng Thái Phiếu Xuất</h2>
            <p id="statusMaPX" style="margin-bottom: 25px; font-weight: 600; color: #ff9800; font-size: 16px; padding: 10px; background-color: #fff3e0; border-radius: 5px; border-left: 4px solid #ff9800;"></p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <button class="status-btn" onclick="updateStatus('Đang xử lý')" style="background-color: #ff9800; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ⏳ Đang xử lý
                </button>
                
                <button class="status-btn" onclick="updateStatus('Đã duyệt')" style="background-color: #4caf50; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ✓ Đã duyệt
                </button>
                
                <button class="status-btn" onclick="updateStatus('Bị từ chối')" style="background-color: #f44336; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';"> 
                    ✗ Bị từ chối
                </button>
                
                <button class="status-btn" onclick="updateStatus('Hoàn thành')" style="background-color: #2196f3; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ✓ Hoàn thành
                </button>
                
                <button class="status-btn" onclick="updateStatus('Có thay đổi')" style="background-color: #ff6f00; padding: 16px; font-size: 15px; font-weight: 600; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'; this.style.transform='translateY(0)';">
                    ⚠ Có thay đổi
                </button>
            </div>
        </div>
    </div>

    <!-- Modal nhập số lượng mới khi trạng thái "Có thay đổi" -->
    <div id="adjustmentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('adjustmentModal')">&times;</span>
            <h2>Nhập Số Lượng Mới</h2>
            <p id="adjustmentMaPX" style="margin-bottom: 20px; font-weight: 600; color: #ff9800;"></p>
            
            <div id="adjustmentDetails" style="max-height: 400px; overflow-y: auto;">
                <!-- Sẽ được load bằng JavaScript -->
            </div>
            
            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                <button type="button" id="saveAdjustmentBtn" onclick="saveAdjustment()" class="btn btn-add">Lưu và Cập Nhật Kho</button>
                <button type="button" class="btn btn-cancel" onclick="closeModal('adjustmentModal')">Hủy</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Thêm Phiếu Xuất';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('phieuForm').reset();
            
            // Reset danh sách sản phẩm về 1 dòng
            document.getElementById('productsList').innerHTML = `
                <div class="product-row" style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <select class="product-select" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn sản phẩm</option>
                            <?php foreach ($sanPhams as $sp): ?>
                                <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                    <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <input type="number" class="product-quantity" placeholder="Số lượng" min="1" required style="width: 100%; padding: 8px;">
                    </div>
                    <div>
                        <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="padding: 8px;">×</button>
                    </div>
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
                        
                        // Hiển thị thông tin chỉ đọc
                        document.getElementById('readonlyInfo').style.display = 'block';
                        document.getElementById('infoNguoiLap').innerText = phieu.TenTK || 'N/A';
                        document.getElementById('infoTrangThai').innerText = phieu.TinhTrang_PX;
                        
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

        let statusChangeMaPX = null;

        function editExport(maPX) {
            // TODO: implement if needed
        }

        function editExportDetail(maPX) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const phieu = data.data.phieu;
                        const chiTiet = data.data.chiTiet;
                        
                        // Điền thông tin phiếu xuất
                        document.getElementById('editMaPX').value = phieu.MaPX;
                        document.getElementById('editNgayXuat').value = new Date(phieu.NgayXuat).toLocaleDateString('vi-VN');
                        document.getElementById('editMaCH').value = phieu.MaCH; // Cho phép chọn cửa hàng mới
                        document.getElementById('editTinhTrang').value = phieu.TinhTrang_PX;
                        
                        // Tạo form cho tất cả chi tiết sản phẩm
                        const detailsList = document.getElementById('detailsList');
                        detailsList.innerHTML = `
                            <form method="POST" id="editDetailsForm">
                                <input type="hidden" name="action" value="edit_detail">
                                <input type="hidden" name="MaPX" value="${phieu.MaPX}">
                                <input type="hidden" name="MaCH" id="hiddenMaCH">
                                
                                <div id="productsList" style="margin-bottom: 20px;">
                                    ${chiTiet.map((detail, index) => `
                                        <div style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                            <input type="hidden" name="MaCTPX[]" value="${detail.MaCTPX}">
                                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px;">
                                                <div>
                                                    <label>Sản Phẩm:</label>
                                                    <select name="MaSP[]" required style="width: 100%; padding: 10px;">
                                                        <option value="">Chọn sản phẩm</option>
                                                        <?php
                                                        $products = $pdo->query("SELECT MaSP, TenSP FROM SANPHAM")->fetchAll();
                                                        foreach ($products as $product) {
                                                            echo "<option value='{$product['MaSP']}'>{$product['TenSP']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label>Số Lượng:</label>
                                                    <input type="number" name="SLX[]" min="1" value="${detail.SLX}" required style="width: 100%; padding: 10px;">
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                                
                                <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                                    <button type="submit" class="btn btn-add" style="padding: 10px 30px;">Lưu tất cả</button>
                                    <button type="button" class="btn btn-cancel" onclick="closeModal('editDetailModal')" style="padding: 10px 30px;">Hủy</button>
                                </div>
                            </form>
                        `;
                        
                        // Set giá trị cho các select và input
                        chiTiet.forEach((detail, index) => {
                            const selects = document.getElementsByName('MaSP[]');
                            const inputs = document.getElementsByName('SLX[]');
                            if (selects[index]) selects[index].value = detail.MaSP;
                            if (inputs[index]) inputs[index].value = detail.SLX;
                        });
                        
                        // Đồng bộ giá trị Cửa hàng từ dropdown vào hidden input khi submit
                        const form = document.getElementById('editDetailsForm');
                        form.onsubmit = function() {
                            document.getElementById('hiddenMaCH').value = document.getElementById('editMaCH').value;
                        };
                        
                        openModal('editDetailModal');
                    } else {
                        alert('Không thể tải dữ liệu');
                    }
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể tải dữ liệu');
                });
        }

        function deleteExport(maPX) {
            if (confirm('Bạn có chắc chắn muốn xóa phiếu xuất này?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaPX" value="${maPX}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function changeStatus(maPX) {
            statusChangeMaPX = maPX;
            document.getElementById('statusMaPX').innerText = `Phiếu xuất: ${maPX}`;
            openModal('statusModal');
        }

        function updateStatus(newStatus) {
            if (statusChangeMaPX) {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('MaPX', statusChangeMaPX);
                formData.append('TinhTrang', newStatus);

                fetch('exports.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeModal('statusModal');
                        
                        if (data.needsAdjustment) {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}". Vui lòng nhập số lượng mới cho từng sản phẩm.`);
                            openAdjustmentModal(statusChangeMaPX);
                        } else {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}"`);
                            location.reload();
                        }
                    } else {
                        alert('Lỗi: ' + (data.error || 'Không thể cập nhật trạng thái'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Có lỗi xảy ra');
                });
            }
        }

        function openAdjustmentModal(maPX) {
            statusChangeMaPX = maPX;
            document.getElementById('adjustmentMaPX').innerText = `Phiếu xuất: ${maPX} - Nhập số lượng mới cho từng sản phẩm`;
            
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const adjustmentDetails = document.getElementById('adjustmentDetails');
                        adjustmentDetails.innerHTML = `
                            <form id="adjustmentForm">
                                <input type="hidden" name="action" value="adjustment">
                                <input type="hidden" name="MaPX" value="${maPX}">
                                ${data.data.chiTiet.map((detail, index) => `
                                    <div style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                        <h4>${detail.TenSP}</h4>
                                        <p>Số lượng ban đầu: ${detail.SLX} cái</p>
                                        <label>Số lượng mới (sẽ trừ khỏi tồn kho):</label>
                                        <input type="number" name="SLX_MOI[]" min="0" value="${detail.SLX_MOI || ''}" required style="width: 100%; padding: 8px; margin-bottom: 10px;">
                                        <input type="hidden" name="MaCTPX[]" value="${detail.MaCTPX}">
                                    </div>
                                `).join('')}
                            </form>
                        `;
                        openModal('adjustmentModal');
                    } else {
                        alert('Không thể tải chi tiết');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Không thể tải chi tiết');
                });
        }

        function saveAdjustment() {
            if (statusChangeMaPX) {
                const formData = new FormData(document.getElementById('adjustmentForm'));
                fetch('exports.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Đã lưu số lượng mới và cập nhật tồn kho thành công!');
                        closeModal('adjustmentModal');
                        location.reload();
                    } else {
                        alert('Lỗi khi lưu: ' + (data.error || 'Không xác định'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Không thể lưu');
                });
            }
        }

        function addProductRow() {
            const productsList = document.getElementById('productsList');
            const newRow = document.createElement('div');
            newRow.className = 'product-row';
            newRow.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;';
            newRow.innerHTML = `
                <div>
                    <select class="product-select" required style="width: 100%; padding: 8px;">
                        <option value="">Chọn sản phẩm</option>
                        <?php foreach ($sanPhams as $sp): ?>
                            <option value="<?php echo $sp['MaSP']; ?>" data-sltk="<?php echo $sp['SLTK']; ?>">
                                <?php echo htmlspecialchars($sp['TenSP']) . ' (Tồn: ' . $sp['SLTK'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <input type="number" class="product-quantity" placeholder="Số lượng" min="1" required style="width: 100%; padding: 8px;">
                </div>
                <div>
                    <button type="button" class="btn btn-delete" onclick="removeProductRow(this)" style="padding: 8px;">×</button>
                </div>
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

        // Hàm giả định từ script.js (nếu chưa có)
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
    </script>
</body>
</html>