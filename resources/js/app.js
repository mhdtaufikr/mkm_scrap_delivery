import './bootstrap';

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(visible));
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelectorAll('details[open]').forEach((menu) => menu.removeAttribute('open'));
});
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
});
document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
document.querySelectorAll('form[data-submit-once]').forEach((form) => {
    form.addEventListener('submit', () => {
        // Keep the clicked submit button's action value in the form payload.
        setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((button) => button.disabled = true), 0);
    });
});

document.querySelectorAll('[data-copy-value]').forEach((button) => {
    button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(button.dataset.copyValue);
        const label = button.textContent;
        button.textContent = 'Link copied';
        setTimeout(() => button.textContent = label, 1800);
    });
});

document.querySelectorAll('[data-driver-signature-watch]').forEach((panel) => {
    let checking = false;
    const check = async () => {
        if (checking || document.hidden) return;
        checking = true;
        try {
            const response = await fetch(panel.dataset.driverSignatureWatch, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (response.status === 403 || response.status === 404) {
                window.location.reload();
                return;
            }
            if (!response.ok) return;
            const status = await response.json();
            if (status.signed) {
                panel.querySelector('[data-driver-signature-state]').textContent = 'Signature received';
                panel.querySelector('[data-driver-signature-waiting]').textContent = 'Driver signature received. Loading verification…';
                window.location.reload();
            }
        } finally {
            checking = false;
        }
    };
    const timer = window.setInterval(check, 4000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) check();
    });
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
});

document.querySelectorAll('[data-security-lookup]').forEach((form) => {
    const startButton = form.querySelector('[data-security-scan]');
    const stopButton = form.querySelector('[data-security-scan-stop]');
    const scanner = form.querySelector('[data-security-scanner]');
    const video = scanner.querySelector('video');
    const status = form.querySelector('[data-security-scan-status]');
    const codeInput = form.querySelector('[name="security_code"]');
    let stream;
    let scanning = false;
    const stop = () => {
        scanning = false;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
        scanner.hidden = true;
        startButton.hidden = false;
    };
    const scanFrame = async (detector) => {
        if (!scanning) return;
        try {
            const codes = await detector.detect(video);
            const value = codes[0]?.rawValue?.trim();
            if (value) {
                const match = value.match(/^(?:MKM[-\s]*SMB\s*:\s*)?([A-Z0-9]{4}-?[A-Z0-9]{4}-?[A-Z0-9]{4})$/i);
                if (match) {
                    codeInput.value = match[1].toUpperCase();
                    status.textContent = 'QR berhasil dibaca. Membuka dokumen…';
                    stop();
                    form.requestSubmit();
                    return;
                }
                status.textContent = 'QR ini bukan QR Security MKM yang valid.';
            }
        } catch {
            status.textContent = 'QR belum terbaca. Tahan kamera agar tidak bergerak.';
        }
        window.setTimeout(() => scanFrame(detector), 300);
    };
    startButton.addEventListener('click', async () => {
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            status.textContent = 'Browser ini tidak mendukung pemindaian QR. Masukkan nomor unik secara manual.';
            scanner.hidden = false;
            scanner.querySelector('video').hidden = true;
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            video.srcObject = stream;
            await video.play();
            scanner.hidden = false;
            video.hidden = false;
            startButton.hidden = true;
            scanning = true;
            scanFrame(new BarcodeDetector({ formats: ['qr_code'] }));
        } catch {
            status.textContent = 'Kamera tidak dapat digunakan. Masukkan nomor unik secara manual.';
            scanner.hidden = false;
        }
    });
    stopButton.addEventListener('click', stop);
    window.addEventListener('pagehide', stop, { once: true });
});

