<?php
require '../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$nodeUrl = 'http://localhost:3000';

if ($action === 'pair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $phone = formatWA($input['phone'] ?? '');

    $ch = curl_init("$nodeUrl/api/pair");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['user_id' => $uid, 'phone' => $phone]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode(['status' => 'error', 'message' => 'Mesin WA (Node.js) belum menyala!']);
    } else {
        echo $response;
    }
    exit;
}

if ($action === 'status') {
    $ch = curl_init("$nodeUrl/api/status/$uid");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    echo $response ?: json_encode(['connected' => false]);
    exit;
}
