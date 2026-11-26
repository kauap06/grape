#!/bin/bash
# Gaming Calls - Development Startup Script
# Usage: ./start-dev.sh

set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

echo "=========================================="
echo "Gaming Calls - Development Server"
echo "=========================================="

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
REQUIRED_VERSION="8.2"

if [ "$(printf '%s\n' "$REQUIRED_VERSION" "$PHP_VERSION" | sort -V | head -n1)" != "$REQUIRED_VERSION" ]; then
    echo "ERROR: PHP $REQUIRED_VERSION+ required, found $PHP_VERSION"
    exit 1
fi

echo "PHP Version: $PHP_VERSION ✓"

# Check Composer
if ! command -v composer &> /dev/null; then
    echo "ERROR: Composer not found"
    exit 1
fi

echo "Composer: $(composer --version | head -n1) ✓"

# Install dependencies if needed
if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction
fi

# Check database
if ! command -v mysql &> /dev/null; then
    echo "WARNING: MySQL client not found. Make sure database is running."
else
    echo "MySQL client available ✓"
fi

# Create logs directory
mkdir -p logs

# Function to cleanup on exit
cleanup() {
    echo ""
    echo "Shutting down servers..."
    kill $WS_PID $HTTP_PID 2>/dev/null || true
    wait $WS_PID $HTTP_PID 2>/dev/null || true
    echo "Done."
    exit 0
}

trap cleanup SIGINT SIGTERM

# Start WebSocket server
echo ""
echo "Starting WebSocket server on port 8080..."
php server/websocket-server.php > logs/websocket.log 2>&1 &
WS_PID=$!

# Give WebSocket server time to start
sleep 2

# Check if WebSocket server started
if ! kill -0 $WS_PID 2>/dev/null; then
    echo "ERROR: WebSocket server failed to start"
    cat logs/websocket.log
    exit 1
fi

echo "WebSocket server started (PID: $WS_PID) ✓"

# Start HTTP server
echo ""
echo "Starting HTTP server on port 8000..."
php -S 0.0.0.0:8000 -t public > logs/http.log 2>&1 &
HTTP_PID=$!

sleep 1

if ! kill -0 $HTTP_PID 2>/dev/null; then
    echo "ERROR: HTTP server failed to start"
    cat logs/http.log
    kill $WS_PID 2>/dev/null
    exit 1
fi

echo "HTTP server started (PID: $HTTP_PID) ✓"

echo ""
echo "=========================================="
echo "Servers running!"
echo "=========================================="
echo "Home page:     http://localhost:8000"
echo "WebSocket:     ws://localhost:8080"
echo ""
echo "Logs:"
echo "  WebSocket:  tail -f logs/websocket.log"
echo "  HTTP:       tail -f logs/http.log"
echo ""
echo "Press Ctrl+C to stop all servers"
echo "=========================================="

# Wait for processes
wait $WS_PID $HTTP_PID