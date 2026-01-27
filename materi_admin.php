<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/db.php";

if (empty($_SESSION["admin"])) {
  header("Location: login_admin.php");
  exit;
}

$UPLOAD_DIR = __DIR__ . "/uploads/materi";
$UPLOAD_URL = "uploads/materi";

if (!is_dir($UPLOAD_DIR)) {
  mkdir($UPLOAD_DIR, 0775, true);
}

/* =========================
   UTIL
========================= */
function safe_file_name(string $ext): string {
  return "materi_" . date("Ymd_His") . "_" . bin2hex(random_bytes(6)) . "." . strtolower($ext);
}

function upload_one(array $file, array $allowExt, int $maxBytes, string $destDir): array {
  if (!isset($file["tmp_name"]) || !is_uploaded_file($file["tmp_name"])) {
    return [false, "", "File tidak valid."];
  }

  if ($file["size"] > $maxBytes) {
    return [false, "", "Ukuran file terlalu besar."];
  }

  $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowExt, true)) {
    return [false, "", "Ekstensi tidak diizinkan."];
  }

  $name = safe_file_name($ext);
  $path = $destDir . "/" . $name;

  if (!move_uploaded_file($file["tmp_name"], $path)) {
    return [false, "", "Gagal menyimpan file."];
  }

  return [true, $name, ""];
}

/* =========================
   AJAX: LOAD MEDIA
========================= */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "media") {
  header("Content-Type: application/json");

  $id = (int)($_GET["id"] ?? 0);
  if ($id <= 0) {
    echo json_encode(["ok"=>false]);
    exit;
  }

  $st = db()->prepare("SELECT id, file_path FROM materi_media WHERE materi_id=? ORDER BY sort_order ASC");
  $st->execute([$id]);

  $data = [];
  foreach ($st->fetchAll() as $r) {
    $data[] = [
      "id" => (int)$r["id"],
      "url" => $UPLOAD_URL . "/" . $r["file_path"]
    ];
  }

  echo json_encode(["ok"=>true,"media"=>$data]);
  exit;
}

/* =========================
   AJAX: DELETE SINGLE MEDIA
========================= */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "delete_media") {
  header("Content-Type: application/json");

  $mediaId = (int)($_POST["media_id"] ?? 0);
  if ($mediaId <= 0) {
    echo json_encode(["ok"=>false]);
    exit;
  }

  $st = db()->prepare("SELECT materi_id, file_path FROM materi_media WHERE id=?");
  $st->execute([$mediaId]);
  $m = $st->fetch();

  if (!$m) {
    echo json_encode(["ok"=>false]);
    exit;
  }

  $path = $UPLOAD_DIR . "/" . $m["file_path"];
  if (is_file($path)) unlink($path);

  db()->prepare("DELETE FROM materi_media WHERE id=?")->execute([$mediaId]);

  $st = db()->prepare("SELECT COUNT(*) c FROM materi_media WHERE materi_id=?");
  $st->execute([$m["materi_id"]]);
  $count = (int)$st->fetch()["c"];

  db()->prepare("UPDATE materi SET jumlah_slide=? WHERE id=?")
     ->execute([$count, $m["materi_id"]]);

  echo json_encode(["ok"=>true,"count"=>$count]);
  exit;
}

/* =========================
   POST CRUD
========================= */
$toast = ["type"=>"","msg"=>""];

