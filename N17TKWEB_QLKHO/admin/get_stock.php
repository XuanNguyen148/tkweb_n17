<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

if (isset($_GET['maSP'])) {
    $maSP = $_GET['maSP'];
    $stmt = $pdo->prepare("SELECT sp.MaSP, sp.TenSP, sp.SLTK, 
        (SELECT SUM(SoLuong) FROM NHAPKHO WHERE MaSP = sp.MaSP) as TongNhap,
        (SELECT SUM(SoLuong) FROM XUATKHO WHERE MaSP = sp.MaSP) as TongXuat
        FROM SANPHAM sp 
        WHERE sp.MaSP = ?");
    $stmt->execute([$maSP]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $tongNhap = $result['TongNhap'] ?: 0;
        $tongXuat = $result['TongXuat'] ?: 0;
        $response = [
            'success' => true,
            'data' => [
                'maSP' => $result['MaSP'],
                'tenSP' => $result['TenSP'],
                'tonKho' => $result['SLTK'],
                'tongNhap' => $tongNhap,
                'tongXuat' => $tongXuat
            ]
        ];
    } else {
        $response = ['success' => false, 'message' => 'Không tìm thấy sản phẩm'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
}
?>