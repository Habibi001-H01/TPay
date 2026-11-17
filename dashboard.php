<?php
require 'config/database.php';
$user = checkAuth($conn);
$uid  = (int)$user['id'];

if ($user['role'] === 'admin') {
    header("Location: admin.php");
    exit;
}

$msg = $_SESSION['flash_msg'] ?? '';
$err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

// 1. Simpan Pengaturan Profil, Tema Warna, Wallpaper Bawaan & Upload Galeri (Maks 10MB)
if (isset($_POST['save_settings'])) {
    $bname        = $conn->real_escape_string(trim($_POST['business_name']));
    $bg_theme     = $conn->real_escape_string($_POST['bg_theme'] ?? 'desk');
    $accent_color = $conn->real_escape_string($_POST['accent_color'] ?? 'emerald');
    $payment_info = $conn->real_escape_string(trim($_POST['payment_info']));
    $template     = $conn->real_escape_string(trim($_POST['message_template']));
    $new_pass     = trim($_POST['new_password'] ?? '');

    if (empty($bname)) {
        $_SESSION['flash_err'] = "Nama usaha tidak boleh kosong.";
        header("Location: dashboard.php");
        exit;
    }

    // Upload gambar dari galeri HP/Laptop (Maks 10 MB)
    $customBgSql = "";
    if (isset($_FILES['custom_wallpaper']) && $_FILES['custom_wallpaper']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $fileName   = $_FILES['custom_wallpaper']['name'];
        $fileSize   = $_FILES['custom_wallpaper']['size'];
        $ext        = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            $_SESSION['flash_err'] = "Format gambar harus JPG, PNG, atau WEBP.";
            header("Location: dashboard.php");
            exit;
        }
        if ($fileSize > 10 * 1024 * 1024) {
            $_SESSION['flash_err'] = "Ukuran gambar maksimal 10 MB.";
            header("Location: dashboard.php");
            exit;
        }

        $uploadDir = __DIR__ . '/assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (!empty($user['custom_bg']) && file_exists(__DIR__ . '/' . $user['custom_bg'])) {
            @unlink(__DIR__ . '/' . $user['custom_bg']);
        }

        $newFileName = 'wp_user_' . $uid . '_' . time() . '.' . $ext;
        $targetPath  = $uploadDir . $newFileName;

        if (move_uploaded_file($_FILES['custom_wallpaper']['tmp_name'], $targetPath)) {
            $dbPath      = 'assets/uploads/' . $newFileName;
            $customBgSql = ", custom_bg = '$dbPath'";
            $bg_theme    = 'custom';
        }
    }

    $passSql = "";
    if (!empty($new_pass)) {
        if (strlen($new_pass) < 6) {
            $_SESSION['flash_err'] = "Password baru minimal 6 karakter.";
            header("Location: dashboard.php");
            exit;
        }
        $hashed  = password_hash($new_pass, PASSWORD_DEFAULT);
        $passSql = ", password = '$hashed'";
    }

    $sql = "UPDATE users SET 
            business_name = '$bname', 
            bg_theme = '$bg_theme', 
            accent_color = '$accent_color', 
            payment_info = '$payment_info', 
            message_template = '$template' 
            $customBgSql $passSql 
            WHERE id = $uid";

    if ($conn->query($sql)) {
        $_SESSION['flash_msg'] = "Tampilan, wallpaper & profil usaha berhasil diperbarui!";
    } else {
        $_SESSION['flash_err'] = "Gagal menyimpan pengaturan: " . $conn->error;
    }
    header("Location: dashboard.php");
    exit;
}

// 2. Tambah Tagihan Pelanggan Baru
if (isset($_POST['add_bill'])) {
    if ($user['plan_status'] === 'expired') {
        $_SESSION['flash_err'] = "Masa aktif akun habis. Silakan perpanjang paket untuk menambah tagihan.";
    } else {
        $cname   = $conn->real_escape_string(trim($_POST['customer_name']));
        $cwa     = formatWA(trim($_POST['customer_wa']));
        $btitle  = $conn->real_escape_string(trim($_POST['bill_title']));
        $amount  = (float)preg_replace('/[^0-9]/', '', $_POST['amount']);
        $duedate = $conn->real_escape_string($_POST['due_date']);
        $cycle   = $conn->real_escape_string($_POST['cycle']);

        if (strlen($cwa) < 10 || $amount <= 0) {
            $_SESSION['flash_err'] = "Nomor WA atau nominal tagihan tidak valid.";
        } else {
            $sql = "INSERT INTO bills (user_id, customer_name, customer_wa, bill_title, amount, due_date, cycle, status) 
                    VALUES ($uid, '$cname', '$cwa', '$btitle', $amount, '$duedate', '$cycle', 'unpaid')";
            if ($conn->query($sql)) {
                $_SESSION['flash_msg'] = "Tagihan untuk $cname berhasil ditambahkan!";
            } else {
                $_SESSION['flash_err'] = "Gagal menambah tagihan: " . $conn->error;
            }
        }
    }
    header("Location: dashboard.php");
    exit;
}

