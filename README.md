# Grape

A real-time voice, video, and screen sharing platform designed for gamers. Built with PHP 8.3, WebRTC, and WebSocket signaling.

Designed for **local hosting through Radmin VPN**: one computer hosts the room, the others join through the same Radmin VPN network. Everything needed to run ships inside the project — **no PHP, MariaDB, Caddy, or Composer installation required**.

---

## Features

- **Voice & Video Calls**: Low-latency WebRTC communication for every participant (not just the room creator)
- **Screen Sharing**: Share entire screen, application window, or browser tab; visible to all participants
- **Participant Tabs**: Discord-style tabs for switching between participant streams
- **Shared Chat**: Real-time chat distributed through the WebSocket signaling server to all participants in the same room, with sender identification and timestamps
- **New Message Notification**: `!` badge on the chat tab when a message arrives while the chat is not open
- **User Avatars**: Profile image (custom, gradient color, or initials fallback) shown beside each participant's name
- **Active Speaker Indicator**: Highlight on the participant currently speaking (Web Audio API)
- **Participant Limit**: Configurable room capacity, default 10, enforced on frontend and backend with transactional join handling
- **Stream Composition**: Twitch-style layouts (screen focus, camera focus, side-by-side, full-screen, custom)
- **Dark Gaming UI**: Discord/Twitch inspired interface optimized for 1366x768 and 1920x1080
- **No Authentication Required**: Local profile with localStorage persistence
- **HTTPS by default**: Caddy with a local CA, trusted automatically, so camera/mic/screen work in any modern browser

---

## Quick start (host, Windows)

```bat
cd C:\xampp\htdocs\grape-new
bin\start-host.bat
```

That single command:

1. uses the bundled toolchain in `portable\` (no installation anywhere);
2. starts MariaDB `3306`, the PHP HTTP server `8080`, the WebSocket server `8081` and Caddy `443`;
3. initializes the bundled database on first run and applies migrations (`database\migrate.php`);
4. trusts the local HTTPS certificate on this PC (current user, no administrator);
5. generates `deploy\ca\dist\install-ca.bat` for the guests;
6. prints the invite link and how to find the Radmin VPN IPv4 address.

Then open **`https://localhost`** on the host.

Stop everything by closing the four service windows (`Grape MariaDB`, `Grape HTTP`, `Grape WebSocket`, `Grape HTTPS`), or:

```bat
taskkill /F /IM php.exe & taskkill /F /IM caddy.exe & taskkill /F /IM mysqld.exe
```

---

## Joining as a guest

1. Receive **one file** from the host: `deploy\ca\dist\install-ca.bat`.
2. Double-click it (no administrator needed) → it trusts the host's certificate.
3. **Close every browser window** (Chrome/Edge/Brave cache the certificate error until then).
4. Open the invite link that starts with **`https://<HOST_VPN_IP>/?code=...`**.

Camera, microphone and screen sharing only work over HTTPS — that is why the certificate step exists. Details and troubleshooting: [`docs/CERTIFICADOS.md`](docs/CERTIFICADOS.md).

> Firefox keeps its **own** certificate store; either use a Chromium browser or import the CA manually (Firefox → Settings → Privacy & Security → Certificates → View Certificates → Authorities → Import).

If the guest still sees "not secure", run `deploy\ca\test-https.bat <HOST_VPN_IP>` on their PC — it reports whether the CA is installed, whether the Windows trust chain validates, and whether the default browser is Firefox.

---

## Requirements

Bundled in `portable/` (nothing to install):

| Component | Version | Path |
|---|---|---|
| PHP | 8.3.36 (TS x64) | `portable/php` — with `php.ini` (pdo_mysql, openssl, mbstring, curl, opcache) and `extras/ssl/cacert.pem` |
| MariaDB | 11.4.5 | `portable/mariadb` — data dir in `portable/mariadb/data` |
| Caddy | 2.11.9 | `portable/caddy` |
| Composer | 2.11.2 | `portable/composer/composer.phar` |

