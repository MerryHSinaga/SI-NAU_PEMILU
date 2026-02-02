<?php
session_start();

/* CEK APAKAH KUIS SUDAH DISELESAIKAN */
if (
    !isset($_SESSION['skor']) ||
    !isset($_SESSION['jawaban'])
) {
    header("Location: user_kuis.php");
    exit;
}

/* AMBIL DATA SESSION */
$nama   = $_SESSION['nama']     ?? 'Peserta';
$materi = $_SESSION['materi']   ?? 'Pemilu';
$skor   = $_SESSION['skor'];
$pred   = $_SESSION['predikat'] ?? 'Predikat';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Hasil Kuis</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
body{
    font-family:'Inter',sans-serif;
    background:#E5E8FF;
    padding-top:150px;
    padding-bottom:220px;
}

/* ===== NAVBAR ===== */
.bg-maroon{background:#700D09}

/* ===== TITLE ===== */
.title{
    text-align:center;
    font-size:56px;
    font-weight:900;
    color:#B00000;
    margin-bottom:40px;
}

/* ===== RESULT CARD ===== */
.result-box{
    max-width:920px;
    margin:auto;
    background:linear-gradient(180deg,#700D09,#950600);
    border-radius:36px;
    padding:56px 50px;
    color:#fff;
    text-align:center;
    box-shadow:0 16px 36px rgba(0,0,0,.45);
}

.score{
    font-size:92px;
    font-weight:900;
    margin:6px 0 4px;
}

.predikat{
    font-size:22px;
    font-weight:700;
}

.line{
    width:200px;
    height:3px;
    background:#E00000;
    margin:12px auto 22px;
}

.btn-wrap{margin-top:36px}

.btn-glossy{
    background:linear-gradient(180deg,#FF1A1A,#B00000);
    color:#fff;
    border:none;
    border-radius:40px;
    padding:11px 36px;
    margin:0 10px;
    font-size:18px;
    font-weight:700;
    text-decoration:none;
}

/* ===== MODAL EXIT ===== */
.modal-exit{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.6);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
}

.modal-box{
    background:#fff;
    border-radius:22px;
    padding:26px 30px;
    max-width:420px;
    text-align:center;
}

.modal-box p{
    font-size:15px;
    color:#444;
    margin-bottom:22px;
}

.modal-actions{
    display:flex;
    gap:14px;
    justify-content:center;
}

.btn-yes{
    background:#700D09;
    color:#fff;
    border:0;
    border-radius:20px;
    padding:6px 16px;
    min-width:110px;
}

.btn-no{
    background:#ddd;
    border:0;
    border-radius:20px;
    padding:6px 16px;
    min-width:110px;
}
</style>
</head>

<body>

<nav class="navbar navbar-dark bg-maroon fixed-top py-4">
<div class="container">
    <img src="/sinaupemilu/assets/LogoKPU.png" width="56">
</div>
</nav>

<div class="container">
    <div class="title">HASIL KUIS ANDA</div>

    <div class="result-box">
        <p>Selamat, Anda telah berhasil menyelesaikan SI-NAU PEMILU hari ini!</p>

        <div class="score"><?= $skor ?></div>
        <div class="predikat"><?= $pred ?></div>
        <div class="line"></div>

        <p>
            Semoga pembelajaran ini dapat meningkatkan pemahaman tentang pemilu
            dan partisipasi dalam demokrasi.
        </p>

        <div class="btn-wrap">
            <!-- PERBAIKAN: navigasi via JS -->
            <a href="#"
               class="btn-glossy"
               onclick="goToJawaban()">
               Cek Jawaban
            </a>

            <a href="download_sertifikat.php"
               class="btn-glossy"
               onclick="allowNavigate = true;">
               Download Sertifikat
            </a>
        </div>
    </div>
</div>

<!-- MODAL EXIT -->
<div class="modal-exit" id="exitModal">
    <div class="modal-box">
        <p>
            Anda yakin ingin keluar?<br>
            Pastikan telah mendownload sertifikat dan memeriksa hasil kuis anda.
        </p>
        <div class="modal-actions">
            <button class="btn-yes" onclick="allowExit=true;window.location='daftar_kuis.php'">Ya</button>
            <button class="btn-no" onclick="closeExit()">Tidak</button>
        </div>
    </div>
</div>

<script>
let allowExit = false;
let allowNavigate = false;

/* Simpan state awal */
window.history.pushState(null, "", window.location.href);

/* Deteksi tombol BACK */
window.addEventListener("popstate", function () {
    if (!allowExit && !allowNavigate) {
        openExit();
        window.history.pushState(null, "", window.location.href);
    }
});

function openExit(){
    document.getElementById("exitModal").style.display = "flex";
}

function closeExit(){
    document.getElementById("exitModal").style.display = "none";
}

/* ===== TAMBAHAN MINIMAL (INTI PERBAIKAN) ===== */
function goToJawaban(){
    allowNavigate = true;
    window.location.href = "user_jawabankuis.php";
}
</script>

</body>
</html>
