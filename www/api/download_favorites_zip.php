<?php
// api/download_favorites_zip.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../init.php';

ini_set('display_errors', 0);
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 300);

$db = Database::getInstance();
$pdo = $db->getConnection();
$deviceId = DEVICE_ID;

// Получаем избранные книги
$stmt = $pdo->prepare("
    SELECT b.*, f.created_at as favorited_at
    FROM book_favorites f
    INNER JOIN books b ON b.id = f.book_id
    WHERE f.user_ip = :device_id
    ORDER BY f.created_at DESC
");
$stmt->execute([':device_id' => $deviceId]);
$favorites = $stmt->fetchAll();

if (empty($favorites)) {
    die('У вас нет избранных книг');
}

// Функция для получения размера конкретного файла внутри архива
function getFileSizeFromArchive($archivePath, $internalPath) {
    if (!file_exists($archivePath)) return 0;
    
    $zip = new ZipArchive();
    if ($zip->open($archivePath) === true) {
        $stat = $zip->statName($internalPath);
        $zip->close();
        if ($stat !== false) {
            return $stat['size'];
        }
    }
    return 0;
}

// Собираем информацию о книгах
$validBooks = [];
$totalSize = 0;

foreach ($favorites as $book) {
    $fileInfo = null;
    $fileSize = 0;
    
    // Формируем имя файла
    $author = preg_replace('/[\/\\\:*?"<>|]/', '_', $book['author'] ?? 'Unknown');
    $title = preg_replace('/[\/\\\:*?"<>|]/', '_', $book['title'] ?? 'Book');
    $safeName = trim($author . ' - ' . $title);
    $safeName = substr($safeName, 0, 200);
    
    // Книга в архиве
    if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
        if (file_exists($book['archive_path'])) {
            // Получаем РЕАЛЬНЫЙ размер файла внутри архива
            $fileSize = getFileSizeFromArchive($book['archive_path'], $book['archive_internal_path']);
            
            if ($fileSize > 0) {
                $ext = pathinfo($book['archive_internal_path'], PATHINFO_EXTENSION);
                $validBooks[] = [
                    'type' => 'archive',
                    'path' => $book['archive_path'],
                    'internal' => $book['archive_internal_path'],
                    'name' => $safeName . '.' . $ext,
                    'size' => $fileSize
                ];
                $totalSize += $fileSize;
            }
        }
    } 
    // Обычный файл
    elseif (!empty($book['file_path']) && file_exists($book['file_path'])) {
        $fileSize = filesize($book['file_path']);
        $ext = pathinfo($book['file_path'], PATHINFO_EXTENSION);
        $validBooks[] = [
            'type' => 'file',
            'path' => $book['file_path'],
            'name' => $safeName . '.' . $ext,
            'size' => $fileSize
        ];
        $totalSize += $fileSize;
    }
}

if (empty($validBooks)) {
    die('Не найдено доступных файлов для скачивания');
}

// Проверка размера (максимум 500 MB)
$maxSize = 500 * 1024 * 1024;
$totalSizeMB = round($totalSize / 1024 / 1024, 1);

if ($totalSize > $maxSize) {
    die("Слишком большой объём: {$totalSizeMB} MB (книг: " . count($validBooks) . "). Максимум 500 MB.");
}

// Создаём ZIP-архив
$tempZip = tempnam(sys_get_temp_dir(), 'favorites_');
$zipName = $tempZip . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Не удалось создать архив');
}

$added = 0;

foreach ($validBooks as $book) {
    if ($book['type'] === 'file') {
        $zip->addFile($book['path'], $book['name']);
        $added++;
    } 
    elseif ($book['type'] === 'archive') {
        $archive = new ZipArchive();
        if ($archive->open($book['path']) === true) {
            $content = $archive->getFromName($book['internal']);
            $archive->close();
            
            if ($content) {
                $tempFile = tempnam(sys_get_temp_dir(), 'extract_');
                file_put_contents($tempFile, $content);
                $zip->addFile($tempFile, $book['name']);
                $added++;
                
                register_shutdown_function(function() use ($tempFile) {
                    @unlink($tempFile);
                });
            }
        }
    }
}

$zip->close();

if ($added === 0) {
    @unlink($zipName);
    die('Не удалось добавить книги в архив');
}

// Отправляем архив
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="favorites_' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zipName));
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($zipName);

register_shutdown_function(function() use ($zipName) {
    @unlink($zipName);
});

exit;