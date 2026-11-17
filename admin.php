<?php
require 'config/database.php';
$admin = checkAuth($conn);
$aid   = (int)$admin['id'];

if ($admin['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$msg = $_SESSION['flash_msg'] ?? '';
$err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

// 1. Simpan Tampilan Wallpaper & Tema Super Admin (Maks 10MB)
if (isset($_POST['save_admin_theme'])) {
    $bg_theme     = $conn->real_escape_string($_POST['bg_theme'] ?? 'desk');
    $accent_color = $conn->real_escape_string($_POST['accent_color'] ?? 'emerald');
    $customBgSql  = "";

    if (isset($_FILES['custom_wallpaper']) && $_FILES['custom_wallpaper']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $fileName   = $_FILES['custom_wallpaper']['name'];
        $fileSize   = $_FILES['custom_wallpaper']['size'];
        $ext        = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            $_SESSION['flash_err'] = "Format gambar harus JPG, PNG, atau WEBP.";
            header("Location: admin.php");
            exit;
        }
        if ($fileSize > 10 * 1024 * 1024) {
            $_SESSION['flash_err'] = "Ukuran gambar maksimal 10 MB.";
            header("Location: admin.php");
            exit;
        }

        $uploadDir = __DIR__ . '/assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (!empty($admin['custom_bg']) && file_exists(__DIR__ . '/' . $admin['custom_bg'])) {
            @unlink(__DIR__ . '/' . $admin['custom_bg']);
        }

        $newFileName = 'wp_admin_' . $aid . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['custom_wallpaper']['tmp_name'], $uploadDir . $newFileName)) {
            $dbPath      = 'assets/uploads/' . $newFileName;
            $customBgSql = ", custom_bg = '$dbPath'";
            $bg_theme    = 'custom';
        }
    }

    $conn->query("UPDATE users SET bg_theme = '$bg_theme', accent_color = '$accent_color' $customBgSql WHERE id = $aid");
    $_SESSION['flash_msg'] = "Tampilan & wallpaper ruang Super Admin berhasil diperbarui!";
    header("Location: admin.php");
    exit;
}

// 2. Aktivasi / Perpanjang +30 Hari Klien
if (isset($_GET['extend'])) {
    $cid = (int)$_GET['extend'];
    $cek = $conn->query("SELECT business_name, expired_at FROM users WHERE id = $cid AND role = 'client' LIMIT 1");
    if ($cek->num_rows > 0) {
        $c = $cek->fetch_assoc();
        $baseTime = max(time(), strtotime($c['expired_at']));
        $newExp   = date('Y-m-d H:i:s', strtotime('+30 days', $baseTime));
        $conn->query("UPDATE users SET plan_status = 'active', expired_at = '$newExp' WHERE id = $cid");
        $_SESSION['flash_msg'] = "Paket {$c['business_name']} aktif & diperpanjang hingga " . date('d M Y', strtotime($newExp)) . "!";
    }
    header("Location: admin.php");
    exit;
}

// 3. Set Tanggal Kedaluwarsa Kustom Klien
if (isset($_POST['set_custom_expiry'])) {
    $cid     = (int)$_POST['client_id'];
    $newDate = $conn->real_escape_string($_POST['expired_date']) . ' 23:59:59';
    $status  = (strtotime($newDate) < time()) ? 'expired' : 'active';
    $conn->query("UPDATE users SET plan_status = '$status', expired_at = '$newDate' WHERE id = $cid AND role = 'client'");
    $_SESSION['flash_msg'] = "Masa aktif klien berhasil diubah ke " . date('d M Y', strtotime($newDate)) . " ($status).";
    header("Location: admin.php");
    exit;
}

// 4. Nonaktifkan / Kedaluwarsakan Akun Klien
if (isset($_GET['expire'])) {
    $cid = (int)$_GET['expire'];
    $now = date('Y-m-d H:i:s', strtotime('-1 minute'));
    $conn->query("UPDATE users SET plan_status = 'expired', expired_at = '$now' WHERE id = $cid AND role = 'client'");
    $_SESSION['flash_msg'] = "Akun klien berhasil dinonaktifkan (Expired).";
    header("Location: admin.php");
    exit;
}

// 5. Hapus Akun Klien Permanen
if (isset($_GET['delete_client'])) {
    $cid = (int)$_GET['delete_client'];
    $conn->query("DELETE FROM users WHERE id = $cid AND role = 'client'");
    $_SESSION['flash_msg'] = "Akun klien dan seluruh data tagihannya telah dihapus.";
    header("Location: admin.php");
    exit;
}