try {
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    /* ===== ADD ===== */
    if ($action === "add") {
      $judul = trim($_POST["judul"] ?? "");
      $mode  = $_POST["mode"] ?? "jpg";

      if ($judul === "") throw new RuntimeException("Judul wajib diisi.");

      db()->beginTransaction();
      db()->prepare("INSERT INTO materi (judul, tipe, jumlah_slide) VALUES (?,?,0)")
         ->execute([$judul, $mode]);
      $materiId = (int)db()->lastInsertId();

      if ($mode === "jpg") {
        $files = $_FILES["jpgs"] ?? null;
        if (!$files) throw new RuntimeException("Gambar belum dipilih.");

        $saved = 0;
        for ($i=0; $i<count($files["name"]); $i++) {
          [$ok,$fn] = upload_one([
            "name"=>$files["name"][$i],
            "tmp_name"=>$files["tmp_name"][$i],
            "size"=>$files["size"][$i]
          ], ["jpg","jpeg","png"], 300*1024, $UPLOAD_DIR);

          if ($ok) {
            db()->prepare("INSERT INTO materi_media (materi_id,file_path,sort_order) VALUES (?,?,?)")
              ->execute([$materiId,$fn,$saved]);
            $saved++;
          }
        }
        if ($saved === 0) throw new RuntimeException("Upload gambar gagal.");
        db()->prepare("UPDATE materi SET jumlah_slide=? WHERE id=?")->execute([$saved,$materiId]);
      }

      if ($mode === "pdf") {
        [$ok,$fn,$err] = upload_one($_FILES["pdf"], ["pdf"], 2*1024*1024, $UPLOAD_DIR);
        if (!$ok) throw new RuntimeException($err);

        db()->prepare("INSERT INTO materi_media (materi_id,file_path,sort_order) VALUES (?,?,0)")
          ->execute([$materiId,$fn]);
        db()->prepare("UPDATE materi SET jumlah_slide=1 WHERE id=?")->execute([$materiId]);
      }

      db()->commit();
      $toast = ["type"=>"success","msg"=>"Materi berhasil ditambahkan."];
    }

    /* ===== DELETE ===== */
    if ($action === "delete") {
      $id = (int)$_POST["id"];
      $st = db()->prepare("SELECT file_path FROM materi_media WHERE materi_id=?");
      $st->execute([$id]);
      foreach ($st->fetchAll() as $m) {
        $p = $UPLOAD_DIR."/".$m["file_path"];
        if (is_file($p)) unlink($p);
      }
      db()->prepare("DELETE FROM materi WHERE id=?")->execute([$id]);
      $toast = ["type"=>"success","msg"=>"Materi berhasil dihapus."];
    }
  }
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  $toast = ["type"=>"danger","msg"=>$e->getMessage()];
}

$rows = db()->query("SELECT * FROM materi ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Materi Admin</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body{background:#e9edff;font-family:Inter,sans-serif}
.btn-add{background:#700D09;color:#fff;font-weight:800;border-radius:999px;padding:12px 36px;border:0}
.table-wrap{background:#fff;border-radius:20px;margin-top:30px;overflow:hidden}
.table-row{display:grid;grid-template-columns:80px 1fr 160px 80px;padding:18px;border-top:1px solid #eee}
.icon-btn{background:none;border:0;color:#700D09;font-size:22px}
.media-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:12px}
.slot{background:#ccc;border-radius:12px;height:80px;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative}
.thumb{position:absolute;inset:0;background-size:cover;background-position:center}
.thumb-close{position:absolute;top:6px;right:6px;background:#000a;color:#fff;border:0;border-radius:50%;width:22px;height:22px}
</style>
</head>

<body class="p-4">

<h1 class="fw-bold">Daftar Materi</h1>
<button class="btn-add" id="btnAdd">+ Tambah Materi</button>

<?php if($toast["type"]): ?>
<div class="alert alert-<?= $toast["type"] ?> mt-3"><?= htmlspecialchars($toast["msg"]) ?></div>
<?php endif; ?>

<div class="table-wrap">
<?php foreach($rows as $r): ?>
<div class="table-row">
  <div>
    <button class="icon-btn btn-edit"
      data-id="<?= $r["id"] ?>"
      data-judul="<?= htmlspecialchars($r["judul"]) ?>"
      data-tipe="<?= $r["tipe"] ?>">
      <i class="bi bi-pencil-fill"></i>
    </button>
  </div>
  <div><?= htmlspecialchars($r["judul"]) ?></div>
  <div class="text-center"><?= (int)$r["jumlah_slide"] ?></div>
  <div>
    <form method="post" onsubmit="return confirm('Hapus?')">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $r["id"] ?>">
      <button class="icon-btn"><i class="bi bi-trash-fill"></i></button>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
