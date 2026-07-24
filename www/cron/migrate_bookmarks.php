<?php

define('LOPDS_ROOT', __DIR__ . '/..');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$dbType = Config::getDbType();

echo "=== Миграция таблицы bookmarks ===\n";

$columns = [];
if ($dbType === 'sqlite') {
    $stmt = $pdo->query("PRAGMA table_info('bookmarks')");
    while ($row = $stmt->fetch()) {
        $columns[] = $row['name'];
    }
} else {
    $stmt = $pdo->query("DESCRIBE bookmarks");
    while ($row = $stmt->fetch()) {
        $columns[] = $row['Field'];
    }
}

$newColumns = [
    'type' => "TEXT DEFAULT 'bookmark'",
    'color' => "TEXT DEFAULT 'yellow'",
    'selected_text' => "TEXT",
    'context_before' => "TEXT",
    'context_after' => "TEXT",
    'tags' => "TEXT DEFAULT '[]'",
    'is_public' => "INTEGER DEFAULT 0"
];

foreach ($newColumns as $name => $definition) {
    if (!in_array($name, $columns)) {
        $sql = "ALTER TABLE bookmarks ADD COLUMN $name $definition";
        try {
            $pdo->exec($sql);
            echo "  ✅ Добавлена колонка: $name\n";
        } catch (Exception $e) {
            echo "  ❌ Ошибка: $name - " . $e->getMessage() . "\n";
        }
    } else {
        echo "  ✓ Колонка уже есть: $name\n";
    }
}

// Индексы
try {
    if ($dbType === 'sqlite') {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_type ON bookmarks(type)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bookmarks_color ON bookmarks(color)");
    }
    echo "  ✅ Индексы созданы\n";
} catch (Exception $e) {
    echo "  ⚠️ Индексы: " . $e->getMessage() . "\n";
}

echo "=== Миграция завершена ===\n";
