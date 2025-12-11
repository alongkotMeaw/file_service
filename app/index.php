<?php
require "config.php";

session_start();

function normalize_segments($relative) {
    $relative = str_replace("\\", "/", $relative);
    $parts = array_filter(explode("/", $relative), "strlen");
    $stack = [];
    foreach ($parts as $part) {
        if ($part === ".") continue;
        if ($part === "..") { array_pop($stack); continue; }
        $stack[] = $part;
    }
    return $stack;
}

function rel_join($base, $addition) {
    $b = trim($base, "/");
    $a = trim($addition, "/");
    if ($b === "") return $a;
    if ($a === "") return $b;
    return $b . "/" . $a;
}

function build_path($base, $relative) {
    $base = rtrim(realpath($base), DIRECTORY_SEPARATOR);
    $segments = normalize_segments($relative);
    return $base . ($segments ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments) : "");
}

function ensure_dir($path) {
    if (!is_dir($path)) mkdir($path, 0777, true);
}

function format_bytes($bytes) {
    $units = ["bytes", "KB", "MB", "GB", "TB"];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . " " . $units[$i];
}

function folder_size($dir) {
    $size = 0;
    if (!is_dir($dir)) return 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) if ($file->isFile()) $size += $file->getSize();
    return $size;
}

function delete_path($target) {
    if (is_file($target)) return @unlink($target);
    if (is_dir($target)) {
        $items = array_diff(scandir($target), [".", ".."]);
        foreach ($items as $item) delete_path($target . DIRECTORY_SEPARATOR . $item);
        return @rmdir($target);
    }
    return false;
}

// --- Login ---
if (isset($_POST["password"])) {
    if ($_POST["password"] === $APP_PASSWORD) {
        $_SESSION["login"] = true;
    } else {
        $error = "รหัสผ่านไม่ถูกต้อง";
    }
}

if (!isset($_SESSION["login"])) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mini PHP Drive - Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
<div class="login-box">
    <h2>Mini PHP Drive</h2>
    <form method="POST">
        <input type="password" name="password" placeholder="Password">
        <button type="submit">เข้าสู่ระบบ</button>
    </form>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
</div>
</body>
</html>
<?php
    exit;
}

// --------------------- File Manager Logic ---------------------
$BASE = __DIR__ . "/uploads";
ensure_dir($BASE);
$baseReal = realpath($BASE);

$path = $_GET["path"] ?? ($_POST["path"] ?? "");
$realPath = build_path($BASE, $path);
if (!is_dir($realPath)) {
    $path = "";
    $realPath = $baseReal;
}

// Upload (AJAX multi-file + folder support)
if (!empty($_POST["ajaxUpload"]) && !empty($_FILES["files"])) {
    $saved = [];
    $failed = [];
    $count = count($_FILES["files"]["name"]);

    for ($i = 0; $i < $count; $i++) {
        $relativeName = $_POST["paths"][$i] ?? $_FILES["files"]["name"][$i];
        $relativeName = ltrim(trim($relativeName), "/\\");
        if ($relativeName === "") { $failed[] = "(empty name)"; continue; }

        $targetRel = rel_join($path, $relativeName);
        $targetPath = build_path($BASE, $targetRel);
        if (strpos(realpath(dirname($targetPath)) ?: dirname($targetPath), $baseReal) !== 0) {
            $failed[] = $relativeName;
            continue;
        }
        ensure_dir(dirname($targetPath));

        if (move_uploaded_file($_FILES["files"]["tmp_name"][$i], $targetPath)) {
            $saved[] = $relativeName;
        } else {
            $failed[] = $relativeName;
        }
    }

    header("Content-Type: application/json");
    echo json_encode([
        "success" => count($failed) === 0,
        "saved" => $saved,
        "failed" => $failed
    ]);
    exit;
}

// Upload (basic single-file fallback)
if (!empty($_FILES["file"])) {
    $targetRel = rel_join($path, $_FILES["file"]["name"]);
    $targetPath = build_path($BASE, $targetRel);
    ensure_dir(dirname($targetPath));
    move_uploaded_file($_FILES["file"]["tmp_name"], $targetPath);
    header("Location: ?path=" . urlencode($path));
    exit;
}

