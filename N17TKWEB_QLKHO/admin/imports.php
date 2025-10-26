<?php
// admin/imports.php - Trang quản lý phiếu nhập
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';

$finalStatuses = ['Hoàn thành', 'Có thay đổi', 'Bị từ chối'];
$mutableStatuses = ['Đang xử lý', 'Đã duyệt'];

// Hàm tạo mã phiếu nhập tự động
function generateMaPN($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaPN, 3) AS UNSIGNED)) as max_id FROM PHIEUNHAP");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'PN' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// Hàm tạo mã chi tiết phiếu nhập tự động
function generateMaCTPN($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaCTPN, 5) AS UNSIGNED)) as max_id FROM CHITIETPHIEUNHAP");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'CTPN' . str_pad($next_id, 3, '0', STR_PAD_LEFT);
}

// ============================
//  XỬ LÝ THÊM / SỬA / XÓA
// ============================
if (isset($_POST['action']) && !empty($_POST['action'])) {
    $action = $_POST['action'];
    if ($action == 'add' || $action == 'edit') {
        $maPN = $_POST['MaPN'] ?? '';
        $ngayNhap = $_POST['NgayNhap'] ?? '';
        $maTK = $_POST['MaTK'] ?? '';
        $tinhTrang = $_POST['TinhTrang_PN'] ?? 'Đang xử lý';
        $maSPs = $_POST['MaSP'] ?? [];
        $slns = $_POST['SLN'] ?? [];

        if ($action == 'add') {
            try {
                $pdo->beginTransaction();

                // Thêm phiếu nhập
                $maPN = generateMaPN($pdo);
                $stmt = $pdo->prepare("INSERT INTO PHIEUNHAP (MaPN, NgayNhap, MaTK, TinhTrang_PN) VALUES (?, ?, ?, ?)");
                $stmt->execute([$maPN, $ngayNhap, $maTK, $tinhTrang]);

                // Thêm chi tiết sản phẩm
                foreach ($maSPs as $index => $maSP) {
                    if (!empty($maSP) && !empty($slns[$index])) {
                        // Kiểm tra trùng sản phẩm trong cùng phiếu nhập
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM CHITIETPHIEUNHAP WHERE MaPN = ? AND MaSP = ?");
                        $stmt->execute([$maPN, $maSP]);
                        if ($stmt->fetchColumn() > 0) {
                            throw new Exception("Sản phẩm $maSP đã tồn tại trong phiếu nhập $maPN");
                        }

                        // Sinh MaCTPN duy nhất
                        $maCTPN = generateMaCTPN($pdo);

                        // Thêm chi tiết phiếu nhập
                        $stmt = $pdo->prepare("INSERT INTO CHITIETPHIEUNHAP (MaCTPN, MaPN, MaSP, SLN) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$maCTPN, $maPN, $maSP, $slns[$index]]);

                        // Cập nhật số lượng tồn kho
                        $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK + ?, TinhTrang = CASE WHEN SLTK + ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                        $stmt->execute([$slns[$index], $slns[$index], $maSP]);
                    }
                }

                $pdo->commit();
                header("Location: imports.php?success=Thêm phiếu nhập thành công");
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: imports.php?error=" . urlencode($e->getMessage()));
            }
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE PHIEUNHAP SET NgayNhap=?, MaTK=?, TinhTrang_PN=? WHERE MaPN=?");
                $stmt->execute([$ngayNhap, $maTK, $tinhTrang, $maPN]);
                $pdo->commit();
                header("Location: imports.php?success=Sửa phiếu nhập thành công");
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: imports.php?error=" . urlencode($e->getMessage()));
            }
        }
    } elseif ($action == 'delete') {
        $maPN = $_POST['MaPN'] ?? '';
        try {
            $pdo->beginTransaction();

            // Lấy danh sách chi tiết phiếu nhập để cập nhật tồn kho
            $stmt = $pdo->prepare("SELECT MaSP, SLN FROM CHITIETPHIEUNHAP WHERE MaPN = ?");
            $stmt->execute([$maPN]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Trừ số lượng tồn kho trong SANPHAM
            foreach ($details as $detail) {
                $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ?, TinhTrang = CASE WHEN SLTK - ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                $stmt->execute([$detail['SLN'], $detail['SLN'], $detail['MaSP']]);
            }

            // Xóa chi tiết phiếu nhập
            $stmt = $pdo->prepare("DELETE FROM CHITIETPHIEUNHAP WHERE MaPN = ?");
            $stmt->execute([$maPN]);

            // Xóa phiếu nhập
            $stmt = $pdo->prepare("DELETE FROM PHIEUNHAP WHERE MaPN = ?");
            $stmt->execute([$maPN]);

            $pdo->commit();
            header("Location: imports.php?success=Xóa phiếu nhập thành công");
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: imports.php?error=" . urlencode($e->getMessage()));
        }
    } elseif ($action == 'edit_detail') {
        $maPN = $_POST['MaPN'] ?? '';
        $maCTPNs = $_POST['MaCTPN'] ?? [];
        $maSPs = $_POST['MaSP'] ?? [];
        $slns = $_POST['SLN'] ?? [];

        // Kiểm tra trạng thái phiếu nhập - chỉ cho sửa khi "Đang xử lý"
        $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
        $stmt->execute([$maPN]);
        $import = $stmt->fetch();

        if ($import && $import['TinhTrang_PN'] === 'Đang xử lý') {
            try {
                $pdo->beginTransaction();
                foreach ($maCTPNs as $index => $maCTPN) {
                    $maSP = $maSPs[$index] ?? '';
                    $sln = $slns[$index] ?? '';
                    if (!empty($maSP) && !empty($sln)) {
                        // Kiểm tra xem MaSP mới có trùng với bản ghi khác trong cùng MaPN không
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM CHITIETPHIEUNHAP WHERE MaPN = ? AND MaSP = ? AND MaCTPN != ?");
                        $stmt->execute([$maPN, $maSP, $maCTPN]);
                        if ($stmt->fetchColumn() > 0) {
                            throw new Exception("Sản phẩm $maSP đã tồn tại trong phiếu nhập $maPN");
                        }

                        // Cập nhật số lượng cũ và mới trong SANPHAM
                        $stmt = $pdo->prepare("SELECT MaSP, SLN FROM CHITIETPHIEUNHAP WHERE MaCTPN = ? AND MaPN = ?");
                        $stmt->execute([$maCTPN, $maPN]);
                        $oldDetail = $stmt->fetch();
                        if ($oldDetail) {
                            $oldMaSP = $oldDetail['MaSP'];
                            $oldSLN = $oldDetail['SLN'];

                            // Trừ số lượng cũ khỏi SLTK
                            $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ?, TinhTrang = CASE WHEN SLTK - ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                            $stmt->execute([$oldSLN, $oldSLN, $oldMaSP]);

                            // Cập nhật chi tiết phiếu nhập
                            $stmt = $pdo->prepare("UPDATE CHITIETPHIEUNHAP SET MaSP = ?, SLN = ? WHERE MaCTPN = ? AND MaPN = ?");
                            $stmt->execute([$maSP, $sln, $maCTPN, $maPN]);

                            // Cộng số lượng mới vào SLTK
                            $stmt = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK + ?, TinhTrang = CASE WHEN SLTK + ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END WHERE MaSP = ?");
                            $stmt->execute([$sln, $sln, $maSP]);
                        }
                    }
                }
                $pdo->commit();
                header("Location: imports.php?success=Sửa chi tiết phiếu nhập thành công");
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: imports.php?error=" . urlencode($e->getMessage()));
            }
        } else {
            header("Location: imports.php?error=Chỉ sửa được khi trạng thái là Đang xử lý");
        }
    } elseif ($action == 'adjustment') {
        // Xử lý nhập SLN_MOI và cộng vào SLTK
        header('Content-Type: application/json');
        $maPN = $_POST['MaPN'] ?? '';
        $maCTPNs = $_POST['MaCTPN'] ?? [];
        $sln_mois = $_POST['SLN_MOI'] ?? [];

        $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
        $stmt->execute([$maPN]);
        $import = $stmt->fetch();

        if ($import && $import['TinhTrang_PN'] === 'Có thay đổi') {
            try {
                $pdo->beginTransaction();
                foreach ($maCTPNs as $index => $maCTPN) {
                    $sln_moi = $sln_mois[$index] ?? 0;
                    $stmt = $pdo->prepare("UPDATE CHITIETPHIEUNHAP SET SLN_MOI = ? WHERE MaCTPN = ? AND MaPN = ?");
                    $stmt->execute([$sln_moi, $maCTPN, $maPN]);

                    // Cộng SLN_MOI vào SLTK
                    $stmt_sp = $pdo->prepare("SELECT MaSP FROM CHITIETPHIEUNHAP WHERE MaCTPN = ?");
                    $stmt_sp->execute([$maCTPN]);
                    $sp = $stmt_sp->fetch();
                    if ($sp) {
                        $stmt_update = $pdo->prepare("
                            UPDATE SANPHAM 
                            SET SLTK = SLTK + ?, 
                                TinhTrang = CASE WHEN SLTK + ? > 0 THEN 'Còn hàng' ELSE 'Hết hàng' END 
                            WHERE MaSP = ?
                        ");
                        $stmt_update->execute([$sln_moi, $sln_moi, $sp['MaSP']]);
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
    } elseif ($action == 'change_status') {
        $maPN = $_POST['MaPN'] ?? '';
        $newStatus = $_POST['TinhTrang_PN'] ?? '';

        // Danh sách trạng thái hợp lệ
        $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];

        if (in_array($newStatus, $validStatuses)) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE PHIEUNHAP SET TinhTrang_PN = ? WHERE MaPN = ?");
                $stmt->execute([$newStatus, $maPN]);
                $pdo->commit();
                header("Location: imports.php?success=Đổi trạng thái thành công");
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: imports.php?error=" . urlencode($e->getMessage()));
            }
        } else {
            header("Location: imports.php?error=Trạng thái không hợp lệ");
        }
    }
    exit();
}

// Xử lý các action GET riêng biệt
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'get_import_details') {
        header('Content-Type: application/json');
        $maPN = $_GET['MaPN'] ?? '';

        // Lấy thông tin phiếu nhập
        $stmt = $pdo->prepare("
            SELECT p.MaPN, p.NgayNhap, p.TinhTrang_PN, t.MaTK, t.TenTK
            FROM PHIEUNHAP p
            LEFT JOIN TAIKHOAN t ON p.MaTK = t.MaTK
            WHERE p.MaPN = ?
        ");
        $stmt->execute([$maPN]);
        $importInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        // Lấy chi tiết sản phẩm (thêm SLN_MOI)
        $stmt = $pdo->prepare("
            SELECT ct.MaCTPN, ct.MaSP, ct.SLN, ct.SLN_MOI, sp.TenSP
            FROM CHITIETPHIEUNHAP ct
            LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
            WHERE ct.MaPN = ?
        ");
        $stmt->execute([$maPN]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'info' => $importInfo,
            'details' => $details
        ]);
        exit();
    }

    if ($action === 'change_status_ajax') {
        header('Content-Type: application/json');
        $maPN = $_POST['MaPN'] ?? '';
        $newStatus = $_POST['TinhTrang_PN'] ?? '';

        // Danh sách trạng thái hợp lệ
        $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];

        $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
        $stmt->execute([$maPN]);
        $currentStatus = $stmt->fetchColumn();

        // Nếu trạng thái hiện tại là trạng thái cuối cùng, không cho phép đổi
        if (in_array($currentStatus, $finalStatuses)) {
            echo json_encode(['success' => false, 'error' => 'Phiếu nhập này đã được khóa và không thể thay đổi trạng thái']);
            exit();
        }

        if (in_array($newStatus, $validStatuses)) {
            try {
                $pdo->beginTransaction();

                // Lấy trạng thái cũ của phiếu nhập
                $stmt = $pdo->prepare("SELECT TinhTrang_PN FROM PHIEUNHAP WHERE MaPN = ?");
                $stmt->execute([$maPN]);
                $oldStatus = $stmt->fetchColumn();

                // Cập nhật trạng thái mới cho phiếu nhập
                $stmt = $pdo->prepare("UPDATE PHIEUNHAP SET TinhTrang_PN = ? WHERE MaPN = ?");
                $stmt->execute([$newStatus, $maPN]);

                // Nếu chuyển sang trạng thái "Hoàn thành"
                if ($newStatus == 'Hoàn thành' && !in_array($oldStatus, ['Hoàn thành', 'Có thay đổi'])) {
                    // Cập nhật số lượng tồn kho cho từng sản phẩm trong phiếu nhập (cộng SLN gốc)
                    $stmt = $pdo->prepare("
                        UPDATE SANPHAM sp
                        INNER JOIN CHITIETPHIEUNHAP ct ON sp.MaSP = ct.MaSP
                        SET 
                            sp.SLTK = sp.SLTK + ct.SLN,
                            sp.TinhTrang = CASE 
                                WHEN (sp.SLTK + ct.SLN) > 0 THEN 'Còn hàng'
                                ELSE 'Hết hàng'
                            END
                        WHERE ct.MaPN = ?
                    ");
                    $stmt->execute([$maPN]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'newStatus' => $newStatus, 'needsAdjustment' => ($newStatus == 'Có thay đổi')]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Trạng thái không hợp lệ']);
        }
        exit();
    }
}

// ============================
//  LẤY DANH SÁCH PHIẾU NHẬP
// ============================
$search = $_GET['search'] ?? '';
$where = $search ? "WHERE p.MaPN LIKE '%$search%' OR t.TenTK LIKE '%$search%' OR sp.TenSP LIKE '%$search%'" : '';
$stmt = $pdo->query("
    SELECT 
        p.MaPN,
        p.NgayNhap,
        p.TinhTrang_PN,
        t.MaTK,
        t.TenTK,
        sp.TenSP,
        ct.SLN,
        ct.SLN_MOI,
        ct.MaCTPN,
        ct.MaSP
    FROM PHIEUNHAP p 
    LEFT JOIN TAIKHOAN t ON p.MaTK = t.MaTK 
    LEFT JOIN CHITIETPHIEUNHAP ct ON p.MaPN = ct.MaPN
    LEFT JOIN SANPHAM sp ON ct.MaSP = sp.MaSP
    $where 
    ORDER BY p.MaPN ASC
");
$imports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nhóm dữ liệu theo phiếu nhập
$groupedImports = [];
foreach ($imports as $row) {
    if (!isset($groupedImports[$row['MaPN']])) {
        $groupedImports[$row['MaPN']] = [
            'info' => [
                'MaPN' => $row['MaPN'],
                'NgayNhap' => $row['NgayNhap'],
                'TenTK' => $row['TenTK'],
                'TinhTrang_PN' => $row['TinhTrang_PN']
            ],
            'details' => []
        ];
    }
    if ($row['TenSP']) {  // Chỉ thêm vào details nếu có sản phẩm
        $groupedImports[$row['MaPN']]['details'][] = [
            'TenSP' => $row['TenSP'],
            'SLN' => $row['SLN'],
            'SLN_MOI' => $row['SLN_MOI'] ?? null,
            'MaCTPN' => $row['MaCTPN'],
            'MaSP' => $row['MaSP']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Phiếu Nhập - Hệ Thống Quản Lý Kho Tink</title>
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
                <li><a href="imports.php" class="active">Quản Lý Nhập Kho</a></li>
                <li><a href="exports.php">Quản Lý Xuất Kho</a></li>
                <li><a href="reports.php">Quản Lý Báo Cáo</a></li>
            <?php else: ?>
                <li><a href="imports.php" class="active">Quản Lý Nhập Kho</a></li>
                <li><a href="exports.php">Quản Lý Xuất Kho</a></li>
            <?php endif; ?>
        </ul>
        <button class="logout-btn" onclick="location.href='../logout.php'">Đăng Xuất</button>
    </header>

    <div class="container">
        <h1 style="text-align: center; margin-bottom: 20px; color: #d4af37;">Quản Lý Phiếu Nhập Kho</h1>
        
        <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form">
            <input type="text" class="search-box" placeholder="Tìm kiếm phiếu nhập..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
        </form>
        
        <!-- Nút thêm -->
        <button class="btn btn-add" onclick="openModal('addModal')">Thêm Phiếu Nhập</button>

        <!-- Hiển thị thông báo -->
        <?php if (isset($_GET['success'])): ?>
            <p style="color: green;"><?php echo htmlspecialchars($_GET['success']); ?></p>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <p style="color: red;"><?php echo htmlspecialchars($_GET['error']); ?></p>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 100px">Mã PN</th>
                        <th style="width: 120px">Ngày Nhập</th>
                        <th style="width: 200px">Người Nhập</th>
                        <th>Sản Phẩm</th>
                        <th style="width: 100px">Số Lượng</th>
                        <th style="width: 120px">Số lượng mới</th>
                        <th style="width: 120px">Tình Trạng</th>
                        <th class="actions-column">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groupedImports as $maPN => $import): ?>
                        <?php 
                        $rowspan = max(1, count($import['details']));
                        $canEdit = in_array($import['info']['TinhTrang_PN'], $mutableStatuses);
                        $isLocked = in_array($import['info']['TinhTrang_PN'], $finalStatuses);
                        ?>
                        <?php if (empty($import['details'])): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($import['info']['MaPN']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($import['info']['NgayNhap'])); ?></td>
                                <td><?php echo htmlspecialchars($import['info']['TenTK']); ?></td>
                                <td><em>Chưa có sản phẩm</em></td>
                                <td><em>0</em></td>
                                <td><em>-</em></td>
                                <td><?php echo htmlspecialchars($import['info']['TinhTrang_PN']); ?></td>
                                <td class="actions-column">
                                    <?php if ($canEdit): ?>
                                        <button class="btn btn-edit" onclick="editImport('<?php echo $import['info']['MaPN']; ?>')">Sửa</button>
                                    <?php else: ?>
                                        <button class="btn btn-edit" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                    <?php endif; ?>
                                    <button class="btn btn-delete" onclick="deleteImport('<?php echo $import['info']['MaPN']; ?>')">Xóa</button>
                                    <?php if ($isLocked): ?>
                                        <button class="btn btn-status" disabled title="Phiếu nhập này đã bị khóa và không thể thay đổi trạng thái">Đổi trạng thái</button>
                                    <?php else: ?>
                                        <button class="btn btn-status" onclick="changeStatus('<?php echo $import['info']['MaPN']; ?>')">Đổi trạng thái</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($import['details'] as $index => $detail): ?>
                                <tr>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($import['info']['MaPN']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo date('d/m/Y', strtotime($import['info']['NgayNhap'])); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($import['info']['TenTK']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($detail['TenSP']); ?></td>
                                    <td><?php echo htmlspecialchars($detail['SLN']); ?> cái</td>
                                    <td><?php echo ($detail['SLN_MOI'] !== null) ? $detail['SLN_MOI'] . ' cái' : (($import['info']['TinhTrang_PN'] === 'Có thay đổi') ? 'Chưa cập nhật' : '-'); ?></td>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($import['info']['TinhTrang_PN']); ?></td>
                                        <td rowspan="<?php echo $rowspan; ?>" class="actions-column">
                                            <?php if ($canEdit): ?>
                                                <button class="btn btn-edit" onclick="editImportDetail('<?php echo $import['info']['MaPN']; ?>')">Sửa</button>
                                            <?php else: ?>
                                                <button class="btn btn-edit" disabled title="Chỉ sửa được khi trạng thái là 'Đang xử lý'">Sửa</button>
                                            <?php endif; ?>
                                            <button class="btn btn-delete" onclick="deleteImport('<?php echo $import['info']['MaPN']; ?>')">Xóa</button>
                                            <?php if ($isLocked): ?>
                                                <button class="btn btn-status" disabled title="Phiếu nhập này đã bị khóa và không thể thay đổi trạng thái">Đổi trạng thái</button>
                                            <?php else: ?>
                                                <button class="btn btn-status" onclick="changeStatus('<?php echo $import['info']['MaPN']; ?>')">Đổi trạng thái</button>
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

    <!-- Modal Thêm Phiếu Nhập -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Thêm Phiếu Nhập</h2>
            <form method="POST" id="addImportForm">
                <input type="hidden" name="action" value="add">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label>Mã Phiếu Nhập:</label>
                        <?php 
                        $nextMaPN = generateMaPN($pdo);
                        ?>
                        <input type="text" value="<?php echo $nextMaPN; ?>" disabled 
                               style="width: 100%; padding: 8px; background-color: #f0f0f0;">
                    </div>
                    <div>
                        <label>Ngày Nhập:</label>
                        <input type="date" name="NgayNhap" required style="width: 100%; padding: 8px;">
                    </div>
                    <div>
                        <label>Người Nhập:</label>
                        <select name="MaTK" required style="width: 100%; padding: 8px;">
                            <option value="">Chọn người nhập</option>
                            <?php
                            $users = $pdo->query("SELECT MaTK, TenTK FROM TAIKHOAN")->fetchAll();
                            foreach ($users as $user) {
                                echo "<option value='{$user['MaTK']}'>{$user['TenTK']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Tình Trạng:</label>
                        <select name="TinhTrang_PN" required style="width: 100%; padding: 8px;">
                            <option value="Đang xử lý" selected>Đang xử lý</option>
                            <option value="Đã duyệt">Đã duyệt</option>
                            <option value="Bị từ chối">Bị từ chối</option>
                            <option value="Hoàn thành">Hoàn thành</option>
                            <option value="Có thay đổi">Có thay đổi</option>
                        </select>
                    </div>
                </div>

                <h3>Chi Tiết Sản Phẩm</h3>
                <div id="productEntries">
                    <div class="product-entry" style="display: grid; grid-template-columns: 2fr 1fr 40px; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <select name="MaSP[]" required style="width: 100%; padding: 8px;">
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
                            <input type="number" name="SLN[]" min="1" placeholder="Số lượng" required style="width: 100%; padding: 8px;">
                        </div>
                        <div>
                            <button type="button" class="btn btn-delete" onclick="removeProduct(this)" style="padding: 8px;">×</button>
                        </div>
                    </div>
                </div>
                
                <button type="button" onclick="addProductEntry()" class="btn btn-add" style="margin: 10px 0;">+ Thêm sản phẩm</button>
                
                <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-add">Lưu phiếu nhập</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('addModal')">Hủy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal sửa chi tiết phiếu nhập -->
    <div id="editDetailModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editDetailModal')">&times;</span>
            <h2>Sửa Chi Tiết Phiếu Nhập</h2>
            
            <div style="background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label>Mã Phiếu Nhập:</label>
                        <input type="text" id="editMaPN" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Ngày Nhập:</label>
                        <input type="text" id="editNgayNhap" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
                    </div>
                    <div>
                        <label>Người Nhập:</label>
                        <input type="text" id="editTenTK" disabled style="width: 100%; padding: 8px; background-color: #e0e0e0;">
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

    <!-- Modal xác nhận xóa -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <h2>Xác Nhận Xóa</h2>
            <p id="confirmDeleteMessage" style="margin: 20px 0; font-size: 16px;">Bạn có chắc chắn muốn xóa phiếu nhập này?</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-cancel" onclick="closeModal('confirmDeleteModal')" style="background-color: #999;">Hủy</button>
                <button class="btn btn-delete" id="confirmDeleteBtn" onclick="confirmDelete()" style="background-color: #d32f2f;">Xóa</button>
            </div>
        </div>
    </div>

    <!-- Modal đổi trạng thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2 style="color: #1976d2; margin-bottom: 10px; font-size: 24px;">Đổi Trạng Thái Phiếu Nhập</h2>
            <p id="statusMaPN" style="margin-bottom: 25px; font-weight: 600; color: #ff9800; font-size: 16px; padding: 10px; background-color: #fff3e0; border-radius: 5px; border-left: 4px solid #ff9800;"></p>
            
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
            <p id="adjustmentMaPN" style="margin-bottom: 20px; font-weight: 600; color: #ff9800;"></p>
            
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
        let deleteConfirmMaPN = null;
        let statusChangeMaPN = null;

        function editImport(maPN) {
            document.getElementById('modalTitle').innerText = 'Sửa Phiếu Nhập';
            document.getElementById('modalAction').value = 'edit';
            // TODO: Load dữ liệu phiếu nhập vào form
            openModal('addModal');
        }

        function editImportDetail(maPN) {
            fetch(`imports.php?action=get_import_details&MaPN=${encodeURIComponent(maPN)}`)
                .then(response => response.json())
                .then(data => {
                    const info = data.info;
                    const details = data.details;
                    
                    // Điền thông tin phiếu nhập (disabled)
                    document.getElementById('editMaPN').value = info.MaPN;
                    document.getElementById('editNgayNhap').value = new Date(info.NgayNhap).toLocaleDateString('vi-VN');
                    document.getElementById('editTenTK').value = info.TenTK;
                    document.getElementById('editTinhTrang').value = info.TinhTrang_PN;
                    
                    // Tạo form cho tất cả chi tiết sản phẩm
                    const detailsList = document.getElementById('detailsList');
                    detailsList.innerHTML = `
                        <form method="POST" id="editDetailsForm">
                            <input type="hidden" name="action" value="edit_detail">
                            <input type="hidden" name="MaPN" value="${info.MaPN}">
                            
                            <div id="productsList" style="margin-bottom: 20px;">
                                ${details.map((detail, index) => `
                                    <div style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                        <input type="hidden" name="MaCTPN[]" value="${detail.MaCTPN}">
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
                                                <input type="number" name="SLN[]" min="1" value="${detail.SLN}" required style="width: 100%; padding: 10px;">
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

                    //Set selected values for selects after innerHTML is set
                    const selects = detailsList.querySelectorAll('select[name="MaSP[]"]');
                    details.forEach((detail, index) => {
                        if (selects[index]) {
                            selects[index].value = detail.MaSP;
                        }
                    });

                    openModal('editDetailModal');
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể tải dữ liệu');
                });
        }

        function deleteImport(maPN) {
            deleteConfirmMaPN = maPN;
            document.getElementById('confirmDeleteMessage').innerText = `Bạn có chắc chắn muốn xóa phiếu nhập "${maPN}"?`;
            openModal('confirmDeleteModal');
        }

        function confirmDelete() {
            if (deleteConfirmMaPN) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaPN" value="${deleteConfirmMaPN}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function changeStatus(maPN) {
            statusChangeMaPN = maPN;
            document.getElementById('statusMaPN').innerText = `Phiếu nhập: ${maPN}`;
            openModal('statusModal');
        }

        function updateStatus(newStatus) {
            if (statusChangeMaPN) {
                fetch('imports.php?action=change_status_ajax', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `MaPN=${encodeURIComponent(statusChangeMaPN)}&TinhTrang_PN=${encodeURIComponent(newStatus)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Cập nhật UI
                        const rows = document.querySelectorAll('table tbody tr');
                        rows.forEach(row => {
                            const maPNCell = row.querySelector('td:first-child');
                            if (maPNCell && maPNCell.textContent.trim() === statusChangeMaPN) {
                                const statusCell = row.querySelector('td:nth-child(7)');
                                if (statusCell) {
                                    statusCell.textContent = newStatus;
                                }
                                
                                const editButton = row.querySelector('.btn-edit');
                                if (editButton) {
                                    if (newStatus === 'Đang xử lý') {
                                        editButton.removeAttribute('disabled');
                                        editButton.setAttribute('onclick', `editImportDetail('${statusChangeMaPN}')`);
                                        editButton.removeAttribute('title');
                                    } else {
                                        editButton.setAttribute('disabled', 'disabled');
                                        editButton.removeAttribute('onclick');
                                        editButton.setAttribute('title', "Chỉ sửa được khi trạng thái là 'Đang xử lý'");
                                    }
                                }
                                
                                const statusButton = row.querySelector('.btn-status');
                                if (statusButton) {
                                    const finalStatuses = ['Hoàn thành', 'Có thay đổi', 'Bị từ chối'];
                                    if (finalStatuses.includes(newStatus)) {
                                        statusButton.setAttribute('disabled', 'disabled');
                                        statusButton.removeAttribute('onclick');
                                        statusButton.setAttribute('title', 'Phiếu nhập này đã bị khóa và không thể thay đổi trạng thái');
                                    } else {
                                        statusButton.removeAttribute('disabled');
                                        statusButton.setAttribute('onclick', `changeStatus('${statusChangeMaPN}')`);
                                        statusButton.removeAttribute('title');
                                    }
                                }
                            }
                        });

                        closeModal('statusModal');
                        
                        if (newStatus === 'Hoàn thành') {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}"`);
                        } else if (data.needsAdjustment) {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}". Vui lòng nhập số lượng mới cho từng sản phẩm.`);
                            openAdjustmentModal(statusChangeMaPN);
                        } else {
                            alert(`Đã cập nhật trạng thái thành "${newStatus}"`);
                        }
                    } else {
                        alert('Lỗi: ' + (data.error || 'Không thể cập nhật trạng thái'));
                    }
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể cập nhật trạng thái');
                });
            }
        }

        function openAdjustmentModal(maPN) {
            statusChangeMaPN = maPN;
            document.getElementById('adjustmentMaPN').innerText = `Phiếu nhập: ${maPN} - Nhập số lượng mới cho từng sản phẩm`;
            
            fetch(`imports.php?action=get_import_details&MaPN=${encodeURIComponent(maPN)}`)
                .then(response => response.json())
                .then(data => {
                    const adjustmentDetails = document.getElementById('adjustmentDetails');
                    adjustmentDetails.innerHTML = `
                        <form id="adjustmentForm">
                            <input type="hidden" name="action" value="adjustment">
                            <input type="hidden" name="MaPN" value="${maPN}">
                            ${data.details.map((detail, index) => `
                                <div style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                    <h4>${detail.TenSP}</h4>
                                    <p>Số lượng ban đầu: ${detail.SLN} cái</p>
                                    <label>Số lượng mới (sẽ cộng vào tồn kho):</label>
                                    <input type="number" name="SLN_MOI[]" min="0" value="${detail.SLN_MOI || ''}" required style="width: 100%; padding: 8px; margin-bottom: 10px;">
                                    <input type="hidden" name="MaCTPN[]" value="${detail.MaCTPN}">
                                </div>
                            `).join('')}
                        </form>
                    `;
                    openModal('adjustmentModal');
                })
                .catch(error => {
                    console.error('Lỗi:', error);
                    alert('Không thể tải chi tiết');
                });
        }

        function saveAdjustment() {
            if (statusChangeMaPN) {
                const formData = new FormData(document.getElementById('adjustmentForm'));
                fetch('imports.php', {
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
                    console.error('Lỗi:', error);
                    alert('Không thể lưu');
                });
            }
        }

        function addProductEntry() {
            const template = document.querySelector('.product-entry').cloneNode(true);
            template.querySelector('select').value = '';
            template.querySelector('input[type="number"]').value = '';
            document.getElementById('productEntries').appendChild(template);
        }

        function removeProduct(button) {
            const entries = document.querySelectorAll('.product-entry');
            if (entries.length > 1) {
                button.closest('.product-entry').remove();
            }
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Tự động reload trang sau khi hiển thị success/error để tránh resubmit prompt
        window.addEventListener('load', function() {
            if (window.history && window.history.pushState) {
                window.history.pushState('', null, window.location.pathname);
            }
        });
    </script>
</body>
</html>