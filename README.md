# MKM Scrap Delivery

Laravel 12 untuk Surat Pengeluaran Barang internal MKM. Layout terang mengikuti mkm_testbench: kartu putih, aksen teal #00677f, topbar desktop dan menu mobile. Frontend Tailwind CSS 4 + Vite. Workspace compact maksimal 1.280 px, kontrol desktop sekitar 32 px dan mobile 40 px, padding rapat, serta master role berbentuk tabel.

## Mulai lokal

Aktifkan MySQL XAMPP, lalu:

```powershell
composer install
# Komputer baru: salin .env.example ke .env dan sesuaikan database.
php artisan key:generate
php artisan migrate --seed
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Jangan menjalankan key:generate ulang pada instalasi yang sudah memiliki APP_KEY. Login: http://localhost/mkm_scrap_delivery/public/MKM@dm1n. Database lokal `mkm_scrap_delivery`, timezone Asia/Jakarta. Apache harus diarahkan ke direktori `public`.

## Seeder dan akun login

`DatabaseSeeder` menjalankan RoleSeeder, ApprovalFlowSeeder, ExistingDocumentApprovalSeeder, dan LocalAccountSeeder. Untuk menjalankan ulang: `php artisan db:seed`.

Seeder tidak menimpa password akun yang sudah ada, perubahan role/permission, atau master flow yang telah dikonfigurasi. Dokumen lama tanpa snapshot dibackfill ke flow awal, tanpa mengubah statusnya.

Akun uji dibuat hanya jika APP_ENV=local dan LOCAL_DEMO_ACCOUNTS=true. Password awal berasal dari LOCAL_DEMO_PASSWORD di .env lokal. Semua akun uji wajib mengganti password pada login pertama.

| Username lokal | Role awal |
| --- | --- |
| admin.scrap | Administrator |
| ppc.scrap | PPC Receiving |
| proc.staff | Staff Procurement |
| proc.dh | Dept. Head Procurement |
| proc.sh | Section Head Procurement |
| hrd.scrap | Dept. Head HRD |
| security.scrap | Security |
| viewer.scrap | Viewer |

Di environment lain, seeder tidak membuat akun uji walaupun flag diaktifkan. Untuk administrator pertama pada instalasi tanpa akun admin: `php artisan app:create-admin`. Command tidak menimpa akun admin lama dan menyimpan kredensial sementara pada `storage/app/private/bootstrap-admin.txt` (diabaikan Git).

## Role, autentikasi, dan SSO

Master Role menyimpan kode, nama, status, dan permission di database. Permission menentukan akses membuat dokumen, mengelola user, role, atau approval. Hak approval mengikuti penugasan flow, bukan permission admin umum. Seluruh user internal aktif dapat membaca dokumen.

Users mendukung pembuatan akun, pengaturan role/status, reset password, serta identitas dan metode login SSO. Login lokal memiliki throttle; akun/role nonaktif ditolak. Perubahan identitas SSO atau metode login mencabut sesi lama. Reset password mewajibkan ganti password pada login berikutnya.

SSO Microsoft Entra/Azure lokal sudah diaktifkan memakai konfigurasi dari mkm_qualityportal. Callback tetap menuju Scrap Delivery; login tenant langsung belum diverifikasi. Panduan lengkap: [docs/SSO.md](docs/SSO.md). Login lokal tetap tersedia sesuai pengaturan akun. New SSO users are provisioned with restricted access and must request a role at /access. An administrator reviews /access-requests before application access is enabled. Existing email collisions are not automatically linked; Microsoft profile data never grants administrator access.

## Master Approval dinamis

Buka menu **Approval** dengan akun admin. Flow awal Monthly Scrap disediakan dari MOM 15 Oktober 2026. Administrator dapat:

- Membuat flow, menambah/mengubah/menghapus tahap draft.
- Memilih approver berdasarkan role, user tertentu (salah satu dapat bertindak), atau pembuat dokumen.
- Menentukan tahap awal, tujuan tindakan, jalur Delegate, tombol tindakan, dan hak mengembalikan ke draft.
- Menentukan penerima notifikasi draft, mengetahui di tiap tahap, dan notifikasi tambahan Delegate.
- Publish dan mengaktifkan versi baru setelah seluruh jalur tervalidasi.

**Simpan draft master** tidak langsung mengubah versi published. **Publish** mengaktifkan flow tersebut untuk pengajuan pertama dokumen berikutnya. Dokumen yang sudah diajukan menyimpan snapshot lengkap dan versi flow. Pengembalian/revisi dokumen mempertahankan versi itu, sehingga perubahan master tidak mengalihkan approver atau mengubah riwayatnya.

Validasi publish menolak tujuan hilang, putaran, tahap tidak terhubung, role/user tidak aktif, dan jalur barang keluar yang melewati bukti serah terima. Kode tahap tetap; ubah nama tampilannya bila perlu. Tambahkan semua tahap dahulu, atur hubungan antar tahap dan tahap awal, lalu publish.

## Default Monthly Scrap

Referensi kebutuhan: MOM Digitalisasi Surat Jalan 15 Oct 2026.pdf yang diberikan user. Dokumen ini menjadi referensi kebutuhan aplikasi.

1. PPC menyimpan SMB dengan nomor unik. Security dan Staff Procurement menerima notifikasi draft serta pembawa barang.
2. PPC mengajukan ke Staff Procurement.
3. Staff memilih Next approval ke DH Procurement, atau Delegate & setujui dengan alasan wajib. Interpretasi awal Delegate: staff bertindak sebagai pelaksana delegasi lalu melanjutkan ke HRD; DH dan SH Procurement menerima notifikasi mengetahui.
4. HRD melakukan approval.
5. PPC mencatat penerima dan mengunggah bukti yang sudah ditandatangani pembawa serta penerima (PDF/JPG/PNG, maksimal 5 MB). PPC tidak dianggap menandatangani atas nama pihak lain.
6. Security memeriksa dan mencatat persetujuan barang keluar.

Semua tindakan mencatat user, role saat bertindak, waktu, label tindakan/status, catatan, dan perpindahan status. Draft hanya dapat diedit pembuat yang punya permission. Approval memakai transaksi dan row lock. Bukti aktif dibatalkan jika dikembalikan ke draft; bukti historis tetap dapat diunduh dari riwayat oleh user internal terautentikasi. Bukti disimpan privat.

Menu Tugas Saya mengikuti snapshot penugasan. Dashboard dan daftar menggunakan filter yang sama, dengan Total = Open + Close. Notifikasi berada di aplikasi/database; belum menggunakan email, WhatsApp, atau push. Cetak menggunakan browser, bukan template surat resmi yang disahkan.

## Disposal

Disposal masih berupa draft SPB dengan referensi workflow existing. Proses approval sampai Presiden Direktur, bukti settled, price confirmation, serta persetujuan PPC/GA/Sales belum diintegrasikan. Draft disposal tidak dapat masuk flow scrap atau melepas barang.

## Validasi

```powershell
php artisan test
npm run build
php artisan view:cache
php artisan migrate:status
git diff --check
```

Tests menggunakan SQLite in-memory, tidak menghapus database operasional. Pengujian SSO memakai provider asli dengan respons token/Graph yang disimulasikan; tidak membuktikan koneksi tenant MKM. Pemeriksaan HTTP lokal berbeda dari pemeriksaan visual HP dan bukti deployment production.

## Login utama dan administrator yang diminta

Root aplikasi memulai SSO; login lokal GET/POST tersedia di `/MKM@dm1n`. `/login` mengarah ke SSO. Callback Azure tetap `/auth/azure/callback`; perubahan halaman login tidak mengubah redirect URI yang didaftarkan di Azure.

`RequestedAdminSeeder` memprovisikan email yang ditetapkan melalui SSO_BOOTSTRAP_ADMIN_EMAIL sebagai admin. Pada setup lokal, email yang diminta adalah mkm02120@ptmkm.co.id. Jika belum memiliki Object ID, penautan satu kali diizinkan hanya untuk akun yang secara eksplisit ditandai pending, tenant yang dikonfigurasi, dan userPrincipalName yang persis sesuai dari Microsoft Graph setelah autentikasi Azure. Sesudah itu, login memakai pasangan Tenant ID dan Object ID. Akun lain tidak ditautkan otomatis berdasarkan email. Password akun existing tidak diubah. Akun admin baru ini tidak menggunakan password demo; akses utamanya SSO.


## MKM document forms

Goods Release uses a structured SMB-style form with up to 50 item rows (code, goods name, quantity/unit, pallet, posts, remarks). Additional fields cover the identity card, lot, department/shop, loader, official delivery-note reference, attachments indication, and sales/release-letter details. The existing description-only records remain printable and are not rewritten.

Each document provides three print previews: SMB, Surat Jalan Non Produksi (LD-GA-0020 reference layout), and Pengeluaran Barang. SMB and delivery notes print A4 landscape; correspondence prints A4 portrait. Original Indonesian official document captions are retained to match the supplied forms; application controls remain English. Screenshots' annotations, handwritten signatures and security stamps are not copied. Non-completed records show a draft warning; approval names/times come only from recorded workflow events. Carrier/recipient signature spaces remain for actual signing and handover evidence. The delivery note number is entered separately from the internal SMB identifier; preview generation does not assign an official number or approve goods release.


Delegated Procurement approvals print the actual Staff approver, timestamp and a delegation label in the delivery-note and release-letter Procurement spaces. The reason is shown as a short excerpt; the complete reason remains in document history. Returned submission cycles do not contribute signatures to the current printed form. Workflow recipients receive immediate application notifications and queued SMTP emails; the worker drains `access-mail,workflow-mail`.


## Security Gate (no SSO)

Security officers use `/security` on a browser registered by an administrator. Administration > Security access (`/masters/security`) manages one-time browser activation, officer names, personal six-digit PIN resets and revocation. Only registered browsers can read Security documents; a valid officer PIN and completed physical-check confirmations are required to approve release. The normal workflow, handover-evidence check and stale-document protection remain enforced. Every release records officer and post snapshots. See [docs/SECURITY_GATE.md](docs/SECURITY_GATE.md) for setup and operations.


## Two installable PWAs

Open `/app/install` for **MKM Scrap Delivery** or `/security/install` for **MKM Security Gate**. Separate manifests, IDs, PNG icons and sibling worker scopes prevent general-app navigation from enclosing the Security app. Existing links and the Azure callback remain supported; generated main links now use `/app/...`. Install Security first, then activate it inside the installed app. Transactions require online server validation. See [docs/PWA.md](docs/PWA.md) for HTTPS, shared-origin storage and deployment verification.
