
#!/bin/bash

# Указываем корневую директорию для поиска (по умолчанию — текущая)
ROOT_DIR="${1:-.}"
# Имя выходного файла
OUTPUT_FILE="collected_php_files.txt"

# Очищаем выходной файл, если он существует
> "$OUTPUT_FILE"

# Рекурсивно находим все .php файлы и обрабатываем их
while IFS= read -r -d '' file; do
    # Записываем путь к файлу
    echo "$file" >> "$OUTPUT_FILE"
    # Записываем содержимое файла
    cat "$file" >> "$OUTPUT_FILE"
    # Добавляем пустую строку для разделения
    echo "" >> "$OUTPUT_FILE"
done < <(find "$ROOT_DIR" -type f -name "*.php" -print0)

echo "Сбор завершён. Результат сохранён в $OUTPUT_FILE"