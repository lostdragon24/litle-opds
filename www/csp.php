<?php

// Определяем протокол
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

// Базовые директивы CSP
$csp = "default-src 'self'; ";

// Разрешаем скрипты с CDN и своего домена
$csp .= "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline' 'wasm-unsafe-eval' $baseUrl; ";

// Стили
$csp .= "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline' data: $baseUrl; ";

// Изображения
$csp .= "img-src 'self' data: blob: https: http:; ";

// Шрифты
$csp .= "font-src 'self' https://cdnjs.cloudflare.com data: $baseUrl; ";

// Подключения
$csp .= "connect-src 'self' $baseUrl; ";

// Фреймы
$csp .= "frame-src 'self' blob:; ";

// Остальное
$csp .= "object-src 'none'; base-uri 'self'; form-action 'self';";

// Устанавливаем заголовок
header("Content-Security-Policy: $csp");
