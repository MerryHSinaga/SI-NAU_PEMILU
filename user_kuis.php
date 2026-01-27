<?php
session_start();

/* ===== DB CONFIG ===== */
$pdo = new PDO(
    "mysql:host=localhost;dbname=sinau_pemilu;charset=utf8mb4",
    "root",
    "",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

/* ===== AMBIL PAKET TERBARU ===== */
$paket = $pdo->query("
    SELECT id, judul FROM kuis_paket
    ORDER BY id DESC LIMIT 1
")->fetch();

if (!$paket) die("Kuis belum tersedia.");

/* ===== AMBIL SOAL ===== */
$st = $pdo->prepare("
    SELECT nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d
    FROM kuis_soal
    WHERE paket_id=?
    ORDER BY nomor ASC
");
$st->execute([$paket['id']]);
$soal = $st->fetchAll();

$totalSoal = count($soal);
if ($totalSoal === 0) die("Soal belum tersedia.");

/* ===== INDEX ===== */
$currentIndex = isset($_POST['current_index'])
    ? (int)$_POST['current_index']
    : 0;

$targetIndex = isset($_POST['target_index'])
    ? (int)$_POST['target_index']
    : $currentIndex;

$currentIndex = max(0, min($currentIndex, $totalSoal - 1));
$targetIndex  = max(0, min($targetIndex,  $totalSoal - 1));

/* ===== SIMPAN JAWABAN UNTUK SOAL SAAT INI ===== */
if (isset($_POST['jawaban'])) {
    $nomorSoal = $soal[$currentIndex]['nomor'];
    $_SESSION['jawaban'][$nomorSoal] = $_POST['jawaban'];
}

/* ===== PINDAH KE SOAL TUJUAN ===== */
$index = $targetIndex;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kuis – Sinau Pemilu</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">

<style>
body{font-family:Inter;background:#E5E8FF;padding-top:140px}
.bg-maroon{background:#700D09}
.footer{background:#700D09;margin-top:90px}

.soal-card{background:#fff;border-radius:20px;padding:30px;box-shadow:0 8px 20px rgba(0,0,0,.2)}
.opsi label{display:block;margin-bottom:10px;font-weight:500;cursor:pointer}
.opsi input{accent-color:#700D09;margin-right:8px}

.nav-soal{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:25px}
.nav-soal button{
    width:36px;height:36px;border-radius:50%;
    border:2px solid #700D09;
    background:transparent;color:#700D09;font-weight:600
}
.nav-soal button.answered{background:#700D09;color:#fff}
.nav-soal button.active{background:rgba(112,13,9,.2)}

.btn-prev{background:rgba(112,13,9,.78);color:#fff;border-radius:25px;padding:8px 30px;border:0}
.btn-next{background:#700D09;color:#fff;border-radius:25px;padding:8px 30px;border:0}
.btn-submit{background:#459517;color:#fff;border-radius:25px;padding:8px 40px;border:0}
</style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top py-4">
<div class="container">
    <img src="/sinaupemilu/assets/LogoKPU.png" width="56">
</div>
</nav>

<div class="container">
<h4><?= htmlspecialchars($paket['judul']) ?></h4>

<div class="soal-card mt-3">
<form method="post" id="quizForm">
<input type="hidden" name="current_index" value="<?= $index ?>">
<input type="hidden" name="target_index" id="target_index">

<p>
<b><?= $soal[$index]['nomor'] ?>.</b>
<?= htmlspecialchars($soal[$index]['pertanyaan']) ?>
</p>

<div class="opsi">
<?php
$opsi = [
    'A' => $soal[$index]['opsi_a'],
    'B' => $soal[$index]['opsi_b'],
    'C' => $soal[$index]['opsi_c'],
    'D' => $soal[$index]['opsi_d'],
];
foreach ($opsi as $k => $v):
$checked = (
    isset($_SESSION['jawaban'][$soal[$index]['nomor']]) &&
    $_SESSION['jawaban'][$soal[$index]['nomor']] === $k
);
?>
<label>
<input type="radio" name="jawaban" value="<?= $k ?>" <?= $checked ? 'checked' : '' ?>>
<?= htmlspecialchars($v) ?>
</label>
<?php endforeach; ?>
</div>

<div class="d-flex justify-content-between mt-4">
<?php if ($index > 0): ?>
<button type="submit" class="btn-prev" onclick="go(<?= $index-1 ?>)">Sebelumnya</button>
<?php endif; ?>

<?php if ($index < $totalSoal-1): ?>
<button type="submit" class="btn-next" onclick="go(<?= $index+1 ?>)">Selanjutnya</button>
<?php else: ?>
<button class="btn-submit">Kirim</button>
<?php endif; ?>
</div>
</form>
</div>

<div class="nav-soal">
<?php foreach ($soal as $i => $s):
$class = ($i === $index)
    ? 'active'
    : (isset($_SESSION['jawaban'][$s['nomor']]) ? 'answered' : '');
?>
<button type="submit" form="quizForm"
        class="<?= $class ?>"
        onclick="go(<?= $i ?>)">
<?= $s['nomor'] ?>
</button>
<?php endforeach; ?>
</div>
</div>

<footer class="footer">
<img src="/sinaupemilu/assets/Footer.png" class="img-fluid w-100">
</footer>

<script>
function go(i){
    document.getElementById('target_index').value = i;
}
</script>

</body>
</html>
