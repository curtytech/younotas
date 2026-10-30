#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
dir=$(mktemp -d /tmp/younotas-browser-XXXXXXXX)
server_pid=''
cleanup() {
    if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
    shopt -s nullglob
    screenshots=("$dir"/*.png)
    if (( ${#screenshots[@]} )); then
        evidence=$(mktemp -d /tmp/younotas-browser-evidence-XXXXXXXX)
        cp "${screenshots[@]}" "$evidence/"
        echo "Capturas: $evidence"
    fi
    rm -rf "$dir"
}
trap cleanup EXIT
mkdir -p "$dir/storage/framework/sessions" "$dir/storage/framework/views" "$dir/storage/framework/cache/data" "$dir/storage/logs" "$dir/pkg"
touch "$dir/database.sqlite"
export BROWSER_TEST_DIR="$dir"
php_bin="${BROWSER_TEST_PHP:-/usr/bin/php}"
port="${BROWSER_TEST_PORT:-$("$php_bin" -r '$s=stream_socket_server("tcp://127.0.0.1:0"); echo parse_url("tcp://".stream_socket_get_name($s,false),PHP_URL_PORT);')}"
[[ "$port" =~ ^[0-9]+$ ]] && ((port >= 1024 && port <= 65535)) || { echo 'Porta de teste inválida.' >&2; exit 1; }
export BROWSER_TEST_URL="http://127.0.0.1:$port"
"$php_bin" tests/Browser/setup.php
"$php_bin" -S "127.0.0.1:$port" -t public tests/Browser/router.php >"$dir/server.log" 2>&1 &
server_pid=$!
ready=false
for attempt in {1..50}; do
    kill -0 "$server_pid" 2>/dev/null || { cat "$dir/server.log"; exit 1; }
    identity=$(curl --silent --max-time 1 "$BROWSER_TEST_URL/__browser-test-health" || true)
    if [[ "$identity" == "${dir##*/}" ]]; then ready=true; break; fi
    sleep 0.1
done
[[ "$ready" == true ]] || { echo 'Servidor isolado não iniciou ou a porta pertence a outro processo.' >&2; cat "$dir/server.log"; exit 1; }
cd "$dir/pkg"
bun add --no-save --no-progress playwright@1.56.1 >/dev/null
export CHROMIUM_PATH="${CHROMIUM_PATH:-$HOME/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome}"
export PLAYWRIGHT_MODULE="$dir/pkg/node_modules/playwright/index.mjs"
cd - >/dev/null
bun tests/Browser/login.mjs || { cat "$dir/server.log"; exit 1; }
