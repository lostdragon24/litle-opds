<?php
// templates/admin/about.php

$about = $about ?? [];
$changelog = $changelog ?? [];
$credits = $credits ?? [];
$requirements = $requirements ?? [];
$csrf_token = $csrf_token ?? '';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Заголовок -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0">
                    <i class="fas fa-info-circle me-2 text-info"></i>
                    <?php echo __('about_title'); ?>
                </h1>
                <span class="badge bg-primary fs-6">
                    v<?php echo $about['version']; ?>
                </span>
            </div>
            
            <!-- Вкладки -->
            <ul class="nav nav-tabs mb-4" id="aboutTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" 
                            data-bs-target="#overview" type="button" role="tab">
                        <i class="fas fa-home me-2"></i><?php echo __('about_overview'); ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="changelog-tab" data-bs-toggle="tab" 
                            data-bs-target="#changelog" type="button" role="tab">
                        <i class="fas fa-history me-2"></i><?php echo __('about_changelog'); ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="system-tab" data-bs-toggle="tab" 
                            data-bs-target="#system" type="button" role="tab">
                        <i class="fas fa-server me-2"></i><?php echo __('about_system'); ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="credits-tab" data-bs-toggle="tab" 
                            data-bs-target="#credits" type="button" role="tab">
                        <i class="fas fa-heart me-2"></i><?php echo __('about_credits'); ?>
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="aboutTabsContent">
                
                <!-- ===== ВКЛАДКА 1: ОБЗОР ===== -->
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card shadow-sm mb-4">
                                <div class="card-body">
                                    <h4 class="card-title">
                                        <i class="fas fa-book-open me-2 text-primary"></i>
                                        <?php echo htmlspecialchars($about['name']); ?>
                                    </h4>
                                    <p class="card-text text-muted">
                                        <?php echo $about['description']; ?>
                                    </p>
                                    
                                    <hr>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-tag text-primary me-2"></i>
                                                <strong><?php echo __('about_version'); ?>:</strong>
                                                <span class="ms-2">v<?php echo $about['version']; ?></span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-user text-success me-2"></i>
                                                <strong><?php echo __('about_author'); ?>:</strong>
                                                <span class="ms-2"><?php echo htmlspecialchars($about['author']); ?></span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-balance-scale text-warning me-2"></i>
                                                <strong><?php echo __('about_license'); ?>:</strong>
                                                <span class="ms-2"><?php echo $about['license']; ?></span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-globe text-info me-2"></i>
                                                <strong><?php echo __('about_website'); ?>:</strong>
                                                <a href="<?php echo $about['website']; ?>" target="_blank" class="ms-2">
                                                    GitHub
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Статистика -->
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-chart-bar me-2 text-primary"></i>
                                        <?php echo __('about_statistics'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="h3 text-primary mb-0"><?php echo number_format($stats['total_books'] ?? 0); ?></div>
                                            <small class="text-muted"><?php echo __('stats_total_books'); ?></small>
                                        </div>
                                        <div class="col-4">
                                            <div class="h3 text-success mb-0"><?php echo number_format($stats['total_authors'] ?? 0); ?></div>
                                            <small class="text-muted"><?php echo __('stats_total_authors'); ?></small>
                                        </div>
                                        <div class="col-4">
                                            <div class="h3 text-warning mb-0"><?php echo number_format($stats['total_genres'] ?? 0); ?></div>
                                            <small class="text-muted"><?php echo __('stats_total_genres'); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Быстрые действия -->
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-bolt me-2 text-warning"></i>
                                        <?php echo __('about_quick_actions'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a href="?action=dashboard" class="btn btn-outline-primary">
                                            <i class="fas fa-tachometer-alt me-2"></i>
                                            <?php echo __('dashboard'); ?>
                                        </a>
                                        <a href="?action=settings" class="btn btn-outline-secondary">
                                            <i class="fas fa-cog me-2"></i>
                                            <?php echo __('admin_settings'); ?>
                                        </a>
                                        <a href="?action=logs" class="btn btn-outline-info">
                                            <i class="fas fa-history me-2"></i>
                                            <?php echo __('admin_logs'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Версия PHP и окружение -->
                            <div class="card shadow-sm">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fab fa-php me-2 text-info"></i>
                                        <?php echo __('about_environment'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th><?php echo __('about_php_version'); ?></th>
                                            <td><code><?php echo $about['php_version']; ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_db_type'); ?></th>
                                            <td><code><?php echo strtoupper($about['db_type']); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_cache'); ?></th>
                                            <td><?php echo $about['cache_enabled']; ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_apcu'); ?></th>
                                            <td><?php echo $about['apcu_enabled']; ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_server'); ?></th>
                                            <td><small><?php echo htmlspecialchars($about['server_software']); ?></small></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ===== ВКЛАДКА 2: ИСТОРИЯ ВЕРСИЙ ===== -->
                <div class="tab-pane fade" id="changelog" role="tabpanel">
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">
                                <i class="fas fa-history me-2 text-primary"></i>
                                <?php echo __('about_changelog'); ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($changelog)): ?>
                                <p class="text-muted text-center py-4">
                                    <?php echo __('about_no_changelog'); ?>
                                </p>
                            <?php else: ?>
                                <?php foreach ($changelog as $version => $info): ?>
                                    <div class="changelog-item mb-4 pb-4 border-bottom">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-1">
                                                    <span class="badge bg-primary me-2">v<?php echo $version; ?></span>
                                                    <?php echo htmlspecialchars($info['title']); ?>
                                                </h5>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo $info['date']; ?>
                                                </small>
                                            </div>
                                        </div>
                                        <ul class="mt-2 mb-0">
                                            <?php foreach ($info['changes'] as $change): ?>
                                                <li><?php echo $change; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- ===== ВКЛАДКА 3: СИСТЕМА ===== -->
                <div class="tab-pane fade" id="system" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-check-circle me-2 text-success"></i>
                                        <?php echo __('about_requirements'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <h6 class="fw-bold"><?php echo __('about_php_requirements'); ?></h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <th><?php echo __('about_php_version'); ?></th>
                                            <td>
                                                <?php echo $requirements['php']['current']; ?>
                                                <span class="badge <?php echo $requirements['php']['status'] ? 'bg-success' : 'bg-danger'; ?> ms-2">
                                                    <?php echo $requirements['php']['status'] ? '✅' : '❌'; ?>
                                                    (<?php echo __('about_required'); ?> <?php echo $requirements['php']['version']; ?>)
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <h6 class="fw-bold mt-3"><?php echo __('about_extensions'); ?></h6>
                                    <table class="table table-sm">
                                        <?php foreach ($requirements['extensions'] as $name => $loaded): ?>
                                            <tr>
                                                <th><?php echo $name; ?></th>
                                                <td>
                                                    <span class="badge <?php echo $loaded ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $loaded ? '✅ ' . __('yes') : '❌ ' . __('no'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-folder me-2 text-warning"></i>
                                        <?php echo __('about_permissions'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th><?php echo __('about_books_dir'); ?></th>
                                            <td>
                                                <span class="badge <?php echo $requirements['permissions']['books_dir'] ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $requirements['permissions']['books_dir'] ? '✅ ' . __('writable') : '❌ ' . __('not_writable'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_cache_dir'); ?></th>
                                            <td>
                                                <span class="badge <?php echo $requirements['permissions']['cache_dir'] ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $requirements['permissions']['cache_dir'] ? '✅ ' . __('writable') : '❌ ' . __('not_writable'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_cover_dir'); ?></th>
                                            <td>
                                                <span class="badge <?php echo $requirements['permissions']['cover_cache_dir'] ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $requirements['permissions']['cover_cache_dir'] ? '✅ ' . __('writable') : '❌ ' . __('not_writable'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <hr>
                                    
                                    <h6 class="fw-bold"><?php echo __('about_php_settings'); ?></h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <th><?php echo __('about_memory_limit'); ?></th>
                                            <td><code><?php echo $about['memory_limit']; ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_upload_max'); ?></th>
                                            <td><code><?php echo $about['upload_max_filesize']; ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_post_max'); ?></th>
                                            <td><code><?php echo $about['post_max_size']; ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo __('about_max_execution'); ?></th>
                                            <td><code><?php echo $about['max_execution_time']; ?></code></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ===== ВКЛАДКА 4: БЛАГОДАРНОСТИ ===== -->
                <div class="tab-pane fade" id="credits" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-users me-2 text-primary"></i>
                                        <?php echo __('about_development_team'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($credits['core'] as $name => $role): ?>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <div>
                                                <strong><?php echo htmlspecialchars($name); ?></strong>
                                            </div>
                                            <div>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($role); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-heart me-2 text-danger"></i>
                                        <?php echo __('about_thanks'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($credits['thanks'] as $name => $reason): ?>
                                        <div class="py-2 border-bottom">
                                            <strong><?php echo htmlspecialchars($name); ?></strong>
                                            <br>
                                            <small class="text-muted">— <?php echo htmlspecialchars($reason); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-cubes me-2 text-success"></i>
                                        <?php echo __('about_libraries'); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($credits['libraries'] as $name => $purpose): ?>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <span><strong><?php echo htmlspecialchars($name); ?></strong></span>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($purpose); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="card shadow-sm">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-info-circle me-2 text-info"></i>
                                        <?php echo __('about_support'); ?>
                                    </h6>
                                </div>
                                <div class="card-body text-center">
                                    <p class="text-muted mb-3">
                                        <?php echo __('about_support_text'); ?>
                                    </p>
                                    <div class="d-grid gap-2">
                                        <a href="<?php echo $about['website']; ?>" target="_blank" class="btn btn-outline-primary">
                                            <i class="fab fa-github me-2"></i>
                                            <?php echo __('about_github'); ?>
                                        </a>
                                        <a href="mailto:ldragon24@gmail.com?subject=Little%20OPDS%20Support" class="btn btn-outline-secondary">
                                            <i class="fas fa-envelope me-2"></i>
                                            <?php echo __('about_contact'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Стили -->
<style>
.changelog-item:last-child {
    border-bottom: none !important;
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
}
.changelog-item ul {
    padding-left: 1.5rem;
}
.changelog-item ul li {
    list-style-type: none;
    padding: 2px 0;
}
.changelog-item ul li:before {
    content: "• ";
    color: #0d6efd;
    font-weight: bold;
}
.changelog-item ul li:first-letter {
    text-transform: uppercase;
}
.badge {
    font-size: 0.85rem;
}
.table-sm th {
    width: 40%;
}
@media (max-width: 768px) {
    .changelog-item .d-flex {
        flex-direction: column;
    }
}
</style>