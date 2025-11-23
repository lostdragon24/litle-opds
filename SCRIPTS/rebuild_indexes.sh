#!/bin/bash

# rebuild_indexes.sh
# Перестраивает все индексы в таблице books (полезно после массовой загрузки)

set -euo pipefail

# === Настройки ===
DB_PATH="${OPDS_DB_PATH:-/home/alex/book_scanner/library.db}"
LOG_FILE="/home/pi/opds2/rebuild_indexes.log"

mkdir -p "$(dirname "$LOG_FILE")"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

if [[ ! -f "$DB_PATH" ]]; then
    echo "Ошибка: база данных не найдена: $DB_PATH" >&2
    exit 1
fi

log "Начинаю перестройку индексов в $DB_PATH..."

# Получаем список индексов по префиксу
INDEXES=$(sqlite3 "$DB_PATH" "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='books' AND name LIKE 'idx_books_%';")

if [[ -z "$INDEXES" ]]; then
    log "⚠️  Не найдено индексов с префиксом 'idx_books_'. Возможно, они ещё не созданы."
    log "💡 Запустите сначала create_indexes.sh"
    exit 0
fi

# Перестраиваем каждый индекс
while IFS= read -r idx; do
    if [[ -n "$idx" ]]; then
        log "Перестраиваю индекс: $idx"
        if ! sqlite3 "$DB_PATH" "REINDEX \"$idx\";"; then
            log "❌ Ошибка при перестройке индекса $idx"
            exit 1
        fi
    fi
done <<< "$INDEXES"

log "✅ Все индексы успешно перестроены."