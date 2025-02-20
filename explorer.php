<?php
session_start();

// Debug log setup
$debug_log = '/var/www/html/selfhostedgdrive/debug.log';
if (!file_exists($debug_log)) {
    file_put_contents($debug_log, "Debug log initialized\n");
    chown($debug_log, 'www-data');
    chmod($debug_log, 0666);
}
file_put_contents($debug_log, "=== New Request ===\n", FILE_APPEND);
file_put_contents($debug_log, "Session ID: " . session_id() . "\n", FILE_APPEND);
file_put_contents($debug_log, "Loggedin: " . (isset($_SESSION['loggedin']) ? var_export($_SESSION['loggedin'], true) : "Not set") . "\n", FILE_APPEND);
file_put_contents($debug_log, "Username: " . (isset($_SESSION['username']) ? $_SESSION['username'] : "Not set") . "\n", FILE_APPEND);
file_put_contents($debug_log, "GET params: " . var_export($_GET, true) . "\n", FILE_APPEND);

// Handle file serving first
if (isset($_GET['action']) && $_GET['action'] === 'serve' && isset($_GET['file'])) {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
        file_put_contents($debug_log, "Unauthorized file request, redirecting to index.php\n", FILE_APPEND);
        header("Location: index.php");
        exit;
    }

    // Use user-specific base directory
    $username = $_SESSION['username'];
    $baseDir = realpath("/var/www/html/webdav/users/$username/Home");
    $filePath = realpath($baseDir . '/' . urldecode($_GET['file']));
    file_put_contents($debug_log, "File request: " . $_GET['file'] . "\n", FILE_APPEND);
    file_put_contents($debug_log, "Resolved file path: " . ($filePath ? $filePath : "Not found") . "\n", FILE_APPEND);

    if ($filePath && strpos($filePath, $baseDir) === 0 && file_exists($filePath)) {
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        file_put_contents($debug_log, "MIME type: $mime\n", FILE_APPEND);
        header("Content-Type: $mime");
        header("Content-Length: " . filesize($filePath));
        if (!preg_match('/\.(png|jpe?g|gif|heic|mp4|webm|mov|avi|mkv)$/i', $filePath)) {
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        }
        ob_clean();
        flush();
        readfile($filePath);
        file_put_contents($debug_log, "Served file: $filePath\n", FILE_APPEND);
        exit;
    } else {
        file_put_contents($debug_log, "File not found or access denied: $filePath\n", FILE_APPEND);
        header("HTTP/1.1 404 Not Found");
        echo "File not found.";
        exit;
    }
}

// Check if user is logged in for page access
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
    file_put_contents($debug_log, "Redirecting to index.php due to no login\n", FILE_APPEND);
    header("Location: index.php");
    exit;
}

/************************************************
 * 1. Define the "Home" directory as base
 ************************************************/
$username = $_SESSION['username'];
$homeDirPath = "/var/www/html/webdav/users/$username/Home";
if (!is_dir($homeDirPath)) {
    mkdir($homeDirPath, 0777, true);
    chown($homeDirPath, 'www-data');
    chgrp($homeDirPath, 'www-data');
}
$baseDir = realpath($homeDirPath);
file_put_contents($debug_log, "BaseDir: $baseDir (User: $username)\n", FILE_APPEND);

// Redirect to Home folder by default if no folder specified
if (!isset($_GET['folder'])) {
    file_put_contents($debug_log, "No folder specified, redirecting to Home\n", FILE_APPEND);
    header("Location: explorer.php?folder=Home");
    exit;
}

/************************************************
 * 2. Determine current folder (GET param)
 ************************************************/
$currentRel = isset($_GET['folder']) ? $_GET['folder'] : 'Home';
$currentRel = trim(str_replace('..', '', $currentRel), '/');
$currentDir = realpath($baseDir . '/' . $currentRel);
file_put_contents($debug_log, "CurrentRel: $currentRel\n", FILE_APPEND);
file_put_contents($debug_log, "CurrentDir: " . ($currentDir ? $currentDir : "Not resolved") . "\n", FILE_APPEND);

if ($currentDir === false || strpos($currentDir, $baseDir) !== 0) {
    file_put_contents($debug_log, "Invalid folder, resetting to Home\n", FILE_APPEND);
    $currentDir = $baseDir;
    $currentRel = 'Home';
}

