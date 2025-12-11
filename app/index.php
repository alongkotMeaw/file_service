<?php
require "config.php";

session_start();

// --- Login ---
if (isset($_POST['password'])) {
    if ($_POST['password'] === $APP_PASSWORD) {
        $_SESSION['login'] = true;
    } else {
        $error = "รหัสผ่านผิด!";
    }
}

// --- Check login ---
if (!isset($_SESSION['login'])) {
?>
<!DOCTYPE html>
<html>
<head>
<title>Mini PHP Drive - Login</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-box">
    <h2>Mini PHP Drive</h2>
    <form method="POST">
        <input type="password" name="password" placeholder="Password">
        <button type="submit">เข้าสู่ระบบ</button>
    </form>
    <?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>
</div>
</body>
</html>
<?php
exit;
}

// --------------------- File Manager Logic ---------------------

$BASE = __DIR__ . "/uploads";
if (!is_dir($BASE)) mkdir($BASE);

$path = $_GET['path'] ?? "";
$realPath = realpath($BASE . "/" . $path);
if ($realPath === false || strpos($realPath, realpath($BASE)) !== 0) {
    $realPath = $BASE;
    $path = "";
}

// Upload
if (!empty($_FILES['file'])) {
    move_uploaded_file($_FILES['file']['tmp_name'], $realPath . "/" . $_FILES['file']['name']);
    header("Location: ?path=$path");
    exit;
}

// Create folder
if (isset($_POST['folder'])) {
    mkdir($realPath . "/" . $_POST['folder']);
    header("Location: ?path=$path");
    exit;
}

// Delete
if (isset($_GET['delete'])) {
    $target = realpath($realPath . "/" . $_GET['delete']);
    if ($target && strpos($target, realpath($BASE)) === 0) {
        if (is_dir($target)) rmdir($target);
        else unlink($target);
    }
    header("Location: ?path=$path");
    exit;
}

// Download
if (isset($_GET['download'])) {
    $file = realpath($realPath . "/" . $_GET['download']);
    if ($file && strpos($file, realpath($BASE)) === 0 && is_file($file)) {
        header("Content-Disposition: attachment; filename=".basename($file));
        header("Content-Length: " . filesize($file));
        readfile($file);
        exit;
    }
}

// List files
$items = scandir($realPath);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mini PHP Drive</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<h2>📁 Mini PHP Drive</h2>

<div class="toolbar">
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="file">
        <button>อัปโหลด</button>
    </form>

    <form method="POST" class="newfolder">
        <input type="text" name="folder" placeholder="New folder">
        <button>+</button>
    </form>
</div>

<p>Path: /<?= htmlspecialchars($path) ?></p>

<table>
    <tr><th>ชื่อ</th><th>ประเภท</th><th>ขนาด</th><th>จัดการ</th></tr>

    <?php if ($path): ?>
    <tr>
        <td colspan="4">
            <a href="?path=<?= urlencode(dirname($path)) ?>">⬆ กลับ</a>
        </td>
    </tr>
    <?php endif; ?>

    <?php
    foreach ($items as $item) {
        if ($item === "." || $item === "..") continue;

        $itemPath = "$realPath/$item";
        $rel = ($path ? "$path/" : "") . $item;

        echo "<tr>";
        if (is_dir($itemPath)) {
            echo "<td><a href='?path=" . urlencode($rel) . "'>📁 $item</a></td>";
            echo "<td>โฟลเดอร์</td>";
            echo "<td>-</td>";
        } else {
            echo "<td>📄 $item</td>";
            echo "<td>ไฟล์</td>";
            echo "<td>" . filesize($itemPath) . " bytes</td>";
        }

        echo "<td>
                <a href='?path=$path&download=" . urlencode($item) . "'>ดาวน์โหลด</a> | 
                <a href='?path=$path&delete=" . urlencode($item) . "' onclick='return confirm(\"ลบ $item?\")'>
                    ลบ
                </a>
              </td>";
        echo "</tr>";
    }
    ?>
</table>

</body>
</html>