`bin\start-host.bat` resolves each of these from `portable\` first and only falls back to `PATH` if a folder is missing. The WebSocket server is pure PHP (Ratchet), so it runs from the same portable PHP.

Still external, by nature:

- **Radmin VPN** on the host and every guest;
- a **modern browser** with WebRTC (Chrome, Edge, Brave; Firefox needs the extra CA step);
- the **Visual C++ 2015-2022 runtime** (present on most Windows installs; a clean Windows may need it — it cannot be reliably bundled).

---

## Ports and access

| Port | Service | Purpose |
|---|---|---|
| 3306 | MariaDB | rooms and participants |
| 8080 | PHP built-in server | pages and REST API |
| 8081 | WebSocket (Ratchet) | WebRTC signaling and chat |
| 443 | Caddy | HTTPS + `/ws` reverse proxy |

- Host: `https://localhost` (or `http://localhost:8080` without HTTPS).
- Guests: **always the `https://<HOST_VPN_IP>` link** — the HTTP link on `:8080` is not a secure origin and blocks camera/mic/screen.
- Windows Firewall on the host: allow TCP **443** (and `8080`/`8081` if anyone uses the HTTP link).

Find the host's Radmin VPN IPv4 address with `ipconfig` (look for the Radmin VPN virtual adapter). See [`docs/LOCAL_HOSTING.md`](docs/LOCAL_HOSTING.md) for firewall rules.

---

## Configuration

Runtime settings live in `config/config.php`, overridable per machine in `config/local.php` (not versioned). Defaults:

- database `grape_calls` on `127.0.0.1:3306`, user `grape` — no password is shipped; set it in `config/local.php` (copy `config/local.php.example`);
- PHP HTTP server `127.0.0.1:8080`, WebSocket `127.0.0.1:8081` — reachable only through the Caddy proxy on 443;
- room capacity 10, chat message limit 500 characters, WebSocket ping 25 s.

Credentials never belong in `config/config.php`, which is versioned. Use `config/local.php` or the `DATABASE_USERNAME` / `DATABASE_PASSWORD` environment variables.

WebRTC STUN/TURN servers (`config/config.php`):

```php
'webrtc' => [
    'stun_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
    ],
    'turn_servers' => [
        // ['urls' => 'turn:your-turn-server.com:3478', 'username' => 'user', 'credential' => 'pass'],
    ],
],
```

On Radmin VPN the peers can reach each other directly, so local ICE candidates are usually enough. Removing the STUN entries saves ~1 s of ICE gathering per connection; only do that if everyone is on the same VPN.

`.env.example` exists for environment-driven setups, but the portable flow does not require a `.env` file.

---

## Commands

```bat
REM Start everything (host)
bin\start-host.bat

REM Development mode: PHP on 8000, WebSocket on 8081, no Caddy/HTTPS
bin\start-dev.bat

REM Logs
type logs\http.log
type logs\websocket.log
type logs\websocket-error.log
type logs\caddy.log
type logs\mysql.log

REM Database console (prompts for the password of the app user)
portable\mariadb\bin\mariadb.exe -h 127.0.0.1 -u grape -p grape_calls

REM Re-apply schema migrations (start-host does this automatically)
portable\php\php.exe database\migrate.php

REM Certificates
deploy\ca\install-ca.bat             REM host: trust the CA and build the guest installer
deploy\ca\install-ca.bat /silent     REM same, no prompts (used by start-host)
deploy\ca\test-https.bat <IP>        REM diagnose trust on any PC
deploy\ca\remove-ca.bat              REM undo the trust
deploy\ca\make-guest-installer.bat   REM regenerate dist\install-ca.bat
```

---

## Database

The database and schema are created by `bin\start-host.bat` on first run, using the credentials from `config/local.php`. For a fresh database or another server:

```sql
CREATE DATABASE grape_calls CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'grape'@'127.0.0.1' IDENTIFIED BY 'uma-senha-longa-e-aleatoria';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER
    ON grape_calls.* TO 'grape'@'127.0.0.1';
FLUSH PRIVILEGES;
```

