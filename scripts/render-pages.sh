#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-all}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="/tmp/fas-rendered"
SERVER_LOG="$TMP_DIR/php-server.log"
SERVER_HOST="127.0.0.1"
SERVER_PORT="8000"
BASE_URL="http://${SERVER_HOST}:${SERVER_PORT}"
PAGES=("about.php" "contact.php" "cart.php")

mkdir -p "$TMP_DIR"

if [ ! -f "$ROOT_DIR/src/config/config.php" ]; then
    cp "$ROOT_DIR/src/config/config.example.php" "$ROOT_DIR/src/config/config.php"
fi

touch "$ROOT_DIR/database/flipandstrip.db"

resolve_browser_driver_paths() {
    npx --yes browser-driver-manager install chrome >/dev/null
    local driver_env
    driver_env="$(npx --yes browser-driver-manager which)"

    CHROMEDRIVER_PATH="$(printf '%s\n' "$driver_env" | sed -n 's/^CHROMEDRIVER_TEST_PATH=\"\(.*\)\"$/\1/p')"
    CHROME_PATH="$(printf '%s\n' "$driver_env" | sed -n 's/^CHROME_TEST_PATH=\"\(.*\)\"$/\1/p')"

    if [ -z "${CHROMEDRIVER_PATH}" ] || [ -z "${CHROME_PATH}" ]; then
        echo "Unable to locate Chrome or ChromeDriver after installation." >&2
        exit 1
    fi
}

run_htmlhint() {
    npx htmlhint "${TMP_DIR}"/*.html
}

run_axe() {
    resolve_browser_driver_paths
    npx axe --exit --disable color-contrast --chrome-path "${CHROME_PATH}" --chromedriver-path "${CHROMEDRIVER_PATH}" "${BASE_URL}/about.php" "${BASE_URL}/contact.php" "${BASE_URL}/cart.php"
}

php -S "${SERVER_HOST}:${SERVER_PORT}" -t "$ROOT_DIR" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

cleanup() {
    if kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID"
        wait "$SERVER_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

for _ in $(seq 1 20); do
    if curl --silent --fail "${BASE_URL}/about.php" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

for page in "${PAGES[@]}"; do
    curl --silent --show-error --fail "${BASE_URL}/${page}" --output "${TMP_DIR}/${page%.php}.html"
done

case "$MODE" in
    html)
        run_htmlhint
        ;;
    axe)
        run_axe
        ;;
    all)
        run_htmlhint
        run_axe
        ;;
    *)
        echo "Unsupported mode: $MODE" >&2
        exit 1
        ;;
esac
