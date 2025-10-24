<?php
// index.php - Trang chủ trưng bày sản phẩm
session_start();
require_once 'config/db.php';

// Kiểm tra login (giả sử đã login, role từ session)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Giả sử có trang login
    exit();
}

$userRole = $_SESSION['role'] ?? 'Nhân viên'; // Lấy role từ session
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang Chủ - Hệ Thống Quản Lý Kho Tink</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Hero Section */
        .hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 100px 80px;
            background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), url('8.jpg');
            background-size: cover;
            background-position: center;
            position: relative;
            min-height: 80vh;
            background-image: url("photos/8.jpg");
        }

        .hero-text {
            flex: 1;
            padding-right: 40px;
            position: relative;
            z-index: 2;
        }

        .hero-text h1 {
            font-family: 'Playfair Display', serif;
            font-size: 48px;
            line-height: 1.2;
            color: black;
        }

        .hero-text p {
            color: black;
            margin: 20px 0;
            font-size: 18px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .btn {
            display: inline-block;
            padding: 12px 28px;
            background: #fff;
            color: #000;
            text-decoration: none;
            font-weight: 500;
            letter-spacing: 1px;
            border-radius: 30px;
            transition: all 0.3s;
        }

        .btn:hover {
            background: rgba(255,255,255,0.9);
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .hero-image {
            display: none;
        }

        /* Gallery */
        .gallery {
            text-align: center;
            padding: 80px 60px;
            background: #fff;
        }

        .gallery h2 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            margin-bottom: 40px;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 25px;
        }

        .gallery-grid .item img {
            width: 320px;
            height: 320px;
            border-radius: 12px;
            transition: all 0.5s;
            filter: grayscale(100%) contrast(1.1);
        }

        .gallery-grid .item img:hover {
            filter: grayscale(0);
            transform: scale(1.05);
        }

        /* Review Section */
        .reviews {
            background: #f7f7f7;
            text-align: center;
            padding: 80px 60px;
        }

        .reviews h2 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            margin-bottom: 50px;
        }

        .review-grid {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .review-card {
            position: relative;
            padding: 40px 30px;
            border-radius: 12px;
            max-width: 320px;
            min-height: 250px;
            transition: all 0.3s;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background-size: cover;
            background-position: center;
        }

        .review-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
            transition: all 0.3s;
        }

        .review-card:nth-child(1) {
            background-image: url('photos/9.jpg');
        }

        .review-card:nth-child(2) {
            background-image: url('photos/9.jpg');
        }

        .review-card:nth-child(3) {
            background-image: url('photos/9.jpg');
        }

        .review-card:hover {
            transform: translateY(-8px);
        }

        .review-card:hover::before {
            background: rgba(0, 0, 0, 0.4);
        }

        .review-card p {
            font-style: italic;
            color: #fff;
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
            font-size: 16px;
            line-height: 1.6;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .review-card span {
            font-weight: 600;
            color: #fff;
            position: relative;
            z-index: 2;
            font-size: 14px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        /* Store Description */
        .store-description {
            padding: 80px 60px;
            background: #fff;
        }

        .store-content {
            display: flex;
            align-items: center;
            gap: 50px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .store-text {
            flex: 1;
        }

        .store-text h2 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            margin-bottom: 25px;
        }

        .store-text p {
            color: #555;
            line-height: 1.8;
            margin-bottom: 15px;
        }

        .store-image {
            flex: 1;
        }

        .store-image img {
            width: 100%;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            transition: transform 0.6s;
        }

        .store-image img:hover {
            transform: scale(1.03);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">Tink Jewelry</div>
        <ul class="nav-menu">
            <li><a href="../index.php">Trang chủ</a></li>
            <?php if ($userRole == 'Quản lý'): ?>
                <li><a href="admin/accounts.php">Quản Lý Tài Khoản</a></li>
            <?php endif; ?>
            <li><a href="admin/stores.php">Quản Lý Cửa Hàng</a></li>
            <li><a href="admin/products.php">Quản Lý Sản Phẩm</a></li>
            <?php if ($userRole == 'Quản lý'): ?>
                <li><a href="admin/imports.php">Quản Lý Nhập Kho</a></li>
                <li><a href="admin/exports.php">Quản Lý Xuất Kho</a></li>
                <li><a href="admin/reports.php">Quản Lý Báo Cáo</a></li>
            <?php else: ?>
                <li><a href="admin/imports.php">Quản Lý Nhập Kho</a></li>
                <li><a href="admin/exports.php">Quản Lý Xuất Kho</a></li>
            <?php endif; ?>
        </ul>
        <button class="logout-btn" onclick="location.href='logout.php'">Đăng Xuất</button>
    </header>

    <!-- Hero -->
    <section class="hero">
        <div class="hero-text">
            <h1>Vẻ đẹp tinh tế trong từng chi tiết</h1>
            <p>Khám phá sự sang trọng thuần khiết và tinh xảo qua từng món trang sức của TINK.</p>
            <a href="#gallery" class="btn">Khám phá ngay</a>
        </div>
        <div class="hero-image">
            <img src="photos/8.jpg" alt="Trang sức Tink">
        </div>
    </section>

    <!-- Gallery -->
    <section id="gallery" class="gallery">
        <h2>Bộ Sưu Tập Nổi Bật</h2>
        <div class="gallery-grid">
            <div class="item"><img src="photos/1.webp" alt="."></div>
            <div class="item"><img src="photos/2.webp" alt="."></div>
            <div class="item"><img src="photos/3.jpg" alt="."></div>
            <div class="item"><img src="photos/4.webp" alt="."></div>
        </div>
    </section>

    <!-- Store Description -->
    <section class="store-description">
        <div class="store-content">
            <div class="store-text">
                <h2>Không Gian TINK Jewelry</h2>
                <p>Bước vào TINK Jewelry, bạn sẽ được chào đón bởi một không gian sang trọng và ấm cúng. Với thiết kế tối giản nhưng tinh tế, showroom của chúng tôi là nơi hoàn hảo để bạn khám phá và trải nghiệm các bộ sưu tập trang sức độc đáo.</p>
                <p>Mỗi góc trưng bày được chăm chút tỉ mỉ, nơi ánh sáng tự nhiên hòa quyện cùng ánh kim của những món trang sức, tạo nên một không gian nghệ thuật đầy cảm hứng.</p>
            </div>
            <div class="store-image">
                <img src="photos/7.jpg" alt="TINK Jewelry Store">
            </div>
        </div>
    </section>

    <!-- Review -->
    <section class="reviews">
        <h2>Khách hàng nói gì về TINK</h2>
        <div class="review-grid">
            <div class="review-card">
                <p>“Chiếc vòng cổ thật sự tinh tế và sang trọng, cảm giác như được làm riêng cho tôi vậy.”</p>
                <span>- Lan Anh, Hà Nội</span>
            </div>
            <div class="review-card">
                <p>“Dịch vụ tuyệt vời, sản phẩm vượt ngoài mong đợi. Tôi chắc chắn sẽ quay lại.”</p>
                <span>- Minh Trang, TP.HCM</span>
            </div>
            <div class="review-card">
                <p>“Mỗi món trang sức của TINK đều mang một vẻ đẹp độc nhất, thật đáng giá.”</p>
                <span>- Thu Hà, Đà Nẵng</span>
            </div>
        </div>
    </section>
</body>
</html>