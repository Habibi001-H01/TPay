<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TPay (TempoPay) — Pengingat Tagihan WhatsApp Otomatis</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f172a">
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
            background: rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
    </style>
</head>

<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between relative">
    <!-- Lapisan Wallpaper Konsisten -->
    <div class="responsive-wallpaper" style="background-image: url('assets/download (1).jpg');"></div>
    <div class="fixed inset-0 -z-10 bg-slate-950/35"></div>

    <!-- Navbar (Glass) -->
    <header class="glass-bar border-b border-white/15 sticky top-0 z-20">
        <div class="w-full max-w-md mx-auto px-5 py-4 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/90 flex items-center justify-center font-extrabold text-slate-950 shadow-lg shadow-emerald-500/20">T</div>
                <span class="font-bold text-lg tracking-tight text-white drop-shadow">TPay <span class="text-xs font-normal text-emerald-300">TempoPay</span></span>
            </div>
            <a href="auth/login.php" class="text-sm font-semibold text-white bg-white/15 hover:bg-white/25 border border-white/20 px-4 py-2 rounded-xl backdrop-blur transition">Masuk</a>
        </div>
    </header>

    <!-- Hero Section -->
    <main class="w-full max-w-md mx-auto px-5 py-8 text-center">
        <div class="inline-block px-3.5 py-1.5 rounded-full glass-card text-emerald-300 text-xs font-semibold mb-4">
            ✨ Otomatisasi Tagihan UMKM, Bimbel & Kos
        </div>
        <h1 class="text-3xl font-extrabold leading-tight mb-4 text-white drop-shadow-md">
            Tagih Jatuh Tempo Otomatis via WA <span class="text-emerald-400">Tanpa Rasa Sungkan.</span>
        </h1>
        <p class="text-slate-200 text-sm leading-relaxed mb-8 drop-shadow">
            Nggak perlu lagi capek menagih manual satu per satu. TPay otomatis mengirim pesan pengingat sopan pada H-3, Hari-H, dan H+2 langsung dari nomor WhatsApp usahamu.
        </p>

        <div class="space-y-3">
            <a href="auth/register.php" class="block w-full py-3.5 rounded-xl bg-emerald-500/90 hover:bg-emerald-400 text-slate-950 font-bold text-sm shadow-lg shadow-emerald-500/25 backdrop-blur transition">
                Coba Gratis 7 Hari Sekarang 🚀
            </a>
            <a href="auth/login.php" class="block w-full py-3.5 rounded-xl glass-card hover:bg-white/15 text-white font-semibold text-sm transition">
                Sudah Punya Akun? Login
            </a>
        </div>

        <!-- Fitur Ringkas (Ultra Glass Cards) -->
        <div class="grid grid-cols-2 gap-3 mt-10 text-left">
            <div class="p-4 rounded-2xl glass-card">
                <div class="text-emerald-300 font-bold text-sm mb-1 drop-shadow">100% dari HP</div>
                <p class="text-xs text-slate-200 leading-relaxed">Tautkan WA pakai kode 8 digit tanpa perlu scan QR.</p>
            </div>
            <div class="p-4 rounded-2xl glass-card">
                <div class="text-emerald-300 font-bold text-sm mb-1 drop-shadow">Langsung Cair</div>
                <p class="text-xs text-slate-200 leading-relaxed">Transfer langsung masuk ke rekening/QRIS milikmu sendiri.</p>
            </div>
        </div>
    </main>

    <footer class="w-full max-w-md mx-auto px-5 py-6 text-center text-xs text-slate-300 drop-shadow">
        &copy; <?= date('Y') ?> TPay (TempoPay). Dibuat untuk efisiensi arus kas bisnis.
    </footer>
</body>

</html>