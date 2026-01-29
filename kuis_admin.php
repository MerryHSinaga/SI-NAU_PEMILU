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

/* =======================
   TABLES + MIGRATION
======================= */
function ensure_tables(): void {
  db()->exec("
    CREATE TABLE IF NOT EXISTS kuis_paket (
      id INT AUTO_INCREMENT PRIMARY KEY,
      judul VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // tambah kolom input_mode jika belum ada (csv/manual)
  try {
    db()->exec("ALTER TABLE kuis_paket ADD COLUMN input_mode ENUM('csv','manual') NOT NULL DEFAULT 'csv' AFTER judul");
  } catch (Throwable $e) {
    // abaikan kalau kolom sudah ada
  }

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

function paket_create(string $judul, string $mode): int {
  $judul = trim($judul);
  $mode = strtolower(trim($mode));
  if ($judul === "") throw new RuntimeException("Judul kuis wajib diisi.");
  if (!in_array($mode, ["csv","manual"], true)) $mode = "csv";

  $st = db()->prepare("INSERT INTO kuis_paket (judul, input_mode) VALUES (?, ?)");
  $st->execute([$judul, $mode]);
  return (int)db()->lastInsertId();
}

function paket_update(int $id, string $judul, ?string $mode = null): void {
  $judul = trim($judul);
  if ($id <= 0) throw new RuntimeException("ID paket tidak valid.");
  if ($judul === "") throw new RuntimeException("Judul kuis wajib diisi.");

  if ($mode !== null) {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ["csv","manual"], true)) $mode = "csv";
    $st = db()->prepare("UPDATE kuis_paket SET judul=?, input_mode=? WHERE id=?");
    $st->execute([$judul, $mode, $id]);
    return;
  }

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

  // skip jika kosong semua (biar bulk aman)
  if ($pertanyaan === "" && $a === "" && $b === "" && $c === "" && $d === "" && $jawaban === "") return;

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

/* =======================
   CSV PARSER + VALIDATOR
   (Agar paket tidak dibuat kalau CSV kosong)
======================= */
function csv_parse_valid_rows(string $tmpPath): array {
  $fh = fopen($tmpPath, "r");
  if (!$fh) throw new RuntimeException("Gagal membaca CSV.");

  $rows = [];
  $line = 0;

  try {
    while (($row = fgetcsv($fh)) !== false) {
      $line++;

      // normalisasi (trim semua kolom)
      $row = array_map(static fn($v) => is_string($v) ? trim($v) : "", $row);

      // skip baris benar-benar kosong
      $allEmpty = true;
      foreach ($row as $v) {
        if ((string)$v !== "") { $allEmpty = false; break; }
      }
      if ($allEmpty) continue;

      // minimal 7 kolom
      if (count($row) < 7) {
        throw new RuntimeException("CSV baris {$line}: kolom kurang. Wajib 7 kolom (nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban).");
      }

      // skip header (baris pertama) jika kolom 1 bukan angka
      if ($line === 1 && !ctype_digit((string)$row[0])) {
        continue;
      }

      $nomor = (int)$row[0];
      $pertanyaan = (string)$row[1];
      $a = (string)$row[2];
      $b = (string)$row[3];
      $c = (string)$row[4];
      $d = (string)$row[5];
      $jawaban = strtoupper((string)$row[6]);

      // jika satu baris data ternyata kosong semua (kadang terjadi)
      if (
        trim((string)$row[0]) === "" &&
        trim($pertanyaan) === "" &&
        trim($a) === "" && trim($b) === "" && trim($c) === "" && trim($d) === "" &&
        trim($jawaban) === ""
      ) {
        continue;
      }

      // validasi nomor
      if (!ctype_digit((string)$row[0])) {
        throw new RuntimeException("CSV baris {$line}: kolom 'nomor' harus angka.");
      }
      if ($nomor < 1 || $nomor > 15) {
        throw new RuntimeException("CSV baris {$line}: nomor soal harus 1–15.");
      }

      // validasi isi wajib
      if (trim($pertanyaan) === "" || trim($a) === "" || trim($b) === "" || trim($c) === "" || trim($d) === "") {
        throw new RuntimeException("CSV baris {$line} (nomor {$nomor}): pertanyaan & semua opsi (A–D) wajib diisi.");
      }

      // validasi jawaban
      if (!in_array($jawaban, ["A","B","C","D"], true)) {
        throw new RuntimeException("CSV baris {$line} (nomor {$nomor}): jawaban harus A/B/C/D.");
      }

      $rows[] = [
        "nomor" => $nomor,
        "pertanyaan" => $pertanyaan,
        "a" => $a,
        "b" => $b,
        "c" => $c,
        "d" => $d,
        "jawaban" => $jawaban,
        "line" => $line,
      ];
    }
  } finally {
    fclose($fh);
  }

  // kalau kosong (mis. hanya header / baris kosong)
  if (count($rows) === 0) {
    throw new RuntimeException("CSV tidak berisi soal. Pastikan ada minimal 1 soal untuk ditambahkan.");
  }

  return $rows;
}

ensure_tables();

/* =======================
   DOWNLOAD: TEMPLATE CSV
======================= */
if (isset($_GET["download"]) && $_GET["download"] === "template_csv") {
  $filename = "template_kuis.csv";
  header("Content-Type: text/csv; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"{$filename}\"");
  header("Pragma: no-cache");
  header("Expires: 0");

  $out = fopen("php://output", "w");
  if ($out === false) exit;

  fputcsv($out, ["nomor","pertanyaan","opsi_a","opsi_b","opsi_c","opsi_d","jawaban"]);
  fputcsv($out, ["1","Contoh pertanyaan?","Opsi A","Opsi B","Opsi C","Opsi D","A"]);

  fclose($out);
  exit;
}

/* =======================
   AJAX: DETAIL PAKET
======================= */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "paket_detail") {
  header("Content-Type: application/json; charset=utf-8");
  $id = (int)($_GET["id"] ?? 0);
  if ($id <= 0) { echo json_encode(["ok"=>false]); exit; }

  $p = db()->prepare("SELECT id, judul, input_mode FROM kuis_paket WHERE id=?");
  $p->execute([$id]);
  $paket = $p->fetch();
  if (!$paket) { echo json_encode(["ok"=>false]); exit; }

  $soal = db()->prepare("SELECT nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban
                         FROM kuis_soal WHERE paket_id=? ORDER BY nomor ASC");
  $soal->execute([$id]);
  $rows = $soal->fetchAll();

  echo json_encode([
    "ok"=>true,
    "paket"=>[
      "id" => (int)$paket["id"],
      "judul" => (string)$paket["judul"],
      "input_mode" => (string)$paket["input_mode"],
    ],
    "soal"=>$rows
  ]);
  exit;
}

/* =======================
   POST HANDLER
======================= */
$toast = ["type"=>"", "msg"=>""];

try {
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");

    if ($action === "paket_delete") {
      $id = (int)($_POST["paket_id"] ?? 0);
      if ($id <= 0) throw new RuntimeException("ID paket tidak valid.");
      db()->prepare("DELETE FROM kuis_paket WHERE id=?")->execute([$id]);
      $toast = ["type"=>"success","msg"=>"Paket kuis berhasil dihapus."];
    }

    // ==========================
    // MANUAL SAVE (boleh untuk paket CSV juga)
    // ==========================
    if ($action === "soal_save_bulk") {
      $paketId = (int)($_POST["paket_id"] ?? 0);
      $judulPaket = (string)($_POST["judul_paket"] ?? "");

      db()->beginTransaction();

      if ($paketId <= 0) {
        $paketId = paket_create($judulPaket, "manual");
      } else {
        // ✅ saat simpan manual: set mode jadi manual
        paket_update($paketId, $judulPaket, "manual");
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

        if (trim((string)($d["pertanyaan"] ?? "")) !== "") $saved++;
      }

      db()->commit();
      $toast = ["type"=>"success","msg"=>"Soal berhasil disimpan (Manual). Total terisi: {$saved} (maks 15)."];
    }

    // ==========================
    // CSV IMPORT (mode jadi csv)
    // ✅ REVISI: Jangan buat paket kalau CSV kosong/tidak ada data valid
    // ==========================
    if ($action === "csv_import") {
      $paketId = (int)($_POST["paket_id"] ?? 0);
      $judulPaket = (string)($_POST["judul_paket"] ?? "");

      // validasi upload dulu (tanpa bikin paket)
      if (!isset($_FILES["csv"]) || !is_uploaded_file($_FILES["csv"]["tmp_name"])) {
        throw new RuntimeException("File CSV wajib diupload.");
      }

      // parse + validasi isi CSV dulu (kalau kosong => throw, paket tidak jadi dibuat)
      $parsedRows = csv_parse_valid_rows($_FILES["csv"]["tmp_name"]);

      db()->beginTransaction();

      // baru bikin / update paket setelah dipastikan CSV ada isinya
      if ($paketId <= 0) {
        $paketId = paket_create($judulPaket, "csv");
      } else {
        // ✅ saat import csv: set mode jadi csv
        paket_update($paketId, $judulPaket, "csv");
      }

      $saved = 0;
      foreach ($parsedRows as $r) {
        soal_upsert(
          $paketId,
          (int)$r["nomor"],
          (string)$r["pertanyaan"],
          (string)$r["a"],
          (string)$r["b"],
          (string)$r["c"],
          (string)$r["d"],
          (string)$r["jawaban"]
        );
        $saved++;
      }

      db()->commit();
      $toast = ["type"=>"success","msg"=>"Import CSV berhasil. Total soal: {$saved} (maks 15)."];
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
  SELECT p.id, p.judul, p.input_mode,
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
  <title>Daftar Soal | Admin</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{
      --maroon:#700D09;
      --bg:#E9EDFF;
      --header-gray:#d9d9d9;
      --row-line:#e6e6e6;
      --shadow:0 14px 22px rgba(0,0,0,.18);
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
    .navbar{padding:20px 0;border-bottom:1px solid rgba(0,0,0,.15);}
    .navbar-nav-simple{list-style:none;display:flex;align-items:center;gap:46px;margin:0;padding:0;}
    .navbar-nav-simple .nav-link{color:#fff;font-weight:800;letter-spacing:.5px;text-decoration:none;position:relative;padding:6px 0 12px;}
    .navbar-nav-simple .nav-link::after{
      content:"";position:absolute;left:0;right:0;margin:auto;bottom:0;
      width:0;height:3px;background:#fff;border-radius:2px;transition:.25s ease;opacity:.95;
    }
    .navbar-nav-simple .nav-link:hover::after{width:70px;}
    .navbar-nav-simple .nav-link.active::after{width:70px;}

    .page{
      max-width:1200px;
      margin:0 auto;
      width:100%;
      padding:140px 20px 40px;
      flex:1;
    }

    .title{font-weight:900;font-size:48px;margin:0;color:#111;line-height:1.05;}
    .subtitle{margin-top:10px;color:#333;font-size:14px;font-style:italic;}

    .btn-add{
      border:0;background:var(--maroon);color:#fff;
      font-weight:700;font-size:14px;
      padding:12px 34px;border-radius:999px;
      display:inline-flex;align-items:center;gap:10px;white-space:nowrap;
      box-shadow:0 10px 18px rgba(0,0,0,.18);
      transition:transform .2s ease, filter .2s ease;
      margin-top:18px;
    }
    .btn-add:hover{filter:brightness(.92);transform:translateY(1px);}
    .btn-add:active{transform:translateY(2px);}

    .table-wrap{
      margin-top:44px;background:#fff;border-radius:26px;overflow:hidden;
      box-shadow:var(--shadow);max-width:980px;margin-left:auto;margin-right:auto;
    }
    .table-head{
      background:var(--header-gray);padding:18px 34px;
      display:grid;grid-template-columns:90px 1fr 220px 90px;align-items:center;
      font-weight:900;font-size:20px;color:#111;
    }
    .table-row{
      padding:18px 34px;display:grid;grid-template-columns:90px 1fr 220px 90px;
      align-items:center;border-top:1px solid var(--row-line);font-size:16px;color:#111;
    }
    .cell-center{text-align:center;}

    .icon-btn{
      border:0;background:transparent;padding:0;cursor:pointer;
      display:inline-flex;align-items:center;justify-content:center;
      width:44px;height:44px;border-radius:12px;
      transition:background .15s ease, transform .15s ease;
    }
    .icon-btn:hover{background:rgba(112,13,9,.08);transform:translateY(-1px);}
    .icon-edit,.icon-trash{color:var(--maroon);font-size:22px;}

    .mode-badge{
      display:inline-flex;align-items:center;gap:8px;
      font-size:12px;font-weight:900;
      padding:6px 12px;border-radius:999px;
      border:2px solid rgba(112,13,9,.25);
      background:#fff;
      color:#111;
      margin-left:10px;
    }

    .modal-content{border:0;border-radius:28px;overflow:hidden;box-shadow:0 30px 60px rgba(0,0,0,.28);}
    .modal-header-custom{background:var(--maroon);padding:22px 28px 16px;position:relative;}
    .modal-title-custom{margin:0;color:#fff;font-weight:900;font-size:34px;line-height:1.05;}
    .modal-subtitle-custom{margin-top:6px;color:rgba(255,255,255,.85);font-style:italic;font-size:13px;}
    .modal-close-x{
      position:absolute;top:16px;right:18px;width:44px;height:44px;border-radius:12px;border:0;
      background:transparent;color:#fff;font-size:30px;display:flex;align-items:center;justify-content:center;
      opacity:.95;transition:opacity .15s ease, transform .15s ease;
    }
    .modal-close-x:hover{opacity:1;transform:scale(1.03);}

    .modal-body{
      padding:18px 24px 22px;
      background:#fff;
      max-height:calc(100vh - 240px);
      overflow:auto;
    }

    .pill-input{border:2px solid #111;border-radius:999px;padding:10px 16px;font-size:14px;outline:none;width:100%;}
    textarea.big{border:2px solid #111;border-radius:18px;padding:12px 14px;font-size:14px;outline:none;width:100%;min-height:130px;resize:vertical;}

    .mode-switch{width:180px;background:#d9d9d9;border-radius:999px;padding:6px;display:flex;gap:6px;user-select:none;}
    .mode-pill{flex:1;border-radius:999px;padding:8px 0;text-align:center;font-weight:900;cursor:pointer;color:#fff;font-size:13px;}
    .mode-pill.inactive{opacity:.55;background:transparent;color:#fff;}
    .mode-pill.active{background:var(--maroon);}

    .numbers{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;}
    .num-btn{width:40px;height:40px;border-radius:999px;border:2px solid #111;background:#fff;font-weight:900;cursor:pointer;}
    .num-btn.active{background:#e9edff;}
    .num-btn.filled{border-color:var(--maroon);}

    .dropzone{
      margin-top:14px;height:170px;border-radius:18px;background:#d9d9d9;border:2px dashed rgba(112,13,9,.25);
      display:flex;align-items:center;justify-content:center;text-align:center;cursor:pointer;
    }
    .dropzone.dragover{outline:3px solid rgba(112,13,9,.35);}
    .dropzone .dz-icon{font-size:50px;color:#fff;}
    .dropzone .dz-text{color:#fff;font-size:14px;font-weight:800;}

    .ans-grid{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;}
    .ans-item{display:flex;align-items:center;gap:8px;border:2px solid #111;border-radius:999px;padding:10px 14px;cursor:pointer;user-select:none;}
    .ans-item input{accent-color: var(--maroon); transform:scale(1.05);}
    .ans-item.active{border-color:var(--maroon); background:#f3e9e9;}

    .actions{display:flex;justify-content:flex-end;gap:12px;margin-top:16px;}
    .btn-save{border:0;background:var(--maroon);color:#fff;font-weight:900;font-size:14px;padding:12px 34px;border-radius:14px;}
    .btn-outline{border:2px solid #111;background:#fff;color:#111;font-weight:800;font-size:14px;padding:12px 34px;border-radius:14px;}

    .tpl-link{
      display:inline-flex;align-items:center;gap:8px;
      font-weight:900;font-size:12px;
      color:#333;text-decoration:none;
      padding:6px 10px;border-radius:999px;
      background:#f3f3f3;border:1px solid rgba(0,0,0,.12);
      transition:transform .15s ease, filter .15s ease;
    }
    .tpl-link:hover{filter:brightness(.98);transform:translateY(-1px);}

    .info-max{
      margin-top:8px;
      font-size:12px;
      font-weight:900;
      color:#700D09;
      background:rgba(112,13,9,.08);
      border:1px solid rgba(112,13,9,.18);
      padding:8px 10px;
      border-radius:12px;
      display:inline-flex;
      align-items:center;
      gap:8px;
    }

    .btn-back{
      width:42px;height:42px;border-radius:12px;
      display:inline-flex;align-items:center;justify-content:center;
      color:#fff;
      text-decoration:none;
      transition:transform .15s ease, filter .15s ease;
    }
    .btn-back:hover{filter:brightness(1.05);transform:translateY(-1px);}
    .btn-back i{font-size:22px;line-height:1;}

    @media (max-width: 992px){
      .navbar-nav-simple{display:none;}
      .title{font-size:40px;}
      .table-wrap{max-width:100%;}
      .table-head,.table-row{grid-template-columns:70px 1fr 160px 70px;padding-left:18px;padding-right:18px;}
      .modal-title-custom{font-size:28px;}
    }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top">
  <div class="container d-flex justify-content-between align-items-center">

    <div class="d-flex align-items-center gap-2">

      <a class="btn-back" href="javascript:history.back()" aria-label="Kembali" title="Kembali">
        <i class="bi bi-arrow-left"></i>
      </a>

      <a class="navbar-brand d-flex align-items-center gap-2" href="admin.php">
        <img src="Asset/LogoKPU.png" width="40" height="40" alt="KPU">
        <span class="lh-sm text-white fs-6">
          <strong>KPU</strong><br>DIY
        </span>
      </a>

    </div>

    <ul class="navbar-nav-simple">
      <li><a class="nav-link" href="login_admin.php">LOGOUT</a></li>
    </ul>

  </div>
</nav>

<main class="page">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3" style="max-width:980px;margin:0 auto;">
    <div>
      <h1 class="title">Daftar Soal</h1>
      <div class="subtitle">Klik tombol edit untuk memperbarui soal.</div>
    </div>

    <button class="btn-add" type="button" id="btnOpenAdd">
      <span>+ Tambah Soal</span>
    </button>
  </div>

  <?php if ($toast["type"]): ?>
    <div class="alert alert-<?= htmlspecialchars($toast["type"]) ?> mt-4"
         style="border-radius:16px;font-weight:800;max-width:980px;margin-left:auto;margin-right:auto;">
      <?= htmlspecialchars($toast["msg"]) ?>
    </div>
  <?php endif; ?>

  <section class="table-wrap">
    <div class="table-head">
      <div></div>
      <div class="text">PAKET SOAL</div>
      <div class="text-center">JUMLAH SOAL</div>
      <div></div>
    </div>

    <?php foreach ($paket as $p): ?>
      <div class="table-row">
        <div class="cell-center">
          <button class="icon-btn btn-edit" type="button"
                  data-id="<?= (int)$p["id"] ?>"
                  data-judul="<?= htmlspecialchars($p["judul"]) ?>"
                  data-mode="<?= htmlspecialchars((string)$p["input_mode"]) ?>">
            <i class="bi bi-pencil-fill icon-edit"></i>
          </button>
        </div>

        <div>
          <?= htmlspecialchars($p["judul"]) ?>
          <span class="mode-badge"><?= strtoupper((string)$p["input_mode"]) ?></span>
        </div>

        <div class="cell-center"><?= (int)$p["jumlah_soal"] ?></div>

        <div class="cell-center">
          <form method="post" onsubmit="return confirm('Yakin hapus paket kuis ini?')">
            <input type="hidden" name="action" value="paket_delete">
            <input type="hidden" name="paket_id" value="<?= (int)$p["id"] ?>">
            <button class="icon-btn" type="submit" title="Hapus">
              <i class="bi bi-trash3-fill icon-trash"></i>
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <div style="height:14px;background:#fff"></div>
  </section>
</main>

<div class="modal fade" id="kuisModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="kuisForm" method="post" enctype="multipart/form-data">
      <div class="modal-header-custom">
        <button type="button" class="modal-close-x" data-bs-dismiss="modal" aria-label="Close">&times;</button>
        <div class="modal-title-custom" id="modalTitle">Input Kuis</div>
        <div class="modal-subtitle-custom">Lengkapi formulir di bawah ini</div>
      </div>

      <div class="modal-body">
        <input type="hidden" name="action" id="actionInput" value="csv_import">
        <input type="hidden" name="paket_id" id="paketIdInput" value="">
        <input type="hidden" name="bulk_json" id="bulkJsonInput" value="">

        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
          <div class="flex-grow-1">
            <label class="fw-bold mb-2" style="font-size:14px;">Judul Kuis</label>
            <input class="pill-input" type="text" name="judul_paket" id="judulPaketInput" placeholder="Tuliskan Judul Kuis di sini..." required>
          </div>

          <div class="mode-switch mt-2 mt-md-4" id="modeSwitch">
            <div class="mode-pill active" data-mode="csv">CSV</div>
            <div class="mode-pill inactive" data-mode="manual">Manual</div>
          </div>
        </div>

        <div id="csvArea" class="mt-4">
          <div class="d-flex justify-content-between align-items-center">
            <label class="fw-bold mb-2" style="font-size:14px;">Input Kuis</label>
            <a class="tpl-link" href="kuis_admin.php?download=template_csv" target="_blank" rel="noopener">
              <i class="bi bi-download"></i> Unduh Template CSV
            </a>
          </div>

          <div class="info-max">
            <i class="bi bi-exclamation-circle"></i> Maksimal 15 soal (nomor 1–15)
          </div>

          <input type="file" name="csv" id="csvInput" accept=".csv,text/csv" class="d-none">
          <div class="dropzone" id="csvDrop">
            <div>
              <div class="dz-icon"><i class="bi bi-filetype-csv"></i></div>
              <div class="dz-text">Klik atau seret file CSV ke sini</div>
              <div class="dz-text" id="csvName" style="font-size:12px;opacity:.85;"></div>
            </div>
          </div>
          <div class="text-muted mt-2" style="font-size:12px;">
            Kolom wajib: <b>nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban(A/B/C/D)</b>
          </div>
        </div>

        <!-- MANUAL AREA -->
        <div id="manualArea" class="mt-4" style="display:none;">
          <label class="fw-bold mb-2" style="font-size:14px;">Input Kuis</label>

          <div class="info-max">
            <i class="bi bi-exclamation-circle"></i> Maksimal 15 soal (nomor 1–15)
          </div>

          <div class="numbers" id="numbers"></div>
          <input type="hidden" id="nomorActive" value="1">

          <div class="mt-3">
            <label class="fw-bold mb-2" style="font-size:14px;">Pertanyaan</label>
            <textarea class="big" id="pertanyaanInput" placeholder="Tuliskan Pertanyaan di sini..."></textarea>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label class="fw-bold mb-2" style="font-size:14px;">Pilihan A</label>
              <input class="pill-input" id="opsiA" placeholder="Jawaban A...">
            </div>
            <div class="col-md-6">
              <label class="fw-bold mb-2" style="font-size:14px;">Pilihan B</label>
              <input class="pill-input" id="opsiB" placeholder="Jawaban B...">
            </div>
            <div class="col-md-6">
              <label class="fw-bold mb-2" style="font-size:14px;">Pilihan C</label>
              <input class="pill-input" id="opsiC" placeholder="Jawaban C...">
            </div>
            <div class="col-md-6">
              <label class="fw-bold mb-2" style="font-size:14px;">Pilihan D</label>
              <input class="pill-input" id="opsiD" placeholder="Jawaban D...">
            </div>
          </div>

          <div class="mt-3">
            <label class="fw-bold mb-2" style="font-size:14px;">Kunci Jawaban Benar</label>
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
  const modal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true });

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
  let cacheSoal = {};

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

  // Tambah
  btnOpenAdd.addEventListener("click", ()=>{
    resetForm();
    modalTitle.textContent = "Input Kuis";
    modal.show();
  });

  // Edit => selalu load soal dari DB, dan user boleh edit manual walau awalnya CSV
  document.querySelectorAll(".btn-edit").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
      resetForm();
      modalTitle.textContent = "Edit Kuis";

      paketIdInput.value = btn.dataset.id || "";
      judulPaketInput.value = btn.dataset.judul || "";

      try{
        const res = await fetch(`kuis_admin.php?ajax=paket_detail&id=${encodeURIComponent(paketIdInput.value)}`, { cache: "no-store" });
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

          // ✅ otomatis tampil manual agar bisa edit meskipun paket awalnya CSV
          setMode("manual");
        }
      }catch(e){
        // kalau gagal load, tetap tampil modal
      }

      modal.show();
    });
  });

  // submit: csv => csv_import, manual => soal_save_bulk
  document.getElementById("kuisForm").addEventListener("submit", ()=>{
    if(currentMode === "csv"){
      actionInput.value = "csv_import";
      return;
    }
    saveDraft();
    actionInput.value = "soal_save_bulk";
    bulkJsonInput.value = JSON.stringify(cacheSoal);
  });

  resetForm();
</script>

<?php include 'footer.php'; ?>

</body>
</html>
