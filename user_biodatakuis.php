<?php
session_start();

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT BIODATA
|--------------------------------------------------------------------------
| - Simpan biodata ke SESSION
| - Reset jawaban kuis sebelumnya (jika ada)
| - Redirect ke halaman kuis user
*/
if (isset($_POST['mulai'])) {

    // Validasi minimal (server-side)
    if (
        empty($_POST['jk']) ||
        empty($_POST['nama']) ||
        empty($_POST['instansi']) ||
        empty($_POST['lokasi'])
    ) {
        // jika ada field kosong, tetap di halaman ini
    } else {
        // simpan biodata ke session
        $_SESSION['jk']       = $_POST['jk'];
        $_SESSION['nama']     = trim($_POST['nama']);
        $_SESSION['instansi'] = trim($_POST['instansi']);
        $_SESSION['lokasi']   = trim($_POST['lokasi']);

        // reset jawaban kuis sebelumnya (penting)
        unset($_SESSION['jawaban']);

        // redirect ke halaman kuis
        header("Location: user_kuis.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Uji Kemahiran Kepemiluan</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #E5E8FF;
            padding-top: 140px;
        }

        /* NAVBAR */
        .bg-maroon {
            background-color: #700D09;
        }

        .navbar-brand-text {
            border-left: 2px solid rgba(255,255,255,0.6);
            padding-left: 16px;
            margin-left: 16px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.35);
            font-weight: 700;
            line-height: 1.2;
            color: white;
        }

        .navbar-nav-simple {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .navbar-nav-simple li {
            display: inline-block;
            margin-left: 30px;
        }

        .navbar-nav-simple a {
            color: white;
            font-weight: 600;
            text-decoration: none;
        }

        .nav-active {
            border-bottom: 2px solid #ffffff;
            padding-bottom: 6px;
        }

        /* TITLE */
        .page-title {
            text-align: center;
            font-size: 48px;
            font-weight: 800;
            background: linear-gradient(180deg, #B30000, #700D09);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 4px 6px rgba(0,0,0,0.25);
            margin-bottom: 50px;
        }

        /* CARD */
        .card-main {
            background: #ffffff;
            border-radius: 30px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.25);
            overflow: hidden;
        }

        .card-right {
            background: linear-gradient(180deg, #8B0000, #700D09);
            color: white;
            padding: 50px;
        }

        /* RADIO */
        .radio-group {
            margin-bottom: 30px;
        }

        .radio-group label {
            margin-right: 30px;
            font-weight: 600;
            cursor: pointer;
        }

        .radio-group input[type="radio"] {
            accent-color: #540000;
            margin-right: 6px;
            transform: scale(1.1);
        }

        /* CUSTOM INPUT */
        .form-group-custom {
            margin-bottom: 28px;
        }

        .form-group-custom label {
            display: block;
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
        }

        .form-group-custom input {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 2px solid rgba(255,255,255,0.5);
            padding: 6px 2px;
            font-size: 16px;
            color: #ffffff;
        }

        .form-group-custom input::placeholder {
            color: rgba(255,255,255,0.6);
        }

        .form-group-custom input:focus {
            outline: none;
            border-bottom: 2px solid #ffffff;
            background: transparent;
        }

        .btn-kuis {
            background-color: #D00000;
            color: white;
            font-weight: 700;
            padding: 8px 34px;
            border-radius: 24px;
            border: none;
        }

        /* FOOTER */
        .footer {
            background-color: #700D09;
            margin-top: 90px;
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark bg-maroon fixed-top py-4">
    <div class="container d-flex justify-content-between align-items-center">

        <div class="d-flex align-items-center">
            <img src="/sinaupemilu/assets/LogoKPU.png" width="56" alt="Logo KPU">
            <div class="navbar-brand-text">
                DAERAH<br>ISTIMEWA<br>YOGYAKARTA
            </div>
        </div>

        <ul class="navbar-nav-simple">
            <li><a href="#">HOME</a></li>
            <li><a href="#">MATERI</a></li>
            <li><a href="#" class="nav-active">KUIS</a></li>
            <li><a href="#">KONTAK</a></li>
            <li><a href="#">LOGIN</a></li>
        </ul>

    </div>
</nav>

<!-- TITLE -->
<div class="page-title">
    Uji Kemahiran Kepemiluan
</div>

<!-- CONTENT -->
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="row card-main">

                <!-- LEFT IMAGE -->
                <div class="col-md-5 d-flex justify-content-center align-items-center p-4">
                    <img src="/sinaupemilu/assets/Welcome.png" class="img-fluid" alt="Welcome">
                </div>

                <!-- RIGHT FORM -->
                <div class="col-md-7 card-right">
                    <h3 class="fw-bold mb-4">Daftarkan diri</h3>

                    <form method="post">

                        <!-- RADIO JENIS KELAMIN -->
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="jk" value="Laki-Laki" required>
                                Laki-Laki
                            </label>
                            <label>
                                <input type="radio" name="jk" value="Perempuan">
                                Perempuan
                            </label>
                        </div>

                        <div class="form-group-custom">
                            <label>Nama Lengkap</label>
                            <input type="text" name="nama" placeholder="Nama Lengkap" required>
                        </div>

                        <div class="form-group-custom">
                            <label>Asal Instansi</label>
                            <input type="text" name="instansi" placeholder="Asal Instansi" required>
                        </div>

                        <div class="form-group-custom">
                            <label>Lokasi Ujian</label>
                            <input type="text" name="lokasi" placeholder="Lokasi Ujian" required>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="mulai" class="btn-kuis">
                                Mulai Kuis
                            </button>
                        </div>
                    </form>

                </div>

            </div>
        </div>
    </div>
</div>

<!-- FOOTER -->
<footer class="footer">
    <img src="/sinaupemilu/assets/Footer.png" class="img-fluid w-100" alt="Footer">
</footer>

</body>
</html>
