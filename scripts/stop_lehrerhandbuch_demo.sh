#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_DIR="$ROOT_DIR/.runtime/mariadb-run"
PHP_PID_FILE="$RUN_DIR/php-demo.pid"
MARIADB_PID_FILE="$RUN_DIR/mariadb.pid"

stop_pid_file() {
  local file="$1"
  if [[ -f "$file" ]]; then
    local pid
    pid="$(cat "$file")"
    if [[ -n "$pid" ]] && kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
    fi
    rm -f "$file"
  fi
}

stop_pid_file "$PHP_PID_FILE"
stop_pid_file "$MARIADB_PID_FILE"

echo "Lehrer:innen-Demo gestoppt."
