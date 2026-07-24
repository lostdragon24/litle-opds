<?php
// templates/header.php


require_once __DIR__ . '/../csp.php';


// Получаем базовый путь без учета админки
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

// Если мы не в админке, используем обычный путь
if (strpos($scriptPath, '/admin/') === false) {
    $basePath = rtrim(dirname($scriptPath), '/');
}

$csrfToken = Config::startSecureSession();

// Определяем, находимся ли мы в админке
$isAdmin = strpos($scriptPath, '/admin/') !== false;

// Получаем информацию о языках
$detector = LanguageDetector::getInstance();
$currentLang = $detector->getCurrentLanguage();
$availableLangs = $detector->getAvailableLanguages();
$langName = $detector->getLanguageName();
$langFlag = $detector->getLanguageFlag();
?>

<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(Config::getSiteTitle()); ?></title>
    <link rel="shortcut icon" href="<?php echo $basePath; ?>/favicon.ico" type="image/x-icon">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">
    <script src="<?php echo $basePath; ?>/css/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo $basePath; ?>/js/api-client.js?v=<?php echo time(); ?>"></script>

    <!-- Глобальные переменные JavaScript -->
<script>
    window.CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    window.API_URL = '<?php echo $basePath; ?>/api/rating.php';
    window.BASE_PATH = '<?php echo $basePath; ?>';
    window.CURRENT_LANG = '<?php echo $currentLang; ?>';
    window.TRANSLATIONS = {
        // Общие
        'error': '<?php echo __('error'); ?>',
        'success': '<?php echo __('success'); ?>',
        'warning': '<?php echo __('warning'); ?>',
        'info': '<?php echo __('info'); ?>',
        'close': '<?php echo __('close'); ?>',
        'error_occurred': '<?php echo __('error_occurred'); ?>',
        'error_unknown': '<?php echo __('error_unknown'); ?>',
        'error_csrf': '<?php echo __('error_csrf'); ?>',
        'error_invalid_id': '<?php echo __('error_invalid_id'); ?>',
        
        // Рейтинги
        'rating_click_to_rate': '<?php echo __('rating_click_to_rate'); ?>',
        'rating_saved': '<?php echo __('rating_saved'); ?>',
        'rating_error': '<?php echo __('rating_error'); ?>',
        'rating_no_votes': '<?php echo __('rating_no_votes'); ?>',
        'rating_vote_1': '<?php echo __('rating_vote_1'); ?>',
        'rating_vote_2': '<?php echo __('rating_vote_2'); ?>',
        'rating_vote_3': '<?php echo __('rating_vote_2'); ?>',
        'rating_vote_4': '<?php echo __('rating_vote_2'); ?>',
        'rating_vote_5': '<?php echo __('rating_vote_5'); ?>',
        'rating_star_1': '<?php echo __('rating_star_1'); ?>',
        'rating_star_2': '<?php echo __('rating_star_2'); ?>',
        'rating_star_3': '<?php echo __('rating_star_2'); ?>',
        'rating_star_4': '<?php echo __('rating_star_2'); ?>',
        'rating_star_5': '<?php echo __('rating_star_5'); ?>',
        'rating_your_value': '<?php echo __('rating_your_value'); ?>',
        
        // Избранное
        'favorites_add': '<?php echo __('favorites_add'); ?>',
        'favorites_remove': '<?php echo __('favorites_remove'); ?>',
        'favorites_added': '<?php echo __('favorites_added'); ?>',
        'favorites_removed': '<?php echo __('favorites_removed'); ?>',
        'favorites_error_remove': '<?php echo __('favorites_error_remove'); ?>',
        
        // Подтверждения
        'confirm_delete': '<?php echo __('confirm_delete'); ?>'
    };
    console.log('CSRF Token set in header');
    console.log('Current language:', window.CURRENT_LANG);
    console.log('Translations loaded:', Object.keys(window.TRANSLATIONS).length);
</script>


    <!-- Основная JS библиотека -->
    <script src="<?php echo $basePath; ?>/js/library.js?v=<?php echo time(); ?>"></script>

    <style>
        .book-cover { max-width: 100px; height: auto; }
        .book-card { margin-bottom: 20px; }
        .search-form { margin-bottom: 30px; }
        .stats { font-size: 0.85rem; }
        
        .rating-star {
            cursor: pointer;
            transition: transform 0.2s;
            background: none;
            border: none;
            font-size: 1.5rem;
        }
        .rating-star:hover {
            transform: scale(1.0);
        }
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

/* Уменьшить звёзды в блоке рейтинга */
.rating-star i,
#average-stars i,
#user-rating-stars i {
    font-size: 1.2rem !important;  /* вместо стандартных 2rem */
}

/* Или конкретно для разных блоков */
#average-stars i {
    font-size: 2rem;  /* средний рейтинг - крупнее */
}