// Statistik SaaS
$totalClients  = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'client'")->fetch_assoc()['total'];
$activeClients = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'client' AND plan_status = 'active'")->fetch_assoc()['total'];
$trialClients  = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'client' AND plan_status = 'trial'")->fetch_assoc()['total'];
$totalBills    = $conn->query("SELECT COUNT(*) as total FROM bills")->fetch_assoc()['total'];

$clients = $conn->query("SELECT u.*, 
    (SELECT COUNT(*) FROM bills b WHERE b.user_id = u.id) as jml_tagihan,
    (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.user_id = u.id) as total_omzet
    FROM users u WHERE u.role = 'client' ORDER BY u.created_at DESC");

// Konfigurasi Wallpaper & Tema Admin
$wallpapers = [
    'none'   => '',
    'desk'   => 'assets/download (1).jpg',
    'forest' => 'assets/download (4).jpg',
    'custom' => $admin['custom_bg'] ?? ''
];
$activeTheme = $admin['bg_theme'] ?? 'desk';
$currentBg   = $wallpapers[$activeTheme] ?? '';

$accents = [
    'emerald' => ['btn' => 'bg-emerald-500/90 hover:bg-emerald-400 text-slate-950', 'text' => 'text-emerald-300', 'badge' => 'bg-emerald-500/20 text-emerald-200 border-emerald-400/40'],
    'cyan'    => ['btn' => 'bg-cyan-400/90 hover:bg-cyan-300 text-slate-950', 'text' => 'text-cyan-300', 'badge' => 'bg-cyan-500/20 text-cyan-200 border-cyan-400/40'],
    'purple'  => ['btn' => 'bg-purple-500/90 hover:bg-purple-400 text-white', 'text' => 'text-purple-300', 'badge' => 'bg-purple-500/20 text-purple-200 border-purple-400/40'],
    'amber'   => ['btn' => 'bg-amber-400/90 hover:bg-amber-300 text-slate-950', 'text' => 'text-amber-300', 'badge' => 'bg-amber-500/20 text-amber-200 border-amber-400/40']
];
$activeAccentKey = $admin['accent_color'] ?? 'emerald';
$theme           = $accents[$activeAccentKey] ?? $accents['emerald'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — TPay SaaS Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
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

<body class="bg-slate-950 text-slate-100 min-h-screen pb-12 relative">
    <!-- Lapisan Wallpaper Bening -->
    <?php if (!empty($currentBg)): ?>
        <div class="responsive-wallpaper" style="background-image: url('<?= htmlspecialchars($currentBg, ENT_QUOTES) ?>');"></div>
        <div class="fixed inset-0 -z-10 bg-slate-950/30"></div>
    <?php endif; ?>

    <!-- Header Super Admin -->
    <header class="glass-bar border-b border-white/15 sticky top-0 z-30">
        <div class="max-w-3xl mx-auto px-4 py-3.5 flex justify-between items-center">
            <div>
                <h1 class="font-extrabold text-base text-white drop-shadow">🛡️ TPay — Super Admin Control</h1>
                <p class="text-[11px] <?= $theme['text'] ?> drop-shadow">Owner: M. Ridwan Habibi • Otomatisasi Langganan Klien</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openModal('modalAdminTheme')" class="text-xs text-white px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 backdrop-blur border border-white/10">⚙️ Tema & Wallpaper</button>
                <a href="auth/logout.php" class="text-xs text-slate-200 hover:text-red-300 px-3 py-1.5 rounded-lg bg-black/25 border border-white/15">Keluar</a>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 pt-5 space-y-5">
        <?php if ($msg): ?>
            <div class="p-3 rounded-xl bg-emerald-500/25 backdrop-blur-md border border-emerald-400/40 text-emerald-100 text-xs"><?= $msg ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="p-3 rounded-xl bg-red-500/25 backdrop-blur-md border border-red-400/40 text-red-100 text-xs"><?= $err ?></div>
        <?php endif; ?>

        <!-- Ringkasan Statistik Bisnis SaaS -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-4 rounded-2xl glass-card">
                <div class="text-[11px] text-slate-200">Total Klien</div>
                <div class="text-2xl font-extrabold text-white mt-1 drop-shadow"><?= $totalClients ?></div>
            </div>
            <div class="p-4 rounded-2xl glass-card">
                <div class="text-[11px] text-slate-200">Klien Berbayar</div>
                <div class="text-2xl font-extrabold <?= $theme['text'] ?> mt-1 drop-shadow"><?= $activeClients ?></div>
            </div>
            <div class="p-4 rounded-2xl glass-card">
                <div class="text-[11px] text-slate-200">Masa Trial (7 Hari)</div>
                <div class="text-2xl font-extrabold text-amber-300 mt-1 drop-shadow"><?= $trialClients ?></div>
            </div>
            <div class="p-4 rounded-2xl glass-card">
                <div class="text-[11px] text-slate-200">Total Tagihan Klien</div>
                <div class="text-2xl font-extrabold text-sky-300 mt-1 drop-shadow"><?= $totalBills ?></div>
            </div>
        </div>

        <!-- Daftar Klien -->
        <div class="flex justify-between items-center pt-1">
            <h2 class="font-bold text-sm text-white drop-shadow-md">Manajemen Masa Aktif & Paket Klien</h2>
            <span class="text-[11px] text-slate-100 drop-shadow">Otomatis terkunci jika Expired</span>
        </div>

        <div class="space-y-3">
            <?php if ($clients->num_rows === 0): ?>
                <div class="p-8 text-center rounded-2xl glass-card border-dashed text-sm text-slate-200">
                    Belum ada klien usaha yang mendaftar.
                </div>
            <?php else: ?>
                <?php while ($c = $clients->fetch_assoc()):
                    $sisa = max(0, ceil((strtotime($c['expired_at']) - time()) / 86400));
                    $isExp = ($c['plan_status'] === 'expired' || strtotime($c['expired_at']) < time());
                ?>
                    <div class="p-4 rounded-2xl glass-card space-y-3 <?= $isExp ? '!border-red-400/50' : '' ?>">
                        <div class="flex flex-wrap justify-between items-start gap-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-base text-white drop-shadow"><?= htmlspecialchars($c['business_name']) ?></span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase backdrop-blur <?= $isExp ? 'bg-red-500/30 text-red-200' : ($c['plan_status'] === 'active' ? 'bg-emerald-500/25 text-emerald-200' : 'bg-amber-500/25 text-amber-200') ?>">
                                        <?= $isExp ? 'EXPIRED' : $c['plan_status'] ?>
                                    </span>
                                </div>
                                <div class="text-xs text-slate-200 mt-0.5">
                                    WA Login: <a href="https://wa.me/<?= $c['whatsapp_number'] ?>" target="_blank" class="<?= $theme['text'] ?> underline">+<?= $c['whatsapp_number'] ?></a>
                                    • Mesin WA: <?= $c['wa_session_status'] === 'connected' ? '🟢 Online' : '🟠 Offline' ?>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-xs text-slate-200">Berlaku Sampai:</div>
                                <div class="font-bold text-sm <?= $isExp ? 'text-red-300' : 'text-white' ?> drop-shadow">
                                    <?= date('d M Y', strtotime($c['expired_at'])) ?> (<?= $isExp ? 'Habis' : "Sisa $sisa hari" ?>)
                                </div>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-white/15 flex flex-wrap justify-between items-center gap-2 text-xs">
                            <div class="text-slate-200">
                                Tagihan: <b class="text-white"><?= $c['jml_tagihan'] ?></b> • Total Lunas: <b class="<?= $theme['text'] ?>">Rp <?= number_format($c['total_omzet'], 0, ',', '.') ?></b>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="admin.php?extend=<?= $c['id'] ?>" onclick="return confirm('Aktifkan & tambah masa aktif +30 hari untuk <?= htmlspecialchars($c['business_name']) ?>?')"
                                    class="px-3 py-1.5 rounded-lg <?= $theme['btn'] ?> font-bold shadow">
                                    +30 Hari (Aktifkan)
                                </a>
                                <button type="button" onclick="openExpiryModal(<?= $c['id'] ?>, '<?= htmlspecialchars($c['business_name'], ENT_QUOTES) ?>', '<?= date('Y-m-d', strtotime($c['expired_at'])) ?>')"
                                    class="px-2.5 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-white border border-white/10 backdrop-blur">
                                    📅 Ubah Tanggal
                                </button>
                                <?php if (!$isExp): ?>
                                    <a href="admin.php?expire=<?= $c['id'] ?>" onclick="return confirm('Nonaktifkan akun ini sekarang?')"
                                        class="px-2.5 py-1.5 rounded-lg bg-amber-500/25 text-amber-200 border border-amber-400/40 hover:bg-amber-500/35">
                                        Nonaktifkan
                                    </a>
                                <?php endif; ?>
                                <a href="admin.php?delete_client=<?= $c['id'] ?>" onclick="return confirm('HAPUS PERMANEN akun klien ini beserta seluruh tagihannya?')"
                                    class="px-2.5 py-1.5 rounded-lg bg-red-500/25 text-red-200 border border-red-400/40 hover:bg-red-500/35">
                                    Hapus
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Ubah Tanggal Kedaluwarsa Kustom -->
    <div id="modalExpiry" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-sm p-5">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-bold text-base">Atur Tanggal Kedaluwarsa</h3>
                <button onclick="closeModal('modalExpiry')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <p id="expiryClientName" class="text-xs <?= $theme['text'] ?> font-semibold mb-3"></p>
            <form method="POST" action="admin.php" class="space-y-3">
                <input type="hidden" name="client_id" id="expiryClientId">
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Tanggal Berakhir Layanan</label>
                    <input type="date" name="expired_date" id="expiryDateInput" required class="w-full px-3 py-2.5 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                </div>
                <button type="submit" name="set_custom_expiry" class="w-full py-3 rounded-xl <?= $theme['btn'] ?> font-bold text-sm">Simpan Masa Aktif</button>
            </form>
        </div>
    </div>

    <!-- Modal Tema & Wallpaper Super Admin -->
    <div id="modalAdminTheme" class="fixed inset-0 bg-black/75 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900/90 backdrop-blur-xl border border-white/15 rounded-2xl w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-base">⚙️ Kustomisasi Wallpaper & Tema Admin</h3>
                <button onclick="closeModal('modalAdminTheme')" class="text-slate-400 text-lg">&times;</button>
            </div>
            <form method="POST" action="admin.php" enctype="multipart/form-data" class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Pilih Wallpaper Bawaan / Galeri</label>
                    <select name="bg_theme" class="w-full px-3 py-2 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                        <option value="desk" <?= $activeTheme === 'desk' ? 'selected' : '' ?>>☕ Meja Kerja Minimalis (Bawaan)</option>
                        <option value="forest" <?= $activeTheme === 'forest' ? 'selected' : '' ?>>🌲 Hutan Kabut Tenang</option>
                        <?php if (!empty($admin['custom_bg'])): ?>
                            <option value="custom" <?= $activeTheme === 'custom' ? 'selected' : '' ?>>🖼️ Foto Galeri Saya (Tersimpan)</option>
                        <?php endif; ?>
                        <option value="none" <?= $activeTheme === 'none' ? 'selected' : '' ?>>🌑 Gelap Polos (Tanpa Wallpaper)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Ambil Foto dari Galeri HP / Laptop (Maks 10MB)</label>
                    <input type="file" name="custom_wallpaper" accept="image/jpeg,image/png,image/webp"
                        class="w-full text-xs text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-white/15 file:text-white hover:file:bg-white/25 bg-slate-950/80 border border-white/10 rounded-xl p-1">
                </div>
                <div>
                    <label class="block text-xs text-slate-300 mb-1">Warna Aksen Tema</label>
                    <select name="accent_color" class="w-full px-3 py-2 rounded-xl bg-slate-950/80 border border-white/10 text-sm">
                        <option value="emerald" <?= $activeAccentKey === 'emerald' ? 'selected' : '' ?>>🟢 Hijau Emerald</option>
                        <option value="cyan" <?= $activeAccentKey === 'cyan' ? 'selected' : '' ?>>🔵 Biru Cyan Modern</option>
                        <option value="purple" <?= $activeAccentKey === 'purple' ? 'selected' : '' ?>>🟣 Ungu Soft Elegan</option>
                        <option value="amber" <?= $activeAccentKey === 'amber' ? 'selected' : '' ?>>🟡 Emas Amber Premium</option>
                    </select>
                </div>
                <button type="submit" name="save_admin_theme" class="w-full py-3 rounded-xl <?= $theme['btn'] ?> font-bold text-sm mt-2">Simpan Tampilan Admin</button>
            </form>
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

        function openExpiryModal(id, name, currentDate) {
            document.getElementById('expiryClientId').value = id;
            document.getElementById('expiryClientName').innerText = "Klien: " + name;
            document.getElementById('expiryDateInput').value = currentDate;
            openModal('modalExpiry');
        }
    </script>
</body>

</html>