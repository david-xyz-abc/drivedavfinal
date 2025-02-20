<?php
session_start();

$usersFile = __DIR__ . '/users.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username and password are required.";
        header("Location: index.php");
        exit;
    }

    // Load users
    if (!file_exists($usersFile)) {
        $_SESSION['error'] = "No users registered yet.";
        header("Location: index.php");
        exit;
    }

    $users = json_decode(file_get_contents($usersFile), true);
    if (!isset($users[$username]) || !password_verify($password, $users[$username])) {
        $_SESSION['error'] = "Invalid username or password.";
        file_put_contents('/tmp/auth_debug.log', "Authenticate: Login failed for $username\n", FILE_APPEND);
        header("Location: index.php");
        exit;
    }

    // Successful login
    $_SESSION['loggedin'] = true;
    $_SESSION['username'] = $username; // Store username for later use
    file_put_contents('/tmp/auth_debug.log', "Authenticate: Session ID: " . session_id() . "\n", FILE_APPEND);
    file_put_contents('/tmp/auth_debug.log', "Authenticate: Loggedin set: " . var_export($_SESSION['loggedin'], true) . "\n", FILE_APPEND);
    file_put_contents('/tmp/auth_debug.log', "Authenticate: Username: $username\n", FILE_APPEND);
    header("Location: explorer.php?folder=Home");
    exit;
} else {
    header("Location: index.php");
    exit;
}
?>
