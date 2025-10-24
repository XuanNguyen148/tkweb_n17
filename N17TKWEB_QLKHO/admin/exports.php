<?php
// admin/exports.php - Quản lý xuất kho
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên';
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? '';

// Helper: generate next code like PX00001 or CTPX001
function generateNextCode(PDO $pdo, string $table, string $column, string $prefix, int $padLength): string {
    $stmt = $pdo->prepare("SELECT MAX($column) AS max_code FROM $table WHERE $column LIKE ?");
    $stmt->execute([$prefix . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $maxCode = $row && $row['max_code'] ? $row['max_code'] : null;
    if (!$maxCode) {
        return $prefix . str_pad('1', $padLength, '0', STR_PAD_LEFT);
    }
    $num = intval(substr($maxCode, strlen($prefix)));
    return $prefix . str_pad((string)($num + 1), $padLength, '0', STR_PAD_LEFT);
}

// Helper: fetch slip details
function fetchSlipDetails(PDO $pdo, string $maPX): array {
    $stmt = $pdo->prepare("SELECT ct.MaCTPX, ct.MaSP, sp.TenSP, ct.SLX
                           FROM CHITIETPHIEUXUAT ct
                           JOIN SANPHAM sp ON sp.MaSP = ct.MaSP
                           WHERE ct.MaPX = ?");
    $stmt->execute([$maPX]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================
//  AJAX: Lấy chi tiết phiếu (JSON)
// ============================
if (isset($_GET['action']) && $_GET['action'] === 'get_details') {
    header('Content-Type: application/json');
    $maPX = $_GET['MaPX'] ?? '';
    if (!$maPX) {
        echo json_encode(['success' => false, 'message' => 'Thiếu mã phiếu xuất']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT px.MaPX, px.NgayXuat, px.TinhTrang_PX, px.MaCH, ch.TenCH, px.MaTK, tk.TenTK
                               FROM PHIEUXUAT px
                               LEFT JOIN CUAHANG ch ON ch.MaCH = px.MaCH
                               LEFT JOIN TAIKHOAN tk ON tk.MaTK = px.MaTK
                               WHERE px.MaPX = ?");
        $stmt->execute([$maPX]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy phiếu xuất']);
            exit;
        }
        $details = fetchSlipDetails($pdo, $maPX);
        echo json_encode(['success' => true, 'header' => $header, 'details' => $details]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================
//  XỬ LÝ THÊM / SỬA / XÓA / CẬP NHẬT TRẠNG THÁI
// ============================
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'add_px') {
            // Quyền: Quản lý hoặc Nhân viên đều được thêm
            $maCH = $_POST['MaCH'] ?? '';
            $ngayXuat = $_POST['NgayXuat'] ?? '';
            $maSPs = $_POST['MaSP'] ?? [];
            $slxs = $_POST['SLX'] ?? [];

            if (!$maCH || !$ngayXuat || empty($maSPs)) {
                throw new Exception('Thiếu thông tin bắt buộc.');
            }
            // Validate chi tiết
            $rows = [];
            for ($i = 0; $i < count($maSPs); $i++) {
                $msp = trim($maSPs[$i] ?? '');
                $qty = intval($slxs[$i] ?? 0);
                if ($msp && $qty > 0) {
                    $rows[] = [$msp, $qty];
                }
            }
            if (empty($rows)) {
                throw new Exception('Danh sách sản phẩm không hợp lệ.');
            }

            $pdo->beginTransaction();
            $maPX = generateNextCode($pdo, 'PHIEUXUAT', 'MaPX', 'PX', 5);

            $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, 'Đang xử lý')");
            $stmt->execute([$maPX, $ngayXuat, $maCH, $userId]);

            foreach ($rows as [$msp, $qty]) {
                $maCTPX = generateNextCode($pdo, 'CHITIETPHIEUXUAT', 'MaCTPX', 'CTPX', 3);
                $stmt2 = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                $stmt2->execute([$maCTPX, $maPX, $msp, $qty]);
            }

            $pdo->commit();
            header('Location: exports.php');
            exit();
        }

        if ($action === 'edit_px') {
            // Quyền: Quản lý hoặc Nhân viên; chỉ khi đang xử lý
            $maPX = $_POST['MaPX'] ?? '';
            if (!$maPX) throw new Exception('Thiếu mã phiếu xuất.');

            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $cur = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new Exception('Phiếu xuất không tồn tại.');
            if ($cur['TinhTrang_PX'] !== 'Đang xử lý') throw new Exception('Chỉ được sửa khi đang xử lý.');

            $maCTPXs = $_POST['MaCTPX'] ?? [];
            $maSPs = $_POST['MaSP'] ?? [];
            $slxs = $_POST['SLX'] ?? [];

            // Chỉ sửa tên sản phẩm (MaSP) và số lượng
            $pdo->beginTransaction();
            $count = min(count($maCTPXs), count($maSPs), count($slxs));
            for ($i = 0; $i < $count; $i++) {
                $mact = trim($maCTPXs[$i] ?? '');
                $msp = trim($maSPs[$i] ?? '');
                $qty = intval($slxs[$i] ?? 0);
                if ($mact && $msp && $qty > 0) {
                    $up = $pdo->prepare("UPDATE CHITIETPHIEUXUAT SET MaSP = ?, SLX = ? WHERE MaCTPX = ? AND MaPX = ?");
                    $up->execute([$msp, $qty, $mact, $maPX]);
                }
            }
            $pdo->commit();
            header('Location: exports.php');
            exit();
        }

        if ($action === 'delete_px') {
            // Quyền: chỉ Quản lý; và chỉ khi đang xử lý
            if ($userRole !== 'Quản lý') throw new Exception('Bạn không có quyền xóa.');
            $maPX = $_POST['MaPX'] ?? '';
            if (!$maPX) throw new Exception('Thiếu mã phiếu xuất.');
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $cur = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new Exception('Phiếu xuất không tồn tại.');
            if ($cur['TinhTrang_PX'] !== 'Đang xử lý') throw new Exception('Chỉ được xóa khi đang xử lý.');

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX = ?")->execute([$maPX]);
            $pdo->prepare("DELETE FROM PHIEUXUAT WHERE MaPX = ?")->execute([$maPX]);
            $pdo->commit();
            header('Location: exports.php');
            exit();
        }

        if ($action === 'change_status') {
            // Quyền: chỉ Quản lý được đổi trạng thái
            if ($userRole !== 'Quản lý') throw new Exception('Bạn không có quyền cập nhật trạng thái.');
            $maPX = $_POST['MaPX'] ?? '';
            $newStatus = $_POST['new_status'] ?? '';
            $validStatuses = ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'];
            if (!$maPX || !in_array($newStatus, $validStatuses, true)) {
                throw new Exception('Dữ liệu trạng thái không hợp lệ.');
            }
            $stmt = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
            $stmt->execute([$maPX]);
            $cur = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new Exception('Phiếu xuất không tồn tại.');
            $currentStatus = $cur['TinhTrang_PX'];
            if ($currentStatus !== 'Đang xử lý') {
                throw new Exception('Chỉ cập nhật trạng thái từ "Đang xử lý".');
            }

            $pdo->beginTransaction();
            if ($newStatus === 'Hoàn thành' || $newStatus === 'Có thay đổi') {
                // Trừ tồn kho theo SLX hiện tại
                $details = fetchSlipDetails($pdo, $maPX);
                foreach ($details as $row) {
                    $maSP = $row['MaSP'];
                    $qty = (int)$row['SLX'];
                    // Kiểm tra tồn kho đủ
                    $s = $pdo->prepare("SELECT SLTK FROM SANPHAM WHERE MaSP = ? FOR UPDATE");
                    $s->execute([$maSP]);
                    $stockRow = $s->fetch(PDO::FETCH_ASSOC);
                    if (!$stockRow) {
                        $pdo->rollBack();
                        throw new Exception('Sản phẩm không tồn tại: ' . htmlspecialchars($maSP));
                    }
                    $currentStock = (int)$stockRow['SLTK'];
                    if ($currentStock < $qty) {
                        $pdo->rollBack();
                        throw new Exception('Tồn kho không đủ cho sản phẩm ' . htmlspecialchars($maSP));
                    }
                    $upd = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ? WHERE MaSP = ?");
                    $upd->execute([$qty, $maSP]);
                }
            }
            // Cập nhật trạng thái
            $pdo->prepare("UPDATE PHIEUXUAT SET TinhTrang_PX = ? WHERE MaPX = ?")->execute([$newStatus, $maPX]);
            $pdo->commit();
            header('Location: exports.php');
            exit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = $e->getMessage();
    }
}

// ============================
//  DỮ LIỆU HIỂN THỊ
// ============================
$search = $_GET['search'] ?? '';

// Danh sách cửa hàng, sản phẩm cho modal
$stores = $pdo->query("SELECT MaCH, TenCH FROM CUAHANG ORDER BY MaCH")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT MaSP, TenSP FROM SANPHAM ORDER BY MaSP")->fetchAll(PDO::FETCH_ASSOC);

// Danh sách phiếu xuất (xếp theo mã giảm dần, ổn định theo MaPX)
$params = [];
$sql = "SELECT px.MaPX, px.NgayXuat, px.MaCH, ch.TenCH, px.MaTK, tk.TenTK, px.TinhTrang_PX
        FROM PHIEUXUAT px
        LEFT JOIN CUAHANG ch ON ch.MaCH = px.MaCH
        LEFT JOIN TAIKHOAN tk ON tk.MaTK = px.MaTK
        WHERE 1=1";
if ($search) {
    $sql .= " AND (px.MaPX LIKE :kw OR ch.TenCH LIKE :kw OR px.TinhTrang_PX LIKE :kw)";
    $params[':kw'] = "%$search%";
}
$sql .= " ORDER BY px.MaPX DESC";
$stmtList = $pdo->prepare($sql);
$stmtList->execute($params);
$slips = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// Nếu có tham số edit, lấy dữ liệu để mở modal sửa
$editSlip = null;
$editDetails = [];
if (isset($_GET['edit'])) {
    $editId = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM PHIEUXUAT WHERE MaPX = ?");
    $stmt->execute([$editId]);
    $editSlip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editSlip) {
        $editDetails = fetchSlipDetails($pdo, $editId);
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Quản Lý Xuất Kho - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="../assets/css/style.css" />
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

        <?php if ($errorMessage): ?>
            <div class="alert alert-error" style="margin-bottom: 16px; color: #b00020;">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form" style="display: inline;">
            <input type="text" class="search-box" placeholder="Tìm phiếu (mã/cửa hàng/trạng thái)..." name="search" value="<?php echo htmlspecialchars($search); ?>" />
            <button type="submit" class="btn btn-search">Tìm</button>
        </form>

        <!-- Nút thêm -->
        <button class="btn btn-add" onclick="openModal('addModal')">Thêm Phiếu Xuất</button>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Mã PX</th>
                        <th>Ngày Xuất</th>
                        <th>Cửa Hàng</th>
                        <th>Người Lập</th>
                        <th>Trạng Thái</th>
                        <th class="actions-column">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slips as $px): ?>
                        <?php
                            $canEdit = ($px['TinhTrang_PX'] === 'Đang xử lý') && ($userRole === 'Quản lý' || $userRole === 'Nhân viên');
                            $canDelete = ($userRole === 'Quản lý') && ($px['TinhTrang_PX'] === 'Đang xử lý');
                            $canChangeStatus = ($userRole === 'Quản lý');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($px['MaPX']); ?></td>
                            <td><?php echo htmlspecialchars($px['NgayXuat']); ?></td>
                            <td><?php echo htmlspecialchars($px['TenCH'] ?? $px['MaCH']); ?></td>
                            <td><?php echo htmlspecialchars($px['TenTK'] ?? $px['MaTK']); ?></td>
                            <td><?php echo htmlspecialchars($px['TinhTrang_PX']); ?></td>
                            <td class="actions-column">
                                <button class="btn btn-status" onclick="viewDetails('<?php echo $px['MaPX']; ?>')">Xem</button>
                                <button class="btn btn-edit" onclick="editPX('<?php echo $px['MaPX']; ?>')" <?php echo $canEdit ? '' : 'disabled'; ?>>Sửa</button>
                                <button class="btn btn-delete" onclick="deletePX('<?php echo $px['MaPX']; ?>')" <?php echo $canDelete ? '' : 'disabled'; ?>>Xóa</button>
                                <button class="btn btn-add" onclick="openStatusModal('<?php echo $px['MaPX']; ?>')" <?php echo $canChangeStatus ? '' : 'disabled'; ?>>Đổi trạng thái</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Thêm -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Thêm Phiếu Xuất</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_px" />

                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px;">
                        <label>Cửa hàng</label>
                        <select name="MaCH" required>
                            <option value="">-- Chọn cửa hàng --</option>
                            <?php foreach ($stores as $st): ?>
                                <option value="<?php echo htmlspecialchars($st['MaCH']); ?>"><?php echo htmlspecialchars($st['MaCH'] . ' - ' . $st['TenCH']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label>Ngày xuất</label>
                        <input type="date" name="NgayXuat" required value="<?php echo date('Y-m-d'); ?>" />
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label>Người lập</label>
                        <input type="text" value="<?php echo htmlspecialchars($username); ?>" disabled />
                    </div>
                </div>

                <h3 style="margin-top:16px;">Chi Tiết Sản Phẩm</h3>
                <div id="addDetails">
                    <div class="detail-row" style="display:flex; gap:8px; margin-bottom:8px;">
                        <select name="MaSP[]" required style="flex:3;">
                            <option value="">-- Chọn sản phẩm --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['MaSP']); ?>"><?php echo htmlspecialchars($p['MaSP'] . ' - ' . $p['TenSP']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="SLX[]" min="1" placeholder="Số lượng" required style="flex:1;" />
                        <button type="button" class="btn btn-delete" onclick="removeDetailRow(this)">-</button>
                    </div>
                </div>
                <button type="button" class="btn btn-add" onclick="addDetailRow()">+ Thêm dòng</button>
                <button type="submit" class="btn btn-add">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Xem chi tiết -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('viewModal')">&times;</span>
            <h2>Chi Tiết Phiếu Xuất</h2>
            <div id="viewContent">Đang tải...</div>
        </div>
    </div>

    <!-- Modal Sửa: tạo sẵn, nếu ?edit= thì auto mở -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Sửa Phiếu Xuất</h2>
            <?php if ($editSlip && $editSlip['TinhTrang_PX'] === 'Đang xử lý' && ($userRole === 'Quản lý' || $userRole === 'Nhân viên')): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_px" />
                    <input type="hidden" name="MaPX" value="<?php echo htmlspecialchars($editSlip['MaPX']); ?>" />

                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:200px;">
                            <label>Mã phiếu</label>
                            <input type="text" value="<?php echo htmlspecialchars($editSlip['MaPX']); ?>" disabled />
                        </div>
                        <div style="flex:1; min-width:200px;">
                            <label>Trạng thái</label>
                            <input type="text" value="<?php echo htmlspecialchars($editSlip['TinhTrang_PX']); ?>" disabled />
                        </div>
                        <div style="flex:1; min-width:200px;">
                            <label>Cửa hàng</label>
                            <input type="text" value="<?php echo htmlspecialchars($editSlip['MaCH']); ?>" disabled />
                        </div>
                        <div style="flex:1; min-width:200px;">
                            <label>Ngày xuất</label>
                            <input type="text" value="<?php echo htmlspecialchars($editSlip['NgayXuat']); ?>" disabled />
                        </div>
                    </div>

                    <h3 style="margin-top:16px;">Chỉnh sửa sản phẩm và số lượng</h3>
                    <div id="editDetails">
                        <?php foreach ($editDetails as $row): ?>
                            <div class="detail-row" style="display:flex; gap:8px; margin-bottom:8px;">
                                <input type="hidden" name="MaCTPX[]" value="<?php echo htmlspecialchars($row['MaCTPX']); ?>" />
                                <select name="MaSP[]" required style="flex:3;">
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p['MaSP']); ?>" <?php echo ($p['MaSP'] === $row['MaSP']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['MaSP'] . ' - ' . $p['TenSP']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="SLX[]" min="1" required style="flex:1;" value="<?php echo htmlspecialchars($row['SLX']); ?>" />
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-add">Lưu</button>
                </form>
            <?php else: ?>
                <div>Không thể sửa phiếu này.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Đổi trạng thái -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('statusModal')">&times;</span>
            <h2>Chọn Trạng Thái</h2>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn btn-add" onclick="chooseStatus('Đang xử lý')">Đang xử lý</button>
                <button class="btn btn-add" onclick="chooseStatus('Đã duyệt')">Đã duyệt</button>
                <button class="btn btn-add" onclick="chooseStatus('Bị từ chối')">Bị từ chối</button>
                <button class="btn btn-add" onclick="chooseStatus('Hoàn thành')">Hoàn thành</button>
                <button class="btn btn-add" onclick="chooseStatus('Có thay đổi')">Có thay đổi</button>
            </div>
            <form id="statusForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="change_status" />
                <input type="hidden" name="MaPX" id="statusMaPX" />
                <input type="hidden" name="new_status" id="statusValue" />
            </form>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        // Add modal dynamic rows
        function addDetailRow() {
            const container = document.getElementById('addDetails');
            const row = document.createElement('div');
            row.className = 'detail-row';
            row.style.cssText = 'display:flex; gap:8px; margin-bottom:8px;';
            row.innerHTML = `
                <select name="MaSP[]" required style="flex:3;">
                    <option value="">-- Chọn sản phẩm --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['MaSP']); ?>"><?php echo htmlspecialchars($p['MaSP'] . ' - ' . $p['TenSP']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="SLX[]" min="1" placeholder="Số lượng" required style="flex:1;" />
                <button type="button" class="btn btn-delete" onclick="removeDetailRow(this)">-</button>
            `;
            container.appendChild(row);
        }
        function removeDetailRow(btn){
            const row = btn.parentElement;
            row.parentElement.removeChild(row);
        }

        // View details
        function viewDetails(maPX) {
            fetch(`exports.php?action=get_details&MaPX=${encodeURIComponent(maPX)}`)
                .then(r => r.json())
                .then(data => {
                    const view = document.getElementById('viewContent');
                    if (!data.success) {
                        view.textContent = data.message || 'Không lấy được dữ liệu';
                    } else {
                        const h = data.header;
                        const d = data.details;
                        let html = '';
                        html += `<p><strong>Mã PX:</strong> ${h.MaPX}</p>`;
                        html += `<p><strong>Ngày xuất:</strong> ${h.NgayXuat}</p>`;
                        html += `<p><strong>Cửa hàng:</strong> ${h.MaCH} - ${h.TenCH || ''}</p>`;
                        html += `<p><strong>Người lập:</strong> ${h.MaTK} - ${h.TenTK || ''}</p>`;
                        html += `<p><strong>Trạng thái:</strong> ${h.TinhTrang_PX}</p>`;
                        html += '<hr />';
                        html += '<h3>Chi tiết</h3>';
                        html += '<ul>';
                        d.forEach(item => {
                            html += `<li>${item.MaSP} - ${item.TenSP}: SLX ${item.SLX}</li>`;
                        });
                        html += '</ul>';
                        document.getElementById('viewContent').innerHTML = html;
                    }
                    openModal('viewModal');
                })
                .catch(() => {
                    document.getElementById('viewContent').textContent = 'Lỗi tải dữ liệu';
                    openModal('viewModal');
                });
        }

        // Edit
        function editPX(maPX) {
            location.href = `exports.php?edit=${encodeURIComponent(maPX)}`;
        }

        // Delete
        function deletePX(maPX) {
            if (!confirm('Xóa phiếu xuất này?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_px" />
                <input type="hidden" name="MaPX" value="${maPX}" />
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Status change modal
        let currentStatusPX = '';
        function openStatusModal(maPX) {
            currentStatusPX = maPX;
            document.getElementById('statusMaPX').value = maPX;
            openModal('statusModal');
        }
        function chooseStatus(st) {
            if (!currentStatusPX) return;
            const ok = confirm(`Bạn chắc chắn chuyển trạng thái phiếu ${currentStatusPX} sang "${st}"?`);
            if (!ok) return;
            document.getElementById('statusValue').value = st;
            document.getElementById('statusForm').submit();
        }

        // Tự động mở modal sửa nếu có ?edit=
        <?php if ($editSlip && $editSlip['TinhTrang_PX'] === 'Đang xử lý' && ($userRole === 'Quản lý' || $userRole === 'Nhân viên')): ?>
        openModal('editModal');
        <?php endif; ?>
    </script>
</body>
</html>