```bat
REM prompts for the password set above
portable\mariadb\bin\mariadb.exe -h 127.0.0.1 -u grape -p grape_calls < database\schema.sql
```

`database/schema.sql` is the canonical schema; `database/migrate.php` applies small idempotent upgrades to existing databases (currently: `room_participants.avatar_data` widened to `MEDIUMTEXT`). Back up by stopping MariaDB and copying `portable\mariadb\data`.

> The `portable\mariadb\data` directory holds the database files, including the password hashes from `mysql.global_priv`. It is machine-specific and must never be redistributed — `bin\start-host.bat` initializes a fresh one when it is missing.

---

## Project structure

```
grape-new/
├── public/                  # Only web-exposed folder (DocumentRoot)
│   ├── index.php            # Home
│   ├── room.php             # Call room
│   ├── api/rooms/           # REST API (create, join, leave, status)
│   └── assets/css|js/       # Styles and ES modules
├── src/                     # PHP code (PSR-4: GamingCalls\)
│   ├── Config/  Database/  Models/  Server/  Session/  Support/
│   └── Support/Url.php      # Asset/WebSocket URLs derived from the request
├── config/                  # config.php + local.php (overrides, not versioned)
├── database/                # schema.sql + migrate.php
├── server/websocket-server.php
├── bin/                     # start-host.bat, start-dev.bat, ...
├── deploy/                  # Caddyfile, ca/ (certificates), nginx/systemd samples
│   └── ca/                  # install-ca, remove-ca, test-https, guest installer
├── docs/                    # Startup guide, certificates, testing, hosting notes
├── portable/                # PHP, MariaDB, Caddy, Composer (no installation)
├── logs/                    # Service logs (http, websocket, caddy, mysql)
├── vendor/                  # Composer dependencies
├── .env.example
├── composer.json
└── README.md
```

---

## Architecture

### Backend (PHP)
- **REST API**: room creation, joining, leaving, status
- **WebSocket server**: real-time signaling for WebRTC (SDP, ICE candidates) and chat
- **Database**: rooms and participants persistence (MariaDB/MySQL)
- **Sessions**: PHP sessions for server-side identification
- **Caddy**: TLS termination and `/ws` reverse proxy to the WebSocket server

### Frontend (Vanilla JS)
- **WebRTC**: peer-to-peer audio/video/screen
- **Media APIs**: camera, microphone, screen capture
- **Modular ES6**: clean separation of concerns, no frameworks

### Signaling flow
1. User joins the room through the REST API.
2. The browser opens a WebSocket (same-origin `wss://<host>/ws`; falls back to `ws://<host>:8081` on HTTP pages).
3. The server notifies the other participants and returns the participant list.
4. WebRTC peer connections are created (mesh topology).
5. SDP offers/answers and ICE candidates are exchanged over the WebSocket.
6. Media flows directly between peers (mesh); signaling never carries media.

---

## Performance notes

Measured on the reference machine after the current tuning:

| Item | Value |
|---|---|
| 10 requests to the home page (through Caddy) | ~0.9 s |
| Home page TTFB | 20–50 ms |
| Room page full load | ~350 ms |
| WebSocket reconnect after the server returns | ~1 s |
| ICE gathering with the default STUN servers | ~1.1 s |

