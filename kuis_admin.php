<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"])) {
  header("Location: login_admin.php");
  exit;
}

require_once 'db.php';

const BAGIAN_ENUM = [
  'Keuangan',
  'Umum dan Logistik',
  'Teknis Penyelenggara Pemilu, Partisipasi Hubungan Masyarakat',
  'Hukum dan SDM',
  'Perencanaan',
  'Data dan Informasi',
];

function validate_bagian(?string $bagian): ?string {
  if ($bagian === null) return null;
  $bagian = trim($bagian);
  if ($bagian === "") return null;
  return in_array($bagian, BAGIAN_ENUM, true) ? $bagian : null;
}

function validate_judul(string $judul): string {
  $judul = trim($judul);
  if ($judul === "") {
    throw new RuntimeException("Judul kuis wajib diisi.");
  }

  if (mb_strlen($judul, "UTF-8") > 45) {
    throw new RuntimeException("Judul terlalu panjang. Maksimal 45 karakter (termasuk spasi).");
  }

  if (!preg_match('/^[\p{L}\p{N} \.\,\:\?]+$/u', $judul)) {
    throw new RuntimeException("Judul hanya boleh berisi huruf, angka, spasi, titik (.), koma (,), titik dua (:), dan tanda tanya (?).");
  }

  return $judul;
}

function judul_ellipsis(string $text, int $max = 40): string {
  $text = trim($text);
  if ($text === "") return "";
  if (mb_strlen($text, "UTF-8") <= $max) return $text;
  return mb_substr($text, 0, $max, "UTF-8") . "...";
}

function friendly_error_message(string $msg): string {
  $m = trim($msg);
  $lower = strtolower($m);

  if (
    str_contains($lower, "sqlstate") ||
    str_contains($lower, "pdo") ||
    str_contains($lower, "syntax") ||
    str_contains($lower, "duplicate") ||
    str_contains($lower, "foreign key")
  ) {
    return "Terjadi kendala saat menyimpan data. Silakan coba lagi.";
  }

  if ($m === "") {
    return "Gagal memproses permintaan. Silakan coba lagi.";
  }

  return $m;
}

