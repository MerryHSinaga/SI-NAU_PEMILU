<?php
session_start();

/**
 * PROTEKSI AKSES & DATABASE
 */
if (!isset($_SESSION['kuis_id']) || !isset($_SESSION['nama'])) {
    header("Location: daftar_kuis.php");
    exit;
}

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=sinau_pemilu;charset=utf8mb4",
            "root",
            "",
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        die("Koneksi database gagal.");
    }
}

$paketId = (int) $_SESSION['kuis_id'];

/**
 * RESET SESSION SAAT KUIS DIMULAI
 */
if (!isset($_SESSION['kuis_mulai'])) {
    $_SESSION['kuis_mulai'] = true;
    $_SESSION['jawaban'] = [];
    unset($_SESSION['soal_acak_' . $paketId]);
}

/**
 * AMBIL DATA PAKET & SOAL
 */
$stmt = db()->prepare("SELECT judul FROM kuis_paket WHERE id=?");
$stmt->execute([$paketId]);
$paket = $stmt->fetch();
if (!$paket) { header("Location: daftar_kuis.php"); exit; }

$sessionKey = 'soal_acak_' . $paketId;
if (!isset($_SESSION[$sessionKey])) {
    $stmt = db()->prepare("SELECT id, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban FROM kuis_soal WHERE paket_id=?");
    $stmt->execute([$paketId]);
    $soalAsli = $stmt->fetchAll();
    
    if (!$soalAsli) die("Soal belum tersedia");
    
    shuffle($soalAsli);
    foreach ($soalAsli as &$s) {
        $opsi = [
            'A' => $s['opsi_a'], 'B' => $s['opsi_b'], 
            'C' => $s['opsi_c'], 'D' => $s['opsi_d']
        ];
        $keys = array_keys($opsi);
        shuffle($keys);
        $s['opsi_acak'] = [];
        foreach ($keys as $k) { $s['opsi_acak'][$k] = $opsi[$k]; }
    }
    unset($s);
    $_SESSION[$sessionKey] = $soalAsli;
}

$soal = $_SESSION[$sessionKey];
$totalSoal = count($soal);