// Create folder
if (isset($_POST["folder"])) {
    $folder = trim($_POST["folder"]);
    if ($folder !== "") {
        $targetRel = rel_join($path, $folder);
        $newDir = build_path($BASE, $targetRel);
        ensure_dir($newDir);
    }
    header("Location: ?path=" . urlencode($path));
    exit;
}

// Delete
if (isset($_GET["delete"])) {
    $targetRel = rel_join($path, $_GET["delete"]);
    $target = build_path($BASE, $targetRel);
    if (strpos($target, $baseReal) === 0) {
        delete_path($target);
    }
    header("Location: ?path=" . urlencode($path));
    exit;
}

// Download
if (isset($_GET["download"])) {
    $targetRel = rel_join($path, $_GET["download"]);
    $file = build_path($BASE, $targetRel);
    $fileReal = realpath($file);
    if ($fileReal && strpos($fileReal, $baseReal) === 0 && is_file($fileReal)) {
        header("Content-Disposition: attachment; filename=" . basename($fileReal));
        header("Content-Length: " . filesize($fileReal));
        readfile($fileReal);
    }
    exit;
}

// List files
$items = [];
foreach (scandir($realPath) as $item) {
    if ($item === "." || $item === "..") continue;
    $itemPath = $realPath . DIRECTORY_SEPARATOR . $item;
    $rel = ($path ? "$path/" : "") . $item;
    $items[] = [
        "name" => $item,
        "rel" => $rel,
        "isDir" => is_dir($itemPath),
        "size" => is_dir($itemPath) ? 0 : filesize($itemPath),
        "mtime" => filemtime($itemPath)
    ];
}
usort($items, function ($a, $b) {
    if ($a["isDir"] && !$b["isDir"]) return -1;
    if (!$a["isDir"] && $b["isDir"]) return 1;
    return strcasecmp($a["name"], $b["name"]);
});

$parentPath = $path ? dirname($path) : "";
if ($parentPath === ".") $parentPath = "";

$crumbs = [];
$accum = [];
foreach (normalize_segments($path) as $seg) {
    $accum[] = $seg;
    $crumbs[] = [
        "name" => $seg,
        "path" => implode("/", $accum)
    ];
}

$usage = folder_size($BASE);
$quota = 100 * 1024 * 1024 * 1024; // pretend 100 GB for display
$usagePercent = $quota > 0 ? min(100, round(($usage / $quota) * 100, 1)) : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mini PHP Drive</title>
    <link rel="stylesheet" href="style.css">
</head>
<body data-path="<?= htmlspecialchars($path) ?>" class="app">

