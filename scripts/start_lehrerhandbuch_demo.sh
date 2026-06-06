#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="$ROOT_DIR/.runtime"
DATA_DIR="$RUNTIME_DIR/mariadb-data"
RUN_DIR="$RUNTIME_DIR/mariadb-run"
LOG_DIR="$RUNTIME_DIR/mariadb-logs"
SOCKET="$RUN_DIR/mariadb.sock"
DB_PORT="3307"
APP_PORT="8044"
DB_NAME="coolgrades"
DB_USER="coolgrades"
DB_PASS="coolgrades_demo"
CONFIG_FILE="$ROOT_DIR/config.php"
PHP_PID_FILE="$RUN_DIR/php-demo.pid"
MARIADB_PID_FILE="$RUN_DIR/mariadb.pid"
MARIADB_BASE="/opt/homebrew/opt/mariadb"
MARIADB_BIN="$MARIADB_BASE/bin/mariadb"
MARIADBD_BIN="$MARIADB_BASE/bin/mariadbd"
MARIADB_INSTALL_DB="$MARIADB_BASE/bin/mariadb-install-db"

mkdir -p "$DATA_DIR" "$RUN_DIR" "$LOG_DIR"

require_file() {
  local path="$1"
  local label="$2"
  if [[ ! -x "$path" ]]; then
    echo "Fehlt: $label ($path)" >&2
    exit 1
  fi
}

require_file "$MARIADB_BIN" "MariaDB-Client"
require_file "$MARIADBD_BIN" "MariaDB-Server"
require_file "$MARIADB_INSTALL_DB" "MariaDB-Initialisierung"

write_config() {
  cat > "$CONFIG_FILE" <<'PHP'
<?php
define('DB_DSN', 'mysql:host=127.0.0.1;port=3307;dbname=coolgrades;charset=utf8mb4');
define('DB_USER', 'coolgrades');
define('DB_PASS', 'coolgrades_demo');
define('APP_NAME', 'COOL-Grades');
define('APP_ENV', 'local');
PHP
}

db_ready() {
  "$MARIADB_BIN" --protocol=SOCKET --socket="$SOCKET" -uroot -e "SELECT 1" >/dev/null 2>&1
}

app_ready() {
  curl -fsS "http://127.0.0.1:${APP_PORT}/login.php" >/dev/null 2>&1
}

if [[ ! -d "$DATA_DIR/mysql" ]]; then
  "$MARIADB_INSTALL_DB" \
    --datadir="$DATA_DIR" \
    --auth-root-authentication-method=normal \
    --skip-test-db \
    >/dev/null
fi

if ! db_ready; then
  "$MARIADBD_BIN" \
    --datadir="$DATA_DIR" \
    --socket="$SOCKET" \
    --pid-file="$MARIADB_PID_FILE" \
    --log-error="$LOG_DIR/mariadb.log" \
    --port="$DB_PORT" \
    --bind-address=127.0.0.1 \
    --user="$(id -un)" \
    --skip-networking=0 \
    --innodb_use_native_aio=0 \
    >/dev/null 2>&1 &
fi

for _ in {1..30}; do
  if db_ready; then
    break
  fi
  sleep 1
done

if ! db_ready; then
  echo "MariaDB konnte nicht gestartet werden." >&2
  exit 1
fi

"$MARIADB_BIN" --protocol=SOCKET --socket="$SOCKET" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

if [[ ! -f "$CONFIG_FILE" ]]; then
  write_config
fi

php "$ROOT_DIR/scripts/setup_lehrerhandbuch_demo.php" >/dev/null

if ! app_ready; then
  nohup php -S "127.0.0.1:${APP_PORT}" -t "$ROOT_DIR" > "$LOG_DIR/php-demo.log" 2>&1 &
  echo $! > "$PHP_PID_FILE"
fi

for _ in {1..20}; do
  if app_ready; then
    break
  fi
  sleep 1
done

if ! app_ready; then
  echo "PHP-Demo-Server konnte nicht gestartet werden." >&2
  exit 1
fi

cat <<EOF
Lehrer:innen-Demo bereit:
- URL: http://127.0.0.1:${APP_PORT}/login.php
- Lehrer:in: lehrer.demo / DemoLehrer123!
- Admin: admin.demo / DemoAdmin123!
EOF
