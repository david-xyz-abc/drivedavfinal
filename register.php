<?php
session_start();

$usersFile = __DIR__ . '/users.json';
$baseWebdavDir = '/var/www/html/webdav/users';

// Ensure the users.json file exists
if (!file_exists($usersFile)) {
    file_put_contents($usersFile, json_encode([]));
    chmod($usersFile, 0666); // Ensure web server can write to it
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username and password are required.";
        header("Location: index.php");
        exit;
    }

    // Load existing users
    $users = json_decode(file_get_contents($usersFile), true);
    if (isset($users[$username])) {
        $_SESSION['error'] = "Username already exists.";
        header("Location: index.php");
        exit;
    }

    // Store new user with hashed password
    $users[$username] = password_hash($password, PASSWORD_DEFAULT);
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));

    // Create user's private directory
    $userDir = "$baseWebdavDir/$username/Home";
    if (!is_dir($userDir)) {
        mkdir($userDir, 0777, true);
        chown($userDir, 'www-data'); // Adjust based on your web server user
        chgrp($userDir, 'www-data');
    }

    $_SESSION['message'] = "Registration successful! Please sign in.";
    header("Location: index.php");
    exit;
} else {
    header("Location: index.php");
    exit;
}
?>
