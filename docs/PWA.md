# Two MKM progressive web apps

## Install links

- General: `/app/install` - **MKM Scrap Delivery**, teal truck icon.
- Security: `/security/install` - **MKM Security Gate**, navy shield icon.

Open the appropriate link in Chrome/Edge and select **Install app**, or use the browser installation menu. On iOS/iPadOS use Safari > Share > Add to Home Screen. A browser without an installation prompt displays manual instructions. Installed standalone windows hide the install button. Installation is a user action; adding this code does not automatically install applications on users' devices.

Install the Security PWA first, open the installed app, then activate that browser context using a fresh administrator-issued activation code. Browser activation cookies may not migrate to the installed context on all platforms. Every release still requires the actual officer's personal PIN.

## Scopes and compatibility

The two manifests have distinct stable `id`, `name`, icons, start URLs and sibling scopes:

| App | Scope | Worker | Start |
| --- | --- | --- | --- |
| General | `/app/` | `/app/sw.js` | `/app/start` |
| Security | `/security/` | `/security/sw.js` | `/security/gate` |

All paths include the deployment base path automatically, including the local XAMPP `/mkm_scrap_delivery/public` prefix. Named main application routes generate `/app/...` URLs. Previous main URLs remain functional aliases. `/MKM@dm1n` still terminates an existing main session and shows local login. Azure's configured `/auth/azure/callback` remains unchanged; no additional Entra redirect registration is required solely for these route aliases. Complete a real SSO sign-in from each target installed browser as a deployment check.

Security activation, personal PIN verification, middleware and document transition permissions are unchanged. PWA installation itself grants no permissions. The legacy `/security` link remains available; canonical Security links use `/security/gate` so they remain inside the `/security/` scope without relying on trailing slashes.

Same-origin scopes separate app identity/navigation and service worker control, not browser security storage: cookies, permissions and storage quota are still shared by origin. Clearing site data while uninstalling one app can clear data for the other and require reactivation/sign-in. Use separate subdomains if strict browser storage/permission isolation is required later. See https://web.dev/articles/building-multiple-pwas-on-the-same-domain.

## Online-only transactions and offline fallback

Each worker caches only its public offline page, app icons and allowlisted static build assets/logo. It never caches authenticated HTML, avatars, evidence downloads, API responses, PINs, SSO responses or approval forms. Cache cleanup targets only its own deployment/app-specific prefix. Cache versions incorporate the Vite manifest, icon and offline-page source.

Navigation uses the network first. A connection failure displays a generic connection-required page. POST/PIN/approval requests are neither intercepted for caching nor queued for background replay. Reconnect and check document status after a failed submission before trying again. No web push or background notification permission is requested by this feature.

## Hosting and verification

Service workers/PWA installation require a secure context: HTTPS on shared/LAN hosting, with localhost accepted for local development. An ordinary `http://<LAN-IP>` address will not provide the same install/service-worker support. Do not use browser security bypasses as deployment configuration. The server must serve worker URLs as JavaScript and manifests as application/manifest+json (the controller sets these headers). Icons ship as PNGs; their Windows/GD source generator is `scripts/build_pwa_icons.php`.

Local verification covers both HTTP manifests/icon sizes, route aliases and access restrictions, service-worker JavaScript syntax, Edge installability checks, two concurrently registered sibling workers, and offline navigation using network emulation applied to both page and service-worker targets. Actual OS installation and real SSO/PIN operation in an installed mobile app remain target-device checks.