document.querySelectorAll('[data-security-dashboard]').forEach((dashboard) => {
    const content = dashboard.querySelector('[data-security-dashboard-content]');
    const state = dashboard.querySelector('[data-security-dashboard-state]');
    const refreshedAt = dashboard.querySelector('[data-security-dashboard-time]');
    let loading = false;
    const refresh = async () => {
        if (loading || document.hidden) return;
        loading = true;
        try {
            const response = await fetch(dashboard.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) throw new Error('Dashboard refresh failed');
            const data = await response.json();
            const pendingOpen = content.querySelector('[data-security-pending]')?.open;
            content.innerHTML = data.html;
            const pendingPanel = content.querySelector('[data-security-pending]');
            if (pendingPanel && pendingOpen) pendingPanel.open = true;
            refreshedAt.textContent = data.refreshed_at;
            state.textContent = 'Aktif';
            state.classList.remove('bg-amber-400/30');
        } catch {
            state.textContent = 'Tidak terhubung';
            state.classList.add('bg-amber-400/30');
        } finally {
            loading = false;
        }
    };
    const timer = window.setInterval(refresh, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
});

document.querySelectorAll('[data-security-qr-fullscreen]').forEach((button) => {
    const dialog = button.parentElement.querySelector('[data-security-qr-dialog]');
    const close = dialog?.querySelector('[data-security-qr-close]');
    if (!dialog) return;
    button.addEventListener('click', () => dialog.showModal());
    close?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
});

document.querySelectorAll('[data-security-signature-watch]').forEach((panel) => {
    let loading = false;
    const check = async () => {
        if (loading || document.hidden) return;
        loading = true;
        try {
            const response = await fetch(panel.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await response.json();
            if (data.signed || data.status !== 'DRIVER_HANDOVER') window.location.reload();
        } finally {
            loading = false;
        }
    };
    const timer = window.setInterval(check, 4000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
});

document.querySelectorAll('[data-driver-signature]').forEach((form) => {
    const canvas = form.querySelector('[data-signature-canvas]');
    const input = form.querySelector('[data-signature-input]');
    const context = canvas.getContext('2d');
    let drawing = false;
    let signed = false;
    const resize = () => {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.lineWidth = 2.5;
        context.strokeStyle = '#0f172a';
    };
    const point = (event) => {
        const rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };
    canvas.addEventListener('pointerdown', (event) => {
        drawing = true;
        signed = true;
        canvas.setPointerCapture(event.pointerId);
        const position = point(event);
        context.beginPath();
        context.moveTo(position.x, position.y);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) return;
        const position = point(event);
        context.lineTo(position.x, position.y);
        context.stroke();
    });
    canvas.addEventListener('pointerup', () => drawing = false);
    form.querySelector('[data-signature-clear]').addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        signed = false;
        input.value = '';
    });
    form.addEventListener('submit', (event) => {
        if (!signed) {
            event.preventDefault();
            alert('Silakan isi tanda tangan pengemudi.');
            return;
        }
        input.value = canvas.toDataURL('image/png');
    });
    resize();
});

// Only show controls relevant to the selected assignment method in the approval editor.
// Without JavaScript, every field remains visible and the server still validates the selected mode.
document.querySelectorAll('[data-approval-step]').forEach((form) => {
    const mode = form.querySelector('[name="actor_mode"]');
    const update = () => {
        form.querySelectorAll('[data-actor-fields]').forEach((group) => {
            const visible = group.dataset.actorFields === mode.value;
            group.hidden = !visible;
            group.querySelectorAll('input, select').forEach((input) => input.disabled = !visible);
        });
    };
    mode.addEventListener('change', update);
    update();
});


