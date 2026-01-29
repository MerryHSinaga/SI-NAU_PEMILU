<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"])) {
  header("Location: login_admin.php");
  exit;
}

$DB_HOST = "localhost";
$DB_NAME = "sinau_pemilu";
$DB_USER = "root";
$DB_PASS = "";

$UPLOAD_DIR = __DIR__ . "/uploads/materi";
$UPLOAD_URL = "uploads/materi";

if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0775, true); }

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

function upload_one_pdf(array $file, int $maxBytes, string $destDir): array {
  if (!isset($file["tmp_name"]) || !is_uploaded_file($file["tmp_name"])) return [false,"","File wajib diupload."];
  if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false,"","Upload gagal."];
  if (($file["size"] ?? 0) > $maxBytes) return [false,"","Ukuran file terlalu besar (maks 500KB)."];

  $ext = strtolower(pathinfo((string)($file["name"] ?? ""), PATHINFO_EXTENSION));
  if ($ext !== "pdf") return [false,"","Tipe file tidak sesuai (wajib PDF)."];

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file["tmp_name"]);
  if (!in_array($mime, ["application/pdf","application/x-pdf"], true)) return [false,"","File tidak valid (bukan PDF)."];

  $name = safe_name("pdf");
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

$toast = ["type"=>"", "msg"=>""];
$lastAction = "";

