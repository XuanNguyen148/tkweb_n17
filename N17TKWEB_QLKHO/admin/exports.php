<?php
// admin/exports.php - Quản lý xuất kho
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['username'] ?? '';
$userRole = $_SESSION['role'] ?? 'Nhân viên';

// Map nhãn UI "Chờ duyệt" <-> DB "Đang xử lý"
function uiToDbStatus($status)
{
    return $status === 'Chờ duyệt' ? 'Đang xử lý' : $status;
}
function dbToUiStatus($status)
{
    return $status === 'Đang xử lý' ? 'Chờ duyệt' : $status;
}

// Helper: sinh mã tự động
function generateNextId(PDO $pdo, string $table, string $column, string $prefix, int $pad): string
{
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch(PDO::FETCH_COLUMN);
    if (!$last) {
        return $prefix . str_pad('1', $pad, '0', STR_PAD_LEFT);
    }
    $num = (int)preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', $last);
    $num++;
    return $prefix . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
}

// ============================
//  AJAX: LẤY CHI TIẾT PHIẾU
// ============================
if (isset($_GET['action']) && $_GET['action'] === 'get_detail') {
    header('Content-Type: application/json');
    $maPX = $_GET['maPX'] ?? '';
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
        $stmt = $pdo->prepare("SELECT ct.MaSP, sp.TenSP, ct.SLX
                               FROM CHITIETPHIEUXUAT ct
                               JOIN SANPHAM sp ON sp.MaSP = ct.MaSP
                               WHERE ct.MaPX = ?");
        $stmt->execute([$maPX]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'header' => [
                'MaPX' => $header['MaPX'],
                'NgayXuat' => $header['NgayXuat'],
                'TinhTrang' => dbToUiStatus($header['TinhTrang_PX']),
                'MaCH' => $header['MaCH'],
                'TenCH' => $header['TenCH'],
                'MaTK' => $header['MaTK'],
                'TenTK' => $header['TenTK'],
            ],
            'details' => $details,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================
//  XỬ LÝ THÊM / SỬA / XÓA / CẬP NHẬT TRẠNG THÁI
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add' || $action === 'edit') {
            // Quyền: Nhân viên và Quản lý đều được thêm; Sửa chỉ khi còn "Đang xử lý" hoặc là Quản lý
            $maPX = $_POST['MaPX'] ?? '';
            $ngayXuat = $_POST['NgayXuat'] ?? '';
            $maCH = $_POST['MaCH'] ?? '';
            // Chi tiết
            $detailSP = $_POST['detail_maSP'] ?? [];
            $detailSLX = $_POST['detail_slx'] ?? [];

            if (!$maPX || !$ngayXuat || !$maCH || empty($detailSP) || empty($detailSLX) || count($detailSP) !== count($detailSLX)) {
                throw new Exception('Dữ liệu không hợp lệ.');
            }

            $pdo->beginTransaction();
            if ($action === 'add') {
                // Mặc định trạng thái: Đang xử lý (UI hiển thị Chờ duyệt)
                // Cho phép cả Nhân viên và Quản lý
                // Kiểm tra trùng mã
                $chk = $pdo->prepare("SELECT 1 FROM PHIEUXUAT WHERE MaPX = ?");
                $chk->execute([$maPX]);
                if ($chk->fetch()) {
                    throw new Exception('Mã phiếu đã tồn tại.');
                }
                $stmt = $pdo->prepare("INSERT INTO PHIEUXUAT (MaPX, NgayXuat, MaCH, MaTK, TinhTrang_PX) VALUES (?, ?, ?, ?, 'Đang xử lý')");
                $stmt->execute([$maPX, $ngayXuat, $maCH, $userId]);

                // Thêm chi tiết
                for ($i = 0; $i < count($detailSP); $i++) {
                    $maSP = trim($detailSP[$i]);
                    $slx = (int)$detailSLX[$i];
                    if (!$maSP || $slx <= 0) continue;
                    $maCTPX = generateNextId($pdo, 'CHITIETPHIEUXUAT', 'MaCTPX', 'CTPX', 3);
                    $ins = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                    $ins->execute([$maCTPX, $maPX, $maSP, $slx]);
                }
            } else { // edit
                // Cho phép sửa: chỉ khi trạng thái hiện tại là Đang xử lý (cả Quản lý và Nhân viên)
                $cur = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ?");
                $cur->execute([$maPX]);
                $row = $cur->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('Không tìm thấy phiếu xuất để sửa.');
                $curStatus = $row['TinhTrang_PX'];
                $isEditable = ($curStatus === 'Đang xử lý');
                if (!$isEditable) throw new Exception('Bạn không có quyền sửa phiếu này.');

                $stmt = $pdo->prepare("UPDATE PHIEUXUAT SET NgayXuat = ?, MaCH = ? WHERE MaPX = ?");
                $stmt->execute([$ngayXuat, $maCH, $maPX]);
                // Xóa chi tiết cũ, thêm lại
                $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX = ?")->execute([$maPX]);
                for ($i = 0; $i < count($detailSP); $i++) {
                    $maSP = trim($detailSP[$i]);
                    $slx = (int)$detailSLX[$i];
                    if (!$maSP || $slx <= 0) continue;
                    $maCTPX = generateNextId($pdo, 'CHITIETPHIEUXUAT', 'MaCTPX', 'CTPX', 3);
                    $ins = $pdo->prepare("INSERT INTO CHITIETPHIEUXUAT (MaCTPX, MaPX, MaSP, SLX) VALUES (?, ?, ?, ?)");
                    $ins->execute([$maCTPX, $maPX, $maSP, $slx]);
                }
            }
            $pdo->commit();
        } elseif ($action === 'delete') {
            // Chỉ Quản lý; chỉ xóa khi Đang xử lý
            if ($userRole !== 'Quản lý') throw new Exception('Bạn không có quyền xóa.');
            $maPX = $_POST['MaPX'] ?? '';
            if (!$maPX) throw new Exception('Thiếu mã phiếu.');
            $pdo->beginTransaction();
            $cur = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ? FOR UPDATE");
            $cur->execute([$maPX]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Không tìm thấy phiếu để xóa.');
            if ($row['TinhTrang_PX'] !== 'Đang xử lý') throw new Exception('Chỉ xóa khi phiếu ở trạng thái Chờ duyệt.');
            $pdo->prepare("DELETE FROM CHITIETPHIEUXUAT WHERE MaPX = ?")->execute([$maPX]);
            $pdo->prepare("DELETE FROM PHIEUXUAT WHERE MaPX = ?")->execute([$maPX]);
            $pdo->commit();
        } elseif ($action === 'update_status') {
            // Chỉ Quản lý được cập nhật trạng thái
            if ($userRole !== 'Quản lý') throw new Exception('Bạn không có quyền cập nhật trạng thái.');
            $maPX = $_POST['MaPX'] ?? '';
            $newStatusUi = $_POST['NewStatus'] ?? '';
            if (!$maPX || !$newStatusUi) throw new Exception('Thiếu dữ liệu cập nhật trạng thái.');
            $newStatus = uiToDbStatus($newStatusUi);
            if (!in_array($newStatus, ['Đang xử lý', 'Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'], true)) {
                throw new Exception('Trạng thái không hợp lệ.');
            }
            $pdo->beginTransaction();
            $cur = $pdo->prepare("SELECT TinhTrang_PX FROM PHIEUXUAT WHERE MaPX = ? FOR UPDATE");
            $cur->execute([$maPX]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Không tìm thấy phiếu.');
            $oldStatus = $row['TinhTrang_PX'];

            if ($oldStatus === $newStatus) {
                // Không có gì để cập nhật
                $pdo->rollBack();
                header('Location: exports.php');
                exit();
            }

            // Luồng chuyển trạng thái hợp lệ
            $allowedNext = [
                'Đang xử lý' => ['Đã duyệt', 'Bị từ chối', 'Hoàn thành', 'Có thay đổi'],
                'Đã duyệt'   => ['Hoàn thành', 'Có thay đổi', 'Bị từ chối'],
                'Bị từ chối' => [],
                'Hoàn thành' => [],
                'Có thay đổi'=> [],
            ];
            if (!isset($allowedNext[$oldStatus]) || !in_array($newStatus, $allowedNext[$oldStatus], true)) {
                throw new Exception('Chuyển trạng thái không hợp lệ.');
            }

            $upd = $pdo->prepare("UPDATE PHIEUXUAT SET TinhTrang_PX = ? WHERE MaPX = ?");
            $upd->execute([$newStatus, $maPX]);

            // Trừ tồn kho khi chuyển sang Hoàn thành / Có thay đổi lần đầu
            if (in_array($newStatus, ['Hoàn thành', 'Có thay đổi'], true) && !in_array($oldStatus, ['Hoàn thành', 'Có thay đổi'], true)) {
                // Lấy chi tiết và trừ tồn
                $dStmt = $pdo->prepare("SELECT MaSP, SLX FROM CHITIETPHIEUXUAT WHERE MaPX = ?");
                $dStmt->execute([$maPX]);
                $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($details as $d) {
                    $maSP = $d['MaSP'];
                    $slx = (int)$d['SLX'];
                    // Trừ tồn
                    $u = $pdo->prepare("UPDATE SANPHAM SET SLTK = SLTK - ? WHERE MaSP = ?");
                    $u->execute([$slx, $maSP]);
                }
            }
            $pdo->commit();
        }

        header('Location: exports.php');
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Đơn giản: lưu thông báo lỗi vào session flash
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: exports.php');
        exit();
    }
}

// ============================
//  DỮ LIỆU HIỂN THỊ
// ============================
$search = $_GET['search'] ?? '';
$kw = trim($search);
$where = '';
if ($kw !== '') {
    // Tìm theo MaPX, MaCH, TenCH, TenTK
    $kwLike = '%' . $kw . '%';
    $where = "WHERE px.MaPX LIKE :kw OR px.MaCH LIKE :kw OR ch.TenCH LIKE :kw OR tk.TenTK LIKE :kw";
}

$sql = "SELECT px.MaPX, px.NgayXuat, px.MaCH, ch.TenCH, px.MaTK, tk.TenTK, px.TinhTrang_PX
        FROM PHIEUXUAT px
        LEFT JOIN CUAHANG ch ON ch.MaCH = px.MaCH
        LEFT JOIN TAIKHOAN tk ON tk.MaTK = px.MaTK
        $where
        ORDER BY px.MaPX";
$stmt = $pdo->prepare($sql);
if ($kw !== '') $stmt->bindValue(':kw', $kwLike, PDO::PARAM_STR);
$stmt->execute();
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Danh sách Cửa hàng và Sản phẩm cho form
$shops = $pdo->query("SELECT MaCH, TenCH FROM CUAHANG ORDER BY MaCH")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT MaSP, TenSP FROM SANPHAM ORDER BY MaSP")->fetchAll(PDO::FETCH_ASSOC);

// Thông báo flash (nếu có)
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
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

        <?php if ($flashError): ?>
            <div style="background:#ffecec;color:#cc0000;border:1px solid #ffb3b3;padding:10px;border-radius:10px;margin-bottom:15px;">
                <?php echo htmlspecialchars($flashError); ?>
            </div>
        <?php endif; ?>

        <!-- Thanh tìm kiếm -->
        <form method="GET" class="search-form" style="display: inline;">
            <input type="text" class="search-box" placeholder="Tìm kiếm phiếu xuất..." name="search" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-search">Tìm</button>
        </form>

        <!-- Nút thêm (Nhân viên và Quản lý) -->
        <button class="btn btn-add" onclick="openAddModal()">Thêm Phiếu Xuất</button>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Mã PX</th>
                        <th>Ngày xuất</th>
                        <th>Cửa hàng</th>
                        <th>Người lập</th>
                        <th>Tình trạng</th>
                        <th class="actions-column">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exports as $row): ?>
                        <?php
                            $statusUi = dbToUiStatus($row['TinhTrang_PX']);
                            $isLocked = in_array($row['TinhTrang_PX'], ['Đã duyệt','Bị từ chối','Hoàn thành','Có thay đổi'], true);
                            $canEdit = ($row['TinhTrang_PX'] === 'Đang xử lý');
                            $canDelete = ($userRole === 'Quản lý' && $row['TinhTrang_PX'] === 'Đang xử lý');
                            $canUpdateStatus = ($userRole === 'Quản lý');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['MaPX']); ?></td>
                            <td><?php echo htmlspecialchars($row['NgayXuat']); ?></td>
                            <td><?php echo htmlspecialchars($row['MaCH'] . ' - ' . ($row['TenCH'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(($row['MaTK'] ?? '') . ' - ' . ($row['TenTK'] ?? '')); ?></td>
                            <td>
                                <?php if ($canUpdateStatus): ?>
                                    <form method="POST" style="display:inline-flex; gap:8px; align-items:center;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="MaPX" value="<?php echo htmlspecialchars($row['MaPX']); ?>">
                                        <select name="NewStatus" onchange="this.form.submit()" style="min-width:160px;">
                                            <?php foreach (['Chờ duyệt','Đã duyệt','Bị từ chối','Hoàn thành','Có thay đổi'] as $opt): ?>
                                                <option value="<?php echo $opt; ?>" <?php echo ($opt === $statusUi ? 'selected' : ''); ?>><?php echo $opt; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($statusUi); ?>
                                <?php endif; ?>
                            </td>
                            <td class="actions-column">
                                <button class="btn btn-status" onclick="viewDetail('<?php echo $row['MaPX']; ?>')">Chi tiết</button>
                                <?php if ($canEdit): ?>
                                    <button class="btn btn-edit" onclick="editExport('<?php echo $row['MaPX']; ?>')">Sửa</button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <button class="btn btn-delete" onclick="deleteExport('<?php echo $row['MaPX']; ?>')">Xóa</button>
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
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2 id="modalTitle">Thêm Phiếu Xuất</h2>
            <form method="POST" id="exportForm">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="text" name="MaPX" id="MaPX" placeholder="Mã phiếu xuất" required>
                <input type="date" name="NgayXuat" id="NgayXuat" required>
                <select name="MaCH" id="MaCH" required>
                    <option value="">Chọn cửa hàng nhận</option>
                    <?php foreach ($shops as $shop): ?>
                        <option value="<?php echo htmlspecialchars($shop['MaCH']); ?>"><?php echo htmlspecialchars($shop['MaCH'] . ' - ' . $shop['TenCH']); ?></option>
                    <?php endforeach; ?>
                </select>

                <div id="detailList">
                    <!-- Dòng chi tiết sẽ được thêm bằng JS -->
                </div>
                <button type="button" class="btn btn-add" onclick="addDetailRow()">Thêm sản phẩm</button>
                <button type="submit" class="btn btn-add">Lưu</button>
            </form>
        </div>
    </div>

    <!-- Modal Xem Chi tiết -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('detailModal')">&times;</span>
            <h2>Chi tiết phiếu xuất</h2>
            <div id="detailInfo"></div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        const products = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;

        function optionHtml(opts, selectedValue) {
            return opts.map(o => `<option value="${o.MaSP}" ${selectedValue===o.MaSP?'selected':''}>${o.MaSP} - ${o.TenSP}</option>`).join('');
        }

        function addDetailRow(selectedMaSP = '', qty = '') {
            const container = document.getElementById('detailList');
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.gap = '8px';
            row.style.alignItems = 'center';
            row.style.margin = '8px 0';
            row.innerHTML = `
                <select name="detail_maSP[]" required style="flex: 1;">
                    <option value="">Chọn sản phẩm</option>
                    ${optionHtml(products, selectedMaSP)}
                </select>
                <input type="number" name="detail_slx[]" placeholder="Số lượng xuất" min="1" required style="width: 160px;">
                <button type="button" class="btn btn-delete" onclick="this.parentElement.remove()">Xóa</button>
            `;
            container.appendChild(row);
            if (qty) {
                row.querySelector('input[name="detail_slx[]"]').value = qty;
            }
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Thêm Phiếu Xuất';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('exportForm').reset();
            document.getElementById('MaPX').readOnly = false;
            const detail = document.getElementById('detailList');
            detail.innerHTML = '';
            addDetailRow();
            openModal('addModal');
        }

        function editExport(maPX) {
            // Lấy chi tiết để đổ vào form và mở modal ở chế độ sửa
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { alert('Không thể tải chi tiết phiếu.'); return; }
                    document.getElementById('modalTitle').innerText = 'Sửa Phiếu Xuất';
                    document.getElementById('modalAction').value = 'edit';
                    document.getElementById('MaPX').value = data.header.MaPX;
                    document.getElementById('MaPX').readOnly = true; // Không cho sửa mã
                    document.getElementById('NgayXuat').value = data.header.NgayXuat;
                    document.getElementById('MaCH').value = data.header.MaCH;
                    const detail = document.getElementById('detailList');
                    detail.innerHTML = '';
                    if (Array.isArray(data.details) && data.details.length) {
                        data.details.forEach(d => addDetailRow(d.MaSP, d.SLX));
                    } else {
                        addDetailRow();
                    }
                    openModal('addModal');
                })
                .catch(() => alert('Lỗi khi tải chi tiết.'));
        }

        function deleteExport(maPX) {
            if (!confirm('Xóa phiếu xuất này?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="MaPX" value="${maPX}">`;
            document.body.appendChild(form);
            form.submit();
        }

        function viewDetail(maPX) {
            fetch(`exports.php?action=get_detail&maPX=${encodeURIComponent(maPX)}`)
                .then(r => r.json())
                .then(data => {
                    const box = document.getElementById('detailInfo');
                    if (!data.success) { box.innerText = 'Không thể lấy chi tiết phiếu'; openModal('detailModal'); return; }
                    let html = '';
                    html += `<p><strong>Mã phiếu:</strong> ${data.header.MaPX}</p>`;
                    html += `<p><strong>Ngày xuất:</strong> ${data.header.NgayXuat}</p>`;
                    html += `<p><strong>Cửa hàng:</strong> ${data.header.MaCH} - ${data.header.TenCH || ''}</p>`;
                    html += `<p><strong>Người lập:</strong> ${data.header.MaTK} - ${data.header.TenTK || ''}</p>`;
                    html += `<p><strong>Trạng thái:</strong> ${data.header.TinhTrang}</p>`;
                    html += '<hr />';
                    html += '<h3>Chi tiết sản phẩm</h3>';
                    html += '<div class="table-container"><table><thead><tr><th>Mã SP</th><th>Tên SP</th><th>SL xuất</th></tr></thead><tbody>';
                    if (Array.isArray(data.details) && data.details.length) {
                        data.details.forEach(d => {
                            html += `<tr><td>${d.MaSP}</td><td>${d.TenSP}</td><td>${d.SLX}</td></tr>`;
                        });
                    } else {
                        html += '<tr><td colspan="3">Không có chi tiết</td></tr>';
                    }
                    html += '</tbody></table></div>';
                    box.innerHTML = html;
                    openModal('detailModal');
                })
                .catch(() => {
                    document.getElementById('detailInfo').innerText = 'Có lỗi xảy ra khi lấy chi tiết';
                    openModal('detailModal');
                });
        }
    </script>
</body>
</html>

