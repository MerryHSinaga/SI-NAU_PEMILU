<?php
declare(strict_types=1);
session_start();

$ERROR = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    $VALID_USER = "AdminSinauPemilu";
    $VALID_PASS = "KPUYogyakart4#";

    if ($username === $VALID_USER && $password === $VALID_PASS) {
        $_SESSION["admin"] = true;
        $_SESSION["admin_user"] = $username;

        header("Location: admin.php");
        exit;
    } else {
        $ERROR = "Username atau Password salah!";
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin | DIY</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root{
            --primary:#700D09;
            --bg:#e9edff;
        }

        body{
            margin:0;
            font-family:'Inter';
            background:var(--bg);
            min-height:100vh;
        }

        .bg-maroon{background:var(--primary)}

        .navbar-nav-simple{
            list-style:none;
            display:flex;
            gap:26px;
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
            margin:auto;
            padding:140px 20px;
        }

        .btn-back{
            background:var(--primary);
            color:#fff;
            font-weight:800;
            padding:14px 36px;
            border-radius:999px;
            border:0;
            margin-bottom:30px;
        }

        .card{
            background:#fff;
            border-radius:26px;
            box-shadow:0 14px 30px rgba(0,0,0,.15);
            display:grid;
            grid-template-columns:1fr 1.1fr;
            overflow:hidden;
        }

        .card-left{
            display:flex;
            align-items:center;
            justify-content:center;
            background:#fcfcfc;
        }

        .card-right{
            background:radial-gradient(120% 120% at 30% 15%, #9b1111 0%, #7c0c0c 55%, var(--primary) 100%);
            color:#fff;
            padding:60px;
        }

        .title{
            text-align:center;
            font-size:42px;
            font-weight:900;
            margin-bottom:50px;
        }

        .input{
            width:100%;
            background:transparent;
            border:0;
            border-bottom:2px solid rgba(255,255,255,.3);
            color:#fff;
            font-size:20px;
            padding:10px 0;
            outline:none;
        }

        .btn-submit{
            background:#fff;
            color:var(--primary);
            font-weight:900;
            font-size:22px;
            padding:18px;
            border-radius:18px;
            border:0;
            width:100%;
        }

        @media(max-width:860px){
            .card{grid-template-columns:1fr}
            .card-left{display:none}
            .navbar-nav-simple{display:none}
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top py-3">
  <div class="container d-flex justify-content-between">
    <a class="navbar-brand d-flex gap-2 align-items-center">
      <img src="Asset/LogoKPU.png" width="40">
      <span><strong>KPU</strong><br>DIY</span>
    </a>
    <ul class="navbar-nav-simple">
      <li><a class="nav-link" href="kontak.php">KONTAK</a></li>
      <li><a class="btn btn-outline-light btn-sm" href="login_admin.php">LOGIN</a></li>
    </ul>
  </div>
</nav>

<main class="page">
<button class="btn-back" onclick="history.back()">Kembali</button>

<section class="card">
<div class="card-left">
  <div class="avatar-wrap">
    <div class="avatar">
      <svg viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="9" stroke="var(--primary)" stroke-width="1.2"/>
        <circle cx="12" cy="9" r="3" stroke="var(--primary)" stroke-width="1.2"/>
        <path d="M12 15C8.5 15 6 17 5 19" stroke="var(--primary)" stroke-width="1.2" stroke-linecap="round"/>
        <path d="M12 15C15.5 15 18 17 19 19" stroke="var(--primary)" stroke-width="1.2" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="avatar-label">Administrator</div>
  </div>
</div>


<div class="card-right">
<h1 class="title">Masuk Sebagai Admin</h1>

<form method="post">
<div class="mb-4">
<label>Username</label>
<input class="input" name="username" required>
</div>

<div class="mb-4">
<label>Password</label>
<input class="input" type="password" name="password" required>
</div>

<button class="btn-submit">Masuk</button>

<?php if($ERROR): ?>
<div class="alert alert-danger mt-4 fw-bold text-center">
<?= htmlspecialchars($ERROR) ?>
</div>
<?php endif; ?>
</form>
</div>
</section>
</main>

</body>
</html>