#user-rating-stars i {
    font-size: 1.5rem;  /* звёзды для оценки - поменьше */
}
        
        /* Стили для переключателя языка */
        .language-switcher {
            margin-left: 15px;
        }
        .language-switcher .dropdown-menu {
            min-width: 120px;
        }
        .language-switcher .dropdown-item {
            cursor: pointer;
            padding: 8px 15px;
        }
        .language-switcher .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .language-switcher .dropdown-item.active {
            background-color: #007bff;
            color: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $basePath; ?>/">
		<?php echo htmlspecialchars(Config::getSiteTitle()); ?>



            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/">
                            <?php echo __('home'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/stats.php">
                            <?php echo __('stats'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/favorites.php">
                            <?php echo __('favorites'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/top_rated.php">
                            <?php echo __('top_rated'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/bookmarks.php">
                            <?php echo __('book_marks'); ?>
                        </a>
                    </li>







                    <li class="nav-item">
                        <a class="nav-link <?php echo $isAdmin ? 'active' : ''; ?>" 
                           href="<?php echo $basePath; ?>/admin/index.php">
                            <?php echo __('admin'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/api/opds.php" target="_blank">
                            OPDS
                        </a>
                    </li>
                </ul>
                
                <!-- Language switcher -->
                <?php if (count($availableLangs) > 1): ?>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown language-switcher">
                        <a class="nav-link dropdown-toggle" href="#" id="languageDropdown" 
                           role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo $langFlag . ' ' . $langName; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
                            <?php foreach ($availableLangs as $lang):
                                $langFlag = $detector->getLanguageFlag($lang);
                                $langName = $detector->getLanguageName($lang);
                                ?>
                            <li>
                                <a class="dropdown-item <?php echo $lang === $currentLang ? 'active' : ''; ?>" 
                                   href="#" 
                                   onclick="event.preventDefault(); changeLanguage('<?php echo $lang; ?>');">
                                    <?php echo $langFlag . ' ' . $langName; ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Индикатор режима чтения (будет показан только в reader.php) -->
    <?php if (isset($inReader) && $inReader): ?>
    <style>
    .reader-mode-indicator {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 8px 0;
        font-size: 0.9rem;
        text-align: center;
        position: relative;
        z-index: 1040;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .reader-mode-indicator i {
        margin-right: 8px;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.6; }
        100% { opacity: 1; }
    }
    </style>
    <div class="reader-mode-indicator">
        <i class="fas fa-book-open"></i>
        <?php echo __('reader_mode'); ?>
    </div>
    <?php endif; ?>
    
    <div class="container mt-4">

<script>
// Функция для смены языка
function changeLanguage(lang) {
    // Создаем форму и отправляем
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo $basePath; ?>/change-language.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'lang';
    input.value = lang;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

// Функция для обработки ошибок загрузки обложек
function handleCoverError(img, height = 400) {
    // Предотвращаем бесконечный цикл
    if (img.getAttribute('data-error-handled') === 'true') {
        return;
    }
    img.setAttribute('data-error-handled', 'true');
    
    img.style.display = 'none';
    const parent = img.parentNode;
    
    // Ищем или создаем placeholder
    let placeholder = parent.querySelector('.cover-placeholder');
    if (!placeholder) {
        placeholder = document.createElement('div');
        placeholder.className = 'bg-light d-flex align-items-center justify-content-center rounded cover-placeholder';
        placeholder.style.cssText = `width:100%; height:${height}px;`;
        
        if (height >= 300) {
            placeholder.innerHTML = `
                <div class="text-center">
                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                    <p class="text-muted mb-0">${window.TRANSLATIONS?.['book_no_cover'] || 'Нет обложки'}</p>
                </div>
            `;
        } else {
            placeholder.innerHTML = `<small class="text-muted">${window.TRANSLATIONS?.['book_no_cover'] || 'Нет обложки'}</small>`;
        }
        
        parent.appendChild(placeholder);
    }
    
    placeholder.style.display = 'flex';
}

</script>

<script>
(async function() {
    // Функция получения fingerprint
    async function getFingerprint() {

    if (Fingerprint) return Fingerprint;


        // Собираем стабильные данные
        const data = {
            // Разрешение экрана (обычно не меняется)
            screen: screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
            // Язык системы
            language: navigator.language,
            // Платформа (Windows/Linux/Mac)
            platform: navigator.platform,
            // User Agent (браузер + ОС)
            // userAgent: navigator.userAgent,
            // Часовой пояс
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            // Количество ядер CPU
            cpuCores: navigator.hardwareConcurrency || 0,
            // Объём RAM (если доступно)
            memory: navigator.deviceMemory || 0,
            // Сенсорный экран?
            touchPoints: navigator.maxTouchPoints || 0,

            // Аудио-фингерпринтинг
            //audio: await getAudioFingerprint(),
            // Canvas-фингерпринтинг
            //canvas: await getCanvasFingerprint(),
            // WebGL-фингерпринтинг
            //webgl: await getWebGLFingerprint(),

    	    audio: await withTimeout(getAudioFingerprint(), 2000, 'audio_timeout'),
    	    canvas: await withTimeout(getCanvasFingerprint(), 1000, 'canvas_timeout'),
    	    webgl: await withTimeout(getWebGLFingerprint(), 1000, null),



        };
        
        const str = JSON.stringify(data);
	const hash = await cryptoHash(str);
    
	return 'fp_' + hash.slice(0, 32); // Первые 32 символа = 128 бит
    }
    
    // Получаем или создаём fingerprint
    let fingerprint = localStorage.getItem('device_fingerprint');
    let Fingerprint = null;
    
    if (!fingerprint) {
        fingerprint = await getFingerprint();
        localStorage.setItem('device_fingerprint', fingerprint);
        console.log('New device fingerprint created:', fingerprint);
    } else {
        console.log('Existing device fingerprint:', fingerprint);
    }

    // Сохраняем в куку для PHP
    document.cookie = 'device_fp=' + fingerprint + '; path=/; max-age=' + (365 * 24 * 3600 * 10);
})();

function withTimeout(promise, ms, fallback) {
    return Promise.race([
        promise,
        new Promise(resolve => 
            setTimeout(() => resolve(fallback), ms)
        )
    ]).catch(() => fallback);
}

async function cryptoHash(str) {
  // 1. Превращаем строку в массив байт (Uint8Array)
  const encoder = new TextEncoder();
  const data = encoder.encode(str);
  
  // 2. Хешируем с помощью Web Crypto API
  const hashBuffer = await window.crypto.subtle.digest('SHA-256', data);
  
  // 3. Превращаем ArrayBuffer обратно в байты
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  
  // 4. Конвертируем каждый байт в шестнадцатеричную строку и объединяем
  const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
  
  return hashHex;
}



// Canvas fingerprinting
async function getCanvasFingerprint() {
    return new Promise((resolve) => {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 200; canvas.height = 50;
            const ctx = canvas.getContext('2d');
            
            // Рисуем уникальный паттерн
            ctx.font = '18pt Arial';
            ctx.textBaseline = 'top';
            ctx.fillStyle = '#f60';
            ctx.fillRect(0, 0, 200, 50);
            ctx.fillStyle = '#069';
            ctx.fillText('FingerprintTest', 2, 35);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('CanvasTest', 4, 45);
            
            // Возвращаем хеш изображения
            resolve(canvas.toDataURL().slice(-64));
        } catch (e) {
            resolve('canvas_error');
        }
    });
}

// WebGL fingerprinting
async function getWebGLFingerprint() {
    try {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (!gl) return null;
        
        const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
        if (debugInfo) {
            return {
                vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
            };
        }
        return null;
    } catch (e) {
        return null;
    }
}


async function getAudioFingerprint() {
  return new Promise((resolve) => {
    try {
      // 1. Создаем контекст для скрытого рендеринга аудио в памяти
      // Параметры: 1 канал, длина 44100 фреймов, частота 44100 Гц
      const AudioContext = window.OfflineAudioContext || window.webkitOfflineAudioContext;
      if (!AudioContext) {
        return resolve("not_supported");
      }
      
      const context = new AudioContext(1, 44100, 44100);

      // 2. Создаем источник звука — осциллятор (генератор волн)
      const oscillator = context.createOscillator();
      oscillator.type = "triangle"; // Треугольная форма волны создает много гармоник
      oscillator.frequency.setValueAtTime(10000, context.currentTime); // Высокая частота 10 кГц

      // 3. Создаем компрессор (сжимает динамический диапазон)
      // Именно здесь происходит основная масса математических округлений
      const compressor = context.createDynamicsCompressor();
      compressor.threshold.setValueAtTime(-50, context.currentTime);
      compressor.knee.setValueAtTime(40, context.currentTime);
      compressor.ratio.setValueAtTime(12, context.currentTime);
      compressor.attack.setValueAtTime(0, context.currentTime);
      compressor.release.setValueAtTime(0.25, context.currentTime);

      // 4. Соединяем узлы в аудио-граф
      // Осциллятор -> Компрессор -> Виртуальный выход
      oscillator.connect(compressor);
      compressor.connect(context.destination);

      // 5. Запускаем генерацию звука
      oscillator.start(0);

      // 6. Рендерим аудио-поток
      context.startRendering().then((renderedBuffer) => {
        // Извлекаем массив амплитуд (числа с плавающей запятой Float32)
        const audioData = renderedBuffer.getChannelData(0);
        
        // Считаем контрольную сумму (хэш) по подвыборке данных для стабильности
        let hash = 0;
        // Берем значения с шагом, чтобы отсечь незначительный шум, или суммируем определенный кусок
        for (let i = 4000; i < 4500; i++) {
          hash += Math.abs(audioData[i]);
        }
        
        // Возвращаем строковое представление полученного числа
        resolve(hash.toString());
      }).catch(() => {
        resolve("rendering_error");
      });
      
    } catch (e) {
      resolve("error_" + e.message);
    }
  });
}

</script>
