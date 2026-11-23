/**
 * ============================================================
 *  assets/js/widget-suhu.js
 *  Logika widget pemantauan suhu & kelembaban.
 *  Polling setiap 20 detik ke api/get_latest.php
 * ============================================================
 */
(function () {
    const POLL_MS = 20000;

    const STATUS_COLOR = {
        aman:    '#10b981',
        waspada: '#f59e0b',
        bahaya:  '#ef4444',
        offline: '#64748b',
    };

    const el = {
        widget:   document.getElementById('suhuWidget'),
        pillBtn:  document.getElementById('suhuPillBtn'),
        closeBtn: document.getElementById('suhuCloseBtn'),
        dot:      document.getElementById('suhuDot'),
        suhuVal:      document.getElementById('suhuVal'),
        humidVal:     document.getElementById('humidVal'),
        suhuValBig:   document.getElementById('suhuValBig'),
        humidValBig:  document.getElementById('humidValBig'),
        suhuBadge:    document.getElementById('suhuBadge'),
        humidBadge:   document.getElementById('humidBadge'),
        updated:      document.getElementById('suhuUpdated'),
    };

    if (!el.widget) return; // Widget tidak ada di halaman ini, keluar diam-diam

    // ── Toggle expand/collapse ──────────────────────────────
    function setExpanded(state) {
        el.widget.dataset.state = state ? 'expanded' : 'collapsed';
        el.pillBtn.setAttribute('aria-expanded', String(state));
    }
    el.pillBtn.addEventListener('click', () => {
        const isExpanded = el.widget.dataset.state === 'expanded';
        setExpanded(!isExpanded);
    });
    el.closeBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        setExpanded(false);
    });
    document.addEventListener('click', (e) => {
        if (!el.widget.contains(e.target)) setExpanded(false);
    });

    // ── Render data ke DOM ───────────────────────────────────
    function setDotColor(level) {
        el.dot.style.setProperty('--sw-color', STATUS_COLOR[level] || STATUS_COLOR.offline);
    }

    // lastData opsional: kalau ada (alat PERNAH mengirim, tapi sudah
    // berhenti > OFFLINE_AFTER_DETIK di server), tampilkan jam terakhir
    // online supaya lebih informatif daripada cuma tulisan generik.
    function renderOffline(lastData) {
        setDotColor('offline');
        el.suhuVal.textContent = '--';
        el.humidVal.textContent = '--';
        el.suhuValBig.textContent = '--';
        el.humidValBig.textContent = '--';
        el.suhuBadge.textContent = 'OFFLINE';
        el.suhuBadge.dataset.level = 'offline';
        el.humidBadge.textContent = 'OFFLINE';
        el.humidBadge.dataset.level = 'offline';

        if (lastData && lastData.created_at) {
            const waktu = new Date(lastData.created_at.replace(' ', 'T'));
            el.updated.textContent = 'Terakhir online ' + waktu.toLocaleTimeString('id-ID');
        } else {
            el.updated.textContent = 'Sensor belum pernah mengirim data';
        }
    }

    function renderData(data) {
        const level = data.status_suhu === 'bahaya' || data.status_humid === 'bahaya'
            ? 'bahaya'
            : (data.status_suhu === 'waspada' || data.status_humid === 'waspada' ? 'waspada' : 'aman');

        setDotColor(level);

        const suhu  = parseFloat(data.suhu).toFixed(1);
        const humid = parseFloat(data.kelembaban).toFixed(1);

        el.suhuVal.textContent     = suhu;
        el.humidVal.textContent    = humid;
        el.suhuValBig.textContent  = suhu;
        el.humidValBig.textContent = humid;

        el.suhuBadge.textContent = data.status_suhu.toUpperCase();
        el.suhuBadge.dataset.level = data.status_suhu;
        el.humidBadge.textContent = data.status_humid.toUpperCase();
        el.humidBadge.dataset.level = data.status_humid;

        const waktu = new Date(data.created_at.replace(' ', 'T'));
        el.updated.textContent = 'Update ' + waktu.toLocaleTimeString('id-ID');
    }

    // ── Polling ──────────────────────────────────────────────
    async function pollSuhu() {
        try {
            const res  = await fetch('/api/get_latest.php', { cache: 'no-store' });
            const data = await res.json();

            // Baris live belum pernah ada sama sekali
            if (!data || !data.suhu) {
                renderOffline(null);
                return;
            }
            // Baris live ADA, tapi server bilang sudah basi (ESP32 berhenti
            // kirim > OFFLINE_AFTER_DETIK detik) — lihat config/koneksi.php
            if (data.offline) {
                renderOffline(data);
                return;
            }
            renderData(data);
        } catch (err) {
            renderOffline(null);
            console.warn('[Widget Suhu] Polling gagal:', err.message);
        }
    }

    pollSuhu();
    setInterval(pollSuhu, POLL_MS);
})();