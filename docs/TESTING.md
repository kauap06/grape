# Gaming Calls - Testing Guide

## Overview

This document describes the testing procedures for the Gaming Calls platform.

## Prerequisites

- Two or more browsers (Chrome, Firefox, Edge, Safari)
- Webcam and microphone
- HTTPS or localhost (required for media APIs)
- Two separate browser profiles or incognito windows

## Test Environment Setup

### Local Development

```bash
# Start servers
bin/start-host.bat     # Windows (starts both HTTP on 8080 and WebSocket on 8081)
./bin/start-dev.sh     # Linux/macOS

# Or manually:
# Terminal 1: php server/websocket-server.php
# Terminal 2: php -S 0.0.0.0:8080 -t public
```

## Test Cases

### 1. Home Page & Profile

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1.1 | Open http://localhost:8080 | Home page loads with dark theme |
| 1.2 | Enter display name | Name saved to localStorage |
| 1.3 | Select avatar | Avatar preview updates |
| 1.4 | Upload custom avatar | Custom image shown in preview |
| 1.5 | Toggle preferences | Settings saved to localStorage |
| 1.6 | Click "Settings" button | Modal opens with device selectors |
| 1.7 | Select camera/mic/speaker | Devices populate correctly |
| 1.8 | Click "Test Devices" | Media permission requested, test passes |

### 2. Room Creation

| Step | Action | Expected Result |
|------|--------|-----------------|
| 2.1 | Enter room name "Test Room" | Name accepted |
| 2.2 | Set max participants to 8 | Value accepted |
| 2.3 | Click "Criar Sala" | Redirects to room.php with invite code |
| 2.4 | Verify URL contains ?code=XXXX | Invite code in URL |
| 2.5 | Copy invite link | Link copied to clipboard |
| 2.6 | Verify room name in header | Room name displayed |

### 3. Joining Room

| Step | Action | Expected Result |
|------|--------|-----------------|
| 3.1 | Open new incognito window | Fresh session |
| 3.2 | Paste invite link | Loads join page |
| 3.3 | Enter different display name | Name accepted |
| 3.4 | Click "Entrar na Sala" | Joins room successfully |
| 3.5 | Both windows show 2 participants | Participant count = 2 |

### 4. Participant Tabs

| Step | Action | Expected Result |
|------|--------|-----------------|
| 4.1 | Verify tabs bar shows both users | Two tabs visible |
| 4.2 | Click second user's tab | Main view switches to their stream |
| 4.3 | Click first user's tab | Switches back to first stream |
| 4.4 | Verify tab shows mic/cam/screen icons | Status indicators accurate |
| 4.5 | Verify tab shows connection status | Green dot for connected |

### 5. Audio/Video Communication

| Step | Action | Expected Result |
|------|--------|-----------------|
| 5.1 | Enable microphone in both | Audio flows both ways |
| 5.2 | Enable camera in both | Video visible in tabs |
| 5.3 | Mute microphone | Mic icon shows muted, audio stops |
| 5.4 | Unmute microphone | Audio resumes |
| 5.5 | Turn off camera | Video stops, avatar shown |
| 5.6 | Turn on camera | Video resumes |

### 6. Screen Sharing

| Step | Action | Expected Result |
|------|--------|-----------------|
| 6.1 | Click "Tela" button | Browser screen picker opens |
| 6.2 | Select "Entire Screen" | Screen share starts |
| 6.3 | Verify screen tab indicator | Screen icon shows active |
| 6.4 | Switch to other tab | Can view shared screen |
| 6.5 | Click "Stop Sharing" in browser | Screen share ends, returns to camera |
| 6.6 | Select "Window" share | Shares specific application |

### 7. Stream Composition (Streamer Controls)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 7.1 | As streamer, click "Foco na Tela" | Screen large, camera small |
| 7.2 | Click "Foco na Câmera" | Camera large, screen small |
| 7.3 | Click "Lado a Lado" | Both side by side |
| 7.4 | Click "Tela Cheia" | Screen fills view |
| 7.5 | Click "Layout Personalizado" | Draggable camera window |
| 7.6 | Drag camera window | Position updates |
| 7.7 | Resize camera window | Size updates |
| 7.8 | Click "Restaurar Padrão" | Returns to screen focus |

### 8. Participant Sidebar

| Step | Action | Expected Result |
|------|--------|-----------------|
| 8.1 | Click participants icon | Sidebar opens |
| 8.2 | Verify participant list | Both users listed |
| 8.3 | Click user in sidebar | Switches main view |
| 8.4 | Close sidebar | Sidebar closes |

### 9. Chat

| Step | Action | Expected Result |
|------|--------|-----------------|
| 9.1 | Click chat icon | Chat sidebar opens |
| 9.2 | User A sends a message | Message appears in User A and User B windows |
| 9.3 | User B sends a message | Message appears in User A and User B windows |
| 9.4 | Verify sender name and timestamp | Each message shows who sent it and when |
| 9.5 | Verify own messages aligned right | Visual distinction for own messages |
| 9.6 | Close chat | Sidebar closes |
| 9.7 | User A sends message while User B's chat is closed | User B sees a `!` badge on the chat tab |
| 9.8 | User B opens chat | `!` badge is removed |
| 9.9 | User A sends a message | User A does not see a `!` badge for their own message |
| 9.10 | Send an empty message | Rejected (no message added) |
| 9.11 | Send a message longer than 500 chars | Rejected with error |

