# Setup Microsoft Entra / Azure SSO

Implementasi menggunakan Laravel Socialite dan socialiteproviders/microsoft-azure, mengikuti pola penyedia SSO MKM. Route redirect dan callback telah tersedia. Konfigurasi Azure pada setup lokal diambil dari .env mkm_qualityportal sesuai instruksi user. SSO_ENABLED sudah diaktifkan. Callback tetap menuju Scrap Delivery; login end-to-end dan pendaftaran redirect URI di Entra masih perlu diverifikasi.

## URL lokal Apache

Login: http://localhost/mkm_scrap_delivery/public/MKM@dm1n. Redirect URI yang harus ditambahkan pada platform Web di App Registration: `http://localhost/mkm_scrap_delivery/public/auth/azure/callback`. APP_URL dan callback lokal disamakan dengan host/path Apache agar cookie session state kembali ke aplikasi yang sama.

## Konfigurasi tenant

Pada Microsoft Entra, siapkan App Registration untuk tenant internal MKM (single tenant), platform Web, dan redirect URI persis seperti URL callback aplikasi. Provider menggunakan delegated Microsoft Graph User.Read untuk mengambil identitas user.

Isi konfigurasi lokal/deployment melalui environment, bukan kode atau halaman master:

```dotenv
SSO_ENABLED=true
AZURE_AD_TENANT_ID=<Directory-Tenant-ID-UUID>
AZURE_AD_CLIENT_ID=<Application-Client-ID>
AZURE_AD_CLIENT_SECRET=<Client-Secret-Value>
AZURE_AD_REDIRECT_URI=http://localhost/mkm_scrap_delivery/public/auth/azure/callback
```

Ganti callback dengan URL HTTPS aplikasi saat deployment. Jangan gunakan tenant common atau organizations; aplikasi memerlukan Tenant ID UUID yang spesifik. Setelah mengubah environment, jalankan `php artisan config:clear` (atau bangun ulang config cache pada deployment).

Dokumentasi provider: https://socialiteproviders.com/Microsoft-Azure/

## Berikan akses user

1. Buat akun internal melalui menu Users dan tetapkan role aplikasinya.
2. Dari direktori Microsoft Entra, ambil **Object ID user**, bukan Client ID aplikasi.
3. Di Users → Pengaturan SSO, isi Tenant ID dan Object ID user, lalu simpan.
4. Pilih login lokal + SSO atau hanya SSO. Mode hanya SSO membutuhkan kedua ID tersebut. Perubahan mencabut sesi dan remember token lama.
5. Mulai login melalui tombol Masuk dengan akun MKM (SSO) setelah konfigurasi lengkap.

Akun uji @scrap.local bukan identitas Microsoft nyata. New SSO identities enter the access request workflow below. Inactive users and roles remain blocked. Password awal lokal wajib diganti hanya ketika menggunakan login lokal; sesi SSO mengikuti autentikasi Microsoft. Password SSO dikelola melalui Microsoft.

Identitas dipetakan menggunakan pasangan Tenant ID dan Object ID; a new verified Graph principal may provision a restricted account; existing email collisions are never automatically linked. Nama lengkap, jabatan, department, dan foto profil disinkronkan dari Microsoft setiap login SSO. Email login, role, permission, dan password aplikasi tidak ditimpa dari profil Microsoft. Penjelasan identifier Microsoft: https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference

## Mekanisme callback

- OAuth authorization-code dengan session state. Callback yang state-nya salah/hilang ditolak sebelum pertukaran token.
- Pertukaran code diarahkan ke tenant spesifik, lalu provider mengambil identity ID dari Microsoft Graph melalui access token. Aplikasi tidak mengambil keputusan akses dari payload JWT yang sekadar didekode.
- Tidak memakai stateless(). Token dan client secret tidak disimpan dalam user/session atau log aplikasi.
- SSO login tidak memberikan remember-me lokal. Tombol Keluar mengakhiri sesi aplikasi; sesi Microsoft pada browser dapat tetap aktif.
- Tidak ada client secret di Master User/Approval. .env dan kredensial bootstrap tidak dilacak Git.

## Sebelum dinyatakan terintegrasi

Setelah admin tenant menyiapkan app registration dan environment, uji login akun nyata yang sudah dipetakan, penolakan akun tidak aktif/tidak terdaftar, role approval, logout aplikasi, dan callback HTTPS. Pengujian otomatis saat setup menggunakan HTTP provider yang disimulasikan, bukan koneksi Entra MKM langsung.

## Login utama dan administrator yang diminta

