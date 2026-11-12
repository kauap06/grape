# Grape

A real-time voice, video, and screen sharing platform designed for gamers. Built with PHP 8.2+, WebRTC, and WebSocket signaling.

Designed for **local hosting through Radmin VPN**: one computer hosts the room and the others join through the same Radmin VPN network.

## Features

- **Voice & Video Calls**: Low-latency WebRTC communication for every participant (not just the room creator)
- **Screen Sharing**: Share entire screen, application window, or browser tab; visible to all participants
- **Participant Tabs**: Discord-style tabs for switching between participant streams
- **Shared Chat**: Real-time chat distributed through the WebSocket signaling server to all participants in the same room, with sender identification and timestamps
- **New Message Notification**: `!` badge on the chat tab when a new message arrives while the chat is not open
- **User Avatars**: Profile image (custom, gradient color, or initials fallback) shown beside each participant's name
- **Active Speaker Indicator**: Green border around the avatar/card of the participant currently speaking (Web Audio API)
- **Participant Limit**: Configurable room capacity, default 10, enforced on both frontend and backend with transactional join handling
- **Stream Composition**: Twitch-style layouts (screen focus, camera focus, side-by-side, full-screen, custom)
- **Dark Gaming UI**: Discord/Twitch inspired interface optimized for 1366x768 and 1920x1080
- **No Authentication Required**: Local profile with localStorage persistence
- **Real-time Signaling**: PHP WebSocket server using Ratchet

## Requirements

- PHP 8.2 or higher (XAMPP recommended on Windows)
- Composer
- MySQL/MariaDB (for room persistence — XAMPP provides it)
- Radmin VPN installed on every participant (host + guests)
- A modern browser with WebRTC support (camera, microphone, screen sharing)

## Installation

### 1. Install Dependencies

```bash
cd grape
composer install
```

### 2. Configuration

Copy the example configuration and adjust for your environment:

```bash
cp .env.example .env
```

Edit `.env` (or `config/local.php`) if needed. By default the app runs on:

- **PHP HTTP server**: `http://localhost:8080` (and `http://<HOST_VPN_IP>:8080`)
- **WebSocket signaling**: `ws://localhost:8081` (and `ws://<HOST_VPN_IP>:8081`)

### 3. Database

Create the database and run the schema (MySQL/MariaDB, e.g. via XAMPP):

```sql
CREATE DATABASE grape_calls CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
mysql -u root -p grape_calls < database/schema.sql
```

Then update `config/local.php` or `.env` with your database credentials if they differ from the defaults.

## Starting the application (Windows)

Use the provided script, which starts both services in separate windows:

```bash
bin/start-host.bat
```

The script:

- Detects the project root and PHP (prefers XAMPP, falls back to PATH)
- Starts the PHP HTTP server on `0.0.0.0:8080`
- Starts the WebSocket server on `0.0.0.0:8081`
- Shows the local address and how to find the Radmin VPN IPv4 address

Equivalent manual commands:

```bash
php -S 0.0.0.0:8080 -t public
php server/websocket-server.php
```

## Accessing the application

- **Host (local)**: `http://localhost:8080`
- **Guests (same Radmin VPN network)**: `http://<HOST_VPN_IP>:8080`

Find the host's Radmin VPN IPv4 address with `ipconfig` (look for the Radmin VPN virtual adapter). See `docs/LOCAL_HOSTING.md` for the full step-by-step guide, including Windows Firewall rules for TCP ports `8080` and `8081`.

## Project Structure

