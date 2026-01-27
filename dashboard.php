<?php
declare(strict_types=1);
session_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SI-NAU Pemilu | KPU DIY</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root { --maroon:#700D09; }

        body{
            margin:0;
            font-family:'Inter',sans-serif;
            background:#fff;
            overflow-y:scroll;
        }

        .bg-maroon{background:var(--maroon)}

        .navbar{
            padding:20px 0;
            border-bottom:1px solid #000;
        }

        .nav-link{
            color:#fff;
            font-weight:500;
        }

        .nav-hover{position:relative;padding-bottom:6px}
        .nav-hover::after{
            content:"";
            position:absolute;
            left:0;
            bottom:0;
            width:0;
            height:3px;
            background:#f4c430;
            transition:.3s;
        }
        .nav-hover:hover::after{width:100%}

        .hero-section{height:420px;overflow:hidden}
        .hero-image{width:100%;height:100%;object-fit:cover}

        .section-title{
            color:var(--maroon);
            font-weight:800;
        }

        .info-box{
            background:#fff;
            padding:20px 28px;
            border-radius:12px;
            max-width:700px;
            margin:auto;
            box-shadow:0 4px 14px rgba(0,0,0,.08);
        }

        .alur-card{
            background:var(--maroon);
            color:#fff;
            padding:24px 16px;
            border-radius:14px;
            text-align:center;
            transition:.3s;
        }
        .alur-card:hover{
            transform:translateY(-5px);
            box-shadow:0 8px 20px rgba(0,0,0,.25);
        }

        .feature-card{
            background:#fff;
            padding:26px;
            border-radius:16px;
            text-align:center;
            transition:.3s;
            box-shadow:0 4px 14px rgba(0,0,0,.08);
        }
        .feature-card:hover{
            transform:translateY(-5px);
            box-shadow:0 10px 25px rgba(0,0,0,.2);
        }

        .btn-maroon{
            background:var(--maroon);
            color:#fff;
            padding:6px 18px;
            border-radius:18px;
            font-size:.9rem;
        }
        .btn-maroon:hover{background:#5b0a07;color:#fff}

        footer{
            background:var(--maroon);
            color:#fff;
            text-align:center;
            padding:32px 0;
            margin-top:60px;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-maroon fixed-top">
    <div class="container d-flex justify-content-between align-items-center">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <img src="Asset/LogoKPU.png" height="40" alt="KPU">
            <span class="lh-sm text-white fs-6">
                <strong>KPU</strong><br>DIY
            </span>
        </a>

        <ul class="navbar-nav flex-row gap-4">
            <li class="nav-item"><a class="nav-link nav-hover" href="dashboard.php">HOME</a></li>
            <li class="nav-item"><a class="nav-link nav-hover" href="materi.php">MATERI</a></li>
            <li class="nav-item"><a class="nav-link nav-hover" href="daftar_kuis.php">KUIS</a></li>
            <li class="nav-item"><a class="nav-link nav-hover" href="kontak.php">KONTAK</a></li>

            <?php if(!empty($_SESSION["admin"])): ?>
                <li class="nav-item">
                    <a class="btn btn-outline-light btn-sm px-3" href="login_admin.php">ADMIN</a>
                </li>
            <?php else: ?>
                <li class="nav-item">
                    <a class="btn btn-outline-light btn-sm px-3" href="login_admin.php">LOGIN</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<div style="height:80px"></div>

<section class="hero-section">
    <img src="Asset/Welcome.png" class="hero-image" alt="SI-NAU Pemilu">
</section>

<section class="py-5 text-center">
    <h2 class="section-title mb-4">SI-NAU PEMILU</h2>
    <div class="info-box">
        SI-NAU Pemilu merupakan media edukasi pemilu berbasis website
        untuk membantu masyarakat memahami proses dan nilai-nilai pemilu
        secara sederhana dan interaktif.
    </div>
</section>

<section class="container py-5">
    <h3 class="section-title text-center mb-4">Alur Pelaksanaan</h3>

    <div class="row g-4">
        <div class="col-md-3"><div class="alur-card"><i class="bi bi-book fs-3 mb-2"></i><p>Pilih & Baca<br>Materi</p></div></div>
        <div class="col-md-3"><div class="alur-card"><i class="bi bi-ui-checks fs-3 mb-2"></i><p>Ikuti<br>Kuis</p></div></div>
        <div class="col-md-3"><div class="alur-card"><i class="bi bi-bar-chart fs-3 mb-2"></i><p>Lihat<br>Hasil</p></div></div>
        <div class="col-md-3"><div class="alur-card"><i class="bi bi-award fs-3 mb-2"></i><p>Unduh<br>Sertifikat</p></div></div>
    </div>
</section>

<section class="container py-5 text-center">
    <h3 class="section-title mb-4">Ayo, Kenali Pemilu Lebih Dekat!</h3>

    <div class="row justify-content-center g-4">
        <div class="col-md-4">
            <div class="feature-card">
                <h5 class="fw-bold">Materi</h5>
                <p class="text-muted small">Materi yang memperkenalkan tentang pemilu.</p>
                <a href="materi.php" class="btn btn-maroon">Baca</a>
            </div>
        </div>

        <div class="col-md-4">
            <div class="feature-card">
                <h5 class="fw-bold">Kuis</h5>
                <p class="text-muted small">Uji pemahaman Anda tentang pemilu.</p>
                <a href="daftar_kuis.php" class="btn btn-maroon">Coba</a>
            </div>
        </div>
    </div>
</section>

<script src="Asset/acc-toolbar.js"></script>
</body>
</html>
