<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"])) {
  header("Location: login_admin.php");
  exit;
}

/* =======================
   KONFIG DATABASE
======================= */
$DB_HOST = "localhost";
$DB_NAME = "sinau_pemilu";
$DB_USER = "root";
$DB_PASS = "";

/* =======================
   DB CONNECT
======================= */
function db(): PDO {
  global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
  static $pdo = null;
  if ($pdo) return $pdo;

  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function ensure_tables(): void {
  db()->exec("
    CREATE TABLE IF NOT EXISTS kuis_paket (
      id INT AUTO_INCREMENT PRIMARY KEY,
      judul VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  db()->exec("
    CREATE TABLE IF NOT EXISTS kuis_soal (
      id INT AUTO_INCREMENT PRIMARY KEY,
      paket_id INT NOT NULL,
      nomor INT NOT NULL,
      pertanyaan TEXT NOT NULL,
      opsi_a VARCHAR(255) NOT NULL,
      opsi_b VARCHAR(255) NOT NULL,
      opsi_c VARCHAR(255) NOT NULL,
      opsi_d VARCHAR(255) NOT NULL,
      jawaban ENUM('A','B','C','D') NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_paket_nomor (paket_id, nomor),
      CONSTRAINT fk_soal_paket FOREIGN KEY (paket_id) REFERENCES kuis_paket(id)
        ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

function paket_create(string $judul): int {
  $judul = trim($judul);
  if ($judul === "") throw new RuntimeException("Judul kuis wajib diisi.");
  $st = db()->prepare("INSERT INTO kuis_paket (judul) VALUES (?)");
  $st->execute([$judul]);
  return (int)db()->lastInsertId();
}

function paket_update(int $id, string $judul): void {
  $judul = trim($judul);
  if ($id <= 0) throw new RuntimeException("ID paket tidak valid.");
  if ($judul === "") throw new RuntimeException("Judul kuis wajib diisi.");
  $st = db()->prepare("UPDATE kuis_paket SET judul=? WHERE id=?");
  $st->execute([$judul, $id]);
}

function soal_upsert(
  int $paketId,
  int $nomor,
  string $pertanyaan,
  string $a,
  string $b,
  string $c,
  string $d,
  string $jawaban
): void {
  if ($paketId <= 0) throw new RuntimeException("Paket ID tidak valid.");
  if ($nomor < 1 || $nomor > 15) throw new RuntimeException("Nomor soal harus 1 - 15.");

  $pertanyaan = trim($pertanyaan);
  $a = trim($a); $b = trim($b); $c = trim($c); $d = trim($d);
  $jawaban = strtoupper(trim($jawaban));

  // jika user belum mengisi nomor itu (kosong semua), skip aja (biar bulk bisa aman)
  if ($pertanyaan === "" && $a === "" && $b === "" && $c === "" && $d === "" && $jawaban === "") {
    return;
  }

  if ($pertanyaan === "" || $a === "" || $b === "" || $c === "" || $d === "") {
    throw new RuntimeException("Nomor {$nomor}: Pertanyaan dan semua pilihan wajib diisi.");
  }
  if (!in_array($jawaban, ["A","B","C","D"], true)) {
    throw new RuntimeException("Nomor {$nomor}: Jawaban harus A/B/C/D.");
  }

  $sql = "
    INSERT INTO kuis_soal (paket_id, nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      pertanyaan=VALUES(pertanyaan),
      opsi_a=VALUES(opsi_a),
      opsi_b=VALUES(opsi_b),
      opsi_c=VALUES(opsi_c),
      opsi_d=VALUES(opsi_d),
      jawaban=VALUES(jawaban)
  ";
  $st = db()->prepare($sql);
  $st->execute([$paketId, $nomor, $pertanyaan, $a, $b, $c, $d, $jawaban]);
}

ensure_tables();

/* =======================
   AJAX: DETAIL PAKET
======================= */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "paket_detail") {
  header("Content-Type: application/json; charset=utf-8");
  $id = (int)($_GET["id"] ?? 0);
  if ($id <= 0) { echo json_encode(["ok"=>false]); exit; }

  $p = db()->prepare("SELECT id, judul FROM kuis_paket WHERE id=?");
  $p->execute([$id]);
  $paket = $p->fetch();
  if (!$paket) { echo json_encode(["ok"=>false]); exit; }

  $soal = db()->prepare("SELECT nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban
                         FROM kuis_soal WHERE paket_id=? ORDER BY nomor ASC");
  $soal->execute([$id]);
  $rows = $soal->fetchAll();

  echo json_encode(["ok"=>true, "paket"=>$paket, "soal"=>$rows]);
  exit;
}

/* =======================
   POST HANDLER
======================= */
$toast = ["type"=>"", "msg"=>""];

try {
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");

    if ($action === "paket_add") {
      paket_create((string)($_POST["judul_paket"] ?? ""));
      $toast = ["type"=>"success","msg"=>"Paket kuis berhasil ditambahkan."];
    }

    if ($action === "paket_edit") {
      paket_update((int)($_POST["paket_id"] ?? 0), (string)($_POST["judul_paket"] ?? ""));
      $toast = ["type"=>"success","msg"=>"Judul paket kuis berhasil diperbarui."];
    }

    if ($action === "paket_delete") {
      $id = (int)($_POST["paket_id"] ?? 0);
      if ($id <= 0) throw new RuntimeException("ID paket tidak valid.");
      db()->prepare("DELETE FROM kuis_paket WHERE id=?")->execute([$id]);
      $toast = ["type"=>"success","msg"=>"Paket kuis berhasil dihapus."];
    }

    /* ==========================
       ✅ MANUAL BULK SAVE
       kirim semua soal dalam 1x submit
    ========================== */
    if ($action === "soal_save_bulk") {
      $paketId = (int)($_POST["paket_id"] ?? 0);
      $judulPaket = (string)($_POST["judul_paket"] ?? "");

      db()->beginTransaction();

      if ($paketId <= 0) {
        $paketId = paket_create($judulPaket);
      } else {
        // update judul paket juga saat save
        paket_update($paketId, $judulPaket);
      }

      $bulkJson = (string)($_POST["bulk_json"] ?? "");
      if ($bulkJson === "") throw new RuntimeException("Data soal (bulk) kosong.");

      $bulk = json_decode($bulkJson, true);
      if (!is_array($bulk)) throw new RuntimeException("Format bulk_json tidak valid.");

      $saved = 0;
      foreach ($bulk as $noStr => $d) {
        $no = (int)$noStr;
        if (!is_array($d)) continue;

        soal_upsert(
          $paketId,
          $no,
          (string)($d["pertanyaan"] ?? ""),
          (string)($d["a"] ?? ""),
          (string)($d["b"] ?? ""),
          (string)($d["c"] ?? ""),
          (string)($d["d"] ?? ""),
          (string)($d["jawaban"] ?? "")
        );

        // hitung yang benar-benar terisi
        $filled = trim((string)($d["pertanyaan"] ?? "")) !== "";
        if ($filled) $saved++;
      }

      db()->commit();
      $toast = ["type"=>"success","msg"=>"Soal berhasil disimpan (bulk). Total terisi: {$saved}. Paket ID: {$paketId}"];
    }

    /* ==========================
       CSV IMPORT
    ========================== */
    if ($action === "csv_import") {
      $paketId = (int)($_POST["paket_id"] ?? 0);
      $judulPaket = (string)($_POST["judul_paket"] ?? "");

      db()->beginTransaction();

      if ($paketId <= 0) {
        $paketId = paket_create($judulPaket);
      } else {
        paket_update($paketId, $judulPaket);
      }

      if (!isset($_FILES["csv"]) || !is_uploaded_file($_FILES["csv"]["tmp_name"])) {
        throw new RuntimeException("File CSV wajib diupload.");
      }

      $fh = fopen($_FILES["csv"]["tmp_name"], "r");
      if (!$fh) throw new RuntimeException("Gagal membaca CSV.");

      $saved = 0;
      $line = 0;

      while (($row = fgetcsv($fh)) !== false) {
        $line++;
        $row = array_map(fn($v) => is_string($v) ? trim($v) : "", $row);
        if (count($row) < 7) continue;

        // skip header
        if ($line === 1 && !ctype_digit((string)$row[0])) continue;

        soal_upsert(
          $paketId,
          (int)$row[0],
          (string)$row[1],
          (string)$row[2],
          (string)$row[3],
          (string)$row[4],
          (string)$row[5],
          (string)$row[6]
        );
        $saved++;
      }
      fclose($fh);

      db()->commit();

      if ($saved === 0) throw new RuntimeException("Tidak ada soal yang berhasil diimport dari CSV.");
      $toast = ["type"=>"success","msg"=>"Import CSV berhasil. Total soal: {$saved}. Paket ID: {$paketId}"];
    }
  }
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  $toast = ["type"=>"danger","msg"=>$e->getMessage()];
}

/* =======================
   LOAD DATA
======================= */
$paket = db()->query("
  SELECT p.id, p.judul,
         (SELECT COUNT(*) FROM kuis_soal s WHERE s.paket_id=p.id) AS jumlah_soal
  FROM kuis_paket p
  ORDER BY p.id DESC
")->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daftar Soal (Kuis) | Admin</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{--maroon:#700D09;--bg:#e9edff;--shadow:0 18px 26px rgba(0,0,0,.18);}
    body{margin:0;font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);min-height:100vh;}
    .bg-maroon{background:var(--maroon)!important}
    .page{max-width:1200px;margin:0 auto;padding:120px 20px 40px;}
    .title{font-weight:900;font-size:54px;margin:0;color:#111;line-height:1.05;}
    .subtitle{margin-top:10px;color:#333;font-size:18px;font-style:italic;}
    .navbar-nav-simple{list-style:none;display:flex;align-items:center;gap:34px;margin:0;padding:0;}
    .navbar-nav-simple .nav-link{color:#fff;font-weight:700;letter-spacing:.5px;text-decoration:none;padding:6px 0;}
    .navbar-nav-simple .nav-link.active{position:relative;}
    .navbar-nav-simple .nav-link.active::after{content:"";position:absolute;left:0;right:0;margin:auto;bottom:-10px;width:64px;height:3px;background:#fff;border-radius:2px;opacity:.95;}
    .btn-back{border:0;background:var(--maroon);color:#fff;font-weight:800;font-size:18px;padding:12px 34px;border-radius:999px;cursor:pointer;box-shadow:0 10px 14px rgba(0,0,0,.18);display:inline-flex;align-items:center;gap:14px;margin-top:16px;}
    .btn-add{border:0;background:var(--maroon);color:#fff;font-weight:800;font-size:24px;padding:14px 46px;border-radius:999px;box-shadow:0 12px 16px rgba(0,0,0,.18);display:inline-flex;align-items:center;gap:14px;white-space:nowrap;}
    .table-wrap{margin-top:34px;background:#fff;border-radius:26px;overflow:hidden;box-shadow:var(--shadow);}
    .table-head{background:#d9d9d9;padding:22px 30px;display:grid;grid-template-columns:120px 1fr 160px 120px;align-items:center;font-weight:900;font-size:20px;}
    .table-row{padding:18px 30px;display:grid;grid-template-columns:120px 1fr 160px 120px;align-items:center;border-top:1px solid #ececec;font-size:18px;}
    .icon-btn{border:0;background:transparent;padding:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;}
    .icon-btn:hover{background:rgba(112,13,9,.08);}
    .icon-edit,.icon-trash{color:var(--maroon);font-size:24px;}

    .modal-content{border:0;border-radius:28px;overflow:hidden;box-shadow:0 30px 60px rgba(0,0,0,.28);}
    .modal-header-custom{background:var(--maroon);padding:28px 34px;position:relative;}
    .modal-title-custom{margin:0;color:#fff;font-weight:900;font-size:40px;line-height:1.05;}
    .modal-subtitle-custom{margin-top:6px;color:rgba(255,255,255,.85);font-style:italic;font-size:16px;}
    .modal-close-x{position:absolute;top:18px;right:18px;width:44px;height:44px;border-radius:12px;border:0;background:transparent;color:#fff;font-size:30px;display:flex;align-items:center;justify-content:center;}
    .modal-close-x:hover{background:rgba(255,255,255,.12)}
    .pill-input{border:2px solid #111;border-radius:999px;padding:12px 18px;font-size:16px;outline:none;width:100%;}
    textarea.big{border:2px solid #111;border-radius:18px;padding:14px 16px;font-size:16px;outline:none;width:100%;min-height:130px;resize:vertical;}
    .btn-save{border:0;background:var(--maroon);color:#fff;font-weight:800;font-size:18px;padding:14px 42px;border-radius:18px;}
    .btn-outline{border:2px solid #111;background:#fff;color:#111;font-weight:700;font-size:18px;padding:14px 42px;border-radius:18px;}
    .actions{display:flex;justify-content:flex-end;gap:12px;margin-top:18px;}

    .mode-switch{width:230px;background:#d9d9d9;border-radius:999px;padding:6px;display:flex;gap:6px;user-select:none;}
    .mode-pill{flex:1;border-radius:999px;padding:10px 0;text-align:center;font-weight:900;cursor:pointer;color:#fff;}
    .mode-pill.inactive{opacity:.55}
    .mode-pill.active{background:var(--maroon);}

    .numbers{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;}
    .num-btn{width:44px;height:44px;border-radius:999px;border:2px solid #111;background:#fff;font-weight:900;cursor:pointer;}
    .num-btn.active{background:#e9edff;}
    .num-btn.filled{border-color:var(--maroon);}

    .dropzone{margin-top:16px;height:200px;border-radius:18px;background:#d9d9d9;border:2px dashed rgba(112,13,9,.25);display:flex;align-items:center;justify-content:center;text-align:center;cursor:pointer;}
    .dropzone.dragover{outline:3px solid rgba(112,13,9,.35);}
    .dropzone .dz-icon{font-size:54px;color:#fff;}
    .dropzone .dz-text{color:#fff;font-size:16px;font-weight:700;}

    .ans-grid{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;}
    .ans-item{display:flex;align-items:center;gap:8px;border:2px solid #111;border-radius:999px;padding:10px 14px;cursor:pointer;user-select:none;}
    .ans-item input{accent-color: var(--maroon); transform:scale(1.1);}
    .ans-item.active{border-color:var(--maroon); background:#f3e9e9;}
    @media(max-width:992px){.navbar-nav-simple{display:none}.title{font-size:42px}.btn-add{font-size:18px;padding:12px 26px}.table-head,.table-row{grid-template-columns:70px 1fr 140px 70px;font-size:16px}}
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top" style="padding:20px 0;">
  <div class="container d-flex justify-content-between align-items-center">
    <a class="navbar-brand d-flex align-items-center gap-2" href="admin.php">
      <img src="Asset/LogoKPU.png" width="40" height="40" alt="KPU">
      <span><span class="fw-bold">KPU</span><br><span class="fw-normal">DIY</span></span>
    </a>

    <ul class="navbar-nav-simple">
      <li><a class="nav-link" href="dashboard.php">HOME</a></li>
      <li><a class="nav-link" href="tambah_materi_admin.php">MATERI</a></li>
      <li><a class="nav-link active" href="kuis_admin.php">KUIS</a></li>
      <li><a class="nav-link" href="kontak.php">KONTAK</a></li>
      <li><a class="nav-link" href="login_admin.php">LOGOUT</a></li>
    </ul>
  </div>
</nav>

<main class="page">
  <button class="btn-back" type="button" onclick="history.back()">
    <i class="bi bi-arrow-left"></i> Kembali
  </button>

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-5">
    <div>
      <h1 class="title">Daftar Soal</h1>
      <div class="subtitle">Klik tombol edit untuk memperbarui soal.</div>
    </div>
    <button class="btn-add" type="button" id="btnOpenAdd">+ Tambah Soal</button>
  </div>

  <?php if ($toast["type"]): ?>
    <div class="alert alert-<?= htmlspecialchars($toast["type"]) ?> mt-4" style="border-radius:16px;font-weight:800;">
      <?= htmlspecialchars($toast["msg"]) ?>
    </div>
  <?php endif; ?>

  <section class="table-wrap">
    <div class="table-head">
      <div></div>
      <div class="text-center">PAKET SOAL</div>
      <div class="text-center">JUMLAH SOAL</div>
      <div></div>
    </div>

    <?php foreach ($paket as $p): ?>
      <div class="table-row">
        <div class="text-center">
          <button class="icon-btn btn-edit" type="button"
                  data-id="<?= (int)$p["id"] ?>"
                  data-judul="<?= htmlspecialchars($p["judul"]) ?>">
            <i class="bi bi-pencil-fill icon-edit"></i>
          </button>
        </div>
        <div><?= htmlspecialchars($p["judul"]) ?></div>
        <div class="text-center"><?= (int)$p["jumlah_soal"] ?></div>
        <div class="text-center">
          <form method="post" onsubmit="return confirm('Yakin hapus paket kuis ini?')">
            <input type="hidden" name="action" value="paket_delete">
            <input type="hidden" name="paket_id" value="<?= (int)$p["id"] ?>">
            <button class="icon-btn" type="submit"><i class="bi bi-trash3-fill icon-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <div style="height:18px;background:#fff"></div>
  </section>
</main>

<!-- MODAL -->
<div class="modal fade" id="kuisModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="kuisForm" method="post" enctype="multipart/form-data">
      <div class="modal-header-custom">
        <button type="button" class="modal-close-x" data-bs-dismiss="modal">&times;</button>
        <div class="modal-title-custom" id="modalTitle">Input Kuis</div>
        <div class="modal-subtitle-custom">Lengkapi formulir di bawah ini</div>
      </div>

      <div class="modal-body p-4">
        <input type="hidden" name="action" id="actionInput" value="paket_add">
        <input type="hidden" name="paket_id" id="paketIdInput" value="">
        <input type="hidden" name="bulk_json" id="bulkJsonInput" value="">

        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
          <div class="flex-grow-1">
            <label class="fw-bold mb-2">Judul Kuis</label>
            <input class="pill-input" type="text" name="judul_paket" id="judulPaketInput" placeholder="Tuliskan Judul Kuis di sini..." required>
          </div>

          <div class="mode-switch mt-4 mt-md-0" id="modeSwitch">
            <div class="mode-pill active" data-mode="csv">CSV</div>
            <div class="mode-pill inactive" data-mode="manual">MANUAL</div>
          </div>
        </div>

        <!-- CSV AREA -->
        <div id="csvArea" class="mt-4">
          <label class="fw-bold mb-2">Input Kuis (CSV)</label>
          <input type="file" name="csv" id="csvInput" accept=".csv,text/csv" class="d-none">
          <div class="dropzone" id="csvDrop">
            <div>
              <div class="dz-icon"><i class="bi bi-filetype-csv"></i></div>
              <div class="dz-text">Klik atau seret file CSV ke sini</div>
              <div class="dz-text" id="csvName" style="font-size:14px;opacity:.8;"></div>
            </div>
          </div>
          <div class="text-muted mt-2" style="font-size:13px;">
            Format: <b>nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban(A/B/C/D)</b>
          </div>
        </div>

        <!-- MANUAL AREA -->
        <div id="manualArea" class="mt-4" style="display:none;">
          <label class="fw-bold mb-2">Input Kuis (Manual)</label>

          <div class="numbers" id="numbers"></div>
          <input type="hidden" id="nomorActive" value="1">

          <div class="mt-3">
            <label class="fw-bold mb-2">Pertanyaan</label>
            <textarea class="big" id="pertanyaanInput" placeholder="Tuliskan Pertanyaan di sini..."></textarea>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label class="fw-bold mb-2">Pilihan A</label>
              <input class="pill-input" id="opsiA" placeholder="Jawaban A...">
            </div>
            <div class="col-md-6">
              <label class="fw-bold mb-2">Pilihan B</label>
              <input class="pill-input" id="opsiB" placeholder="Jawaban B...">
            </div>
            <div class="col-md-6">
              <label class="fw-bold mb-2">Pilihan C</label>
              <input class="pill-input" id="opsiC" placeholder="Jawaban C...">
            </div>
            <div class="col-md-6">
              <label class="fw-bold mb-2">Pilihan D</label>
              <input class="pill-input" id="opsiD" placeholder="Jawaban D...">
            </div>
          </div>

          <div class="mt-3">
            <label class="fw-bold mb-2">Jawaban Benar</label>
            <div class="ans-grid" id="ansGrid">
              <label class="ans-item" data-val="A"><input type="radio" name="jawaban_radio" value="A"> <span>A</span></label>
              <label class="ans-item" data-val="B"><input type="radio" name="jawaban_radio" value="B"> <span>B</span></label>
              <label class="ans-item" data-val="C"><input type="radio" name="jawaban_radio" value="C"> <span>C</span></label>
              <label class="ans-item" data-val="D"><input type="radio" name="jawaban_radio" value="D"> <span>D</span></label>
            </div>
          </div>
        </div>

        <div class="actions">
          <button class="btn-outline" type="button" data-bs-dismiss="modal">Batalkan</button>
          <button class="btn-save" type="submit">Simpan</button>
        </div>

      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  const modalEl = document.getElementById("kuisModal");
  const modal = new bootstrap.Modal(modalEl);

  const btnOpenAdd = document.getElementById("btnOpenAdd");
  const modalTitle = document.getElementById("modalTitle");

  const actionInput = document.getElementById("actionInput");
  const paketIdInput = document.getElementById("paketIdInput");
  const judulPaketInput = document.getElementById("judulPaketInput");
  const bulkJsonInput = document.getElementById("bulkJsonInput");

  const modeSwitch = document.getElementById("modeSwitch");
  const csvArea = document.getElementById("csvArea");
  const manualArea = document.getElementById("manualArea");

  const csvInput = document.getElementById("csvInput");
  const csvDrop = document.getElementById("csvDrop");
  const csvName = document.getElementById("csvName");

  const numbers = document.getElementById("numbers");
  const nomorActive = document.getElementById("nomorActive");

  const pertanyaanInput = document.getElementById("pertanyaanInput");
  const opsiA = document.getElementById("opsiA");
  const opsiB = document.getElementById("opsiB");
  const opsiC = document.getElementById("opsiC");
  const opsiD = document.getElementById("opsiD");

  const ansGrid = document.getElementById("ansGrid");

  let currentMode = "csv";
  let cacheSoal = {}; // nomor -> {pertanyaan,a,b,c,d,jawaban}

  function setMode(mode){
    currentMode = mode;
    modeSwitch.querySelectorAll(".mode-pill").forEach(p=>{
      const on = p.dataset.mode === mode;
      p.classList.toggle("active", on);
      p.classList.toggle("inactive", !on);
    });
    csvArea.style.display = (mode === "csv") ? "block" : "none";
    manualArea.style.display = (mode === "manual") ? "block" : "none";
  }

  modeSwitch.addEventListener("click", (e)=>{
    const pill = e.target.closest(".mode-pill");
    if(!pill) return;
    setMode(pill.dataset.mode);
  });

  function clearJawabanRadio(){
    ansGrid.querySelectorAll("input[type=radio]").forEach(r => r.checked = false);
    ansGrid.querySelectorAll(".ans-item").forEach(x => x.classList.remove("active"));
  }

  function setJawabanRadio(val){
    clearJawabanRadio();
    const r = ansGrid.querySelector(`input[type=radio][value="${val}"]`);
    if(r) r.checked = true;
    const lab = ansGrid.querySelector(`.ans-item[data-val="${val}"]`);
    if(lab) lab.classList.add("active");
  }

  ansGrid.addEventListener("click", (e)=>{
    const lab = e.target.closest(".ans-item");
    if(!lab) return;
    setJawabanRadio(lab.dataset.val);
  });

  function getJawabanVal(){
    const r = ansGrid.querySelector("input[type=radio]:checked");
    return r ? r.value : "";
  }

  function saveDraft(){
    const no = parseInt(nomorActive.value,10);
    cacheSoal[no] = {
      pertanyaan: pertanyaanInput.value || "",
      a: opsiA.value || "",
      b: opsiB.value || "",
      c: opsiC.value || "",
      d: opsiD.value || "",
      jawaban: getJawabanVal() || ""
    };
  }

  function loadDraft(no){
    const d = cacheSoal[no] || {pertanyaan:"",a:"",b:"",c:"",d:"",jawaban:""};
    pertanyaanInput.value = d.pertanyaan;
    opsiA.value = d.a; opsiB.value = d.b; opsiC.value = d.c; opsiD.value = d.d;
    if(d.jawaban) setJawabanRadio(d.jawaban); else clearJawabanRadio();
  }

  function buildNumbers(){
    numbers.innerHTML = "";
    const activeNo = parseInt(nomorActive.value,10);
    for(let i=1;i<=15;i++){
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "num-btn";
      btn.textContent = i;

      if(i === activeNo) btn.classList.add("active");
      if(cacheSoal[i] && (cacheSoal[i].pertanyaan || "").trim() !== "") btn.classList.add("filled");

      btn.addEventListener("click", ()=>{
        saveDraft();
        nomorActive.value = String(i);
        loadDraft(i);
        buildNumbers();
      });

      numbers.appendChild(btn);
    }
  }

  function resetForm(){
    actionInput.value = "paket_add";
    paketIdInput.value = "";
    judulPaketInput.value = "";
    bulkJsonInput.value = "";
    cacheSoal = {};
    nomorActive.value = "1";
    pertanyaanInput.value = "";
    opsiA.value=""; opsiB.value=""; opsiC.value=""; opsiD.value="";
    clearJawabanRadio();

    csvInput.value = "";
    csvName.textContent = "";

    buildNumbers();
    setMode("csv");
  }

  // CSV dropzone
  csvDrop.addEventListener("click", ()=> csvInput.click());
  csvDrop.addEventListener("dragover", (e)=>{ e.preventDefault(); csvDrop.classList.add("dragover"); });
  csvDrop.addEventListener("dragleave", ()=> csvDrop.classList.remove("dragover"));
  csvDrop.addEventListener("drop", (e)=>{
    e.preventDefault();
    csvDrop.classList.remove("dragover");
    if(e.dataTransfer.files && e.dataTransfer.files[0]){
      csvInput.files = e.dataTransfer.files;
      csvName.textContent = e.dataTransfer.files[0].name;
    }
  });
  csvInput.addEventListener("change", ()=>{
    if(csvInput.files && csvInput.files[0]) csvName.textContent = csvInput.files[0].name;
  });

  btnOpenAdd.addEventListener("click", ()=>{
    resetForm();
    modalTitle.textContent = "Input Kuis";
    modal.show();
  });

  // EDIT paket (load soal)
  document.querySelectorAll(".btn-edit").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
      resetForm();
      modalTitle.textContent = "Edit Kuis";
      setMode("manual");

      paketIdInput.value = btn.dataset.id || "";
      judulPaketInput.value = btn.dataset.judul || "";

      try{
        const res = await fetch(`kuis_admin.php?ajax=paket_detail&id=${paketIdInput.value}`);
        const json = await res.json();
        if(json.ok){
          cacheSoal = {};
          (json.soal || []).forEach(s=>{
            const no = parseInt(s.nomor,10);
            cacheSoal[no] = {
              pertanyaan: s.pertanyaan || "",
              a: s.opsi_a || "",
              b: s.opsi_b || "",
              c: s.opsi_c || "",
              d: s.opsi_d || "",
              jawaban: s.jawaban || ""
            };
          });
          nomorActive.value = "1";
          loadDraft(1);
          buildNumbers();
        }
      }catch(e){}

      modal.show();
    });
  });

  // submit: mode csv => csv_import, mode manual => soal_save_bulk
  document.getElementById("kuisForm").addEventListener("submit", ()=>{
    if(currentMode === "csv"){
      actionInput.value = "csv_import";
      return;
    }

    // manual bulk
    saveDraft();
    actionInput.value = "soal_save_bulk";
    bulkJsonInput.value = JSON.stringify(cacheSoal);
  });

  // init
  resetForm();
</script>

</body>
</html>
