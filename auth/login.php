<?php
require '../config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wa   = formatWA(trim($_POST['whatsapp_number']));
    $pass = $_POST['password'];

    $res = $conn->query("SELECT * FROM users WHERE whatsapp_number = '$wa' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $user = $res->fetch_assoc();
        if (password_verify($pass, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header("Location: " . ($user['role'] === 'admin' ? '../admin.php' : '../dashboard.php'));
            exit;
        } else {
            $error = "Password salah!";
        }
    } else {
        $error = "Nomor WhatsApp tidak ditemukan.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — TPay</title>
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
            background: rgba(15, 23, 42, 0.34);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
    </style>
</head>

<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-5 relative">
    <!-- Lapisan Wallpaper Konsisten -->
    <div class="responsive-wallpaper" style="background-image: url('../assets/download (1).jpg');"></div>
    <div class="fixed inset-0 -z-10 bg-slate-950/35"></div>

    <div class="w-full max-w-sm glass-card rounded-2xl p-6">
        <a href="../index.php" class="text-xs text-emerald-300 hover:underline drop-shadow">&larr; Kembali</a>
        <h2 class="text-xl font-bold mt-2 mb-1 text-white drop-shadow">Selamat Datang</h2>
        <p class="text-xs text-slate-200 mb-5">Masuk untuk kelola jadwal tagihan otomatis.</p>

        <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-500/25 backdrop-blur border border-red-400/40 text-red-100 text-xs"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-xs text-slate-200 mb-1">Nomor WhatsApp</label>
                <input type="tel" name="whatsapp_number" required placeholder="08123456789"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950/65 border border-white/15 text-sm text-white placeholder-slate-400 focus:border-emerald-400 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs text-slate-200 mb-1">Password</label>
                <input type="password" name="password" required placeholder="••••••••"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950/65 border border-white/15 text-sm text-white placeholder-slate-400 focus:border-emerald-400 focus:outline-none">
            </div>
            <button type="submit" class="w-full py-3 rounded-xl bg-emerald-500/90 hover:bg-emerald-400 text-slate-950 font-bold text-sm shadow-lg shadow-emerald-500/20 transition">
                Masuk ke Dashboard
            </button>
        </form>

        <p class="text-center text-xs text-slate-200 mt-5">
            Belum daftar? <a href="register.php" class="text-emerald-300 font-semibold hover:underline">Coba gratis 7 hari</a>
        </p>
    </div>
</body>

</html>