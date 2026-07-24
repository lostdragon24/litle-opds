<?php
// change-language.php

// Сначала определяем, из админки ли запрос
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$isFromAdmin = strpos($referer, '/admin/') !== false;

// Устанавливаем имя сессии ДО её запуска
if ($isFromAdmin) {
    session_name('ADMIN_SESSION');
} else {
    session_name('USER_SESSION');
}

// Теперь запускаем сессию
require_once __DIR__.'/lib/SessionManager.php';
require_once __DIR__.'/lib/SessionInitializer.php';

SessionInitializer::initialize();
SessionManager::start();

error_log("=== change-language.php called ===");
error_log("Referer: " . $referer);
error_log("Is from admin: " . ($isFromAdmin ? 'yes' : 'no'));
error_log("Session name: " . session_name());
error_log("POST data: " . print_r($_POST, true));
error_log("Session before: " . print_r($_SESSION, true));

if (isset($_POST['lang'])) {
    $lang = $_POST['lang'];
    error_log("Attempting to change language to: " . $lang);

    // Сохраняем язык в сессию
    $_SESSION['user_lang'] = $lang;

    // Сохраняем в cookie на 30 дней (для всех страниц)
    setcookie('user_lang', $lang, time() + 86400 * 30, '/');

    // ============================================
    // ВАЖНО: ОЧИЩАЕМ КЭШ СТРАНИЦ ПРИ СМЕНЕ ЯЗЫКА
    // ============================================

    // Подключаем классы для работы с кэшем
    require_once __DIR__ . '/lib/Cache.php';
    require_once __DIR__ . '/lib/PageCache.php';


    // Очищаем кэш страниц
    PageCache::clear();

    // Также очищаем кэш по типам, которые могут зависеть от языка
    Cache::invalidateByType('page_cache');
    Cache::invalidateByType('statistics');
    Cache::invalidateByType('search_results');
    Cache::invalidateByType('book_data');

    my_log("Cache cleared after language change to: " . $lang);

    // Принудительно сохраняем сессию
    session_write_close();

    my_log("Session after save: " . print_r($_SESSION, true));

    // Возвращаемся обратно
    $redirect = $referer ?: '/';
    my_log("Redirecting to: " . $redirect);

    header('Location: ' . $redirect);
    exit;
}

my_log("No lang in POST, redirecting to /");
header('Location: /');
exit;