// 3. Edit / Perbarui Informasi Tagihan Pelanggan
if (isset($_POST['edit_bill'])) {
    $bid     = (int)$_POST['bill_id'];
    $cname   = $conn->real_escape_string(trim($_POST['customer_name']));
    $cwa     = formatWA(trim($_POST['customer_wa']));
    $btitle  = $conn->real_escape_string(trim($_POST['bill_title']));
    $amount  = (float)preg_replace('/[^0-9]/', '', $_POST['amount']);
    $duedate = $conn->real_escape_string($_POST['due_date']);
    $cycle   = $conn->real_escape_string($_POST['cycle']);

    if (strlen($cwa) < 10 || $amount <= 0) {
        $_SESSION['flash_err'] = "Nomor WA atau nominal tagihan tidak valid.";
    } else {
        $sql = "UPDATE bills SET 
                customer_name = '$cname', 
                customer_wa = '$cwa', 
                bill_title = '$btitle', 
                amount = $amount, 
                due_date = '$duedate', 
                cycle = '$cycle',
                reminded_h3 = 0,
                reminded_h0 = 0,
                reminded_h2 = 0
                WHERE id = $bid AND user_id = $uid";
        if ($conn->query($sql)) {
            $_SESSION['flash_msg'] = "Data tagihan $cname berhasil diperbarui!";
        } else {
            $_SESSION['flash_err'] = "Gagal memperbarui tagihan: " . $conn->error;
        }
    }
    header("Location: dashboard.php");
    exit;
}