try {
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");
    $lastAction = $action;

    if ($action === "add" || $action === "edit") {
      $judul = trim((string)($_POST["judul"] ?? ""));
      $mode  = (string)($_POST["mode"] ?? "pdf");

      if ($judul === "") throw new RuntimeException("Judul wajib diisi.");
      if ($mode !== "pdf") throw new RuntimeException("Materi hanya boleh dalam bentuk PDF.");

      db()->beginTransaction();

      if ($action === "add") {
        db()->prepare("INSERT INTO materi (judul, tipe, jumlah_slide) VALUES (?, 'pdf', 0)")
          ->execute([$judul]);
        $materiId = (int)db()->lastInsertId();
      } else {
        $materiId = (int)($_POST["id"] ?? 0);
        if ($materiId <= 0) throw new RuntimeException("ID tidak valid.");

        $st = db()->prepare("SELECT tipe FROM materi WHERE id=?");
        $st->execute([$materiId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException("Materi tidak ditemukan.");
        if ((string)$row["tipe"] !== "pdf") throw new RuntimeException("Tipe materi tidak valid di database.");

        db()->prepare("UPDATE materi SET judul=? WHERE id=?")
          ->execute([$judul, $materiId]);
      }

      $hasNewPdf = isset($_FILES["pdf"]) && ($_FILES["pdf"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
      $shouldReplaceMedia = ($action === "add") || $hasNewPdf;

      if ($shouldReplaceMedia) {
        if (!$hasNewPdf) throw new RuntimeException("Silakan pilih file PDF.");

        remove_media_files($materiId);

        [$ok,$fn,$err] = upload_one_pdf($_FILES["pdf"] ?? [], 500*1024, $UPLOAD_DIR);
        if (!$ok) throw new RuntimeException($err);

        $pages = count_pdf_pages($UPLOAD_DIR . "/" . $fn);

        db()->prepare("INSERT INTO materi_media (materi_id, file_path, sort_order) VALUES (?, ?, 0)")
          ->execute([$materiId, $fn]);

        db()->prepare("UPDATE materi SET jumlah_slide=? WHERE id=?")
          ->execute([$pages, $materiId]);
      }

      db()->commit();

      if ($action === "add") $toast = ["type"=>"success","msg"=>"Berhasil menambahkan " . $judul];
      else $toast = ["type"=>"success","msg"=>"Berhasil memperbarui " . $judul];
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

  $reason = $e->getMessage();
  if ($lastAction === "edit") $toast = ["type"=>"danger","msg"=>"Gagal memperbarui materi karena " . $reason];
  else $toast = ["type"=>"danger","msg"=>"Gagal menambahkan materi karena " . $reason];
}

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
      --maroon:#700D09;
      --bg:#E9EDFF;
      --header-gray:#d9d9d9;
      --row-line:#e6e6e6;
      --shadow:0 14px 22px rgba(0,0,0,.18);
      --gold:#f4c430;
    }

    body{
      margin:0;
      font-family:'Inter';
      background:var(--bg);
      min-height:100vh;
      display:flex;
      flex-direction:column;
    }

    .bg-maroon{background:var(--maroon)!important}
    .navbar{padding:20px 0;border-bottom:1px solid rgba(0,0,0,.15);}

    /* ✅ LOGOUT STYLE (SAMA SEPERTI DASHBOARD) */
    .nav-link{color:#fff !important;font-weight:500;}
    .nav-hover{position:relative;padding-bottom:6px;}
    .nav-hover::after{
      content:"";
      position:absolute;
      left:0;
      bottom:0;
      width:0;
      height:3px;
      background:var(--gold);
      transition:0.3s ease;
    }
    .nav-hover:hover::after,
    .nav-active::after{width:100%;}

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
      font-weight:600;font-size:14px;
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

    /* ✅ BARU: tabel bisa discroll horizontal di mobile */
    .table-scroll{
      overflow-x:auto;
      -webkit-overflow-scrolling:touch;
    }
    .table-grid{
      min-width:860px;
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

    /* ===== MODAL ===== */
    .label-plain{font-weight:600;font-size:14px;color:#111;margin-bottom:8px;}
    .input-pill{
      border:2px solid #111;border-radius:999px;
      padding:10px 18px;font-size:13px;outline:none;width:min(520px,100%);
    }

    .modal-dialog{ max-width:680px; }
    .modal-content{
      border:0;border-radius:28px;overflow:hidden;
      box-shadow:0 30px 60px rgba(0,0,0,.30);
    }
    .modal-header-custom{
      background:var(--maroon);
      padding:22px 28px 16px;
      position:relative;
    }
    .modal-title-custom{margin:0;color:#fff;font-weight:800;font-size:28px;line-height:1.05;}
    .modal-subtitle-custom{margin-top:6px;color:rgba(255,255,255,.85);font-style:italic;font-size:13px;}
    .modal-close-x{
      position:absolute;top:16px;right:18px;width:44px;height:44px;border-radius:12px;
      border:0;background:transparent;color:#fff;font-size:30px;display:flex;
      align-items:center;justify-content:center;opacity:.95;
    }

    .modal-body{
      padding:22px 28px 26px;
      background:#fff;
      max-height:70vh;
      overflow:auto;
    }

    .pdf-preview-box{
      margin-top:12px;background:#d9d9d9;border-radius:18px;padding:14px;
    }
    .pdf-meta{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      margin-bottom:10px;color:#111;font-size:12px;font-weight:800;
    }
    .pdf-canvas-wrap{
      background:#fff;border-radius:14px;overflow:hidden;
      box-shadow:0 10px 18px rgba(0,0,0,.10);
    }
    #pdfCanvas{display:block;width:100%;height:auto;}
    .pdf-fallback{
      width:100%;
      height:360px;
      border:0;
      display:none;
      background:#fff;
    }

    .dropzone{
      margin-top:12px;height:150px;border-radius:18px;background:#d9d9d9;border:2px dashed rgba(112,13,9,.25);
      display:flex;align-items:center;justify-content:center;text-align:center;cursor:pointer;
      padding:12px;
    }
    .dropzone.dragover{outline:3px solid rgba(112,13,9,.35);}
    .dropzone .dz-icon{font-size:42px;color:#fff;}
    .dropzone .dz-text{color:#fff;font-size:13px;font-weight:800;word-break:break-word;}

    .actions-row{display:flex;justify-content:flex-end;margin-top:16px;gap:10px;flex-wrap:wrap;}
    .btn-save{
      border:0;background:var(--maroon);color:#fff;font-weight:800;font-size:14px;
      padding:12px 44px;border-radius:14px;
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

    /* ✅ tablet */
    @media (max-width: 992px){
      .title{font-size:40px;}
      .table-wrap{max-width:100%;}
      .page{padding:120px 16px 30px;}
    }

    /* ✅ mobile: font kecil + tabel geser kanan */
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
      .label-plain{font-size:13px;}
      .input-pill{font-size:13px;padding:9px 14px;}
      .btn-save{font-size:12px;padding:10px 18px;border-radius:12px;}
      .dropzone{height:140px;}
      .dropzone .dz-icon{font-size:40px;}
      .dropzone .dz-text{font-size:12px;}

      .table-grid{min-width:760px;}
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

    <!-- ✅ LOGOUT tampil di mobile + style sama dashboard -->
    <ul class="navbar-nav flex-row gap-5 align-items-center">
      <li class="nav-item">
        <a class="nav-link nav-hover" href="login_admin.php">LOGOUT</a>
      </li>
    </ul>

  </div>
</nav>

<main class="page">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3" style="max-width:980px;margin:0 auto;">
    <div>
      <h1 class="title">Daftar Materi</h1>
      <div class="subtitle">Klik tombol edit untuk memperbarui file atau judul materi.</div>
    </div>

    <button class="btn-add" type="button" id="btnOpenAdd">
      <span>+ Tambah Materi</span>
    </button>
  </div>

  <?php if ($toast["type"]): ?>
    <div class="alert alert-<?= htmlspecialchars($toast["type"]) ?> mt-4"
         style="border-radius:16px;font-weight:800;max-width:980px;margin-left:auto;margin-right:auto;">
      <?= htmlspecialchars($toast["msg"]) ?>
    </div>
  <?php endif; ?>

  <section class="table-wrap">
    <!-- ✅ wrapper scroll -->
    <div class="table-scroll">
      <div class="table-head table-grid">
        <div></div>
        <div class="text">JUDUL MATERI</div>
        <div class="text-center">JUMLAH SLIDE</div>
        <div></div>
      </div>

      <?php foreach ($rows as $r): ?>
        <?php
          $rid = (int)$r["id"];
          $media = $mediaByMateri[$rid] ?? [];
          $pdfFile = $media[0] ?? "";
        ?>
        <div class="table-row table-grid">
          <div class="cell-center">
            <button class="icon-btn btn-edit"
                    type="button"
                    data-id="<?= $rid ?>"
                    data-judul="<?= htmlspecialchars($r["judul"]) ?>"
                    data-pdf="<?= htmlspecialchars($pdfFile) ?>">
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

      <div style="height:14px;background:#fff"></div>
    </div>
  </section>
  
</main>

<!-- MODAL -->
<div class="modal fade" id="materiModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="materiForm" method="post" enctype="multipart/form-data">
      <div class="modal-header-custom">
        <button type="button" class="modal-close-x" data-bs-dismiss="modal" aria-label="Close">&times;</button>
        <div class="modal-title-custom" id="modalTitle">Materi Baru</div>
        <div class="modal-subtitle-custom">Upload materi hanya dalam bentuk PDF</div>
      </div>

      <div class="modal-body">
        <input type="hidden" name="action" id="actionInput" value="add">
        <input type="hidden" name="id" id="idInput" value="">
        <input type="hidden" name="mode" id="modeInput" value="pdf">

        <div class="label-plain">Judul Materi</div>
        <input class="input-pill" name="judul" id="judulInput" type="text" placeholder="Tuliskan judul materi di sini..." required>

        <div class="mt-3" style="font-weight:800;font-size:14px;">Input Materi</div>
        <div style="font-style:italic;font-size:12px;">(PDF) max. 500Kb</div>

        <input id="pdfPicker" name="pdf" type="file" accept="application/pdf" class="d-none">

        <div class="pdf-preview-box" id="pdfPreviewBox" style="display:none;">
          <div class="pdf-meta">
            <div id="pdfName" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:420px;"></div>
            <button type="button" class="btn btn-sm btn-light" id="btnChangePdf" style="border-radius:999px;font-weight:900;">
              Ganti PDF
            </button>
          </div>

          <div class="pdf-canvas-wrap" id="canvasWrap">
            <canvas id="pdfCanvas"></canvas>
          </div>

          <iframe id="pdfFallback" class="pdf-fallback" title="Preview PDF"></iframe>
        </div>

        <div class="dropzone" id="dropzone">
          <div>
            <div class="dz-icon"><i class="bi bi-file-earmark-pdf"></i></div>
            <div class="dz-text">Klik atau seret file PDF ke sini</div>
          </div>
        </div>

        <div class="actions-row">
          <button class="btn-save" type="submit" id="btnSave">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- penting: bootstrap bundle dulu -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- pdfjs optional (kalau gagal load, modal tetap jalan) -->
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.js"></script>

<script>
(function(){
  const UPLOAD_URL = <?= json_encode($UPLOAD_URL) ?>;

  // ===== ambil elemen =====
  const materiModalEl = document.getElementById('materiModal');
  const btnOpenAdd = document.getElementById('btnOpenAdd');
  const modalTitle = document.getElementById('modalTitle');
  const actionInput = document.getElementById('actionInput');
  const idInput = document.getElementById('idInput');
  const judulInput = document.getElementById('judulInput');

  const pdfPicker = document.getElementById('pdfPicker');
  const dropzone = document.getElementById('dropzone');

  const pdfPreviewBox = document.getElementById('pdfPreviewBox');
  const pdfName = document.getElementById('pdfName');
  const btnChangePdf = document.getElementById('btnChangePdf');
  const pdfCanvas = document.getElementById('pdfCanvas');
  const canvasWrap = document.getElementById('canvasWrap');
  const pdfFallback = document.getElementById('pdfFallback');

  const materiForm = document.getElementById('materiForm');

  // ===== bootstrap modal =====
  const materiModal = new bootstrap.Modal(materiModalEl, { backdrop: true, keyboard: true });

  let currentAction = "add";
  let pickedPdfFile = null;
  let existingPdfFilename = "";

  function setFileToInput(file){
    const dt = new DataTransfer();
    dt.items.add(file);
    pdfPicker.files = dt.files;
  }

  function validatePdfFile(file){
    if(!file) return "File tidak ditemukan.";
    if(file.type !== "application/pdf") return "Tipe file harus PDF.";
    if(file.size > 500 * 1024) return "Ukuran file terlalu besar (maks 500KB).";
    return "";
  }

  function showPreviewBox(name){
    pdfPreviewBox.style.display = "block";
    pdfName.textContent = name || "";
  }

  function clearCanvas(){
    const ctx = pdfCanvas.getContext("2d");
    ctx.clearRect(0, 0, pdfCanvas.width, pdfCanvas.height);
  }

  function hidePreviewBox(){
    pdfPreviewBox.style.display = "none";
    pdfName.textContent = "";
    pickedPdfFile = null;
    existingPdfFilename = "";
    clearCanvas();
    pdfFallback.style.display = "none";
    pdfFallback.src = "";
    canvasWrap.style.display = "block";
  }

  async function renderCoverWithPdfJsFromArrayBuffer(buf){
    if(typeof window.pdfjsLib === "undefined") return false;

    try{
      window.pdfjsLib.GlobalWorkerOptions.workerSrc =
        "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.js";

      const pdf = await window.pdfjsLib.getDocument({data: buf}).promise;
      const page = await pdf.getPage(1);

      const baseViewport = page.getViewport({ scale: 1 });
      const targetWidth = Math.min(640, pdfPreviewBox.clientWidth - 28);
      const scale = targetWidth / baseViewport.width;
      const viewport = page.getViewport({ scale });

      const ctx = pdfCanvas.getContext("2d");
      pdfCanvas.width = Math.floor(viewport.width);
      pdfCanvas.height = Math.floor(viewport.height);

      await page.render({ canvasContext: ctx, viewport }).promise;
      return true;
    }catch(e){
      return false;
    }
  }

  async function renderCoverWithPdfJsFromUrl(url){
    if(typeof window.pdfjsLib === "undefined") return false;

    try{
      window.pdfjsLib.GlobalWorkerOptions.workerSrc =
        "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.js";

      const pdf = await window.pdfjsLib.getDocument(url).promise;
      const page = await pdf.getPage(1);

      const baseViewport = page.getViewport({ scale: 1 });
      const targetWidth = Math.min(640, pdfPreviewBox.clientWidth - 28);
      const scale = targetWidth / baseViewport.width;
      const viewport = page.getViewport({ scale });

      const ctx = pdfCanvas.getContext("2d");
      pdfCanvas.width = Math.floor(viewport.width);
      pdfCanvas.height = Math.floor(viewport.height);

      await page.render({ canvasContext: ctx, viewport }).promise;
      return true;
    }catch(e){
      return false;
    }
  }

  function renderFallbackIframe(url){
    canvasWrap.style.display = "none";
    pdfFallback.style.display = "block";
    pdfFallback.src = url + "#page=1&zoom=page-width";
  }

  async function handlePdfSelect(file){
    const msg = validatePdfFile(file);
    if(msg){ alert(msg); return; }

    pickedPdfFile = file;
    setFileToInput(file);

    showPreviewBox(file.name);

    const buf = await file.arrayBuffer();
    const ok = await renderCoverWithPdfJsFromArrayBuffer(buf);
    if(!ok){
      const blobUrl = URL.createObjectURL(file);
      renderFallbackIframe(blobUrl);
    }else{
      pdfFallback.style.display = "none";
      pdfFallback.src = "";
      canvasWrap.style.display = "block";
    }
  }

  function resetModal(){
    judulInput.value = "";
    actionInput.value = "add";
    idInput.value = "";
    pdfPicker.value = "";
    hidePreviewBox();
  }

  btnOpenAdd.addEventListener('click', () => {
    currentAction = "add";
    modalTitle.textContent = "Materi Baru";
    resetModal();
    materiModal.show();
  });

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', async () => {
      currentAction = "edit";
      modalTitle.textContent = "Edit Materi";

      resetModal();
      actionInput.value = "edit";
      idInput.value = btn.dataset.id || "";
      judulInput.value = btn.dataset.judul || "";
      existingPdfFilename = btn.dataset.pdf || "";

      if(existingPdfFilename){
        showPreviewBox(existingPdfFilename);
        const url = `${UPLOAD_URL}/${existingPdfFilename}`;

        const ok = await renderCoverWithPdfJsFromUrl(url);
        if(!ok){
          renderFallbackIframe(url);
        }else{
          pdfFallback.style.display = "none";
          pdfFallback.src = "";
          canvasWrap.style.display = "block";
        }
      }

      materiModal.show();
    });
  });

  pdfPicker.addEventListener('change', () => {
    const f = (pdfPicker.files || [])[0];
    if(f) handlePdfSelect(f);
  });

  btnChangePdf.addEventListener('click', () => pdfPicker.click());

  dropzone.addEventListener('click', () => pdfPicker.click());
  dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    const f = (e.dataTransfer.files || [])[0];
    if(f) handlePdfSelect(f);
  });

  materiForm.addEventListener("submit", (e) => {
    const hasPdf = (pdfPicker.files && pdfPicker.files.length > 0);

    if(currentAction === "add" && !hasPdf){
      e.preventDefault();
      alert("Wajib upload file PDF.");
      return;
    }

    if(hasPdf){
      const msg = validatePdfFile(pdfPicker.files[0]);
      if(msg){
        e.preventDefault();
        alert(msg);
        return;
      }
    }
  });

})();
</script>

<?php include 'footer.php'; ?>
</body>
</html>