document.querySelectorAll('[data-goods-form]').forEach((form) => {
    const rows = form.querySelector('[data-goods-rows]');
    const template = form.querySelector('[data-goods-template]');
    const add = form.querySelector('[data-add-goods]');
    const pasteToggle = form.querySelector('[data-toggle-goods-paste]');
    const pastePanel = form.querySelector('[data-goods-paste-panel]');
    const pasteInput = form.querySelector('[data-goods-paste]');
    const pasteStatus = form.querySelector('[data-goods-paste-status]');
    const fields = ['code', 'name', 'quantity', 'unit', 'pallet', 'posts', 'remarks'];
    const renumber = () => {
        rows.querySelectorAll('[data-goods-row]').forEach((row, index) => {
            row.querySelector('[data-row-number]').textContent = index + 1;
            row.querySelectorAll('input').forEach((input) => {
                input.name = input.name.replace(/items\[[^\]]+\]/, `items[${index}]`);
                const field = input.name.match(/\[([^\]]+)\]$/)[1];
                const labels = { code: 'Kode', name: 'Nama barang', quantity: 'Jumlah', unit: 'Satuan', pallet: 'Palet', posts: 'Pos', remarks: 'Keterangan' };
                input.setAttribute('aria-label', `${labels[field] || field} barang ${index + 1}`);
            });
            row.querySelector('[data-remove-goods]').disabled = rows.children.length <= 1;
        });
        add.disabled = rows.children.length >= 50;
    };
    add.addEventListener('click', () => {
        if (rows.children.length >= 50) return;
        rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', rows.children.length));
        renumber();
        rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-goods]');
        if (!button || rows.children.length <= 1) return;
        button.closest('[data-goods-row]').remove();
        renumber();
    });
    const normalizeHeader = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, '');
    const headerFields = {
        no: null, number: null, code: 'code', kode: 'code', goodsdescription: 'name', description: 'name',
        namabarang: 'name', item: 'name', quantity: 'quantity', qty: 'quantity', jumlah: 'quantity',
        unit: 'unit', satuan: 'unit', pallet: 'pallet', palet: 'pallet', posts: 'posts', post: 'posts',
        tiang: 'posts', remarks: 'remarks', remark: 'remarks', catatan: 'remarks', keterangan: 'remarks',
    };
    const parseNumber = (value) => {
        const compact = value.trim().replace(/\s/g, '');
        if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(compact)) return compact.replaceAll(',', '');
        return compact.replace(',', '.');
    };
    const importPastedRows = () => {
        const matrix = pasteInput.value.replace(/\r/g, '').split('\n')
            .map((line) => line.split('\t').map((cell) => cell.trim()))
            .filter((cells) => cells.some(Boolean));
        if (!matrix.length) {
            pasteStatus.textContent = 'Tempel minimal satu baris dari Excel.';
            pasteInput.focus();
            return;
        }
        const normalized = matrix[0].map(normalizeHeader);
        const hasHeader = normalized.some((header) => Object.hasOwn(headerFields, header));
        const columnMap = {};
        if (hasHeader) {
            normalized.forEach((header, index) => {
                const field = headerFields[header];
                if (field) columnMap[index] = field;
            });
            matrix.shift();
        } else {
            const offset = matrix[0].length >= 8 && /^\d+$/.test(matrix[0][0]) ? 1 : 0;
            fields.forEach((field, index) => columnMap[index + offset] = field);
        }
        const imported = matrix.slice(0, 50).map((cells) => {
            const item = {};
            Object.entries(columnMap).forEach(([index, field]) => item[field] = cells[index] ?? '');
            item.quantity = parseNumber(item.quantity ?? '');
            return item;
        }).filter((item) => fields.some((field) => item[field]));
        if (!imported.length || imported.some((item) => !item.name || !item.quantity || !item.unit)) {
            pasteStatus.textContent = 'Periksa kolom Nama barang, Jumlah, dan Satuan.';
            return;
        }
        rows.innerHTML = '';
        imported.forEach((item, index) => {
            rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
            const row = rows.lastElementChild;
            fields.forEach((field) => {
                const input = row.querySelector(`[name$="[${field}]"]`);
                if (input) input.value = item[field] ?? '';
            });
        });
        renumber();
        pasteStatus.textContent = `${imported.length} baris barang berhasil diimpor.`;
        pastePanel.hidden = true;
    };
    pasteToggle?.addEventListener('click', () => {
        pastePanel.hidden = false;
        pasteInput.focus();
    });
    form.querySelector('[data-close-goods-paste]')?.addEventListener('click', () => pastePanel.hidden = true);
    form.querySelector('[data-import-goods]')?.addEventListener('click', importPastedRows);
    renumber();
});

document.querySelectorAll('[data-goods-form]').forEach((form) => {
    const updateDifference = () => {
        const net = Number(form.querySelector('[data-net-weight]')?.value || 0);
        const gross = Number(form.querySelector('[data-gross-weight]')?.value || 0);
        form.querySelectorAll('[data-weight-difference]').forEach((output) => output.value = Math.max(0, gross - net).toFixed(2));
    };
    form.addEventListener('input', updateDifference);
    updateDifference();
});


// Keep inactive type fields out of browser validation and submitted form data.
document.querySelectorAll('[data-goods-form]').forEach((form) => {
    const type = form.querySelector('#type');
    const syncType = () => {
        form.querySelectorAll('[data-type-panel]').forEach((panel) => {
            panel.hidden = !panel.dataset.typePanel.split(',').includes(type.value);
            panel.disabled = panel.hidden;
        });
        form.querySelectorAll('[data-type-field]').forEach((group) => {
            group.hidden = !group.dataset.typeField.split(',').includes(type.value);
            group.querySelectorAll('input, select, textarea').forEach((input) => input.disabled = group.hidden);
        });
        form.querySelector('[data-type-hint]').textContent = type.value === 'DISPOSAL'
            ? 'Disposal mengikuti alur persetujuan yang sama dengan Scrap: Procurement, HRD, serah-terima pengemudi, lalu Security.'
            : 'Scrap mengikuti alur persetujuan Procurement, HRD, serah-terima pengemudi, lalu Security.';
    };
    type.addEventListener('change', syncType);
    syncType();
});

import './pwa';
