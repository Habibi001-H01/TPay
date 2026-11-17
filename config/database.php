<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_port = getenv('DB_PORT') ?: 3306;
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'tpay_db';

$conn = mysqli_init();
if ($db_host !== 'localhost' && $db_host !== '127.0.0.1') {
    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
    mysqli_real_connect($conn, $db_host, $db_user, $db_pass, $db_name, (int)$db_port, NULL, MYSQLI_CLIENT_SSL);
} else {
    mysqli_real_connect($conn, $db_host, $db_user, $db_pass, $db_name, (int)$db_port);
}

if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function formatWA($number)
{
    $number = preg_replace('/[^0-9]/', '', $number);
    if (substr($number, 0, 1) === '0') {
        $number = '62' . substr($number, 1);
    } elseif (substr($number, 0, 1) === '8') {
        $number = '62' . $number;
    }
    return $number;
}

function checkAuth($conn)
{
    if (!isset($_SESSION['user_id'])) {
        $inAuthDir = (strpos($_SERVER['PHP_SELF'], '/auth/') !== false);
        header("Location: " . ($inAuthDir ? "login.php" : "auth/login.php"));
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $res = $conn->query("SELECT * FROM users WHERE id = $uid LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        session_destroy();
        header("Location: auth/login.php");
        exit;
    }
    $user = $res->fetch_assoc();
    if ($user['role'] === 'client' && $user['plan_status'] !== 'expired' && strtotime($user['expired_at']) < time()) {
        $conn->query("UPDATE users SET plan_status = 'expired' WHERE id = $uid");
        $user['plan_status'] = 'expired';
    }
    return $user;
}
