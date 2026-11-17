const express = require('express');
const cors = require('cors');
const pino = require('pino');
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const http = require('http');
const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    Browsers
} = require('@whiskeysockets/baileys');

const app = express();
app.use(cors());
app.use(express.json());

const PORT = 3000;
const sessions = new Map();

const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'tpay_db'
};

async function updateDBStatus(userId, status) {
    try {
        const conn = await mysql.createConnection(dbConfig);
        await conn.execute('UPDATE users SET wa_session_status = ? WHERE id = ?', [status, userId]);
        await conn.end();
    } catch (err) {
        console.error(`[DB Error User ${userId}]:`, err.message);
    }
}

async function startSession(userId, phoneNumber = null, resPairing = null) {
    const sessionDir = path.join(__dirname, 'sessions', `user_${userId}`);
    if (!fs.existsSync(sessionDir)) {
        fs.mkdirSync(sessionDir, { recursive: true });
    }

    const { state, saveCreds } = await useMultiFileAuthState(sessionDir);
    const { version } = await fetchLatestBaileysVersion();
    const logger = pino({ level: 'silent' });

    const sock = makeWASocket({
        version,
        logger,
        printQRInTerminal: false,
        auth: {
            creds: state.creds,
            keys: makeCacheableSignalKeyStore(state.keys, logger),
        },
        browser: Browsers.ubuntu('Chrome'),
        syncFullHistory: false
    });

    sessions.set(String(userId), sock);

    if (phoneNumber && !sock.authState.creds.registered) {
        setTimeout(async () => {
            try {
                const cleanPhone = phoneNumber.replace(/[^0-9]/g, '');
                const code = await sock.requestPairingCode(cleanPhone);
                const formattedCode = code?.match(/.{1,4}/g)?.join('-') || code;
                console.log(`[User ${userId}] Pairing Code: ${formattedCode}`);
                if (resPairing && !resPairing.headersSent) {
                    resPairing.json({ status: 'pairing', code: formattedCode });
                }
            } catch (err) {
                console.error(`[Pairing Error User ${userId}]:`, err.message);
                if (resPairing && !resPairing.headersSent) {
                    resPairing.status(500).json({ status: 'error', message: 'Gagal membuat kode pairing.' });
                }
            }
        }, 4000);
    }

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect } = update;

        if (connection === 'open') {
            console.log(`[User ${userId}] WhatsApp TERHUBUNG!`);
            await updateDBStatus(userId, 'connected');
        } else if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            console.log(`[User ${userId}] Terputus (Code: ${statusCode}). Reconnect: ${shouldReconnect}`);
            await updateDBStatus(userId, 'disconnected');
            sessions.delete(String(userId));

            if (shouldReconnect) {
                setTimeout(() => startSession(userId), 4000);
            } else {
                fs.rmSync(sessionDir, { recursive: true, force: true });
            }
        }
    });

    return sock;
}

app.post('/api/pair', async (req, res) => {
    const { user_id, phone } = req.body;
    if (!user_id || !phone) {
        return res.status(400).json({ status: 'error', message: 'user_id dan phone wajib diisi' });
    }

    const oldSock = sessions.get(String(user_id));
    if (oldSock) {
        try { oldSock.end(); } catch (e) {}
        sessions.delete(String(user_id));
    }
    const sessionDir = path.join(__dirname, 'sessions', `user_${user_id}`);
    if (fs.existsSync(sessionDir)) {
        fs.rmSync(sessionDir, { recursive: true, force: true });
    }

    await updateDBStatus(user_id, 'connecting');
    await startSession(user_id, phone, res);
});

app.get('/api/status/:user_id', (req, res) => {
    const sock = sessions.get(String(req.params.user_id));
    const isConnected = !!(sock && sock.user);
    res.json({ connected: isConnected });
});

app.post('/api/send', async (req, res) => {
    const { user_id, target, message } = req.body;
    const sock = sessions.get(String(user_id));

    if (!sock || !sock.user) {
        return res.status(400).json({ status: 'error', message: 'WhatsApp belum terhubung' });
    }

    try {
        const jid = target.replace(/[^0-9]/g, '') + '@s.whatsapp.net';
        await sock.sendMessage(jid, { text: message });
        res.json({ status: 'success', message: 'Pesan terkirim' });
    } catch (err) {
        res.status(500).json({ status: 'error', message: err.message });
    }
});

// =========================================================================
// AUTO-SCHEDULER OTOMATIS (Menjalankan cron_tagihan.php setiap 60 detik)
// =========================================================================
function triggerAutoCron() {
    http.get('http://localhost/TPay/cron_tagihan.php', (res) => {
        let body = '';
        res.on('data', chunk => body += chunk);
        res.on('end', () => {
            if (body.includes('Total pesan terkirim:') && !body.endsWith('0')) {
                console.log(`[AUTO-CRON] ${body.trim()}`);
            }
        });
    }).on('error', (err) => {
        console.error('[AUTO-CRON Error]:', err.message);
    });
}

async function restoreAllSessions() {
    const sessionsBase = path.join(__dirname, 'sessions');
    if (!fs.existsSync(sessionsBase)) return;
    const folders = fs.readdirSync(sessionsBase);
    for (const folder of folders) {
        if (folder.startsWith('user_')) {
            const uid = folder.replace('user_', '');
            console.log(`Memulihkan sesi WhatsApp User ID: ${uid}...`);
            await startSession(uid);
        }
    }
}

app.listen(PORT, () => {
    console.log(`🚀 TPay WA Engine berjalan di http://localhost:${PORT}`);
    console.log(`⏰ Auto-Reminder Scheduler aktif (cek tagihan setiap 60 detik)...`);
    restoreAllSessions();

    // Jalankan pengecekan 10 detik setelah server nyala, lalu ulangi tiap 60 detik
    setTimeout(triggerAutoCron, 10000);
    setInterval(triggerAutoCron, 60000);
});