Root aplikasi memulai SSO; login lokal GET/POST tersedia di `/MKM@dm1n`. `/login` mengarah ke SSO. Callback Azure tetap `/auth/azure/callback`; perubahan halaman login tidak mengubah redirect URI yang didaftarkan di Azure.

`RequestedAdminSeeder` memprovisikan email yang ditetapkan melalui SSO_BOOTSTRAP_ADMIN_EMAIL sebagai admin. Pada setup lokal, email yang diminta adalah mkm02120@ptmkm.co.id. Jika belum memiliki Object ID, penautan satu kali diizinkan hanya untuk akun yang secara eksplisit ditandai pending, tenant yang dikonfigurasi, dan userPrincipalName yang persis sesuai dari Microsoft Graph setelah autentikasi Azure. Sesudah itu, login memakai pasangan Tenant ID dan Object ID. Akun lain tidak ditautkan otomatis berdasarkan email. Password akun existing tidak diubah. Akun admin baru ini tidak menggunakan password demo; akses utamanya SSO.

## Profil Microsoft

Setiap callback SSO yang berhasil mengambil nama, jobTitle, department melalui Graph /me dengan $select, serta foto melalui /me/photo/$value menggunakan access token sesi login tersebut. Token hanya dipakai selama request dan tidak disimpan. Data Graph diverifikasi terhadap Object ID user. Avatar disimpan privat dan disajikan melalui route terautentikasi /profile/avatar.

Jika data profil/foto Microsoft belum tersedia atau request opsional gagal, login tetap dapat berlanjut; data lama dipertahankan jika tidak ada respons pengganti yang valid. Jika belum ada foto, menu menampilkan inisial. Setelah pembaruan ini, user yang sedang login harus keluar dan login SSO kembali untuk melakukan sinkronisasi pertama.

## Section mapping

Section is separate from Department. Microsoft Graph has no standard MKM Section field. Organizational data (employeeOrgData and onPremisesExtensionAttributes) is requested separately during profile sync so an unavailable organization endpoint cannot block basic profile updates. No division or extension attribute is guessed to be Section.

After confirming the tenant mapping, set SSO_SECTION_ATTRIBUTE to the exact response path, for example employeeOrgData.division or onPremisesExtensionAttributes.extensionAttribute3. These examples are not confirmed MKM mappings. Until mapped, an administrator can maintain Section in Users. Manual values are preserved when no mapped Microsoft value is available. IT & Facility has not been assigned based on inference.

The interface is English. The profile menu is compact and has no Logout button. Sync profile starts another state-validated Microsoft authorization flow and refuses to switch the signed-in session to a different user.


## Self-service access requests

New Microsoft SSO identities automatically receive an SSO-only account with `access_status=onboarding`. The initial VIEWER role is a placeholder: middleware, permissions and workflow actions block all business access until approval. Existing accounts retain approved status during migration; suspended accounts remain blocked.

At `/access`, a user selects an active role, describes their responsibilities and provides a business reason. Submission records an immutable profile/request snapshot and sets status to pending. Requested roles are never assigned at submission. Active approved users with `users.manage` receive email (demo `.local` addresses are excluded).

Administrators review `/access-requests`. Approval assigns the requested active role; rejection requires a note and allows resubmission. Decisions are authenticated POST requests protected by CSRF, serialized with row locks, audited with reviewer/time, and cannot be repeated or self-approved. Email links only open the review page. Generic Users updates cannot bypass onboarding.

Emails use the database queue `access-mail`, five attempts and increasing retry delays. SMTP settings are copied from the authorized Quality Portal configuration without its trial-recipient override. Local Windows task `MKM Scrap Delivery Access Mail` drains this queue every minute while the current Windows user is logged on. Run manually:

```powershell
C:\xampp\php\php.exe artisan queue:work database --queue=access-mail,workflow-mail --stop-when-empty --tries=5 --timeout=60 --max-time=50
C:\xampp\php\php.exe artisan queue:failed
C:\xampp\php\php.exe artisan queue:retry <failed-job-uuid>
```

On a deployment server install a supervised worker with the same queue. Mail delivery requires a reachable SMTP service. Localhost email links work only on the application computer; set APP_URL and the registered Azure callback to a shared HTTPS address before using across computers. SMTP delivery and actual SSO onboarding must be verified end to end with a real new user; automated tests use isolated data and fake notifications.


Workflow updates also use SMTP, on the separate `workflow-mail` database queue processed by the same local Windows task. Database notifications remain immediate. Emails use the configured workflow recipient list (including DH and SH on delegation), excluding pending/inactive accounts and `.local` demo addresses. Each email records the transition status and notes at enqueue time, so subsequent document changes do not rewrite that update. Delivery retries five times; inspect `queue:failed` for exhausted jobs. Enabling email does not resend previous workflow history.
