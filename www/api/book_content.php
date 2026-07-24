<?php

// api/book_content.php
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$fingerprint = $_COOKIE['device_fp'] ?? '';

$bookId = (int)($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'json'; // json или raw

if (!$bookId) {
    echo json_encode(['success' => false, 'message' => 'Book ID required']);
    exit;
}

// Получаем информацию о книге
$stmt = $db->getConnection()->prepare("
    SELECT * FROM books WHERE id = ?
");
$stmt->execute([$bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    echo json_encode(['success' => false, 'message' => 'Book not found']);
    exit;
}

// Проверяем, что это FB2
if ($book['file_type'] !== 'fb2') {
    echo json_encode(['success' => false, 'message' => 'Not an FB2 file']);
    exit;
}

// Извлекаем содержимое FB2
$content = null;
$filePath = $book['file_path'];

if ($book['archive_path'] && !empty($book['archive_path'])) {
    // Книга в архиве
    require_once __DIR__ . '/../lib/ArchiveReader.php';
    $archiveReader = new ArchiveReader();
    $content = $archiveReader->extractFile(
        $book['archive_path'],
        $book['archive_internal_path']
    );
} else {
    // Обычный файл
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
    }
}

if (!$content) {
    echo json_encode(['success' => false, 'message' => 'Failed to read file']);
    exit;
}

if ($format === 'raw') {
    header('Content-Type: application/xml');
    echo $content;
    exit;
}

echo json_encode([
    'success' => true,
    'content' => base64_encode($content),
    'metadata' => [
        'title' => $book['title'],
        'author' => $book['author'],
        'language' => $book['language'] ?? 'ru'
    ]
]);