<div class="shell">
    <aside class="sidebar">
        <div class="logo">ไดรฟ์จิ๋ว</div>
        <label class="primary-btn" for="fileInput">+ อัปโหลด</label>
        <nav class="nav">
            <a class="nav-item active">หน้าแรก</a>
            <a class="nav-item">ไดรฟ์ของฉัน</a>
            <a class="nav-item">คอมพิวเตอร์</a>
            <a class="nav-item">แชร์กับฉัน</a>
            <a class="nav-item">ล่าสุด</a>
            <a class="nav-item">ที่ติดดาว</a>
            <a class="nav-item">ถังขยะ</a>
        </nav>
        <div class="storage">
            <div class="storage-label">พื้นที่ใช้งาน</div>
            <div class="storage-bar">
                <span style="width: <?= $usagePercent ?>%"></span>
            </div>
            <div class="storage-meta"><?= format_bytes($usage) ?> จาก 100 GB</div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="search">
                <input type="search" id="searchInput" placeholder="ค้นหาไฟล์ในไดรฟ์">
            </div>
            <div class="crumbs">
                <a href="?">ไดรฟ์</a>
                <?php foreach ($crumbs as $c): ?>
                    <span>/</span><a href="?path=<?= urlencode($c["path"]) ?>"><?= htmlspecialchars($c["name"]) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="top-actions">
                <form id="uploadForm" method="POST" enctype="multipart/form-data" action="?path=<?= urlencode($path) ?>" data-path="<?= htmlspecialchars($path) ?>">
                    <input type="hidden" name="ajaxUpload" value="1">
                    <input type="hidden" name="path" value="<?= htmlspecialchars($path) ?>">
                    <input type="file" id="fileInput" name="files[]" multiple webkitdirectory mozdirectory>
                    <button type="submit" class="ghost-btn">Upload</button>
                </form>
                <form method="POST" class="newfolder" action="?path=<?= urlencode($path) ?>">
                    <input type="hidden" name="path" value="<?= htmlspecialchars($path) ?>">
                    <input type="text" name="folder" placeholder="New folder">
                    <button>สร้างโฟลเดอร์</button>
                </form>
            </div>
        </header>

        <section class="hero-drop">
            <div id="dropzone" class="dropzone">
                <div class="drop-title">ลากไฟล์หรือโฟลเดอร์มาวางที่นี่</div>
                <div class="drop-sub">รองรับหลายไฟล์และโครงสร้างโฟลเดอร์แบบ Google Drive</div>
            </div>
            <div id="uploadStatus" class="status"></div>
        </section>

        <section class="grid-header">
            <div class="title">ไฟล์ทั้งหมด</div>
            <?php if ($path): ?>
                <a class="ghost-btn" href="?path=<?= urlencode($parentPath) ?>">ย้อนกลับ</a>
            <?php endif; ?>
        </section>

        <section class="grid" id="fileGrid">
            <?php if (empty($items)): ?>
                <div class="empty">ยังไม่มีไฟล์ในโฟลเดอร์นี้</div>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php
                    $icon = $item["isDir"] ? "folder" : "file";
                    $typeLabel = $item["isDir"] ? "โฟลเดอร์" : "ไฟล์";
                    $sizeLabel = $item["isDir"] ? "-" : format_bytes($item["size"]);
                    $dateLabel = date("d M Y H:i", $item["mtime"]);
                ?>
                <div class="file-card"
                     data-name="<?= htmlspecialchars($item["name"]) ?>"
                     data-type="<?= $typeLabel ?>"
                     data-size="<?= $sizeLabel ?>"
                     data-date="<?= $dateLabel ?>"
                     data-kind="<?= $item["isDir"] ? "folder" : "file" ?>"
                     data-path="<?= htmlspecialchars($item["rel"]) ?>"
                     data-download="<?= htmlspecialchars($item["name"]) ?>"
                >
                    <div class="file-icon <?= $icon ?>"><?= $item["isDir"] ? "📁" : "📄" ?></div>
                    <div class="file-name" title="<?= htmlspecialchars($item["name"]) ?>"><?= htmlspecialchars($item["name"]) ?></div>
                    <div class="file-meta"><?= $typeLabel ?> • <?= $sizeLabel ?> • <?= $dateLabel ?></div>
                    <div class="file-actions">
                        <?php if ($item["isDir"]): ?>
                            <a href="?path=<?= urlencode($item["rel"]) ?>" class="pill">เปิด</a>
                        <?php else: ?>
                            <a href="?path=<?= urlencode($path) ?>&download=<?= urlencode($item["name"]) ?>" class="pill">ดาวน์โหลด</a>
                        <?php endif; ?>
                        <a href="?path=<?= urlencode($path) ?>&delete=<?= urlencode($item["name"]) ?>" class="pill danger" onclick="return confirm('ลบ <?= htmlspecialchars($item["name"]) ?> ?')">ลบ</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </main>

    <aside class="details" id="detailPane">
        <div class="detail-title">รายละเอียด</div>
        <div class="detail-empty">เลือกรายการเพื่อดูรายละเอียด</div>
        <div class="detail-body hidden">
            <div class="detail-name" id="detailName"></div>
            <div class="detail-line"><span>ประเภท</span><strong id="detailType"></strong></div>
            <div class="detail-line"><span>ขนาด</span><strong id="detailSize"></strong></div>
            <div class="detail-line"><span>แก้ไข</span><strong id="detailDate"></strong></div>
            <div class="detail-actions" id="detailActions"></div>
        </div>
    </aside>
</div>

<script src="script.js"></script>
</body>
</html>