### 10. Participant Limit

| Step | Action | Expected Result |
|------|--------|-----------------|
| 10.1 | Create room with max participants 10 | Room accepts up to 10 participants |
| 10.2 | Have 10 participants join | All 10 join successfully |
| 10.3 | Have an 11th participant join | Rejected: room is full |
| 10.4 | Have two participants join simultaneously | Room count stays at or below 10 |
| 10.5 | One participant leaves; an 11th joins | The new participant joins (slot freed) |

### 11. Active Speaker Indicator

| Step | Action | Expected Result |
|------|--------|-----------------|
| 11.1 | Participant speaks into microphone | Green border appears around their avatar/card |
| 11.2 | Participant stops speaking | Green border disappears |
| 11.3 | Multiple participants speak | Each speaking participant shows the green border independently |

### 12. User Avatars

| Step | Action | Expected Result |
|------|--------|-----------------|
| 12.1 | Participant with custom avatar joins | Avatar image shown beside their name |
| 12.2 | Participant with gradient avatar joins | Gradient color + initial shown beside their name |
| 12.3 | Participant with no avatar joins | Initials fallback shown beside their name |

### 10. Connection Handling

| Step | Action | Expected Result |
|------|--------|-----------------|
| 10.1 | Refresh one browser | Reconnects, participant list updates |
| 10.2 | Close one tab | Other user sees "left" notification |
| 10.3 | Rejoin with same invite | Works correctly |
| 10.4 | Stop WebSocket server | Shows disconnected status |
| 10.5 | Restart WebSocket server | Auto-reconnects |

### 11. Network Conditions (Chrome DevTools)

| Condition | Test |
|-----------|------|
| Offline | Verify reconnection |
| Slow 3G | Video quality adapts |
| Fast 3G | Functional but lower quality |
| Online | Full quality |

### 12. Device Permissions

| Scenario | Test |
|----------|------|
| Camera denied | Graceful error, retry button |
| Microphone denied | Graceful error, retry button |
| Screen share denied | Graceful error |
| No camera available | Shows "No camera" message |

### 13. Responsive Design

| Resolution | Test |
|------------|------|
| 1920x1080 | Full layout works |
| 1366x768 | All elements accessible |
| 768x1024 (tablet) | Sidebars overlay correctly |
| 375x667 (mobile) | Toolbar buttons icon-only |

### 14. Browser Compatibility

| Browser | Version | Status |
|---------|---------|--------|
| Chrome | 120+ | ✓ Full support |
| Firefox | 115+ | ✓ Full support |
| Edge | 120+ | ✓ Full support |
| Safari | 17+ | ✓ Full support |

## Performance Benchmarks

### Target Metrics

| Metric | Target |
|--------|--------|
| Initial load | < 2s |
| Room join | < 1s |
| First video frame | < 3s |
| Audio latency | < 100ms |
| Video latency | < 200ms |
| CPU (idle) | < 5% |
| CPU (active call) | < 30% |
| Memory | < 200MB |

### Measurement

```javascript
// In browser console
performance.mark('room-join-start');
// ... join room ...
performance.mark('room-join-end');
performance.measure('room-join', 'room-join-start', 'room-join-end');
```

## Automated Testing

### Unit Tests (PHP)

```bash
# Run PHPUnit
vendor/bin/phpunit
```

### E2E Tests (Playwright)

```bash
# Install
npm init -y && npm install -D @playwright/test
npx playwright install

# Run
npx playwright test
```

### Example E2E Test

```javascript
// tests/room.spec.js
import { test, expect } from '@playwright/test';

test('create and join room', async ({ page, context }) => {
  // User 1 creates room
  await page.goto('http://localhost:8080');
  await page.fill('#room-name', 'E2E Test');
  await page.click('#create-room-btn');
  await expect(page).toHaveURL(/\/room\.php\?code=/);
  
  const inviteUrl = await page.inputValue('#invite-link');
  
  // User 2 joins
  const page2 = await context.newPage();
  await page2.goto(inviteUrl);
  await page2.fill('#display-name', 'User 2');
  await page2.click('#join-room-btn');
  
  // Verify both see each other
  await expect(page.locator('.participant-tab')).toHaveCount(2);
  await expect(page2.locator('.participant-tab')).toHaveCount(2);
});
```

## Debugging Tips

### WebSocket Debugging

```javascript
// In browser console
const ws = new WebSocket('ws://localhost:8081');
ws.onmessage = (e) => console.log('WS:', JSON.parse(e.data));
ws.onopen = () => ws.send(JSON.stringify({type:'join',payload:{...}}));
```

### WebRTC Internals

- Chrome: `chrome://webrtc-internals`
- Firefox: `about:webrtc`

### Media Debugging

```javascript
// Check tracks
stream.getTracks().forEach(t => console.log(t.kind, t.label, t.enabled, t.readyState));

// Check stats
const pc = peerConnections.get(sessionId);
const stats = await pc.getStats();
stats.forEach(report => console.log(report.type, report));
```

## Known Test Limitations

1. **TURN Server Required**: For cross-network testing, configure TURN
2. **HTTPS Required**: For non-localhost testing, use ngrok or similar
3. **Safari Limitations**: Some screen share features limited
4. **Mobile Browsers**: Limited WebRTC support on older versions

## Reporting Issues

When reporting test failures, include:
1. Browser and version
2. OS
3. Steps to reproduce
4. Console errors
5. WebRTC internals dump
6. Network conditions