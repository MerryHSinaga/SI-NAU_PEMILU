<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"])) {
  header("Location: login_admin.php");
  exit;
}

?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin | DIY</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{
      --maroon:#700D09;
      --bg:#e9edff;
      --card-shadow:0 16px 22px rgba(0,0,0,.20);
      --btn-shadow:0 10px 16px rgba(0,0,0,.16);
    }

    body{
      margin:0;
      font-family:'Inter',system-ui,-apple-system,sans-serif;
      background:var(--bg);
      min-height:100vh;
      display:flex;
      flex-direction:column;
    }

    .bg-maroon{background:var(--maroon)!important}

    .navbar-nav-simple{
      list-style:none;
      display:flex;
      align-items:center;
      gap:30px;
      margin:0;
      padding:0;
    }

    .navbar-nav-simple .nav-link{
      color:#fff;
      font-weight:700;
      letter-spacing:.5px;
      text-decoration:none;
    }

    .page{
      max-width:1200px;
      margin:0 auto;
      width:100%;
      padding:120px 20px 16px;
      flex:1;
      display:flex;
      flex-direction:column;
    }

    .btn-back{
      background:var(--maroon);
      color:#fff;
      font-weight:800;
      font-size:18px;
      padding:12px 34px;
      border-radius:999px;
      border:0;
      box-shadow:0 10px 14px rgba(0,0,0,.18);
      display:inline-flex;
      align-items:center;
      gap:20px;
      width:max-content;
      margin-top:20px;
      cursor:pointer;
    }

    .title{
      text-align:center;
      margin:34px 0 38px;
      font-weight:800;
      font-size:40px;
      color:#c61b1b;
    }

    .grid{
      display:grid;
      grid-template-columns:repeat(2, 320px);
      justify-content:center;
      gap:90px;
      flex:1;
      align-content:center;
    }

    .choice-card{
      background:#fff;
      border-radius:20px;
      height:340px;
      box-shadow:var(--card-shadow);
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:44px;
    }

    .choice-icon{
      font-size:88px;
      color:var(--maroon);
    }

    .choice-btn{
      background:var(--maroon);
      color:#fff;
      font-weight:800;
      font-size:20px;
      padding:12px 44px;
      border-radius:999px;
      box-shadow:var(--btn-shadow);
      text-decoration:none;
      display:inline-block;
    }

    .footer-img{
      width:100%;
      height:110px;
      object-fit:cover;
      display:block;
      margin-top:auto;
    }

    @media (max-width: 992px){
      .grid{grid-template-columns:1fr;gap:34px}
      .choice-card{width:min(420px,100%)}
      .navbar-nav-simple{display:none}
    }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top" style="padding:20px 0;">
  <div class="container d-flex justify-content-between align-items-center">
    <a class="navbar-brand d-flex align-items-center gap-2" href="#">
      <img src="Asset/LogoKPU.png" width="40" height="40" alt="Logo KPU">
      <span><strong>KPU</strong><br>DIY</span>
    </a>

    <ul class="navbar-nav-simple">
      <li><a class="nav-link" href="tambah_materi_admin.php">MATERI</a></li>
      <li><a class="nav-link" href="kuis_admin.php">KUIS</a></li>
      <li><a class="nav-link" href="kontak.php">KONTAK</a></li>
      <li><a class="nav-link" href="login_admin.php">LOGIN</a></li>
    </ul>
  </div>
</nav>

<main class="page">
  <button class="btn-back" type="button" onclick="history.back()">
    <i class="bi bi-arrow-left"></i>
    Kembali
  </button>

  <h1 class="title">Halo, Ingin Menambahkan Apa Hari Ini?</h1>

  <section class="grid">
    <div class="choice-card">
      <i class="bi bi-folder-fill choice-icon"></i>
      <a class="choice-btn" href="tambah_materi.php">Tambah Materi</a>
    </div>

    <div class="choice-card">
      <i class="bi bi-pencil-fill choice-icon"></i>
      <a class="choice-btn" href="kuis_admin.php">Tambah Kuis</a>
    </div>
  </section>
</main>

<img src="Asset/Footer.png" class="footer-img" alt="Footer">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
