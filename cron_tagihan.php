<?php
require 'config/database.php';

// Kunci proses menggunakan file lock agar tidak bisa berjalan 2 kali bersamaan
$lockFile = __DIR__ . '/cron.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    exit("Cron sedang berjalan.");
}

$today = date('Y-m-d');
$nodeSendUrl = 'http://localhost:3000/api/send';

$sql = "SELECT b.*, u.business_name, u.payment_info, u.message_template, u.wa_session_status, u.plan_status, u.expired_at 
        FROM bills b 
        JOIN users u ON b.user_id = u.id 
        WHERE b.status = 'unpaid' 
          AND u.plan_status IN ('trial', 'active') 
          AND u.expired_at >= NOW()
          AND u.wa_session_status = 'connected'";

$res = $conn->query($sql);
$sentCount = 0;

function sendWhatsApp($url, $userId, $target, $message)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'user_id' => $userId,
        'target'  => $target,
        'message' => $message
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

while ($row = $res->fetch_assoc()) {
    $dueDate = $row['due_date'];
    $diffDays = (int)round((strtotime($dueDate) - strtotime($today)) / 86400);

    $reminderType = null;
    $flagColumn   = null;

    if ($diffDays === 3 && (int)$row['reminded_h3'] === 0) {
        $reminderType = 'H-3';
        $flagColumn   = 'reminded_h3';
    } elseif ($diffDays === 0 && (int)$row['reminded_h0'] === 0) {
        $reminderType = 'H-0';
        $flagColumn   = 'reminded_h0';
    } elseif ($diffDays === -2 && (int)$row['reminded_h2'] === 0) {
        $reminderType = 'H+2';
        $flagColumn   = 'reminded_h2';
    }

    if ($reminderType !== null) {
        // KUNCI FLAG TERLEBIH DAHULU AGAR MUSTAHIL TERKIRIM GANDA
        $conn->query("UPDATE bills SET $flagColumn = 1 WHERE id = {$row['id']} AND $flagColumn = 0");
        if ($conn->affected_rows === 0) {
            continue;
        }

        $rekening = !empty($row['payment_info']) ? $row['payment_info'] : '(Hubungi admin untuk info rekening)';
        $nominal  = number_format($row['amount'], 0, ',', '.');
        $tglIndo  = date('d M Y', strtotime($dueDate));

        $pesan = str_replace(
            ['{nama}', '{tagihan}', '{nominal}', '{tanggal}', '{rekening}'],
            [$row['customer_name'], $row['bill_title'], $nominal, $tglIndo, $rekening],
            $row['message_template']
        );

        if ($reminderType === 'H-3') {
            $pesan = "*[Pengingat H-3 Jatuh Tempo]*\n" . $pesan;
        } elseif ($reminderType === 'H+2') {
            $pesan = "*[Tindak Lanjut Tagihan]*\n" . $pesan;
        }

        $result = sendWhatsApp($nodeSendUrl, $row['user_id'], $row['customer_wa'], $pesan);
        $statusLog = (isset($result['status']) && $result['status'] === 'success') ? 'success' : 'failed';

        // Jika gagal kirim karena WA putus, kembalikan flag ke 0 agar dicoba lagi nanti
        if ($statusLog === 'failed') {
            $conn->query("UPDATE bills SET $flagColumn = 0 WHERE id = {$row['id']}");
        } else {
            $sentCount++;
            sleep(4);
        }

        $pesanEsc = $conn->real_escape_string($pesan);
        $conn->query("INSERT INTO wa_logs (user_id, bill_id, target_wa, reminder_type, message_sent, send_status) 
                    VALUES ({$row['user_id']}, {$row['id']}, '{$row['customer_wa']}', '$reminderType', '$pesanEsc', '$statusLog')");
    }
}

flock($fp, LOCK_UN);
fclose($fp);

echo "Selesai menjalankan pengecekan tagihan otomatis ($today). Total pesan terkirim: $sentCount";