/**
 * LOGIKA NAVIGASI & SIMPAN JAWABAN
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['soal_id'])) {
        $sId = (int)$_POST['soal_id'];
        if (isset($_POST['jawaban'])) {
            $_SESSION['jawaban'][$sId] = $_POST['jawaban'];
        }
    }

    if (isset($_POST['submit_kuis'])) {
        $benar = 0;
        foreach ($soal as $s) {
            $userJawab = $_SESSION['jawaban'][$s['id']] ?? '';
            if (strtoupper($userJawab) === strtoupper($s['jawaban'])) {
                $benar++;
            }
        }
        $_SESSION['skor'] = round(($benar / $totalSoal) * 100);
        $_SESSION['materi'] = $paket['judul'];
        
        unset($_SESSION['kuis_mulai'], $_SESSION[$sessionKey]);
        header("Location: user_nilaikuis.php");
        exit;
    }
}

$index = isset($_POST['target_index']) ? (int)$_POST['target_index'] : 0;
$index = max(0, min($index, $totalSoal - 1));
$curSoal = $soal[$index];
$jawabanTersimpan = $_SESSION['jawaban'] ?? [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kuis – Sinau Pemilu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #E5E8FF; padding-top: 120px; padding-bottom: 50px; }
        .bg-maroon { background: #700D09; }
        .soal-card { background: #fff; border-radius: 20px; padding: 30px; box-shadow: 0 8px 20px rgba(0,0,0,0.2); border: none; }
        
        .opsi-container { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .opsi-item { 
            display: block; cursor: pointer; padding: 5px 0;
        }
        .opsi-item input { 
            accent-color: #700D09; margin-right: 10px; cursor: pointer;
            width: 16px; height: 16px; vertical-align: middle;
        }
        .opsi-item span { vertical-align: middle; font-size: 16px; color: #333; }
        
        .nav-soal { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-top: 25px; }
        .nav-item { 
            width: 36px; height: 36px; border-radius: 50%; border: 2px solid #700D09; 
            background: transparent; color: #700D09; font-weight: 600; display: flex; 
            align-items: center; justify-content: center; cursor: pointer; transition: 0.3s;
            text-decoration: none; font-size: 14px;
        }
        .nav-item.answered { background: #700D09; color: #fff; }
        .nav-item.active { background: rgba(112, 13, 9, 0.2); }
        
        .btn-custom { border-radius: 25px; padding: 8px 30px; font-weight: 500; border: none; color: #fff; transition: 0.3s; }
        .btn-prev { background: rgba(112, 13, 9, 0.8); }
        .btn-next { background: #700D09; }
        .btn-submit { background: #459517; }
        .btn-custom:hover { opacity: 0.9; }

        /* Modal Styles sesuai desain awal */
        .modal-overlay { 
            position: fixed; inset: 0; background: rgba(0,0,0,0.6); 
            display: none; align-items: center; justify-content: center; z-index: 9999; 
        }
        .modal-content-custom { 
            background: #fff; padding: 26px; border-radius: 18px; 
            width: 360px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .modal-btns { display: flex; gap: 12px; justify-content: center; margin-top: 18px; }
        .btn-modal { 
            border: 0; border-radius: 20px; padding: 6px 16px; min-width: 110px;
            font-weight: 600; transition: 0.2s;
        }
        .btn-modal-primary { background: #700D09; color: #fff; }
        .btn-modal-secondary { background: #700D09; color: #fff; } /* Mengikuti style tombol desain awal */
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top py-4">
    <div class="container">
        <img src="/sinaupemilu/assets/LogoKPU.png" width="56" alt="Logo KPU" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/4/46/KPU_Logo.svg'">
    </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-9">
            <h4 class="mb-3" style="font-weight: 700;"><?= htmlspecialchars($paket['judul']) ?></h4>
            
            <div class="soal-card">
                <form method="POST" id="quizForm" action="user_kuis.php">
                    <input type="hidden" name="target_index" id="target_index" value="<?= $index ?>">
                    <input type="hidden" name="soal_id" value="<?= $curSoal['id'] ?>">

                    <p><b><?= $index + 1 ?>.</b> <?= htmlspecialchars($curSoal['pertanyaan']) ?></p>

                    <div class="opsi-container">
                        <?php foreach ($curSoal['opsi_acak'] as $key => $val): ?>
                            <label class="opsi-item">
                                <input type="radio" name="jawaban" value="<?= $key ?>" 
                                    <?= (($jawabanTersimpan[$curSoal['id']] ?? '') === $key) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($val) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <?php if ($index > 0): ?>
                            <button type="button" class="btn-custom btn-prev" onclick="navigate(<?= $index - 1 ?>)">Sebelumnya</button>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <?php if ($index < $totalSoal - 1): ?>
                            <button type="button" class="btn-custom btn-next" onclick="navigate(<?= $index + 1 ?>)">Selanjutnya</button>
                        <?php else: ?>
                            <button type="button" class="btn-custom btn-submit" onclick="attemptSubmit()">Kirim</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="nav-soal">
                <?php foreach ($soal as $i => $s): 
                    $isAnswered = isset($jawabanTersimpan[$s['id']]);
                    $activeClass = ($i === $index) ? 'active' : '';
                    $answeredClass = $isAnswered ? 'answered' : '';
                ?>
                    <div class="nav-item <?= $activeClass ?> <?= $answeredClass ?>" onclick="navigate(<?= $i ?>)">
                        <?= $i + 1 ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODAL EXIT -->
<div class="modal-overlay" id="exitModal">
    <div class="modal-content-custom">
        <p><b>Akhiri Kuis?</b><br>
        <b>Jawaban tidak akan disimpan.</b></p>
        <div class="modal-btns">
            <button class="btn-modal btn-modal-primary" onclick="confirmExit()">Ya</button>
            <button class="btn-modal btn-modal-secondary" onclick="closeModal('exitModal')">Tidak</button>
        </div>
    </div>
</div>

<!-- MODAL INCOMPLETE -->
<div class="modal-overlay" id="incompleteModal">
    <div class="modal-content-custom">
        <p><b>Tidak dapat mengirim jawaban</b><br>
        <b>Pastikan Anda telah menjawab seluruh soal.<br>
        Silakan periksa kembali jawaban Anda sebelum mengirim.</b></p>
        <div class="modal-btns">
            <button class="btn-modal btn-modal-primary" onclick="closeModal('incompleteModal')">Ya</button>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI KIRIM -->
<div class="modal-overlay" id="confirmSubmitModal">
    <div class="modal-content-custom">
        <p><b>Akhiri Kuis?</b><br>
        <b>Jawaban tidak dapat diubah.</b></p>
        <div class="modal-btns">
            <button class="btn-modal btn-modal-primary" onclick="finalSubmit()">Ya</button>
            <button class="btn-modal btn-modal-secondary" onclick="closeModal('confirmSubmitModal')">Tidak</button>
        </div>
    </div>
</div>

<script>
    let isNavigatingInternal = false;
    const totalSoal = <?= $totalSoal ?>;
    
    function navigate(targetIndex) {
        if (targetIndex < 0 || targetIndex >= totalSoal) return;
        isNavigatingInternal = true;
        document.getElementById('target_index').value = targetIndex;
        document.getElementById('quizForm').submit();
    }

    function attemptSubmit() {
        // Logika pengecekan jawaban di Client-side (session-based sync)
        const answeredCount = <?= count($jawabanTersimpan) ?>;
        const currentRadio = document.querySelector('input[name="jawaban"]:checked');
        const isCurrentAnswered = <?= isset($jawabanTersimpan[$curSoal['id']]) ? 'true' : 'false' ?>;
        
        let totalTerjawab = answeredCount;
        if(!isCurrentAnswered && currentRadio) totalTerjawab++;

        if (totalTerjawab < totalSoal) {
            document.getElementById('incompleteModal').style.display = 'flex';
        } else {
            document.getElementById('confirmSubmitModal').style.display = 'flex';
        }
    }

    function finalSubmit() {
        isNavigatingInternal = true;
        const form = document.getElementById('quizForm');
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'submit_kuis';
        hiddenInput.value = '1';
        form.appendChild(hiddenInput);
        form.submit();
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    // PENCEGAHAN BACK BUTTON SESUAI DESAIN AWAL
    history.pushState(null, null, window.location.href);
    window.onpopstate = function () {
        if (!isNavigatingInternal) {
            document.getElementById('exitModal').style.display = 'flex';
            history.pushState(null, null, window.location.href);
        }
    };

    function confirmExit() {
        isNavigatingInternal = true;
        window.location.href = 'daftar_kuis.php';
    }

    window.onbeforeunload = function(e) {
        if (!isNavigatingInternal) {
            e.preventDefault();
            return "Apakah Anda yakin ingin meninggalkan kuis?";
        }
    };
</script>

</body>
</html>