```
grape/
├── public/                  # Única pasta exposta na web (DocumentRoot)
│   ├── index.php            # Home
│   ├── room.php             # Sala de chamada
│   ├── api/rooms/           # API REST (create, join, leave, status)
│   └── assets/css|js/       # Estilos e módulos JS
├── src/                     # Código PHP (PSR-4: GamingCalls\)
│   ├── Config/  Database/  Models/  Server/  Session/  Support/
│   └── Support/Url.php      # URLs de assets/WebSocket a partir da requisição
├── config/                  # config.php + local.php (overrides, não versionado)
├── database/schema.sql
├── server/websocket-server.php
├── bin/                     # start-host.bat (Windows)
├── deploy/                  # nginx-host.conf e serviço systemd
├── docs/                    # LOCAL_HOSTING.md, TESTING.md, IMPLEMENTATION_SUMMARY.md
├── logs/
├── vendor/
├── .env.example             # Variáveis de ambiente
├── composer.json
└── README.md
```

## Architecture

### Backend (PHP)
- **REST API**: Room creation, joining, leaving, status
- **WebSocket Server**: Real-time signaling for WebRTC (SDP, ICE candidates)
- **Database**: Room and participant persistence (MySQL/MariaDB)
- **Sessions**: PHP sessions for temporary server-side identification

### Frontend (Vanilla JS)
- **WebRTC**: Peer-to-peer audio/video communication
- **Media APIs**: Camera, microphone, screen capture
- **Modular ES6**: Clean separation of concerns
- **No Frameworks**: Pure HTML, CSS, JavaScript

### Signaling Flow
1. User joins room via REST API
2. WebSocket connection established
3. Server notifies all participants of new user
4. WebRTC peer connections created (mesh topology)
5. SDP offers/answers and ICE candidates exchanged via WebSocket
6. Media streams flow directly between peers (STUN/TURN for NAT traversal)

## Configuration

### WebRTC STUN/TURN Servers

Configure in `config/config.php`:

```php
'webrtc' => [
    'stun_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
    ],
    'turn_servers' => [
        // Add your TURN servers for production
        // ['urls' => 'turn:your-turn-server.com:3478', 'username' => 'user', 'credential' => 'pass'],
    ],
],
```

## Important notes on WebRTC media

Signaling (PHP + WebSocket) runs over Radmin VPN on ports `8080`/`8081`. WebRTC **media** (audio/video/screen) travels peer-to-peer and is separate from signaling. Radmin VPN usually allows direct peer connections between members of the same network, but if peers cannot reach each other, a STUN or TURN server is required. Signaling alone does not guarantee audio/video connectivity.

## Limitations & Known Issues

1. **Mesh Topology**: Current implementation uses full mesh WebRTC. For >6 participants, consider SFU (mediasoup/LiveKit).
2. **No Recording**: No built-in recording functionality.
3. **No Chat Persistence**: Chat messages are not persisted to the database.
4. **Single Room**: Users can only be in one room at a time.
5. **Browser Support**: Requires modern browser with WebRTC support.
6. **No public exposure**: Services listen on `0.0.0.0` but are intended only for the Radmin VPN network. Do not expose ports `8080`/`8081` to the public internet.
7. **TURN for production**: STUN only works for some NATs; if media does not establish between peers on Radmin VPN, configure a TURN server in `config/config.php`.

## Recent Fixes (Multiplayer Communication)

- **Microphone & Camera**: Fixed duplicate WebRTC peer connections that prevented non-creator participants from publishing media. Now exactly one peer connection exists per pair of participants, so every participant can enable mic/camera and be seen/heard by all.
- **Screen Sharing**: Track replacement and removal handled correctly; interface updates when a participant starts or stops sharing.
- **Shared Chat**: Added `chat-message` WebSocket message type; the server distributes each message to all participants in the same room only, with sender identification, timestamps, content validation, and a 500-character limit.
- **New Message Notification**: `!` badge on the chat tab when a new message arrives while the chat is not being viewed; cleared when the chat is opened.
- **User Avatars**: Avatars shown beside participant names with initials/gradient fallback.
- **Active Speaker Indicator**: Green border around the speaking participant's avatar/card, detected via the Web Audio API.
- **Participant Limit**: Default capacity 10, enforced with a transactional join (SELECT ... FOR UPDATE) so simultaneous join requests cannot exceed the limit; the 11th participant is rejected.

## License

MIT License - see LICENSE file for details.
