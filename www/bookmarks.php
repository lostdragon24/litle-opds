<?php
// bookmarks.php

define('LOPDS_ROOT', __DIR__);

require_once __DIR__ . '/init.php';
require_once 'templates/header.php';

$fingerprint = $_COOKIE['device_fp'] ?? '';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') $basePath = '';

$db = Database::getInstance();

// Просто получаем все закладки
$stmt = $db->getConnection()->prepare("
    SELECT b.*,
           bk.title as book_title,
           bk.author as book_author,
           bk.file_type
    FROM bookmarks b
    JOIN books bk ON b.book_id = bk.id
    WHERE b.user_fingerprint = :fingerprint
      AND b.is_deleted = 0
    ORDER BY b.updated_at DESC
");
$stmt->execute([':fingerprint' => $fingerprint]);
$bookmarks = $stmt->fetchAll();

// Отдельно получаем последнее прочитанное для верхнего блока
$lastRead = null;
foreach ($bookmarks as $bm) {
    if ($bm['note'] === 'Последнее прочитанное') {
        $lastRead = $bm;
        break;
    }
}

$totalBookmarks = count($bookmarks);

my_log("Fingerprint: " . $fingerprint);
my_log("Regular bookmarks count: " . count($bookmarks));
my_log("Has last read: " . ($lastRead ? 'yes' : 'no'));
my_log("Total bookmarks: " . $totalBookmarks);


?>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-bookmark me-2"></i>
       <?php echo __('my_bookmark'); ?>
        <span class="badge bg-primary ms-2"><?php echo $totalBookmarks; ?></span>
    </h1>

    <?php if ($lastRead): ?>
    <div class="card border-primary mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-clock me-2"></i>
            <?php echo __('last_read'); ?>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><?php echo htmlspecialchars($lastRead['book_title'] ?: "<?php echo __('no_name_book'); ?>"); ?></h5>
                    <p class="text-muted"><?php echo htmlspecialchars($lastRead['book_author'] ?: "<?php echo __('incognito'); ?>"); ?></p>
                    <div>
                        <span class="badge bg-info"><?php echo round($lastRead['percentage']) . '%'; ?></span>
                        <span class="badge bg-secondary"><?php echo strtoupper($lastRead['file_type']); ?></span>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <a href="reader.php?id=<?php echo $lastRead['book_id']; ?>&page=<?php echo max(1, $lastRead['page_number']); ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-book-open me-1"></i>
                        <?php echo __('restore_read'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($bookmarks)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <?php echo __('no_bookmarks'); ?>
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($bookmarks as $bm): ?>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h5><?php echo htmlspecialchars($bm['book_title'] ?: "<?php echo __('no_name_book'); ?>"); ?></h5>
                    <p class="text-muted"><?php echo htmlspecialchars($bm['book_author'] ?: "<?php echo __('incognito'); ?>"); ?></p>
                    <div>
                        <span class="badge bg-info"><?php echo round($bm['percentage']) . '%'; ?></span>
                        <span class="badge bg-secondary"><?php echo strtoupper($bm['file_type']); ?></span>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <?php echo date('d.m.Y H:i', strtotime($bm['created_at'])); ?>
                    </small>
                </div>
                <div class="card-footer">
                    <a href="reader.php?id=<?php echo $bm['book_id']; ?>&page=<?php echo max(1, $bm['page_number']); ?>" 
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-book-open me-1"></i>
                        <?php echo __('bookmark_read'); ?>
                    </a>
                    <button class="btn btn-sm btn-danger float-end" 
                            onclick="if(confirm('<?php echo __('delete_bookmark'); ?>')){fetch('./api/bookmarks.php',{method:'POST',body:new URLSearchParams({action:'delete',bookmark_id:<?php echo $bm['id']; ?>,fingerprint:'<?php echo $fingerprint; ?>'})}).then(()=>location.reload())}">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require 'templates/footer.php'; ?>