function ensure_tables(): void {
  db()->exec("
    CREATE TABLE IF NOT EXISTS kuis_paket (
      id INT AUTO_INCREMENT PRIMARY KEY,
      judul VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  try {
    db()->exec("ALTER TABLE kuis_paket ADD COLUMN input_mode ENUM('csv','manual') NOT NULL DEFAULT 'csv' AFTER judul");
  } catch (Throwable $e) {}

  try {
    db()->exec("
      ALTER TABLE kuis_paket
      ADD COLUMN bagian ENUM(
        'Keuangan',
        'Umum dan Logistik',
        'Teknis Penyelenggara Pemilu, Partisipasi Hubungan Masyarakat',
        'Hukum dan SDM',
        'Perencanaan',
        'Data dan Informasi'
      ) DEFAULT NULL
      AFTER input_mode
    ");
  } catch (Throwable $e) {}

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

function paket_create(string $judul, string $mode, ?string $bagian): int {
  $judul = validate_judul($judul);
  $mode = strtolower(trim($mode));
  $bagian = validate_bagian($bagian);

  if (!in_array($mode, ["csv","manual"], true)) $mode = "csv";
  if ($bagian === null) throw new RuntimeException("Bagian wajib dipilih.");

  $st = db()->prepare("INSERT INTO kuis_paket (judul, input_mode, bagian) VALUES (?, ?, ?)");
  $st->execute([$judul, $mode, $bagian]);
  return (int)db()->lastInsertId();
}

function paket_update(int $id, string $judul, ?string $mode = null, ?string $bagian = null): void {
  if ($id <= 0) throw new RuntimeException("Data paket tidak valid.");

  $judul = validate_judul($judul);

  $bagian = validate_bagian($bagian);
  if ($bagian === null) throw new RuntimeException("Bagian wajib dipilih.");

  if ($mode !== null) {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ["csv","manual"], true)) $mode = "csv";
    $st = db()->prepare("UPDATE kuis_paket SET judul=?, input_mode=?, bagian=? WHERE id=?");
    $st->execute([$judul, $mode, $bagian, $id]);
    return;
  }

  $st = db()->prepare("UPDATE kuis_paket SET judul=?, bagian=? WHERE id=?");
  $st->execute([$judul, $bagian, $id]);
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
  if ($paketId <= 0) throw new RuntimeException("Data paket tidak valid.");

  if ($nomor < 1 || $nomor > 15) {
    throw new RuntimeException("Nomor soal harus 1 sampai 15.");
  }

  $pertanyaan = trim($pertanyaan);
  $a = trim($a); $b = trim($b); $c = trim($c); $d = trim($d);
  $jawaban = strtoupper(trim($jawaban));

  if ($pertanyaan === "" && $a === "" && $b === "" && $c === "" && $d === "" && $jawaban === "") return;

  if ($pertanyaan === "" || $a === "" || $b === "" || $c === "" || $d === "") {
    throw new RuntimeException("Nomor {$nomor}: Pertanyaan dan semua pilihan (A–D) harus diisi.");
  }
  if (!in_array($jawaban, ["A","B","C","D"], true)) {
    throw new RuntimeException("Nomor {$nomor}: Kunci jawaban harus A, B, C, atau D.");
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

function csv_parse_valid_rows(string $tmpPath): array {
  $fh = fopen($tmpPath, "r");
  if (!$fh) throw new RuntimeException("File CSV tidak bisa dibaca. Silakan coba upload ulang.");

  $rows = [];
  $line = 0;

  try {
    while (($row = fgetcsv($fh)) !== false) {
      $line++;
      $row = array_map(static fn($v) => is_string($v) ? trim($v) : "", $row);

      $allEmpty = true;
      foreach ($row as $v) {
        if ((string)$v !== "") { $allEmpty = false; break; }
      }
      if ($allEmpty) continue;

      if (count($row) < 7) {
        throw new RuntimeException("Format CSV tidak sesuai. Pastikan ada 7 kolom: nomor, pertanyaan, opsi_a, opsi_b, opsi_c, opsi_d, jawaban.");
      }

      if ($line === 1 && !ctype_digit((string)$row[0])) {
        continue;
      }

      if (!ctype_digit((string)$row[0])) {
        throw new RuntimeException("Format CSV tidak sesuai. Kolom nomor harus angka.");
      }

      $nomor = (int)$row[0];
      $pertanyaan = (string)$row[1];
      $a = (string)$row[2];
      $b = (string)$row[3];
      $c = (string)$row[4];
      $d = (string)$row[5];
      $jawaban = strtoupper((string)$row[6]);

      if ($nomor < 1 || $nomor > 15) {
        throw new RuntimeException("Nomor soal pada CSV harus 1 sampai 15.");
      }

      if (trim($pertanyaan) === "" || trim($a) === "" || trim($b) === "" || trim($c) === "" || trim($d) === "") {
        throw new RuntimeException("Ada soal pada CSV yang belum lengkap. Pastikan pertanyaan dan opsi A–D terisi.");
      }

      if (!in_array($jawaban, ["A","B","C","D"], true)) {
        throw new RuntimeException("Kunci jawaban pada CSV harus A, B, C, atau D.");
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

  if (count($rows) === 0) {
    throw new RuntimeException("CSV tidak berisi soal. Pastikan minimal ada 1 soal.");
  }

  return $rows;
}

ensure_tables();

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

if (isset($_GET["ajax"]) && $_GET["ajax"] === "paket_detail") {
  header("Content-Type: application/json; charset=utf-8");
  $id = (int)($_GET["id"] ?? 0);
  if ($id <= 0) { echo json_encode(["ok"=>false]); exit; }

  $p = db()->prepare("SELECT id, judul, input_mode, bagian FROM kuis_paket WHERE id=?");
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
      "bagian" => $paket["bagian"] !== null ? (string)$paket["bagian"] : "",
    ],
    "soal"=>$rows
  ]);
  exit;
}

$toast = ["type"=>"", "msg"=>""];

try {
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");

    if ($action === "paket_delete") {
      $id = (int)($_POST["paket_id"] ?? 0);
      if ($id <= 0) throw new RuntimeException("Data paket tidak valid.");
      db()->prepare("DELETE FROM kuis_paket WHERE id=?")->execute([$id]);
      $toast = ["type"=>"success","msg"=>"Paket kuis berhasil dihapus."];
    }

    if ($action === "soal_save_bulk") {
      $paketId = (int)($_POST["paket_id"] ?? 0);
      $judulPaket = (string)($_POST["judul_paket"] ?? "");
      $bagian = (string)($_POST["bagian"] ?? "");

      db()->beginTransaction();

      if ($paketId <= 0) {
        $paketId = paket_create($judulPaket, "manual", $bagian);
      } else {
        paket_update($paketId, $judulPaket, "manual", $bagian);
      }

      $bulkJson = (string)($_POST["bulk_json"] ?? "");
      if ($bulkJson === "") throw new RuntimeException("Data soal masih kosong.");

      $bulk = json_decode($bulkJson, true);
      if (!is_array($bulk)) throw new RuntimeException("Data soal tidak terbaca. Silakan coba lagi.");

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

    if ($action === "csv_import") {
      $paketId = (int)($_POST["paket_id"] ?? 0);
      $judulPaket = (string)($_POST["judul_paket"] ?? "");
      $bagian = (string)($_POST["bagian"] ?? "");

      if (!isset($_FILES["csv"]) || !is_uploaded_file($_FILES["csv"]["tmp_name"])) {
        throw new RuntimeException("File CSV wajib diupload.");
      }

      $parsedRows = csv_parse_valid_rows($_FILES["csv"]["tmp_name"]);

      db()->beginTransaction();

      if ($paketId <= 0) {
        $paketId = paket_create($judulPaket, "csv", $bagian);
      } else {
        paket_update($paketId, $judulPaket, "csv", $bagian);
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
  $toast = ["type"=>"danger","msg"=>friendly_error_message($e->getMessage())];
}

$paket = db()->query("
  SELECT p.id, p.judul, p.input_mode, p.bagian,
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

    .nav-link{color:#fff !important;font-weight:500;}
    .nav-hover{position:relative;padding-bottom:6px;}
    .nav-hover::after{content:"";position:absolute;left:0;bottom:0;width:0;height:3px;background:#f4c430;transition:0.3s ease;}
    .nav-hover:hover::after,.nav-active::after{width:100%;}

    .page{max-width:1200px;margin:0 auto;width:100%;padding:140px 20px 40px;flex:1;}

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
    .table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    .table-grid{min-width:980px;}

    .table-head{
      background:var(--header-gray);padding:18px 34px;
      display:grid;
      grid-template-columns:90px 1fr 280px 220px 90px;
      align-items:center;
      font-weight:900;font-size:20px;color:#111;
    }
    .table-row{
      padding:18px 34px;display:grid;
      grid-template-columns:90px 1fr 280px 220px 90px;
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
    .pill-select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-color:#fff;padding-right:42px;line-height:1.2;}
    .pill-select{
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='none' viewBox='0 0 24 24'%3E%3Cpath stroke='%23111' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat:no-repeat;background-position:right 14px center;
    }
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
      padding:14px;
    }
    .dropzone.dragover{outline:3px solid rgba(112,13,9,.35);}
    .dropzone .dz-icon{font-size:50px;color:#fff;}
    .dropzone .dz-text{color:#fff;font-size:14px;font-weight:800;word-break:break-word;}

    .ans-grid{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;}
    .ans-item{display:flex;align-items:center;gap:8px;border:2px solid #111;border-radius:999px;padding:10px 14px;cursor:pointer;user-select:none;}
    .ans-item input{accent-color: var(--maroon); transform:scale(1.05);}
    .ans-item.active{border-color:var(--maroon); background:#f3e9e9;}

    .actions{display:flex;justify-content:flex-end;gap:12px;margin-top:16px;flex-wrap:wrap;}
    .btn-save{border:0;background:var(--maroon);color:#fff;font-weight:900;font-size:14px;padding:12px 34px;border-radius:14px;}
    .btn-outline{border:2px solid #111;background:#fff;color:#111;font-weight:800;font-size:14px;padding:12px 34px;border-radius:14px;}

    .tpl-link{
      display:inline-flex;align-items:center;gap:8px;
      font-weight:900;font-size:12px;
      color:#333;text-decoration:none;
      padding:6px 10px;border-radius:999px;
      background:#f3f3f3;border:1px solid rgba(0,0,0,.12);
      transition:transform .15s ease, filter .15s ease;
      white-space:nowrap;
    }
    .tpl-link:hover{filter:brightness(.98);transform:translateY(-1px);}

    .info-max{
      margin-top:8px;font-size:12px;font-weight:900;color:#700D09;
      background:rgba(112,13,9,.08);
      border:1px solid rgba(112,13,9,.18);
      padding:8px 10px;border-radius:12px;
      display:inline-flex;align-items:center;gap:8px;
    }

    .btn-back{
      width:42px;height:42px;border-radius:12px;
      display:inline-flex;align-items:center;justify-content:center;
      color:#fff;text-decoration:none;
      transition:transform .15s ease, filter .15s ease;
    }
    .btn-back:hover{filter:brightness(1.05);transform:translateY(-1px);}
    .btn-back i{font-size:22px;line-height:1;}

    .col-bagian{padding-left:0.5cm;}

    @media (max-width: 576px){
      body{font-size:13px;}
      .title{font-size:32px;}
      .subtitle{font-size:12px;}
      .btn-add{font-size:12px;padding:10px 18px;margin-top:10px;}
      .table-head{font-size:16px;padding:14px 16px;}
      .table-row{font-size:14px;padding:14px 16px;}
      .icon-btn{width:40px;height:40px;}
      .icon-edit,.icon-trash{font-size:20px;}
      .modal-header-custom{padding:18px 18px 14px;}
      .modal-title-custom{font-size:22px;}
      .modal-subtitle-custom{font-size:12px;}
      .modal-body{padding:14px 14px 16px;}
      .pill-input{font-size:13px;padding:9px 14px;}
      textarea.big{font-size:13px;}
      .mode-switch{width:160px;}
      .mode-pill{font-size:12px;}
      .dropzone{height:150px;}
      .dropzone .dz-icon{font-size:44px;}
      .dropzone .dz-text{font-size:12px;}
      .btn-save,.btn-outline{font-size:12px;padding:10px 18px;border-radius:12px;}
      .tpl-link{font-size:11px;}
      .info-max{font-size:11px;}
      .table-grid{min-width:980px;}
    }

    .modal-overlay{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.6);
      display:none;
      align-items:center;
      justify-content:center;
      z-index:9999;
    }

    .modal-content-custom{
      background:#fff;
      padding:26px;
      border-radius:18px;
      width:360px;
      text-align:center;
      box-shadow:0 18px 28px rgba(0,0,0,.22);
    }

    .btn-modal-action{
      border:0;
      border-radius:20px;
      padding:6px 22px;
      font-weight:600;
      background:var(--maroon);
      color:#fff;
    }

    .btn-modal-cancel{
      border:2px solid #111;
      border-radius:20px;
      padding:6px 22px;
      font-weight:700;
      background:#fff;
      color:#111;
    }

    .popup-actions{
      display:flex;
      gap:10px;
      justify-content:center;
      margin-top:14px;
      flex-wrap:wrap;
    }

    .popup-title{
      font-weight:900;
      font-size:18px;
      margin:0 0 8px;
      color:#111;
    }

    .popup-msg{
      margin:0;
      font-size:14px;
      color:#333;
      line-height:1.45;
      white-space:pre-line;
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

    <ul class="navbar-nav flex-row gap-5 align-items-center">
      <li class="nav-item">
        <a class="nav-link nav-hover" href="login_admin.php" id="logoutLink">LOGOUT</a>
      </li>
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

  <?php if ($toast["type"] && $toast["type"] !== "danger"): ?>
    <div class="alert alert-<?= htmlspecialchars($toast["type"]) ?> mt-4"
         style="border-radius:16px;font-weight:800;max-width:980px;margin-left:auto;margin-right:auto;">
      <?= htmlspecialchars($toast["msg"]) ?>
    </div>
  <?php endif; ?>

  <section class="table-wrap">
    <div class="table-scroll">
      <div class="table-head table-grid">
        <div></div>
        <div class="text">JUDUL MATERI</div>
        <div class="text col-bagian">BAGIAN</div>
        <div class="text-center">JUMLAH SOAL</div>
        <div></div>
      </div>

      <?php foreach ($paket as $p): ?>
        <?php
          $judulFull = (string)$p["judul"];
          $judulShow = judul_ellipsis($judulFull, 40);
          $bagianVal = (string)($p["bagian"] ?? "");
        ?>
        <div class="table-row table-grid">
          <div class="cell-center">
            <button class="icon-btn btn-edit" type="button"
                    data-id="<?= (int)$p["id"] ?>"
                    data-judul="<?= htmlspecialchars($judulFull) ?>"
                    data-mode="<?= htmlspecialchars((string)$p["input_mode"]) ?>"
                    data-bagian="<?= htmlspecialchars($bagianVal) ?>">
              <i class="bi bi-pencil-fill icon-edit"></i>
            </button>
          </div>

          <div title="<?= htmlspecialchars($judulFull) ?>">
            <?= htmlspecialchars($judulShow) ?>
          </div>

          <div class="col-bagian" title="<?= htmlspecialchars($bagianVal) ?>">
            <?= htmlspecialchars($bagianVal !== "" ? $bagianVal : "-") ?>
          </div>

          <div class="cell-center"><?= (int)$p["jumlah_soal"] ?></div>

          <div class="cell-center">
            <form method="post" class="form-delete-paket">
              <input type="hidden" name="action" value="paket_delete">
              <input type="hidden" name="paket_id" value="<?= (int)$p["id"] ?>">
              <button class="icon-btn btn-delete" type="submit" title="Hapus">
                <i class="bi bi-trash3-fill icon-trash"></i>
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>

      <div style="height:14px;background:#fff"></div>
    </div>
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
            <div class="text-muted fst-italic fw-light" style="font-size:12px;margin-top:-6px;margin-bottom:8px;">
              Maksimal 45 karakter (termasuk spasi). Hanya boleh huruf, angka, spasi, titik (.), koma (,), titik dua (:), dan tanda tanya (?).
            </div>
            <input class="pill-input" type="text" name="judul_paket" id="judulPaketInput" placeholder="Tuliskan Judul Kuis di sini..." required maxlength="45">
          </div>

          <div class="flex-grow-1">
            <label class="fw-bold mb-2" style="font-size:14px;">Bagian</label>
            <select class="pill-input pill-select" id="bagianInput" name="bagian" required>
              <option value="">-- Pilih Bagian --</option>
              <option value="Keuangan">Keuangan</option>
              <option value="Umum dan Logistik">Umum dan Logistik</option>
              <option value="Teknis Penyelenggara Pemilu, Partisipasi Hubungan Masyarakat">Teknis Penyelenggara Pemilu, Partisipasi Hubungan Masyarakat</option>
              <option value="Hukum dan SDM">Hukum dan SDM</option>
              <option value="Perencanaan">Perencanaan</option>
              <option value="Data dan Informasi">Data dan Informasi</option>
            </select>
          </div>

          <div class="mode-switch mt-2 mt-md-4" id="modeSwitch">
            <div class="mode-pill active" data-mode="csv">CSV</div>
            <div class="mode-pill inactive" data-mode="manual">Manual</div>
          </div>
        </div>

        <div id="csvArea" class="mt-4">
          <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
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

<div class="modal-overlay" id="popupOverlay" aria-hidden="true">
  <div class="modal-content-custom" role="dialog" aria-modal="true" aria-labelledby="popupTitle" aria-describedby="popupMsg">
    <h3 class="popup-title" id="popupTitle">Peringatan</h3>
    <p class="popup-msg" id="popupMsg"></p>
    <div class="popup-actions" id="popupActions"></div>
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
  const bagianInput = document.getElementById("bagianInput");
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

  const popupOverlay = document.getElementById("popupOverlay");
  const popupTitle = document.getElementById("popupTitle");
  const popupMsg = document.getElementById("popupMsg");
  const popupActions = document.getElementById("popupActions");

  function openPopup({ title="Peringatan", message="", buttons=[] }) {
    popupTitle.textContent = title;
    popupMsg.textContent = message;

    popupActions.innerHTML = "";
    buttons.forEach((b) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = b.variant === "cancel" ? "btn-modal-cancel" : "btn-modal-action";
      btn.textContent = b.text || "OK";
      btn.addEventListener("click", () => {
        closePopup();
        if (typeof b.onClick === "function") b.onClick();
      });
      popupActions.appendChild(btn);
    });

    popupOverlay.style.display = "flex";
    popupOverlay.setAttribute("aria-hidden", "false");
  }

  function closePopup() {
    popupOverlay.style.display = "none";
    popupOverlay.setAttribute("aria-hidden", "true");
  }

  popupOverlay.addEventListener("click", (e) => {
    if (e.target === popupOverlay && popupActions.children.length <= 1) closePopup();
  });

  function showError(message) {
    openPopup({
      title: "Terjadi Kesalahan",
      message,
      buttons: [{ text: "OK", variant: "primary" }]
    });
  }

  function showConfirm({ title="Konfirmasi", message="", okText="Ya", cancelText="Batal", onOk, onCancel }) {
    openPopup({
      title,
      message,
      buttons: [
        { text: cancelText, variant: "cancel", onClick: onCancel },
        { text: okText, variant: "primary", onClick: onOk }
      ]
    });
  }

  const logoutLink = document.getElementById("logoutLink");
  logoutLink.addEventListener("click", (e) => {
    e.preventDefault();
    const href = logoutLink.getAttribute("href") || "login_admin.php";
    showConfirm({
      title: "Konfirmasi Logout",
      message: "Yakin ingin logout?",
      okText: "Logout",
      cancelText: "Batal",
      onOk: () => {
        window.location.href = href;
      }
    });
  });

  document.querySelectorAll(".form-delete-paket").forEach((form) => {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      showConfirm({
        title: "Konfirmasi Hapus",
        message: "Yakin ingin menghapus soal ini?",
        okText: "Hapus",
        cancelText: "Batal",
        onOk: () => form.submit()
      });
    });
  });

  let isDirty = false;
  let forceClose = false;
  let allowSubmit = false;

  function setDirty(v) { isDirty = !!v; }
  function markDirty(){ setDirty(true); }
  function clearDirty(){ setDirty(false); }

  modalEl.addEventListener("hide.bs.modal", (e) => {
    if (forceClose) return;
    if (isDirty) {
      e.preventDefault();
      showConfirm({
        title: "Perubahan belum disimpan",
        message: "Perubahan belum disimpan, yakin ingin keluar?",
        okText: "Keluar",
        cancelText: "Tetap di sini",
        onOk: () => {
          forceClose = true;
          modal.hide();
          setTimeout(() => { forceClose = false; }, 0);
        }
      });
    }
  });

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
    markDirty();
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
    markDirty();
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
        markDirty();
      });

      numbers.appendChild(btn);
    }
  }

  function resetForm(){
    paketIdInput.value = "";
    judulPaketInput.value = "";
    bagianInput.value = "";
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

    clearDirty();
    allowSubmit = false;
  }

  judulPaketInput.addEventListener("input", markDirty);
  bagianInput.addEventListener("change", markDirty);
  pertanyaanInput.addEventListener("input", markDirty);
  opsiA.addEventListener("input", markDirty);
  opsiB.addEventListener("input", markDirty);
  opsiC.addEventListener("input", markDirty);
  opsiD.addEventListener("input", markDirty);

  csvDrop.addEventListener("click", ()=> csvInput.click());
  csvDrop.addEventListener("dragover", (e)=>{ e.preventDefault(); csvDrop.classList.add("dragover"); });
  csvDrop.addEventListener("dragleave", ()=> csvDrop.classList.remove("dragover"));
  csvDrop.addEventListener("drop", (e)=>{
    e.preventDefault();
    csvDrop.classList.remove("dragover");
    if(e.dataTransfer.files && e.dataTransfer.files[0]){
      csvInput.files = e.dataTransfer.files;
      csvName.textContent = e.dataTransfer.files[0].name;
      markDirty();
    }
  });
  csvInput.addEventListener("change", ()=>{
    if(csvInput.files && csvInput.files[0]) {
      csvName.textContent = csvInput.files[0].name;
      markDirty();
    }
  });

  btnOpenAdd.addEventListener("click", ()=>{
    resetForm();
    modalTitle.textContent = "Input Kuis";
    modal.show();
  });

  document.querySelectorAll(".btn-edit").forEach(btn=>{
    btn.addEventListener("click", async ()=>{
      resetForm();
      modalTitle.textContent = "Edit Kuis";

      paketIdInput.value = btn.dataset.id || "";
      judulPaketInput.value = btn.dataset.judul || "";
      bagianInput.value = btn.dataset.bagian || "";

      try{
        const res = await fetch(`kuis_admin.php?ajax=paket_detail&id=${encodeURIComponent(paketIdInput.value)}`, { cache: "no-store" });
        const json = await res.json();

        if(json.ok){
          if(json.paket && typeof json.paket.bagian === "string") {
            bagianInput.value = json.paket.bagian || "";
          }

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

          setMode("manual");
          clearDirty();
        }
      }catch(e){}

      modal.show();
    });
  });

  function isJudulValid(judul){
    const t = (judul || "").trim();
    if(t.length === 0) return false;
    if(t.length > 45) return false;
    const re = /^[A-Za-z0-9 .,:\?]+$/;
    return re.test(t);
  }

  const formEl = document.getElementById("kuisForm");
  formEl.addEventListener("submit", (e)=>{
    if (allowSubmit) return;

    e.preventDefault();

    const judul = judulPaketInput.value || "";
    if(!isJudulValid(judul)){
      showError(
        "Judul tidak sesuai aturan.\n" +
        "- Maksimal 45 karakter (termasuk spasi)\n" +
        "- Hanya boleh: huruf, angka, spasi, titik (.), koma (,), titik dua (:), tanda tanya (?)"
      );
      return;
    }

    if(currentMode === "csv"){
      actionInput.value = "csv_import";
    } else {
      saveDraft();
      actionInput.value = "soal_save_bulk";
      bulkJsonInput.value = JSON.stringify(cacheSoal);
    }

    showConfirm({
      title: "Konfirmasi",
      message: "Yakin ingin disimpan?",
      okText: "Simpan",
      cancelText: "Batal",
      onOk: () => {
        allowSubmit = true;
        clearDirty();
        formEl.submit();
      }
    });
  });

  resetForm();

  <?php if ($toast["type"] === "danger"): ?>
    window.addEventListener("DOMContentLoaded", () => {
      showError(<?= json_encode((string)$toast["msg"], JSON_UNESCAPED_UNICODE) ?>);
    });
  <?php endif; ?>
</script>

<?php include 'footer.php'; ?>

</body>
</html>
