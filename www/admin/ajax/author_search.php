<?php

// admin/ajax/author_search.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/Cache.php';
require_once __DIR__ . '/../AuthorDeduplicator.php';
require_once __DIR__ . '/../../init.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$deduplicator = new AuthorDeduplicator();

try {
    switch ($action) {
        case 'get_authors':
            $page = (int)($_GET['page'] ?? 1);
            $perPage = (int)($_GET['perPage'] ?? 50);
            $search = trim($_GET['search'] ?? '');

            // Если поиск пустой — просто список
            // Если поиск есть — ищем по ВСЕМ авторам
            $result = $deduplicator->getAllAuthorsList($page, $perPage, $search);

            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;

        case 'find_similar':
            $author = trim($_GET['author'] ?? '');
            $threshold = (float)($_GET['threshold'] ?? 0.5);
            $limit = (int)($_GET['limit'] ?? 20);

            if (empty($author)) {
                throw new Exception('Author name required');
            }

            $similar = $deduplicator->findSimilarForAuthor($author, $threshold, $limit);

            if (empty($similar) && $threshold > 0.3) {
                $similar = $deduplicator->findSimilarForAuthor($author, 0.3, $limit);
            }

            echo json_encode([
                'success' => true,
                'author' => $author,
                'similar' => $similar,
                'count' => count($similar),
                'threshold' => $threshold
            ]);
            break;

        case 'merge':
            $main = trim($_POST['main'] ?? '');
            $duplicate = trim($_POST['duplicate'] ?? '');

            if (empty($main) || empty($duplicate)) {
                throw new Exception('Both author names required');
            }

            $result = $deduplicator->mergeAuthors($main, $duplicate);

            if (!$result['success'] && isset($result['suggestion'])) {
                $result2 = $deduplicator->mergeAuthors($result['suggestion'], $duplicate);
                echo json_encode($result2);
                exit;
            }

            echo json_encode($result);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_log("Author search error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