// 4. Tandai Lunas, Catat Uang Masuk, Geser Siklus & Kirim Kuitansi WA
if (isset($_GET['mark_paid'])) {
    $bid = (int)$_GET['mark_paid'];
    $cek = $conn->query("SELECT * FROM bills WHERE id = $bid AND user_id = $uid AND status = 'unpaid' LIMIT 1");
    if ($cek->num_rows > 0) {
        $bill = $cek->fetch_assoc();
        $nominal   = number_format($bill['amount'], 0, ',', '.');
        $cnameEsc  = $conn->real_escape_string($bill['customer_name']);
        $btitleEsc = $conn->real_escape_string($bill['bill_title']);

        $conn->query("INSERT INTO payments (user_id, bill_id, customer_name, bill_title, amount) 
                      VALUES ($uid, $bid, '$cnameEsc', '$btitleEsc', {$bill['amount']})");

        $infoNext = "";
        if ($bill['cycle'] === 'monthly') {
            $next_due = date('Y-m-d', strtotime($bill['due_date'] . ' +1 month'));
            $conn->query("UPDATE bills SET due_date = '$next_due', status = 'unpaid', reminded_h3 = 0, reminded_h0 = 0, reminded_h2 = 0 WHERE id = $bid");
            $infoNext = "\nJadwal tagihan berikutnya: *" . date('d M Y', strtotime($next_due)) . "*.";
            $_SESSION['flash_msg'] = "Lunas! Saldo diterima +Rp{$nominal}. Jadwal {$bill['customer_name']} digeser ke $next_due.";
        } elseif ($bill['cycle'] === 'weekly') {
            $next_due = date('Y-m-d', strtotime($bill['due_date'] . ' +7 days'));
            $conn->query("UPDATE bills SET due_date = '$next_due', status = 'unpaid', reminded_h3 = 0, reminded_h0 = 0, reminded_h2 = 0 WHERE id = $bid");
            $infoNext = "\nJadwal tagihan berikutnya: *" . date('d M Y', strtotime($next_due)) . "*.";
            $_SESSION['flash_msg'] = "Lunas! Saldo diterima +Rp{$nominal}. Jadwal {$bill['customer_name']} digeser ke $next_due.";
        } else {
            $conn->query("UPDATE bills SET status = 'paid' WHERE id = $bid");
            $_SESSION['flash_msg'] = "Lunas! Saldo diterima +Rp{$nominal} dari {$bill['customer_name']}.";
        }

        if ($user['wa_session_status'] === 'connected') {
            $receiptMsg = "✅ *KONFIRMASI PEMBAYARAN LUNAS*\n\n"
                . "Yth. *{$bill['customer_name']}*,\n"
                . "Terima kasih, pembayaran *{$bill['bill_title']}* sebesar *Rp{$nominal}* telah kami terima dengan baik.$infoNext\n\n"
                . "Salam hangat,\n*{$user['business_name']}*";

            $ch = curl_init('http://localhost:3000/api/send');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'user_id' => $uid,
                'target'  => $bill['customer_wa'],
                'message' => $receiptMsg
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            curl_close($ch);

            $pesanEsc = $conn->real_escape_string($receiptMsg);
            $conn->query("INSERT INTO wa_logs (user_id, bill_id, target_wa, reminder_type, message_sent, send_status) 
                          VALUES ($uid, $bid, '{$bill['customer_wa']}', 'LUNAS', '$pesanEsc', 'success')");
        }
    }
    header("Location: dashboard.php");
    exit;
}

// 5. Hapus Tagihan
if (isset($_GET['delete_bill'])) {
    $bid = (int)$_GET['delete_bill'];
    $conn->query("DELETE FROM bills WHERE id = $bid AND user_id = $uid");
    $_SESSION['flash_msg'] = "Data tagihan dihapus.";
    header("Location: dashboard.php");
    exit;
}

// 6. Kirim Pengingat WA Manual 1-Klik
if (isset($_GET['send_now'])) {
    $bid = (int)$_GET['send_now'];
    if ($user['wa_session_status'] !== 'connected') {
        $_SESSION['flash_err'] = "WhatsApp belum terhubung! Klik tombol 'Tautkan WA' terlebih dahulu.";
    } elseif ($user['plan_status'] === 'expired') {
        $_SESSION['flash_err'] = "Masa aktif akun habis. Perpanjang paket untuk mengirim pesan.";
    } else {
        $cek = $conn->query("SELECT * FROM bills WHERE id = $bid AND user_id = $uid LIMIT 1");
        if ($cek->num_rows > 0) {
            $bill = $cek->fetch_assoc();
            $rekening = !empty($user['payment_info']) ? $user['payment_info'] : '(Hubungi admin untuk info rekening)';
            $nominal  = number_format($bill['amount'], 0, ',', '.');
            $tglIndo  = date('d M Y', strtotime($bill['due_date']));

            $pesan = str_replace(
                ['{nama}', '{tagihan}', '{nominal}', '{tanggal}', '{rekening}'],
                [$bill['customer_name'], $bill['bill_title'], $nominal, $tglIndo, $rekening],
                $user['message_template']
            );

            $ch = curl_init('http://localhost:3000/api/send');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'user_id' => $uid,
                'target'  => $bill['customer_wa'],
                'message' => $pesan
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $resp = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($resp['status']) && $resp['status'] === 'success') {
                $pesanEsc = $conn->real_escape_string($pesan);
                $conn->query("INSERT INTO wa_logs (user_id, bill_id, target_wa, reminder_type, message_sent, send_status) 
                              VALUES ($uid, $bid, '{$bill['customer_wa']}', 'MANUAL', '$pesanEsc', 'success')");
                $_SESSION['flash_msg'] = "Pesan tagihan berhasil dikirim ke WhatsApp {$bill['customer_name']}!";
            } else {
                $_SESSION['flash_err'] = "Gagal mengirim pesan: " . ($resp['message'] ?? 'Server WA tidak merespons');
            }
        }
    }
    header("Location: dashboard.php");
    exit;
}

// Ringkasan Keuangan & Data
$bulan_ini = date('Y-m');
$total_paid_res = $conn->query("SELECT COUNT(*) as total_transaksi, COALESCE(SUM(amount),0) as total_masuk 
                                FROM payments WHERE user_id = $uid AND DATE_FORMAT(paid_at, '%Y-%m') = '$bulan_ini'")->fetch_assoc();

$total_unpaid_res = $conn->query("SELECT COUNT(*) as total_orang, COALESCE(SUM(amount),0) as total_uang 
                                  FROM bills WHERE user_id = $uid AND status = 'unpaid'")->fetch_assoc();

$bills = $conn->query("SELECT * FROM bills WHERE user_id = $uid ORDER BY status ASC, due_date ASC");
$logs  = $conn->query("SELECT * FROM wa_logs WHERE user_id = $uid ORDER BY sent_at DESC LIMIT 10");
$sisa_hari = max(0, ceil((strtotime($user['expired_at']) - time()) / 86400));

// Konfigurasi Wallpaper
$wallpapers = [
    'none'   => '',
    'desk'   => 'assets/download (1).jpg',
    'forest' => 'assets/download (4).jpg',
    'custom' => $user['custom_bg'] ?? ''
];
$activeTheme = $user['bg_theme'] ?? 'desk';
$currentBg   = $wallpapers[$activeTheme] ?? '';

// Konfigurasi Warna Aksen Tema (Ultra Glass)
$accents = [
    'emerald' => [
        'gradient' => 'from-emerald-600/55 to-teal-950/60',
        'btn'      => 'bg-emerald-500/90 hover:bg-emerald-400 text-slate-950 shadow-emerald-500/20',
        'text'     => 'text-emerald-300',
        'badge'    => 'bg-emerald-500/20 text-emerald-200 border-emerald-400/40'
    ],
    'cyan' => [
        'gradient' => 'from-cyan-600/55 to-blue-950/60',
        'btn'      => 'bg-cyan-400/90 hover:bg-cyan-300 text-slate-950 shadow-cyan-500/20',
        'text'     => 'text-cyan-300',
        'badge'    => 'bg-cyan-500/20 text-cyan-200 border-cyan-400/40'
    ],
    'purple' => [
        'gradient' => 'from-purple-600/55 to-indigo-950/60',
        'btn'      => 'bg-purple-500/90 hover:bg-purple-400 text-white shadow-purple-500/20',
        'text'     => 'text-purple-300',
        'badge'    => 'bg-purple-500/20 text-purple-200 border-purple-400/40'
    ],
    'amber' => [
        'gradient' => 'from-amber-600/55 to-orange-950/60',
        'btn'      => 'bg-amber-400/90 hover:bg-amber-300 text-slate-950 shadow-amber-500/20',
        'text'     => 'text-amber-300',
        'badge'    => 'bg-amber-500/20 text-amber-200 border-amber-400/40'
    ]
];
$activeAccentKey = $user['accent_color'] ?? 'emerald';
$theme           = $accents[$activeAccentKey] ?? $accents['emerald'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= htmlspecialchars($user['business_name']) ?></title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f172a">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Responsif penuh di HP, Tablet, dan Laptop tanpa distorsi */
        .responsive-wallpaper {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100dvh;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            z-index: -20;
        }

        /* Efek Kaca Bening (Ultra Transparent Glass) */
        .glass-card {
            background: rgba(15, 23, 42, 0.32);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }

        .glass-bar {
            background: rgba(15, 23, 42, 0.38);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
    </style>
</head>

<body class="bg-slate-950 text-slate-100 min-h-screen pb-24 relative">
    <!-- Lapisan Wallpaper Bening -->
    <?php if (!empty($currentBg)): ?>
        <div class="responsive-wallpaper" style="background-image: url('<?= htmlspecialchars($currentBg, ENT_QUOTES) ?>');"></div>
        <div class="fixed inset-0 -z-10 bg-slate-950/30"></div>
    <?php endif; ?>

    <!-- Top Header (Ultra Glass) -->
    <header class="glass-bar border-b border-white/15 sticky top-0 z-30">
        <div class="max-w-md md:max-w-xl mx-auto px-4 py-3.5 flex justify-between items-center gap-2">
            <div class="min-w-0">
                <h1 class="font-bold text-base text-white leading-tight truncate drop-shadow"><?= htmlspecialchars($user['business_name']) ?></h1>
                <div class="flex items-center gap-2 mt-0.5">
                    <?php if ($user['plan_status'] === 'expired'): ?>
                        <span class="text-[11px] text-red-400 font-semibold drop-shadow">● Masa Aktif Habis</span>
                    <?php else: ?>
                        <span class="text-[11px] <?= $theme['text'] ?> font-semibold drop-shadow">● <?= strtoupper($user['plan_status']) ?> (Sisa <?= $sisa_hari ?> hari)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button onclick="openModal('modalLogs')" class="text-xs text-white px-2.5 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 backdrop-blur border border-white/10">Riwayat</button>
                <button onclick="openModal('modalSettings')" class="text-xs text-white px-2.5 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 backdrop-blur border border-white/10">⚙️ Tema & Profil</button>
                <a href="auth/logout.php" class="text-xs text-slate-200 hover:text-red-300 px-2 py-1.5 rounded-lg bg-black/25 border border-white/15">Keluar</a>
            </div>
        </div>
    </header>

    <main class="max-w-md md:max-w-xl mx-auto px-4 pt-4 space-y-4">
        <?php if ($msg): ?>
            <div class="p-3 rounded-xl bg-emerald-500/25 backdrop-blur-md border border-emerald-400/40 text-emerald-100 text-xs shadow"><?= $msg ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="p-3 rounded-xl bg-red-500/25 backdrop-blur-md border border-red-400/40 text-red-100 text-xs shadow"><?= $err ?></div>
        <?php endif; ?>

        <!-- Banner Perpanjangan Langganan -->
        <?php if ($sisa_hari <= 3 || $user['plan_status'] === 'expired'): ?>
            <div class="p-4 rounded-2xl bg-indigo-500/25 backdrop-blur-md border border-indigo-400/40 text-xs space-y-2 shadow-lg">
                <div class="font-bold text-indigo-200">💎 Perpanjang Layanan TPay (Rp99.000 / Bulan)</div>
                <p class="text-slate-100 leading-relaxed">
                    Agar pengingat WA otomatis terus berjalan tanpa henti, silakan transfer biaya langganan ke <b>DANA / SeaBank: 0838-4011-0948 (a.n. M. Ridwan Habibi)</b> lalu kirim bukti transfer ke Admin.
                </p>
                <a href="https://wa.me/6283840110948?text=Halo%20Admin%20TPay,%20saya%20mau%20perpanjang%20paket%20bulanan%20untuk%20usaha%20<?= urlencode($user['business_name']) ?>" target="_blank"
                    class="inline-block px-3 py-2 rounded-xl bg-indigo-500 text-white font-bold">
                    Konfirmasi Perpanjangan via WA
                </a>
            </div>
        <?php endif; ?>

        <!-- Status Koneksi WhatsApp Card (Ultra Glass) -->
        <div class="p-4 rounded-2xl glass-card flex justify-between items-center">
            <div>
                <div class="text-xs text-slate-200 drop-shadow">Status Mesin WhatsApp</div>
                <?php if ($user['wa_session_status'] === 'connected'): ?>
                    <div class="text-sm font-bold <?= $theme['text'] ?> mt-0.5 drop-shadow">🟢 Terhubung (Siap Kirim Otomatis)</div>
                <?php else: ?>
                    <div class="text-sm font-bold text-amber-300 mt-0.5 drop-shadow">🟠 Belum Terhubung ke WA</div>
                <?php endif; ?>
            </div>
            <button onclick="openModal('modalWA')" class="px-3 py-2 rounded-xl border <?= $theme['badge'] ?> text-xs font-bold backdrop-blur">
                Tautkan WA
            </button>
        </div>

        <!-- Ringkasan 2 Saldo: Uang Diterima vs Belum Lunas -->
        <div class="grid grid-cols-2 gap-3">
            <div class="p-4 rounded-2xl bg-gradient-to-br <?= $theme['gradient'] ?> backdrop-blur-md border border-white/25 text-white shadow-xl">
                <div class="text-[11px] text-white/90 drop-shadow">Uang Diterima (Bulan Ini)</div>
                <div class="text-lg font-extrabold mt-1 drop-shadow">Rp <?= number_format($total_paid_res['total_masuk'], 0, ',', '.') ?></div>
                <div class="text-[11px] text-white/85 mt-1.5"><?= $total_paid_res['total_transaksi'] ?> pembayaran lunas</div>
            </div>

            <div class="p-4 rounded-2xl glass-card flex flex-col justify-between">
                <div>
                    <div class="text-[11px] text-slate-200 drop-shadow">Belum Lunas (Piutang)</div>
                    <div class="text-lg font-extrabold text-amber-300 mt-1 drop-shadow">Rp <?= number_format($total_unpaid_res['total_uang'], 0, ',', '.') ?></div>
                </div>
                <div class="text-[11px] text-slate-200 mt-1.5 flex justify-between items-center">
                    <span><?= $total_unpaid_res['total_orang'] ?> tagihan</span>
                    <button onclick="openModal('modalSettings')" class="<?= $theme['text'] ?> underline font-semibold">Atur Info</button>
                </div>
            </div>
        </div>

        <!-- Peringatan Jika Rekening Belum Diisi -->
        <?php if (empty($user['payment_info'])): ?>
            <div class="p-3.5 rounded-xl bg-amber-500/25 backdrop-blur-md border border-amber-400/40 text-amber-100 text-xs flex justify-between items-center">
                <span>⚠️ Info rekening/QRIS kamu masih kosong.</span>
                <button onclick="openModal('modalSettings')" class="font-bold underline ml-2 shrink-0">Isi Sekarang</button>
            </div>
        <?php endif; ?>

        <!-- Daftar Kartu Tagihan -->
        <div class="flex justify-between items-center pt-2">
            <h2 class="font-bold text-sm text-white drop-shadow-md">Daftar Jadwal Tagihan</h2>
            <span class="text-[11px] text-slate-100 drop-shadow-md">Otomatis H-3, Hari-H, H+2</span>
        </div>

        <div class="space-y-3">
            <?php if ($bills->num_rows === 0): ?>
                <div class="p-8 text-center rounded-2xl glass-card border-dashed">
                    <p class="text-sm text-white drop-shadow">Belum ada data tagihan pelanggan.</p>
                    <p class="text-xs text-slate-200 mt-1">Tekan tombol <b>+ Tambah Tagihan</b> di bawah untuk mulai.</p>
                </div>
            <?php else: ?>
                <?php while ($b = $bills->fetch_assoc()):
                    $is_overdue = ($b['status'] === 'unpaid' && strtotime($b['due_date']) < strtotime(date('Y-m-d')));
                    $is_today   = ($b['status'] === 'unpaid' && $b['due_date'] === date('Y-m-d'));
                ?>
                    <div class="p-4 rounded-2xl glass-card <?= $is_overdue ? '!border-red-400/60' : ($is_today ? '!border-amber-400/60' : '') ?>">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-bold text-base text-white drop-shadow"><?= htmlspecialchars($b['customer_name']) ?></div>
                                <div class="text-xs text-slate-200 drop-shadow"><?= htmlspecialchars($b['bill_title']) ?> • +<?= $b['customer_wa'] ?></div>
                            </div>
                            <div class="text-right">
                                <div class="font-extrabold text-sm <?= $theme['text'] ?> drop-shadow">Rp <?= number_format($b['amount'], 0, ',', '.') ?></div>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-semibold backdrop-blur <?= $b['status'] === 'paid' ? 'bg-emerald-500/25 text-emerald-200' : ($is_overdue ? 'bg-red-500/30 text-red-200' : 'bg-white/15 text-white') ?>">
                                    <?= $b['status'] === 'paid' ? 'LUNAS' : ($is_overdue ? 'LEWAT JATUH TEMPO' : ($is_today ? 'JATUH TEMPO HARI INI' : 'Jatuh Tempo: ' . date('d M Y', strtotime($b['due_date'])))) ?>
                                </span>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-white/15 flex justify-between items-center text-xs">
                            <div class="text-slate-200 drop-shadow">
                                Siklus: <span class="text-white font-semibold"><?= $b['cycle'] === 'monthly' ? 'Bulanan' : ($b['cycle'] === 'weekly' ? 'Mingguan' : 'Sekali') ?></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="button"
                                    onclick='openEditModal(<?= json_encode([
                                                                "id" => $b["id"],
                                                                "customer_name" => $b["customer_name"],
                                                                "customer_wa" => $b["customer_wa"],
                                                                "bill_title" => $b["bill_title"],
                                                                "amount" => (int)$b["amount"],
                                                                "due_date" => $b["due_date"],
                                                                "cycle" => $b["cycle"]
                                                            ]) ?>)'
                                    class="px-2 py-1.5 rounded-lg text-white bg-white/15 hover:bg-white/25 backdrop-blur border border-white/10">
                                    ✏️ Edit
                                </button>
                                <a href="dashboard.php?delete_bill=<?= $b['id'] ?>" onclick="return confirm('Hapus tagihan ini?')" class="px-2 py-1.5 rounded-lg text-slate-200 hover:text-red-300 bg-black/20 hover:bg-white/15">Hapus</a>
                                <?php if ($b['status'] === 'unpaid'): ?>
                                    <a href="dashboard.php?send_now=<?= $b['id'] ?>" class="px-2.5 py-1.5 rounded-lg bg-sky-500/30 text-sky-200 border border-sky-400/40 font-bold hover:bg-sky-500/40 backdrop-blur">
                                        📩 Tagih
                                    </a>
                                    <a href="dashboard.php?mark_paid=<?= $b['id'] ?>" onclick="return confirm('Pastikan transfer sudah masuk ke rekening/e-wallet Anda. Tandai lunas sekarang?')" class="px-2.5 py-1.5 rounded-lg <?= $theme['btn'] ?> font-bold shadow">
                                        ✓ Lunas
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Bottom Floating Action Bar -->
    <div class="fixed bottom-0 inset-x-0 glass-bar border-t border-white/15 p-3 z-30">
        <div class="max-w-md md:max-w-xl mx-auto">
            <button onclick="openModal('modalAdd')" class="w-full py-3.5 rounded-xl <?= $theme['btn'] ?> font-extrabold text-sm shadow-lg backdrop-blur">
                + Tambah Tagihan Pelanggan
            </button>
        </div>
    </div>

    <!-- MODAL 1: Tambah Tagihan -->
    <div id="modalAdd" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-base">Tambah Jadwal Tagihan</h3>
                <button onclick="closeModal('modalAdd')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <form method="POST" action="dashboard.php" class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Nama Pelanggan / Siswa / Penghuni</label>
                    <input type="text" name="customer_name" required placeholder="Contoh: Ibu Rina (Wali Andi)" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Nomor WhatsApp Pelanggan</label>
                    <input type="tel" name="customer_wa" required placeholder="08123456789" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Keterangan Tagihan</label>
                    <input type="text" name="bill_title" required placeholder="Contoh: SPP Bimbel / Sewa Kos Kamar 2" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Nominal (Rp)</label>
                        <input type="number" name="amount" required placeholder="250000" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Tanggal Jatuh Tempo</label>
                        <input type="date" name="due_date" required value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Siklus Pengulangan</label>
                    <select name="cycle" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                        <option value="monthly">Bulanan (Otomatis geser +1 bulan saat lunas)</option>
                        <option value="weekly">Mingguan (Otomatis geser +7 hari saat lunas)</option>
                        <option value="once">Sekali Tagih Saja</option>
                    </select>
                </div>
                <button type="submit" name="add_bill" class="w-full py-3 rounded-xl <?= $theme['btn'] ?> font-bold text-sm mt-2">Simpan Jadwal Tagihan</button>
            </form>
        </div>
    </div>

    <!-- MODAL 1B: Edit Tagihan -->
    <div id="modalEdit" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-base">✏️ Edit Informasi Tagihan</h3>
                <button onclick="closeModal('modalEdit')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <form method="POST" action="dashboard.php" class="space-y-3">
                <input type="hidden" name="bill_id" id="edit_bill_id">
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Nama Pelanggan / Siswa / Penghuni</label>
                    <input type="text" name="customer_name" id="edit_customer_name" required class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Nomor WhatsApp Pelanggan</label>
                    <input type="tel" name="customer_wa" id="edit_customer_wa" required class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Keterangan Tagihan</label>
                    <input type="text" name="bill_title" id="edit_bill_title" required class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Nominal (Rp)</label>
                        <input type="number" name="amount" id="edit_amount" required class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Tanggal Jatuh Tempo</label>
                        <input type="date" name="due_date" id="edit_due_date" required class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Siklus Pengulangan</label>
                    <select name="cycle" id="edit_cycle" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                        <option value="monthly">Bulanan (Otomatis geser +1 bulan saat lunas)</option>
                        <option value="weekly">Mingguan (Otomatis geser +7 hari saat lunas)</option>
                        <option value="once">Sekali Tagih Saja</option>
                    </select>
                </div>
                <button type="submit" name="edit_bill" class="w-full py-3 rounded-xl <?= $theme['btn'] ?> font-bold text-sm mt-2">Simpan Perubahan</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2: Pengaturan Profil, Tema Warna & Upload Galeri (Maks 10MB) -->
    <div id="modalSettings" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-md p-5 max-h-[88vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-base">⚙️ Pengaturan Tampilan & Profil</h3>
                <button onclick="closeModal('modalSettings')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="space-y-3.5">
                <!-- Tema & Wallpaper Galeri -->
                <div class="p-3 rounded-xl bg-slate-950/60 border border-white/10 space-y-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wider <?= $theme['text'] ?>">Kustomisasi Wallpaper & Warna Tema</div>

                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Pilih Wallpaper Bawaan / Galeri</label>
                        <select name="bg_theme" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-sm">
                            <option value="desk" <?= $activeTheme === 'desk' ? 'selected' : '' ?>>☕ Meja Kerja Minimalis (Bawaan)</option>
                            <option value="forest" <?= $activeTheme === 'forest' ? 'selected' : '' ?>>🌲 Hutan Kabut Tenang</option>
                            <?php if (!empty($user['custom_bg'])): ?>
                                <option value="custom" <?= $activeTheme === 'custom' ? 'selected' : '' ?>>🖼️ Foto Galeri Saya (Tersimpan)</option>
                            <?php endif; ?>
                            <option value="none" <?= $activeTheme === 'none' ? 'selected' : '' ?>>🌑 Gelap Polos (Tanpa Wallpaper)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Atau Ambil Foto dari Galeri HP / Laptop</label>
                        <input type="file" name="custom_wallpaper" accept="image/jpeg,image/png,image/webp"
                            class="w-full text-xs text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-white/15 file:text-white hover:file:bg-white/25 bg-slate-900 border border-white/10 rounded-xl p-1">
                        <p class="text-[10px] text-slate-400 mt-1">Otomatis menyesuaikan ukuran layar HP, Tablet, & Laptop (Maks 10MB).</p>
                    </div>

                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Warna Aksen Kartu & Tombol</label>
                        <select name="accent_color" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-sm">
                            <option value="emerald" <?= $activeAccentKey === 'emerald' ? 'selected' : '' ?>>🟢 Hijau Emerald (Default)</option>
                            <option value="cyan" <?= $activeAccentKey === 'cyan' ? 'selected' : '' ?>>🔵 Biru Cyan Modern</option>
                            <option value="purple" <?= $activeAccentKey === 'purple' ? 'selected' : '' ?>>🟣 Ungu Soft Elegan</option>
                            <option value="amber" <?= $activeAccentKey === 'amber' ? 'selected' : '' ?>>🟡 Emas Amber Premium</option>
                        </select>
                    </div>
                </div>

                <!-- Profil Usaha -->
                <div class="p-3 rounded-xl bg-slate-950/60 border border-white/10 space-y-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wider <?= $theme['text'] ?>">Profil Usaha Anda</div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Nama Usaha / Bimbel / Kos</label>
                        <input type="text" name="business_name" required value="<?= htmlspecialchars($user['business_name']) ?>" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Nomor WA Terdaftar (ID Login)</label>
                        <input type="text" disabled value="+<?= htmlspecialchars($user['whatsapp_number']) ?>" class="w-full px-3 py-2 rounded-xl bg-slate-900/50 border border-white/10 text-xs text-slate-400">
                    </div>
                </div>

                <!-- Rekening & Template Pesan -->
                <div class="p-3 rounded-xl bg-slate-950/60 border border-white/10 space-y-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wider <?= $theme['text'] ?>">Rekening & Format Pesan WA</div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Info Rekening / E-Wallet Tujuan Transfer</label>
                        <textarea name="payment_info" rows="2" required placeholder="Contoh: BCA 1234567890 a.n. Budi Santoso / DANA 0812xxx" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-sm"><?= htmlspecialchars($user['payment_info'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Template Pesan WA Otomatis</label>
                        <textarea name="message_template" rows="4" required class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-xs leading-relaxed"><?= htmlspecialchars($user['message_template']) ?></textarea>
                        <p class="text-[11px] text-slate-400 mt-1">Variabel: <code>{nama}</code>, <code>{tagihan}</code>, <code>{nominal}</code>, <code>{tanggal}</code>, <code>{rekening}</code></p>
                    </div>
                </div>

                <!-- Ganti Password -->
                <div class="p-3 rounded-xl bg-slate-950/60 border border-white/10 space-y-2">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-300">Keamanan Akun (Opsional)</div>
                    <div>
                        <label class="block text-xs text-slate-300 mb-1">Password Baru (Kosongkan jika tidak ingin mengganti)</label>
                        <input type="password" name="new_password" placeholder="Minimal 6 karakter" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-sm">
                    </div>
                </div>

                <button type="submit" name="save_settings" class="w-full py-3 rounded-xl <?= $theme['btn'] ?> font-bold text-sm">Simpan Semua Pengaturan</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: Tautkan WA -->
    <div id="modalWA" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-bold text-base">Tautkan WhatsApp (8-Digit Code)</h3>
                <button onclick="closeModal('modalWA')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <p class="text-xs text-slate-300 mb-4">Masukkan nomor WA usaha yang akan dipakai mengirim pengingat otomatis. Tanpa perlu scan QR Code!</p>
            <div class="space-y-3">
                <input type="tel" id="waPairNumber" value="<?= htmlspecialchars($user['whatsapp_number']) ?>" class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                <button type="button" onclick="requestPairing(this)" class="w-full py-3 rounded-xl <?= $theme['btn'] ?> font-bold text-sm">Minta Kode Pairing 8 Digit</button>
                <div id="pairingResult" class="hidden p-4 rounded-xl bg-slate-950/80 border border-emerald-500/40 text-center">
                    <div class="text-xs text-slate-300 mb-1">Masukkan kode ini di WhatsApp HP kamu:</div>
                    <div id="pairingCodeText" class="text-2xl font-mono font-extrabold tracking-widest <?= $theme['text'] ?>">---- ----</div>
                    <div class="text-[11px] text-slate-400 mt-2">Buka WA &gt; Titik Tiga &gt; Perangkat Tertaut &gt; Tautkan dengan Nomor Telepon</div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 4: Riwayat Pengiriman WA -->
    <div id="modalLogs" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-end sm:items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-md p-5 max-h-[80vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-base">10 Riwayat Pengiriman Terakhir</h3>
                <button onclick="closeModal('modalLogs')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <div class="space-y-2.5">
                <?php if ($logs->num_rows === 0): ?>
                    <p class="text-xs text-slate-400 text-center py-4">Belum ada riwayat pesan terkirim.</p>
                <?php else: ?>
                    <?php while ($lg = $logs->fetch_assoc()): ?>
                        <div class="p-3 rounded-xl bg-slate-950/70 border border-white/10 text-xs">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-bold <?= $theme['text'] ?>">+<?= $lg['target_wa'] ?> (<?= $lg['reminder_type'] ?>)</span>
                                <span class="text-[10px] text-slate-400"><?= date('d M H:i', strtotime($lg['sent_at'])) ?></span>
                            </div>
                            <p class="text-slate-300 line-clamp-2"><?= htmlspecialchars($lg['message_sent']) ?></p>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }

        function openEditModal(bill) {
            document.getElementById('edit_bill_id').value = bill.id;
            document.getElementById('edit_customer_name').value = bill.customer_name;
            document.getElementById('edit_customer_wa').value = bill.customer_wa;
            document.getElementById('edit_bill_title').value = bill.bill_title;
            document.getElementById('edit_amount').value = bill.amount;
            document.getElementById('edit_due_date').value = bill.due_date;
            document.getElementById('edit_cycle').value = bill.cycle;
            openModal('modalEdit');
        }
        async function requestPairing(btn) {
            const phone = document.getElementById('waPairNumber').value;
            const resultBox = document.getElementById('pairingResult');
            const codeText = document.getElementById('pairingCodeText');

            btn.disabled = true;
            btn.innerText = "Meminta kode ke server WA...";

            try {
                const res = await fetch('api/wa_bridge.php?action=pair', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        phone: phone
                    })
                });
                const data = await res.json();

                if (data.status === 'pairing') {
                    resultBox.classList.remove('hidden');
                    codeText.innerText = data.code;
                    btn.innerText = "Minta Ulang Kode";
                    startStatusPolling();
                } else {
                    alert(data.message || "Gagal mendapatkan kode.");
                    btn.innerText = "Minta Kode Pairing 8 Digit";
                }
            } catch (e) {
                alert("Gagal terhubung ke server lokal.");
                btn.innerText = "Minta Kode Pairing 8 Digit";
            }
            btn.disabled = false;
        }

        function startStatusPolling() {
            const interval = setInterval(async () => {
                const res = await fetch('api/wa_bridge.php?action=status');
                const data = await res.json();
                if (data.connected) {
                    clearInterval(interval);
                    alert("Mantap! WhatsApp berhasil terhubung ke TPay!");
                    location.reload();
                }
            }, 3000);
        }
    </script>
</body>

</html>