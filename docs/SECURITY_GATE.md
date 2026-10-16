# Security Gate - device activation and officer PIN

Security Gate is a separate, restricted interface at `/security`. It does not sign a Security officer into the main application. A registered browser may search/read Scrap SMBs and download the current handover evidence. It cannot access main application routes, master data, Disposal documents, or issue arbitrary workflow actions.

## Setup

1. Sign in as an approved administrator with `users.manage`.
2. Open **Administration > Security access** (`/masters/security`).
3. Create a device/post. Enter its one-time 12-character activation code at `/security/activate` on the actual post browser. Codes expire in 15 minutes and are consumed after one activation. Generating another code immediately revokes the previous browser credential.
4. Create each officer using their actual name. A random six-digit PIN is displayed once. Officers receive an internal SECURITY account with a random unusable-by-local-login password; no Microsoft identity or email is needed.
5. Security opens `/security`, inspects the document, selects their name, confirms goods/vehicle checks, and enters the PIN to approve release.

The initial placeholder officer must be renamed to the actual responsible person before operational use. Create a separate officer and PIN for every person; do not share a generic PIN across shifts.

## Authorization and audit

- The browser credential is an encrypted, HttpOnly, SameSite=Strict cookie backed by a hashed random token in the database. It lasts up to 90 days, can expire or be revoked, and is scoped to Security routes. This identifies an activated browser profile, not a hardware certificate or a named person.
- Officers' PINs are hashed, never displayed again or placed in URLs, and excluded from flashed validation inputs. Five incorrect attempts lock the officer for 15 minutes. Administrators can reset a PIN and clear that lock.
- Approval rechecks and locks the current device, officer and document; it follows the published workflow snapshot and verifies current handover evidence. A document version check prevents approval from a stale inspection screen. Repeated submissions cannot release twice.
- PIN officers cannot bypass the PIN through the normal document transition endpoint. Existing ordinary authenticated Security users retain their existing role-based flow; they are separate from the newly provisioned PIN-only officers.
- Release history records officer user ID, officer name snapshot, device/post ID and name snapshot, action and timestamp. Print output includes the officer and post. Historical names remain available even after officers/posts are renamed.

## Operations

Use a dedicated browser profile on a physically controlled post device. Clearing its cookies requires reactivation. A stolen/copied browser credential must be revoked by an administrator. Deploy over shared HTTPS before use from other computers; `localhost` links work only on the application computer. Serve the Laravel `public` directory and keep `.env`/storage private. No Windows kiosk lockdown, badge reader, QR scanner integration or hardware certificates are installed by this feature. A USB scanner can type an SMB number into the search field; app-issued QR labels are not added here.

Admin controls create officers, update names, deactivate officers, reset PINs, generate fresh browser activation codes and revoke devices. SMTP updates continue through the existing `workflow-mail` queue; no real emails are sent by automated verification.