/************************************************
 * 3. Create Folder
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder'])) {
    $folderName = trim($_POST['folder_name'] ?? '');
    if ($folderName !== '') {
        $targetPath = $currentDir . '/' . $folderName;
        if (!file_exists($targetPath)) {
            mkdir($targetPath, 0777);
            chown($targetPath, 'www-data');
            chgrp($targetPath, 'www-data');
            file_put_contents($debug_log, "Created folder: $targetPath\n", FILE_APPEND);
        }
    }
    header("Location: explorer.php?folder=" . urlencode($currentRel));
    exit;
}

/************************************************
 * 4. Upload Files
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_files'])) {
    foreach ($_FILES['upload_files']['name'] as $i => $fname) {
        if ($_FILES['upload_files']['error'][$i] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['upload_files']['tmp_name'][$i];
            $dest = $currentDir . '/' . basename($fname);
            move_uploaded_file($tmpPath, $dest);
            chown($dest, 'www-data');
            chgrp($dest, 'www-data');
            chmod($dest, 0664);
            file_put_contents($debug_log, "Uploaded file: $dest\n", FILE_APPEND);
        } else {
            file_put_contents($debug_log, "Upload error for $fname: " . $_FILES['upload_files']['error'][$i] . "\n", FILE_APPEND);
        }
    }
    header("Location: explorer.php?folder=" . urlencode($currentRel));
    exit;
}

/************************************************
 * 5. Delete an item (folder or file)
 ************************************************/
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemToDelete = $_GET['delete'];
    $targetPath = realpath($currentDir . '/' . $itemToDelete);

    if ($targetPath && strpos($targetPath, $currentDir) === 0) {
        if (is_dir($targetPath)) {
            deleteRecursive($targetPath);
            file_put_contents($debug_log, "Deleted folder: $targetPath\n", FILE_APPEND);
        } else {
            unlink($targetPath);
            file_put_contents($debug_log, "Deleted file: $targetPath\n", FILE_APPEND);
        }
    }
    header("Location: explorer.php?folder=" . urlencode($currentRel));
    exit;
}

/************************************************
 * 6. Recursively delete a folder
 ************************************************/
function deleteRecursive($dirPath) {
    $items = scandir($dirPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $dirPath . '/' . $item;
        if (is_dir($full)) {
            deleteRecursive($full);
        } else {
            unlink($full);
        }
    }
    rmdir($dirPath);
}

/************************************************
 * 7. Rename a folder
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_folder'])) {
    $oldFolderName = $_POST['old_folder_name'] ?? '';
    $newFolderName = $_POST['new_folder_name'] ?? '';
    $oldPath = realpath($currentDir . '/' . $oldFolderName);

    if ($oldPath && is_dir($oldPath)) {
        $newPath = $currentDir . '/' . $newFolderName;
        if (!file_exists($newPath)) {
            rename($oldPath, $newPath);
            file_put_contents($debug_log, "Renamed folder: $oldPath to $newPath\n", FILE_APPEND);
        }
    }
    header("Location: explorer.php?folder=" . urlencode($currentRel));
    exit;
}

/************************************************
 * 8. Rename a file (prevent extension change)
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_file'])) {
    $oldFileName = $_POST['old_file_name'] ?? '';
    $newFileName = $_POST['new_file_name'] ?? '';
    $oldFilePath = realpath($currentDir . '/' . $oldFileName);

    if ($oldFilePath && is_file($oldFilePath)) {
        $oldExt = strtolower(pathinfo($oldFileName, PATHINFO_EXTENSION));
        $newExt = strtolower(pathinfo($newFileName, PATHINFO_EXTENSION));
        if ($oldExt !== $newExt) {
            $_SESSION['error'] = "Modification of file extension is not allowed.";
            header("Location: explorer.php?folder=" . urlencode($currentRel));
            exit;
        }
        $newFilePath = $currentDir . '/' . $newFileName;
        if (!file_exists($newFilePath)) {
            rename($oldFilePath, $newFilePath);
            file_put_contents($debug_log, "Renamed file: $oldFilePath to $newFilePath\n", FILE_APPEND);
        }
    }
    header("Location: explorer.php?folder=" . urlencode($currentRel));
    exit;
}

/************************************************
 * 9. Gather folders & files
 ************************************************/