- `opcache` is enabled for CLI in `portable/php/php.ini` (`opcache.enable_cli=1`) — this is what keeps the PHP built-in server fast.
- `bin\start-host.bat` warms the server (two requests) so the first guest does not hit a cold process.
- The client reconnects with a 300 ms → 5 s backoff and retries immediately when the tab regains focus or the network returns.
- `php -S` is **single-threaded** (one request at a time): fine for ~10 participants, not a replacement for Nginx/Apache.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `start-host.bat` closes instantly | a port is already in use by a previous run | the console now pauses with the error; close the old service windows or `taskkill /F /IM php.exe` |
| HTTP/HTTPS works but the room stays on "Reconectando..." | WebSocket unreachable, or the certificate is not trusted | install the CA (`deploy\ca\dist\install-ca.bat`) and restart the browser; run `deploy\ca\test-https.bat <IP>` |
| Guest: "site is not secure", camera/mic blocked | CA not installed, browser not restarted, HTTP link, or Firefox | see [`docs/CERTIFICADOS.md`](docs/CERTIFICADOS.md) §7.1 |
| Join fails / room never opens | oversized avatar was rejected by the database | fixed: column is `MEDIUMTEXT`, migration runs at startup, and the browser downscales photos to 256 px |
| Everything loads slowly the first time | cold PHP process / no opcache | already handled (opcache + warm-up); worst case restart `start-host.bat` |
| Media does not connect although chat works | NAT/ICE, not the WebSocket | same VPN should be enough; otherwise configure TURN in `config/config.php` |
| XAMPP's Apache holds port 443 | Apache answers instead of Caddy | stop Apache (`taskkill /F /IM httpd.exe`) or disable its service |

Full startup guide: [`docs/GUIA-INICIALIZACAO.md`](docs/GUIA-INICIALIZACAO.md).

---

## Documentation

| Document | Contents |
|---|---|
| [`docs/GUIA-INICIALIZACAO.md`](docs/GUIA-INICIALIZACAO.md) | Startup guide, checklists, performance, change history (Portuguese) |
| [`docs/CERTIFICADOS.md`](docs/CERTIFICADOS.md) | HTTPS, local CA, guest installer, diagnostics (Portuguese) |
| [`deploy/ca/README.md`](deploy/ca/README.md) | Certificate scripts reference (Portuguese) |
| [`docs/LOCAL_HOSTING.md`](docs/LOCAL_HOSTING.md) | Radmin VPN hosting and Windows Firewall |
| [`docs/TESTING.md`](docs/TESTING.md) | Manual test script |
| [`docs/HTTPS_RADMIN.md`](docs/HTTPS_RADMIN.md) | HTTPS over Radmin VPN notes |

---

## Limitations & known issues

1. **Mesh topology**: full mesh WebRTC. Above ~6 participants, consider an SFU (mediasoup/LiveKit).
2. **No recording**: no built-in recording.
3. **No chat persistence**: chat messages are not stored.
4. **Single room per user**.
5. **Browser support**: modern browser with WebRTC; Firefox needs the CA imported manually.
6. **No public exposure**: services listen on `0.0.0.0` but are meant for the Radmin VPN only. Do not expose `8080`/`8081` to the internet.
7. **Single-threaded PHP server**: adequate for small rooms, not a production web server.
8. **TURN for hostile NATs**: STUN covers most cases; otherwise configure TURN.

---

## Recent changes

**Portable runtime**
- Project now ships PHP, MariaDB, Caddy and Composer in `portable/`; `start-host.bat` needs no installation and only falls back to `PATH` if a folder is missing.
- Bundled database starts initialized, and `database/migrate.php` runs at startup.

**HTTPS / certificates**
- The host trusts the Caddy CA automatically (current-user store, no administrator).
- Guests receive a **single** `install-ca.bat` with the certificate embedded — no `.crt` to send around.
- Added `test-https.bat` (trust diagnostics) and `remove-ca.bat`.

**Reliability**
- `avatar_data` widened to `MEDIUMTEXT`; the join no longer fails when a profile photo is large.
- Profile photos are downscaled to 256 px JPEG in the browser (also fixes the 1 MB WebSocket message limit in Ratchet).
- WebSocket client now tries the same-origin `/ws` proxy and the direct `:8081` endpoint, remembers the working one, keeps retrying instead of giving up, and shows `Reconectando... (n)`.
- Faster reconnect (300 ms → 5 s backoff) plus immediate retry when the tab regains focus.

**Earlier fixes (multiplayer communication)**
- Exactly one peer connection per pair of participants, so every participant can publish mic/camera.
- Screen sharing track replacement/removal handled correctly.
- `chat-message` broadcast with sender identification, timestamps and 500-character limit.
- Transactional join (`SELECT ... FOR UPDATE`) enforcing the participant limit.

---

## License

MIT License - see LICENSE file for details.
