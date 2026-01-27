<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"])) {
  header("Location: login_admin.php");
  exit;
}

/* =======================
   KONFIG DATABASE & UPLOAD
======================= */
$DB_HOST = "localhost";
$DB_NAME = "sinau_pemilu";
$DB_USER = "root";
$DB_PASS = "";

$UPLOAD_DIR = __DIR__ . "/uploads/materi";
$UPLOAD_URL = "uploads/materi";

if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0775, true); }

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
   HELPERS
======================= */
function safe_name(string $ext): string {
  $ext = strtolower($ext);
  return "materi_" . date("Ymd_His") . "_" . bin2hex(random_bytes(6)) . "." . $ext;
}

function count_pdf_pages(string $pdfPath): int {
  $pdfinfo = @shell_exec("pdfinfo " . escapeshellarg($pdfPath) . " 2>/dev/null");
  if (is_string($pdfinfo) && $pdfinfo !== "" && preg_match('/Pages:\s+(\d+)/i', $pdfinfo, $m)) {
    return (int)$m[1];
  }
  $content = @file_get_contents($pdfPath);
  if (is_string($content) && $content !== "") {
    $n = preg_match_all("/\/Type\s*\/Page\b/", $content);
    if ($n > 0) return (int)$n;
  }
  return 1;
}

function upload_one(array $file, array $allowExt, int $maxBytes, string $destDir): array {
  if (!isset($file["tmp_name"]) || !is_uploaded_file($file["tmp_name"])) return [false,"","File wajib diupload."];
  if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false,"","Upload gagal."];
  if (($file["size"] ?? 0) > $maxBytes) return [false,"","Ukuran file terlalu besar."];

  $ext = strtolower(pathinfo((string)($file["name"] ?? ""), PATHINFO_EXTENSION));
  if (!in_array($ext, $allowExt, true)) return [false,"","Tipe file tidak sesuai."];

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file["tmp_name"]);

  $okMime = false;
  if ($ext === "pdf" && in_array($mime, ["application/pdf","application/x-pdf"], true)) $okMime = true;
  if (in_array($ext, ["jpg","jpeg"], true) && in_array($mime, ["image/jpeg"], true)) $okMime = true;
  if ($ext === "png" && in_array($mime, ["image/png"], true)) $okMime = true;
  if (!$okMime) return [false,"","File tidak valid."];

  $name = safe_name($ext === "jpeg" ? "jpg" : $ext);
  $path = rtrim($destDir,"/") . "/" . $name;

  if (!move_uploaded_file($file["tmp_name"], $path)) return [false,"","Gagal menyimpan file."];
  return [true,$name,""];
}

function remove_media_files(int $materiId): void {
  global $UPLOAD_DIR;
  $st = db()->prepare("SELECT file_path FROM materi_media WHERE materi_id=?");
  $st->execute([$materiId]);
  foreach ($st->fetchAll() as $f) {
    $p = $UPLOAD_DIR . "/" . $f["file_path"];
    if (is_file($p)) @unlink($p);
  }
  db()->prepare("DELETE FROM materi_media WHERE materi_id=?")->execute([$materiId]);
}

/* =======================
   CRUD HANDLER
======================= */
$toast = ["type"=>"", "msg"=>""];