$folders = [];
$files = [];
if (is_dir($currentDir)) {
    $all = scandir($currentDir);
    foreach ($all as $one) {
        if ($one === '.' || $one === '..') continue;
        $path = $currentDir . '/' . $one;
        if (is_dir($path)) {
            $folders[] = $one;
        } else {
            $files[] = $one;
        }
    }
}
sort($folders);
sort($files);
file_put_contents($debug_log, "Folders: " . implode(", ", $folders) . "\n", FILE_APPEND);
file_put_contents($debug_log, "Files: " . implode(", ", $files) . "\n", FILE_APPEND);

/************************************************
 * 10. "Back" link if not at Home
 ************************************************/
$parentLink = '';
if ($currentDir !== $baseDir) {
    $parts = explode('/', $currentRel);
    array_pop($parts);
    $parentRel = implode('/', $parts);
    $parentLink = 'explorer.php?folder=' . urlencode($parentRel);
}

/************************************************
 * 11. Helper: Decide which FA icon to show
 ************************************************/
function getIconClass($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'heic'])) {
        return 'fas fa-file-image';
    }
    if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'])) {
        return 'fas fa-file-video';
    }
    if ($ext === 'pdf') {
        return 'fas fa-file-pdf';
    }
    if ($ext === 'exe') {
        return 'fas fa-file-exclamation';
    }
    return 'fas fa-file';
}

/************************************************
 * 12. Helper: Check if file is "previewable" (image/video)
 ************************************************/