try {
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");

    if ($action === "add" || $action === "edit") {
      $judul = trim((string)($_POST["judul"] ?? ""));
      $mode  = (string)($_POST["mode"] ?? "jpg");
      if ($judul === "") throw new RuntimeException("Judul wajib diisi.");
      if (!in_array($mode, ["pdf","jpg"], true)) throw new RuntimeException("Mode tidak valid.");

      db()->beginTransaction();

      if ($action === "add") {
        db()->prepare("INSERT INTO materi (judul, tipe, jumlah_slide) VALUES (?, ?, 0)")
          ->execute([$judul, $mode]);
        $materiId = (int)db()->lastInsertId();
        $oldMode = null;
      } else {
        $materiId = (int)($_POST["id"] ?? 0);
        if ($materiId <= 0) throw new RuntimeException("ID tidak valid.");

        $st = db()->prepare("SELECT tipe FROM materi WHERE id=?");
        $st->execute([$materiId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException("Materi tidak ditemukan.");
        $oldMode = (string)$row["tipe"];

        db()->prepare("UPDATE materi SET judul=?, tipe=? WHERE id=?")
          ->execute([$judul, $mode, $materiId]);
      }

      // Deteksi apakah user benar-benar upload file baru
      $hasNewPdf = isset($_FILES["pdf"]) && ($_FILES["pdf"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
      $hasNewJpg = isset($_FILES["jpgs"]) && is_array($_FILES["jpgs"]["name"] ?? null) && !empty($_FILES["jpgs"]["name"][0]);

      // Kalau edit dan cuma ubah judul (tanpa upload baru) -> jangan sentuh media
      $shouldReplaceMedia = ($action === "add") || ($oldMode !== $mode) || ($mode === "pdf" && $hasNewPdf) || ($mode === "jpg" && $hasNewJpg);

      if ($shouldReplaceMedia) {
        // hapus media lama hanya jika memang ada upload/berubah mode
        remove_media_files($materiId);

        // MODE PDF
        if ($mode === "pdf") {
          if (!$hasNewPdf && $action !== "add") {
            // edit mode pdf tapi tidak upload pdf baru -> harusnya tidak sampai sini
            // karena $shouldReplaceMedia false, tapi sebagai safety:
            throw new RuntimeException("Silakan pilih file PDF.");
          }

          [$ok,$fn,$err] = upload_one($_FILES["pdf"] ?? [], ["pdf"], 500*1024, $UPLOAD_DIR);
          if (!$ok) throw new RuntimeException($err);

          $pages = count_pdf_pages($UPLOAD_DIR . "/" . $fn);

          db()->prepare("INSERT INTO materi_media (materi_id, file_path, sort_order) VALUES (?, ?, 0)")
            ->execute([$materiId, $fn]);

          db()->prepare("UPDATE materi SET jumlah_slide=? WHERE id=?")
            ->execute([$pages, $materiId]);
        }

        // MODE JPG
        if ($mode === "jpg") {
          if (!$hasNewJpg && $action !== "add") {
            // edit mode jpg tapi tidak upload jpg baru -> harusnya tidak sampai sini
            throw new RuntimeException("Silakan pilih minimal 1 gambar.");
          }

          $count = is_array($_FILES["jpgs"]["name"] ?? null) ? count($_FILES["jpgs"]["name"]) : 0;
          if ($count === 0) throw new RuntimeException("Minimal 1 gambar.");

          $max = min(10, $count);
          $saved = 0;

          for ($i=0; $i<$max; $i++) {
            $file = [
              "name" => $_FILES["jpgs"]["name"][$i] ?? "",
              "type" => $_FILES["jpgs"]["type"][$i] ?? "",
              "tmp_name" => $_FILES["jpgs"]["tmp_name"][$i] ?? "",
              "error" => $_FILES["jpgs"]["error"][$i] ?? UPLOAD_ERR_NO_FILE,
              "size" => $_FILES["jpgs"]["size"][$i] ?? 0
            ];

            [$ok,$fn,$err] = upload_one($file, ["jpg","jpeg","png"], 100*1024, $UPLOAD_DIR);
            if (!$ok) continue;

            db()->prepare("INSERT INTO materi_media (materi_id, file_path, sort_order) VALUES (?, ?, ?)")
              ->execute([$materiId, $fn, $saved]);

            $saved++;
          }

          if ($saved === 0) throw new RuntimeException("Tidak ada gambar yang berhasil diupload.");

          db()->prepare("UPDATE materi SET jumlah_slide=? WHERE id=?")
            ->execute([$saved, $materiId]);
        }
      }

      db()->commit();
      $toast = ["type"=>"success","msg"=> $action==="add" ? "Materi berhasil ditambahkan." : "Materi berhasil diperbarui."];
    }

    if ($action === "delete") {
      $id = (int)($_POST["id"] ?? 0);
      if ($id <= 0) throw new RuntimeException("ID tidak valid.");

      remove_media_files($id);
      db()->prepare("DELETE FROM materi WHERE id=?")->execute([$id]);

      $toast = ["type"=>"success","msg"=>"Materi berhasil dihapus."];
    }
  }
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  $toast = ["type"=>"danger","msg"=>$e->getMessage()];
}

/* =======================
   LOAD DATA
======================= */
$rows = db()->query("SELECT * FROM materi ORDER BY id DESC")->fetchAll();

$mediaByMateri = [];
$st = db()->query("SELECT materi_id, file_path, sort_order FROM materi_media ORDER BY materi_id DESC, sort_order ASC, id ASC");
foreach ($st->fetchAll() as $m) {
  $mid = (int)$m["materi_id"];
  $mediaByMateri[$mid] ??= [];
  $mediaByMateri[$mid][] = $m["file_path"];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daftar Materi | DIY</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{
      --maroon:#700D09; --bg:#e9edff;
      --table-shadow:0 18px 26px rgba(0,0,0,.18);
      --header-gray:#d9d9d9; --row-line:#e6e6e6; --modal-radius:28px;
    }
    body{margin:0;font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);min-height:100vh;}
    .bg-maroon{background:var(--maroon)!important}
    .navbar-nav-simple{list-style:none;display:flex;align-items:center;gap:34px;margin:0;padding:0;}
    .navbar-nav-simple .nav-link{color:#fff;font-weight:700;letter-spacing:.5px;text-decoration:none;padding:6px 0;}
    .navbar-nav-simple .nav-link.active{position:relative;}
    .navbar-nav-simple .nav-link.active::after{content:"";position:absolute;left:0;right:0;margin:auto;bottom:-10px;width:64px;height:3px;background:#fff;border-radius:2px;opacity:.95;}
    .page{max-width:1200px;margin:0 auto;padding:120px 20px 40px;}
    .btn-back{border:0;background:var(--maroon);color:#fff;font-weight:800;font-size:18px;padding:12px 34px;border-radius:999px;cursor:pointer;box-shadow:0 10px 14px rgba(0,0,0,.18);display:inline-flex;align-items:center;gap:14px;margin-top:16px;}
    .title{font-weight:800;font-size:54px;margin:0;color:#111;line-height:1.05;}
    .subtitle{margin-top:10px;color:#333;font-size:18px;font-style:italic;}
    .btn-add{border:0;background:var(--maroon);color:#fff;font-weight:800;font-size:26px;padding:14px 54px;border-radius:999px;box-shadow:0 12px 16px rgba(0,0,0,.18);display:inline-flex;align-items:center;gap:14px;white-space:nowrap;}
    .table-wrap{margin-top:34px;background:#fff;border-radius:26px;overflow:hidden;box-shadow:var(--table-shadow);}
    .table-head{background:var(--header-gray);padding:24px 34px;display:grid;grid-template-columns:110px 1fr 240px 110px;align-items:center;font-weight:800;font-size:22px;color:#111;}
    .table-row{padding:22px 34px;display:grid;grid-template-columns:110px 1fr 240px 110px;align-items:center;border-top:1px solid var(--row-line);font-size:20px;}
    .cell-center{text-align:center;}
    .icon-btn{border:0;background:transparent;padding:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;transition:background .15s;}
    .icon-btn:hover{background:rgba(112,13,9,.08);}
    .icon-edit,.icon-trash{color:var(--maroon);font-size:26px;}
    .footer-img{width:100%;height:110px;object-fit:cover;display:block;margin-top:auto;}

    .modal-dialog{max-width:980px}
    .modal-content{border:0;border-radius:var(--modal-radius);overflow:hidden;box-shadow:0 30px 60px rgba(0,0,0,.28);}
    .modal-header-custom{background:var(--maroon);padding:34px 38px 28px;position:relative;}
    .modal-title-custom{margin:0;color:#fff;font-weight:800;font-size:44px;line-height:1.05;}
    .modal-subtitle-custom{margin-top:6px;color:rgba(255,255,255,.85);font-style:italic;font-size:18px;}
    .modal-close-x{position:absolute;top:28px;right:28px;width:48px;height:48px;border-radius:12px;border:0;background:transparent;color:#fff;font-size:34px;display:flex;align-items:center;justify-content:center;}
    .modal-body{padding:28px 38px 34px}
    .label-plain{font-weight:500;font-size:20px;color:#111;margin-bottom:10px;}
    .input-pill{border:2px solid #111;border-radius:999px;padding:12px 18px;font-size:18px;outline:none;width:min(520px,100%);}

    .mode-switch{width:210px;background:#d9d9d9;border-radius:999px;padding:6px;display:flex;gap:6px;position:relative;user-select:none;}
    .mode-pill{flex:1;border-radius:999px;padding:10px 0;text-align:center;font-weight:800;cursor:pointer;color:#fff;position:relative;z-index:2;font-size:18px;letter-spacing:.4px;opacity:.9;}
    .mode-pill.inactive{opacity:.55}
    .mode-slider{position:absolute;top:6px;bottom:6px;width:calc(50% - 6px);left:6px;border-radius:999px;background:var(--maroon);transition:transform .22s ease;z-index:1;}
    .mode-switch[data-mode="jpg"] .mode-slider{transform:translateX(100%)}

    .media-grid{margin-top:14px;display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;max-width:720px;}
    .slot{border-radius:18px;background:#d9d9d9;height:86px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;cursor:pointer;}
    .slot .plus{font-size:42px;color:#fff;font-weight:300;line-height:1;opacity:.95;}
    .thumb{position:absolute;inset:0;background-size:cover;background-position:center;}
    .thumb-overlay{position:absolute;inset:0;background:rgba(0,0,0,.35);}
    .thumb-close{position:absolute;top:8px;right:8px;width:24px;height:24px;border-radius:999px;border:0;background:rgba(0,0,0,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer;z-index:3;}

    .dropzone{margin-top:16px;max-width:740px;height:220px;border-radius:20px;background:#d9d9d9;border:2px dashed rgba(112,13,9,.25);display:flex;align-items:center;justify-content:center;text-align:center;gap:14px;cursor:pointer;}
    .dropzone.dragover{outline:3px solid rgba(112,13,9,.35);}
    .dropzone .dz-icon{font-size:56px;color:#fff;}
    .dropzone .dz-text{color:#fff;font-size:18px;font-weight:600;}

    .pdf-preview{margin-top:16px;max-width:740px;height:140px;border-radius:22px;overflow:hidden;background:#3a0b0a;position:relative;display:none;}
    .pdf-preview .pv-overlay{position:absolute;inset:0;background:rgba(112,13,9,.72);}
    .pdf-preview .pv-title{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:30px;text-align:center;padding:0 22px;line-height:1.1;}

    .actions-row{display:flex;justify-content:flex-end;margin-top:26px;gap:12px;}
    .btn-save{border:0;background:var(--maroon);color:#fff;font-weight:700;font-size:20px;padding:16px 64px;border-radius:18px;box-shadow:0 14px 18px rgba(0,0,0,.18);}
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-maroon fixed-top" style="padding:20px 0;">
  <div class="container d-flex justify-content-between align-items-center">
    <a class="navbar-brand d-flex align-items-center gap-2" href="admin.php">
      <img src="Asset/LogoKPU.png" width="40" height="40" alt="KPU Logo">
      <span><span class="fw-bold">KPU</span><br><span class="fw-normal">DIY</span></span>
    </a>

    <ul class="navbar-nav-simple">
      <li><a class="nav-link" href="dashboard.php">HOME</a></li>
      <li><a class="nav-link active" href="tambah_materi_admin.php">MATERI</a></li>
      <li><a class="nav-link" href="kuis_Admin.php">KUIS</a></li>
      <li><a class="nav-link" href="kontak.php">KONTAK</a></li>
      <li><a class="nav-link" href="dashboard.php">LOGOUT</a></li>
    </ul>
  </div>
</nav>

<main class="page">
  <button class="btn-back" type="button" onclick="history.back()">
    <i class="bi bi-arrow-left"></i> Kembali
  </button>

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-5">
    <div>
      <h1 class="title">Daftar Materi</h1>
      <div class="subtitle">Klik tombol edit untuk memperbarui file atau judul materi.</div>
    </div>

    <button class="btn-add" type="button" id="btnOpenAdd">
      <span>+ Tambah Materi</span>
    </button>
  </div>

  <?php if ($toast["type"]): ?>
    <div class="alert alert-<?= htmlspecialchars($toast["type"]) ?> mt-4" style="border-radius:16px;font-weight:700;">
      <?= htmlspecialchars($toast["msg"]) ?>
    </div>
  <?php endif; ?>

  <section class="table-wrap">
    <div class="table-head">
      <div></div>
      <div class="text-center">JUDUL MATERI</div>
      <div class="text-center">JUMLAH SLIDE</div>
      <div></div>
    </div>

    <?php foreach ($rows as $r): ?>
      <?php $rid = (int)$r["id"]; $media = $mediaByMateri[$rid] ?? []; ?>
      <div class="table-row">
        <div class="cell-center">
          <button class="icon-btn btn-edit"
                  type="button"
                  data-id="<?= $rid ?>"
                  data-judul="<?= htmlspecialchars($r["judul"]) ?>"
                  data-tipe="<?= htmlspecialchars($r["tipe"]) ?>"
                  data-media='<?= htmlspecialchars(json_encode($media, JSON_UNESCAPED_SLASHES)) ?>'>
            <i class="bi bi-pencil-fill icon-edit"></i>
          </button>
        </div>

        <div><?= htmlspecialchars($r["judul"]) ?></div>
        <div class="cell-center"><?= (int)$r["jumlah_slide"] ?></div>

        <div class="cell-center">
          <form method="post" onsubmit="return confirm('Yakin hapus materi ini?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $rid ?>">
            <button class="icon-btn" type="submit" title="Hapus">
              <i class="bi bi-trash3-fill icon-trash"></i>
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <div style="height:18px;background:#fff"></div>
  </section>
</main>

<img src="Asset/Footer.png" class="footer-img" alt="Footer">

<!-- MODAL -->
<div class="modal fade" id="materiModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="materiForm" method="post" enctype="multipart/form-data">
      <div class="modal-header-custom">
        <button type="button" class="modal-close-x" data-bs-dismiss="modal" aria-label="Close">&times;</button>
        <div class="modal-title-custom" id="modalTitle">Materi Baru</div>
        <div class="modal-subtitle-custom">Lengkapi formulir di bawah ini</div>
      </div>

      <div class="modal-body">
        <input type="hidden" name="action" id="actionInput" value="add">
        <input type="hidden" name="id" id="idInput" value="">
        <input type="hidden" name="mode" id="modeInput" value="jpg">

        <div class="label-plain">Judul Materi</div>
        <input class="input-pill" name="judul" id="judulInput" type="text" placeholder="Tuliskan judul materi di sini..." required>

        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mt-4">
          <div>
            <div class="section-title" id="sectionLabel">Input Materi</div>
            <div class="hint-red" id="hintLabel">(JPG/PNG) max. 100Kb (max 10)</div>
          </div>

          <div class="mode-switch" id="modeSwitch" data-mode="jpg">
            <div class="mode-slider"></div>
            <div class="mode-pill" id="pillPdf">PDF</div>
            <div class="mode-pill" id="pillJpg">JPG</div>
          </div>
        </div>

        <!-- IMPORTANT: input file JANGAN dihapus value-nya sebelum submit, karena kita isi lewat DataTransfer -->
        <input id="jpgPicker" name="jpgs[]" type="file" accept="image/png,image/jpeg" multiple class="d-none">
        <input id="pdfPicker" name="pdf" type="file" accept="application/pdf" class="d-none">

        <div id="jpgArea">
          <div class="media-grid" id="mediaGrid"></div>
        </div>

        <div id="pdfArea" style="display:none;">
          <div class="pdf-preview" id="pdfPreview">
            <div class="pv-overlay"></div>
            <div class="pv-title" id="pdfPreviewTitle">PDF TERPILIH</div>
          </div>

          <div class="dropzone" id="dropzone">
            <div>
              <div class="dz-icon"><i class="bi bi-file-earmark-pdf"></i></div>
              <div class="dz-text">Klik atau seret file PDF ke sini</div>
              <div class="dz-text" style="font-size:14px; font-weight:600; opacity:.85" id="pdfName"></div>
            </div>
          </div>
        </div>

        <div class="actions-row">
          <button class="btn-save" type="submit" id="btnSave">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  const UPLOAD_URL = <?= json_encode($UPLOAD_URL) ?>;

  const materiModalEl = document.getElementById('materiModal');
  const materiModal = new bootstrap.Modal(materiModalEl, { backdrop: true, keyboard: true });

  const materiForm = document.getElementById('materiForm');
  const modalTitle = document.getElementById('modalTitle');
  const sectionLabel = document.getElementById('sectionLabel');
  const hintLabel = document.getElementById('hintLabel');
  const judulInput = document.getElementById('judulInput');

  const actionInput = document.getElementById('actionInput');
  const idInput = document.getElementById('idInput');
  const modeInput = document.getElementById('modeInput');

  const modeSwitch = document.getElementById('modeSwitch');
  const pillPdf = document.getElementById('pillPdf');
  const pillJpg = document.getElementById('pillJpg');

  const jpgArea = document.getElementById('jpgArea');
  const pdfArea = document.getElementById('pdfArea');

  const jpgPicker = document.getElementById('jpgPicker');
  const pdfPicker = document.getElementById('pdfPicker');

  const mediaGrid = document.getElementById('mediaGrid');
  const dropzone = document.getElementById('dropzone');
  const pdfPreview = document.getElementById('pdfPreview');
  const pdfName = document.getElementById('pdfName');
  const pdfPreviewTitle = document.getElementById('pdfPreviewTitle');

  const MAX_SLOTS = 10;

  let currentAction = "add";
  let existingMedia = [];      // nama file lama (dari server) -> hanya preview
  let pickedJpgFiles = [];     // File object baru
  let pickedPdfFile = null;    // File object baru

  function setMode(mode){
    modeSwitch.setAttribute('data-mode', mode);
    modeInput.value = mode;

    if(mode === "pdf"){
      pillPdf.classList.remove('inactive');
      pillJpg.classList.add('inactive');
      jpgArea.style.display = "none";
      pdfArea.style.display = "block";
      hintLabel.textContent = "(PDF) max. 500Kb";
    }else{
      pillJpg.classList.remove('inactive');
      pillPdf.classList.add('inactive');
      jpgArea.style.display = "block";
      pdfArea.style.display = "none";
      hintLabel.textContent = "(JPG/PNG) max. 100Kb (max 10)";
    }
    sectionLabel.textContent = currentAction === "edit" ? "Edit Materi" : "Input Materi";
  }

  function resetModal(){
    judulInput.value = "";
    existingMedia = [];
    pickedJpgFiles = [];
    pickedPdfFile = null;

    pdfName.textContent = "";
    pdfPreview.style.display = "none";
    pdfPreviewTitle.textContent = "PDF TERPILIH";

    // jangan langsung kosongkan file input di sini kalau user baru pilih (tapi reset modal aman)
    jpgPicker.value = "";
    pdfPicker.value = "";

    setMode("jpg");
    renderGrid();
  }

  function renderGrid(){
    mediaGrid.innerHTML = "";

    const combined = [];
    for(const m of existingMedia) combined.push({type:"existing", value:m});
    for(const f of pickedJpgFiles) combined.push({type:"new", value:f});

    const shown = combined.slice(0, MAX_SLOTS);

    for(let i=0;i<MAX_SLOTS;i++){
      const slot = document.createElement('div');
      slot.className = "slot";

      if(shown[i]){
        const item = shown[i];
        let bgUrl = "";

        if(item.type === "existing"){
          bgUrl = `${UPLOAD_URL}/${item.value}`;
        }else{
          bgUrl = URL.createObjectURL(item.value);
        }

        const thumb = document.createElement('div');
        thumb.className = "thumb";
        thumb.style.backgroundImage = `url('${bgUrl}')`;

        const overlay = document.createElement('div');
        overlay.className = "thumb-overlay";

        const close = document.createElement('button');
        close.type = "button";
        close.className = "thumb-close";
        close.innerHTML = "&times;";
        close.addEventListener('click', (e) => {
          e.stopPropagation();
          if(item.type === "existing"){
            const idx = existingMedia.indexOf(item.value);
            if(idx >= 0) existingMedia.splice(idx, 1);
          }else{
            const idx = pickedJpgFiles.indexOf(item.value);
            if(idx >= 0) pickedJpgFiles.splice(idx, 1);
          }
          renderGrid();
        });

        slot.appendChild(thumb);
        slot.appendChild(overlay);
        slot.appendChild(close);
      } else {
        const plus = document.createElement('div');
        plus.className = "plus";
        plus.textContent = "+";
        slot.appendChild(plus);
      }

      slot.addEventListener('click', () => {
        if(modeSwitch.getAttribute('data-mode') !== "jpg") return;
        jpgPicker.click();
      });

      mediaGrid.appendChild(slot);
    }
  }

  // JPG input: simpan file object ke array (JANGAN buang semua input tanpa alasan)
  jpgPicker.addEventListener('change', () => {
    const incoming = Array.from(jpgPicker.files || []);
    if(incoming.length === 0) return;

    for(const f of incoming){
      if(pickedJpgFiles.length >= MAX_SLOTS) break;
      pickedJpgFiles.push(f);
    }

    // boleh kosongkan input supaya bisa pilih file yang sama lagi
    jpgPicker.value = "";
    renderGrid();
  });

  function handlePdfSelect(file){
    if(!file) return;
    pickedPdfFile = file;
    pdfName.textContent = file.name;
    pdfPreviewTitle.textContent = file.name.length > 34 ? file.name.slice(0,34) + "..." : file.name;
    pdfPreview.style.display = "block";
  }

  pdfPicker.addEventListener('change', () => {
    handlePdfSelect((pdfPicker.files || [])[0]);
    pdfPicker.value = "";
  });

  dropzone.addEventListener('click', () => pdfPicker.click());
  dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    const f = (e.dataTransfer.files || [])[0];
    if(f) handlePdfSelect(f);
  });

  pillPdf.addEventListener('click', () => setMode("pdf"));
  pillJpg.addEventListener('click', () => setMode("jpg"));

  document.getElementById('btnOpenAdd').addEventListener('click', () => {
    currentAction = "add";
    modalTitle.textContent = "Materi Baru";
    actionInput.value = "add";
    idInput.value = "";
    resetModal();
    materiModal.show();
  });

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      currentAction = "edit";
      modalTitle.textContent = "Edit Materi";
      actionInput.value = "edit";

      resetModal();

      idInput.value = btn.dataset.id || "";
      judulInput.value = btn.dataset.judul || "";

      const tipe = btn.dataset.tipe || "jpg";
      let media = [];
      try{ media = JSON.parse(btn.dataset.media || "[]"); }catch(e){ media = []; }

      if(tipe === "pdf"){
        setMode("pdf");
        if(media[0]){
          pdfPreviewTitle.textContent = media[0];
          pdfPreview.style.display = "block";
          pdfName.textContent = media[0];
        }
      } else {
        setMode("jpg");
        existingMedia = media;
        renderGrid();
      }

      materiModal.show();
    });
  });

  // ✅ KUNCI PERBAIKAN: sebelum submit, isi file input pakai DataTransfer
  materiForm.addEventListener("submit", (e) => {
    const mode = modeInput.value;

    if (mode === "jpg") {
      if (pickedJpgFiles.length === 0 && currentAction === "add") {
        e.preventDefault();
        alert("Pilih minimal 1 gambar.");
        return;
      }

      const dt = new DataTransfer();
      pickedJpgFiles.slice(0, MAX_SLOTS).forEach(f => dt.items.add(f));
      jpgPicker.files = dt.files; // sekarang $_FILES['jpgs'] terisi
    }

    if (mode === "pdf") {
      if (!pickedPdfFile && currentAction === "add") {
        e.preventDefault();
        alert("Pilih file PDF.");
        return;
      }

      if (pickedPdfFile) {
        const dt = new DataTransfer();
        dt.items.add(pickedPdfFile);
        pdfPicker.files = dt.files; // sekarang $_FILES['pdf'] terisi
      }
    }
  });

  // init
  setMode("jpg");
  renderGrid();
</script>

</body>
</html>