function isImage($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'heic']);
}
function isVideo($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Explorer with Previews</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
  <style>
  :root {
    --background: #121212;
    --text-color: #fff;
    --sidebar-bg: linear-gradient(135deg, #1e1e1e, #2a2a2a);
    --content-bg: #1e1e1e;
    --border-color: #333;
    --button-bg: linear-gradient(135deg, #555, #777);
    --button-hover: linear-gradient(135deg, #777, #555);
    --accent-red: #d32f2f; /* Darker red for dark mode */
    --dropzone-bg: rgba(211, 47, 47, 0.1); /* Light red tint for drop zone */
    --dropzone-border: #d32f2f;
  }
  body.light-mode {
    --background: #f5f5f5;
    --text-color: #333;
    --sidebar-bg: linear-gradient(135deg, #e0e0e0, #fafafa);
    --content-bg: #fff;
    --border-color: #ccc;
    --button-bg: linear-gradient(135deg, #888, #aaa);
    --button-hover: linear-gradient(135deg, #aaa, #888);
    --accent-red: #f44336; /* Normal red for light mode */
    --dropzone-bg: rgba(244, 67, 54, 0.1);
    --dropzone-border: #f44336;
  }
  html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
    background: var(--background);
    color: var(--text-color);
    font-family: 'Poppins', sans-serif;
    overflow: hidden;
    transition: background 0.3s, color 0.3s;
  }
  .app-container {
    display: flex;
    width: 100%;
    height: 100%;
    position: relative;
  }
  .sidebar {
    width: 270px;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    z-index: 9998;
    position: sticky;
    top: 0;
    height: 100vh;
    transform: translateX(-100%);
    transition: transform 0.3s ease;
  }
  @media (min-width: 1024px) {
    .sidebar {
      transform: none;
    }
  }
  .sidebar.open {
    transform: translateX(0);
  }
  @media (max-width: 1023px) {
    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      height: 100%;
    }
  }
  .sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9997;
  }
  .sidebar-overlay.show {
    display: block;
  }
  @media (min-width: 1024px) {
    .sidebar-overlay {
      display: none !important;
    }
  }
  .folders-container {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
  }
  .top-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    justify-content: flex-start;
  }
  .top-row h2 {
    font-size: 18px;
    font-weight: 500;
    margin: 0;
    color: var(--text-color);
  }
  .btn {
    background: var(--button-bg);
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.3s, transform 0.2s;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    text-decoration: none;
  }
  .btn:hover {
    background: var(--button-hover);
    transform: scale(1.05);
  }
  .btn:active {
    transform: scale(0.95);
  }
  .btn i {
    color: var(--text-color);
    margin: 0;
  }
  .btn-back {
    background: var(--button-bg);
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.3s, transform 0.2s;
    text-decoration: none;
  }
  .btn-back i {
    color: var(--text-color);
    margin: 0;
  }
  .btn-back:hover {
    background: var(--button-hover);
    transform: scale(1.05);
  }
  .btn-back:active {
    transform: scale(0.95);
  }
  .logout-btn {
    background: linear-gradient(135deg, var(--accent-red), #b71c1c) !important;
  }
  .logout-btn:hover {
    background: linear-gradient(135deg, #b71c1c, var(--accent-red)) !important;
  }
  .folder-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .folder-item {
    padding: 8px 10px;
    margin-bottom: 5px;
    border-radius: 4px;
    background: var(--content-bg);
    cursor: pointer;
    transition: background 0.3s;
  }
  .folder-item:hover {
    background: var(--border-color);
  }
  .folder-item.selected {
    background: var(--accent-red);
    color: #fff;
    transform: translateX(5px);
  }
  .folder-item i {
    margin-right: 6px;
  }
  .main-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative; /* For drop zone positioning */
  }
  .header-area {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid var(--border-color);
    background: var(--background);
    z-index: 10; /* Above drop zone */
  }
  .header-title {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .header-area h1 {
    font-size: 18px;
    font-weight: 500;
    margin: 0;
    color: var(--text-color);
  }
  .hamburger {
    background: none;
    border: none;
    color: var(--text-color);
    font-size: 24px;
    cursor: pointer;
  }
  @media (min-width: 1024px) {
    .hamburger {
      display: none;
    }
  }
  .content-inner {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    position: relative; /* For drop zone */
  }
  .file-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .file-row {
    display: flex;
    align-items: center;
    padding: 8px;
    background: var(--content-bg);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    transition: box-shadow 0.3s ease, transform 0.2s;
    position: relative;
  }
  .file-row:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
    transform: translateX(5px);
  }
  .file-icon {
    font-size: 20px;
    margin-right: 10px;
    flex-shrink: 0;
  }
  .file-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-right: 20px;
    cursor: pointer;
  }
  .file-name:hover {
    border-bottom: 1px solid var(--accent-red);
  }
  .file-actions {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .file-actions button {
    background: var(--button-bg);
    border-radius: 4px;
    color: var(--text-color);
    border: none;
    font-size: 14px;
    transition: background 0.3s, transform 0.2s;
    cursor: pointer;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .file-actions button:hover {
    background: var(--button-hover);
    transform: scale(1.05);
  }
  .file-actions button:active {
    transform: scale(0.95);
  }
  .file-actions button i {
    color: var(--text-color);
    margin: 0;
  }
  #fileInput {
    display: none;
  }
  #uploadProgressContainer {
    display: none;
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 300px;
    background: var(--content-bg);
    border: 1px solid var(--border-color);
    padding: 10px;
    border-radius: 4px;
    z-index: 9999;
  }
  #uploadProgressBar {
    height: 20px;
    width: 0%;
    background: var(--accent-red);
    border-radius: 4px;
    transition: width 0.1s ease;
  }
  #uploadProgressPercent {
    text-align: center;
    margin-top: 5px;
    font-weight: 500;
  }
  .cancel-upload-btn {
    margin-top: 5px;
    padding: 6px 10px;
    background: var(--accent-red);
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.3s, transform 0.2s;
  }
  .cancel-upload-btn:hover {
    background: #b71c1c;
    transform: scale(1.05);
  }
  #previewModal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    justify-content: center;
    align-items: center;
    z-index: 9998;
  }
  #previewContent {
    position: relative;
    width: 100%;
    height: 100%;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  #previewClose {
    position: absolute;
    top: 20px;
    right: 20px;
    cursor: pointer;
    font-size: 30px;
    color: #fff;
    z-index: 9999;
  }
  #previewContainer {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }
  #previewContainer img,
  #previewContainer video {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    display: block;
  }
  #dialogModal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    justify-content: center;
    align-items: center;
    z-index: 10000;
  }
  #dialogModal.show {
    display: flex;
  }
  .dialog-content {
    background: var(--content-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 20px;
    max-width: 90%;
    width: 400px;
    text-align: center;
  }
  .dialog-message {
    margin-bottom: 20px;
    font-size: 16px;
  }
  .dialog-buttons {
    display: flex;
    justify-content: center;
    gap: 10px;
  }
  .dialog-button {
    background: var(--button-bg);
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 6px 10px;
    cursor: pointer;
    transition: background 0.3s, transform 0.2s;
  }
  .dialog-button:hover {
    background: var(--button-hover);
    transform: scale(1.05);
  }
  .dialog-button:active {
    transform: scale(0.95);
  }
  .theme-toggle-btn i {
    color: var(--text-color);
  }
  /* Drag and Drop Styles */
  #dropZone {
    display: none;
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: var(--dropzone-bg);
    border: 3px dashed var(--dropzone-border);
    z-index: 5;
    justify-content: center;
    align-items: center;
    font-size: 18px;
    font-weight: 500;
    color: var(--accent-red);
    text-align: center;
    padding: 20px;
    box-sizing: border-box;
  }
  #dropZone.active {
    display: flex;
  }
</style>
</head>
<body>
  <div class="app-container">
    <div class="sidebar" id="sidebar">
      <div class="folders-container">
        <div class="top-row">
          <h2>Folders</h2>
          <?php if ($parentLink): ?>
            <a class="btn-back" href="<?php echo $parentLink; ?>" title="Back">
              <i class="fas fa-arrow-left"></i>
            </a>
          <?php endif; ?>
          <button type="button" class="btn" title="Create New Folder" onclick="createFolder()">
            <i class="fas fa-folder-plus"></i>
          </button>
          <button type="button" class="btn" id="btnDeleteFolder" title="Delete selected folder" style="display:none;">
            <i class="fas fa-trash"></i>
          </button>
          <button type="button" class="btn" id="btnRenameFolder" title="Rename selected folder" style="display:none;">
            <i class="fas fa-edit"></i>
          </button>
          <a href="logout.php" class="btn logout-btn" title="Logout">
            <i class="fa fa-sign-out" aria-hidden="true"></i>
          </a>
        </div>
        <ul class="folder-list">
          <?php foreach ($folders as $folderName): ?>
            <?php 
            $folderPath = ($currentRel === 'Home' ? '' : $currentRel . '/') . $folderName; 
            file_put_contents($debug_log, "Folder path for $folderName: $folderPath\n", FILE_APPEND);
            ?>
            <li class="folder-item"
                ondblclick="openFolder('<?php echo urlencode($folderPath); ?>')"
                onclick="selectFolder(this, '<?php echo addslashes($folderName); ?>'); event.stopPropagation();">
              <i class="fas fa-folder"></i> <?php echo htmlspecialchars($folderName); ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
      <div class="header-area">
        <div class="header-title">
          <button class="hamburger" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
          </button>
          <h1><?php echo ($currentRel === 'Home') ? 'Home' : htmlspecialchars($currentRel); ?></h1>
        </div>
        <div style="display: flex; gap: 10px;">
          <form id="uploadForm" method="POST" enctype="multipart/form-data" action="explorer.php?folder=<?php echo urlencode($currentRel); ?>">
            <input type="file" name="upload_files[]" multiple id="fileInput" style="display:none;" />
            <button type="button" class="btn" id="uploadBtn" title="Upload" style="width:36px; height:36px;">
              <i class="fas fa-cloud-upload-alt"></i>
            </button>
          </form>
          <button type="button" class="btn theme-toggle-btn" id="themeToggleBtn" title="Toggle Theme" style="width:36px; height:36px;">
            <i class="fas fa-moon"></i>
          </button>
          <div id="uploadProgressContainer">
            <div style="background:var(--border-color); width:100%; height:20px; border-radius:4px; overflow:hidden;">
              <div id="uploadProgressBar"></div>
            </div>
            <div id="uploadProgressPercent">0%</div>
            <button class="cancel-upload-btn" id="cancelUploadBtn">Cancel</button>
          </div>
        </div>
      </div>
      <div class="content-inner">
        <div id="dropZone">Drop files here to upload</div>
        <div class="file-list">
          <?php foreach ($files as $fileName): ?>
            <?php
              $relativePath = $currentRel . '/' . $fileName;
              $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
              $iconClass = getIconClass($fileName);
              $canPreview = (isImage($fileName) || isVideo($fileName));
              file_put_contents($debug_log, "File URL for $fileName: $fileURL\n", FILE_APPEND);
            ?>
            <div class="file-row">
              <i class="<?php echo $iconClass; ?> file-icon"></i>
              <div class="file-name"
                   title="<?php echo htmlspecialchars($fileName); ?>"
                   onclick="<?php echo $canPreview ? "openPreviewModal('$fileURL','".addslashes($fileName)."')" : "downloadFile('$fileURL')"; ?>">
                <?php echo htmlspecialchars($fileName); ?>
              </div>
              <div class="file-actions">
                <button type="button" class="btn" onclick="downloadFile('<?php echo $fileURL; ?>')" title="Download">
                  <i class="fas fa-download"></i>
                </button>
                <button type="button" class="btn" title="Rename File" onclick="renameFilePrompt('<?php echo addslashes($fileName); ?>')">
                  <i class="fas fa-edit"></i>
                </button>
                <button type="button" class="btn" title="Delete File" onclick="confirmFileDelete('<?php echo addslashes($fileName); ?>')">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div id="previewModal">
    <div id="previewContent">
      <span id="previewClose" onclick="closePreviewModal()"><i class="fas fa-times"></i></span>
      <div id="previewContainer"></div>
    </div>
  </div>

  <div id="dialogModal">
    <div class="dialog-content">
      <div class="dialog-message" id="dialogMessage"></div>
      <div class="dialog-buttons" id="dialogButtons"></div>
    </div>
  </div>

  <script>
  let selectedFolder = null;
  let currentXhr = null;

  function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sb.classList.toggle('open');
    overlay.classList.toggle('show');
  }
  document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

  function selectFolder(element, folderName) {
    document.querySelectorAll('.folder-item.selected').forEach(item => item.classList.remove('selected'));
    element.classList.add('selected');
    selectedFolder = folderName;
    document.getElementById('btnDeleteFolder').style.display = 'flex';
    document.getElementById('btnRenameFolder').style.display = 'flex';
  }
  function openFolder(folderPath) {
    console.log("Opening folder: " + folderPath);
    window.location.href = 'explorer.php?folder=' + folderPath;
  }

  function showPrompt(message, defaultValue, callback) {
    const dialogModal = document.getElementById('dialogModal');
    const dialogMessage = document.getElementById('dialogMessage');
    const dialogButtons = document.getElementById('dialogButtons');

    dialogMessage.innerHTML = '';
    dialogButtons.innerHTML = '';

    const msgEl = document.createElement('div');
    msgEl.textContent = message;
    msgEl.style.marginBottom = '10px';
    dialogMessage.appendChild(msgEl);

    const inputField = document.createElement('input');
    inputField.type = 'text';
    inputField.value = defaultValue || '';
    inputField.style.width = '100%';
    inputField.style.padding = '8px';
    inputField.style.border = '1px solid #555';
    inputField.style.borderRadius = '4px';
    inputField.style.background = '#2a2a2a';
    inputField.style.color = '#fff';
    inputField.style.marginBottom = '15px';
    dialogMessage.appendChild(inputField);

    const okBtn = document.createElement('button');
    okBtn.className = 'dialog-button';
    okBtn.textContent = 'OK';
    okBtn.onclick = () => {
      closeDialog();
      if (callback) callback(inputField.value);
    };
    dialogButtons.appendChild(okBtn);

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'dialog-button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = () => {
      closeDialog();
      if (callback) callback(null);
    };
    dialogButtons.appendChild(cancelBtn);

    dialogModal.classList.add('show');
  }
  function closeDialog() {
    document.getElementById('dialogModal').classList.remove('show');
  }
  function showAlert(message, callback) {
    const dialogModal = document.getElementById('dialogModal');
    const dialogMessage = document.getElementById('dialogMessage');
    const dialogButtons = document.getElementById('dialogButtons');

    dialogMessage.textContent = message;
    dialogButtons.innerHTML = '';

    const okBtn = document.createElement('button');
    okBtn.className = 'dialog-button';
    okBtn.textContent = 'OK';
    okBtn.onclick = () => {
      closeDialog();
      if (callback) callback();
    };
    dialogButtons.appendChild(okBtn);

    dialogModal.classList.add('show');
  }
  function showConfirm(message, onYes, onNo) {
    const dialogModal = document.getElementById('dialogModal');
    const dialogMessage = document.getElementById('dialogMessage');
    const dialogButtons = document.getElementById('dialogButtons');

    dialogMessage.textContent = message;
    dialogButtons.innerHTML = '';

    const yesBtn = document.createElement('button');
    yesBtn.className = 'dialog-button';
    yesBtn.textContent = 'Yes';
    yesBtn.onclick = () => {
      closeDialog();
      if (onYes) onYes();
    };
    dialogButtons.appendChild(yesBtn);

    const noBtn = document.createElement('button');
    noBtn.className = 'dialog-button';
    noBtn.textContent = 'No';
    noBtn.onclick = () => {
      closeDialog();
      if (onNo) onNo();
    };
    dialogButtons.appendChild(noBtn);

    dialogModal.classList.add('show');
  }

  function createFolder() {
    showPrompt("Enter new folder name:", "", function(folderName) {
      if (folderName && folderName.trim() !== "") {
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'explorer.php?folder=<?php echo urlencode($currentRel); ?>';
        let inputCreate = document.createElement('input');
        inputCreate.type = 'hidden';
        inputCreate.name = 'create_folder';
        inputCreate.value = '1';
        form.appendChild(inputCreate);
        let inputName = document.createElement('input');
        inputName.type = 'hidden';
        inputName.name = 'folder_name';
        inputName.value = folderName.trim();
        form.appendChild(inputName);
        document.body.appendChild(form);
        form.submit();
      }
    });
  }

  document.getElementById('btnRenameFolder').addEventListener('click', function() {
    if (!selectedFolder) return;
    showPrompt("Enter new folder name:", selectedFolder, function(newName) {
      if (newName && newName.trim() !== "" && newName !== selectedFolder) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'explorer.php?folder=<?php echo urlencode($currentRel); ?>';
        let inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'rename_folder';
        inputAction.value = '1';
        form.appendChild(inputAction);
        let inputOld = document.createElement('input');
        inputOld.type = 'hidden';
        inputOld.name = 'old_folder_name';
        inputOld.value = selectedFolder;
        form.appendChild(inputOld);
        let inputNew = document.createElement('input');
        inputNew.type = 'hidden';
        inputNew.name = 'new_folder_name';
        inputNew.value = newName.trim();
        form.appendChild(inputNew);
        document.body.appendChild(form);
        form.submit();
      }
    });
  });

  document.getElementById('btnDeleteFolder').addEventListener('click', function() {
    if (!selectedFolder) return;
    showConfirm(`Delete folder "${selectedFolder}"?`, () => {
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = 'explorer.php?folder=<?php echo urlencode($currentRel); ?>&delete=' + encodeURIComponent(selectedFolder);
      document.body.appendChild(form);
      form.submit();
    });
  });

  function renameFilePrompt(fileName) {
    let dotIndex = fileName.lastIndexOf(".");
    let baseName = fileName;
    let ext = "";
    if(dotIndex > 0) {
      baseName = fileName.substring(0, dotIndex);
      ext = fileName.substring(dotIndex);
    }
    showPrompt("Enter new file name:", baseName, function(newBase) {
      if (newBase && newBase.trim() !== "" && newBase.trim() !== baseName) {
        let finalName = newBase.trim() + ext;
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'explorer.php?folder=<?php echo urlencode($currentRel); ?>';
        let inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'rename_file';
        inputAction.value = '1';
        form.appendChild(inputAction);
        let inputOld = document.createElement('input');
        inputOld.type = 'hidden';
        inputOld.name = 'old_file_name';
        inputOld.value = fileName;
        form.appendChild(inputOld);
        let inputNew = document.createElement('input');
        inputNew.type = 'hidden';
        inputNew.name = 'new_file_name';
        inputNew.value = finalName;
        form.appendChild(inputNew);
        document.body.appendChild(form);
        form.submit();
      }
    });
  }

  function confirmFileDelete(fileName) {
    showConfirm(`Delete file "${fileName}"?`, () => {
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = 'explorer.php?folder=<?php echo urlencode($currentRel); ?>&delete=' + encodeURIComponent(fileName);
      document.body.appendChild(form);
      form.submit();
    });
  }

  function downloadFile(fileURL) {
    console.log("Downloading: " + fileURL);
    fetch(fileURL)
      .then(response => {
        if (!response.ok) throw new Error('Download failed: ' + response.status);
        return response.blob();
      })
      .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileURL.split('/').pop().split('&')[0]; // Extract filename from URL
        document.body.appendChild(a);
        a.click();
        a.remove();
      })
      .catch(error => showAlert('Download error: ' + error.message));
  }

  const uploadForm = document.getElementById('uploadForm');
  const fileInput = document.getElementById('fileInput');
  const uploadBtn = document.getElementById('uploadBtn');
  const uploadProgressContainer = document.getElementById('uploadProgressContainer');
  const uploadProgressBar = document.getElementById('uploadProgressBar');
  const uploadProgressPercent = document.getElementById('uploadProgressPercent');
  const cancelUploadBtn = document.getElementById('cancelUploadBtn');
  const dropZone = document.getElementById('dropZone');
  const mainContent = document.querySelector('.main-content');

  uploadBtn.addEventListener('click', () => {
    fileInput.click();
  });
  fileInput.addEventListener('change', () => {
    if (!fileInput.files.length) return;
    startUpload(fileInput.files);
  });

  // Drag and Drop Event Listeners
  mainContent.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('active');
  });
  mainContent.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dropZone.classList.remove('active');
  });
  mainContent.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('active');
    const files = e.dataTransfer.files;
    if (files.length > 0) {
      startUpload(files);
    }
  });

  function startUpload(fileList) {
    const formData = new FormData(uploadForm);
    formData.delete("upload_files[]");
    for (let i = 0; i < fileList.length; i++) {
      formData.append("upload_files[]", fileList[i]);
    }
    uploadProgressContainer.style.display = 'block';
    uploadProgressBar.style.width = '0%';
    uploadProgressPercent.textContent = '0%';
    const xhr = new XMLHttpRequest();
    currentXhr = xhr;
    xhr.open('POST', uploadForm.action, true);
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        let percent = Math.round((e.loaded / e.total) * 100);
        uploadProgressBar.style.width = percent + '%';
        uploadProgressPercent.textContent = percent + '%';
      }
    };
    xhr.onload = function() {
      if (xhr.status === 200) {
        location.reload();
      } else {
        showAlert('Upload failed. Status: ' + xhr.status);
      }
    };
    xhr.onerror = function() {
      showAlert('Upload failed. Could not connect to server.');
    };
    xhr.send(formData);
  }
  cancelUploadBtn.addEventListener('click', () => {
    if (currentXhr) {
      currentXhr.abort();
      uploadProgressContainer.style.display = 'none';
      fileInput.value = "";
      showAlert('Upload canceled.');
    }
  });

  function openPreviewModal(fileURL, fileName) {
    console.log("Previewing: " + fileURL);
    const previewContainer = document.getElementById('previewContainer');
    previewContainer.innerHTML = '';
    let lowerName = fileName.toLowerCase();
    fetch(fileURL)
      .then(response => {
        if (!response.ok) throw new Error('Preview failed: ' + response.status);
        return response.blob();
      })
      .then(blob => {
        const blobURL = URL.createObjectURL(blob);
        if (lowerName.match(/\.(png|jpe?g|gif|heic)$/)) {
          let img = document.createElement('img');
          img.src = blobURL;
          previewContainer.appendChild(img);
        } else if (lowerName.match(/\.(mp4|webm|mov|avi|mkv)$/)) {
          let video = document.createElement('video');
          video.src = blobURL;
          video.controls = true;
          video.autoplay = true;
          previewContainer.appendChild(video);
        } else {
          downloadFile(fileURL);
          return;
        }
        document.getElementById('previewModal').style.display = 'flex';
      })
      .catch(error => showAlert('Preview error: ' + error.message));
  }
  window.openPreviewModal = openPreviewModal;
  function closePreviewModal() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContainer').innerHTML = '';
  }
  window.closePreviewModal = closePreviewModal;

  // Theme toggle functionality
  const themeToggleBtn = document.getElementById('themeToggleBtn');
  const body = document.body;

  // Load saved theme from localStorage
  const savedTheme = localStorage.getItem('theme') || 'dark';
  if (savedTheme === 'light') {
    body.classList.add('light-mode');
    themeToggleBtn.querySelector('i').classList.replace('fa-moon', 'fa-sun');
  } else {
    body.classList.remove('light-mode');
    themeToggleBtn.querySelector('i').classList.replace('fa-sun', 'fa-moon');
  }

  themeToggleBtn.addEventListener('click', () => {
    body.classList.toggle('light-mode');
    const isLightMode = body.classList.contains('light-mode');
    themeToggleBtn.querySelector('i').classList.toggle('fa-moon', !isLightMode);
    themeToggleBtn.querySelector('i').classList.toggle('fa-sun', isLightMode);
    localStorage.setItem('theme', isLightMode ? 'light' : 'dark');
  });
</script>
</body>
</html>
