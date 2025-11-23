<?php

class Config {
    // Настройки базы данных
    const DB_TYPE =  'mysql'; // или 'mysql', 'pgsql'
    const DB_PATH = '/path/to/db/library.db';
    const DB_HOST = 'localhost';
    const DB_USER = 'opds';
    const DB_PASS = 'password
    const DB_NAME = 'mybook';
    
    // Настройки сканера
    const BOOKS_DIR = '/mnt/sda1/book/_Lib.rus.ec - Официальная/lib.rus.ec/';
    const SCANNER_PATH = __DIR__ . '/home/pi/opds2/';
    const SCANNER_CONFIG = __DIR__ . '/config.ini';
    
    // Настройки веб-интерфейса
    const SITE_TITLE = 'Моя домашняя библиотека';
    const ITEMS_PER_PAGE = 20;
//    const CACHE_DIR = __DIR__ . '/home/pi/opds2/cache';
//    const COVER_CACHE_DIR = __DIR__ . '/home/pi/opds2/covers';
    const CACHE_DIR = '/var/www/html/4/cache';  // Изменяем путь
    const COVER_CACHE_DIR = '/var/www/html/4/cache/covers';  // Изменяем путь
    
    // Настройки OPDS
    const OPDS_TITLE = 'Моя библиотека';
    const OPDS_AUTHOR = 'Book Scanner';
    const OPDS_ID = 'urn:uuid:your-uuid-here';
    
    public static function init() {
        // Создаем необходимые директории
        if (!file_exists(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
        if (!file_exists(self::COVER_CACHE_DIR)) {
            mkdir(self::COVER_CACHE_DIR, 0755, true);
        }
        
        // Создаем конфиг для сканера если его нет
        if (!file_exists(self::SCANNER_CONFIG)) {
            self::createScannerConfig();
        }
    }
    
    private static function createScannerConfig() {
        $config_content = "[database]\n";
        $config_content .= "type = " . self::DB_TYPE . "\n";
        
        if (self::DB_TYPE === 'sqlite') {
            $config_content .= "path = " . self::DB_PATH . "\n";
        } else {
            $config_content .= "host = " . self::DB_HOST . "\n";
            $config_content .= "user = " . self::DB_USER . "\n";
            $config_content .= "password = " . self::DB_PASS . "\n";
            $config_content .= "database = " . self::DB_NAME . "\n";
        }
        
        $config_content .= "\n[scanner]\n";
        $config_content .= "books_dir = " . self::BOOKS_DIR . "\n";
        $config_content .= "log_file = NULL\n";
        
        file_put_contents(self::SCANNER_CONFIG, $config_content);
    }


const FB2_GENRES = [
        // Фантастика
        'sf_history' => 'Альтернативная история',
        'sf_action' => 'Боевая фантастика',
        'sf_epic' => 'Эпическая фантастика',
        'sf_heroic' => 'Героическая фантастика',
        'sf_detective' => 'Детективная фантастика',
        'sf_cyberpunk' => 'Киберпанк',
        'sf_space' => 'Космическая фантастика',
        'sf_social' => 'Социально-психологическая фантастика',
        'sf_horror' => 'Ужасы и Мистика',
        'sf_humor' => 'Юмористическая фантастика',
        'sf_fantasy' => 'Фэнтези',
        'sf' => 'Научная Фантастика',
        
        // Детективы и Триллеры
        'det_classic' => 'Классический детектив',
        'det_police' => 'Полицейский детектив',
        'det_action' => 'Боевик',
        'det_irony' => 'Иронический детектив',
        'det_history' => 'Исторический детектив',
        'det_espionage' => 'Шпионский детектив',
        'det_crime' => 'Криминальный детектив',
        'det_political' => 'Политический детектив',
        'det_maniac' => 'Маньяки',
        'det_hard' => 'Крутой детектив',
        'thriller' => 'Триллер',
        'detective' => 'Детектив',
        
        // Проза
        'prose_classic' => 'Классическая проза',
        'prose_history' => 'Историческая проза',
        'prose_contemporary' => 'Современная проза',
        'prose_counter' => 'Контркультура',
        'prose_rus_classic' => 'Русская классическая проза',
        'prose_su_classics' => 'Советская классическая проза',
        
        // Любовные романы
        'love_contemporary' => 'Современные любовные романы',
        'love_history' => 'Исторические любовные романы',
        'love_detective' => 'Остросюжетные любовные романы',
        'love_short' => 'Короткие любовные романы',
        'love_erotica' => 'Эротика',
        
        // Приключения
        'adv_western' => 'Вестерн',
        'adv_history' => 'Исторические приключения',
        'adv_indian' => 'Приключения про индейцев',
        'adv_maritime' => 'Морские приключения',
        'adv_geo' => 'Путешествия и география',
        'adv_animal' => 'Природа и животные',
        'adventure' => 'Приключения',
        
        // Детское
        'child_tale' => 'Сказка',
        'child_verse' => 'Детские стихи',
        'child_prose' => 'Детская проза',
        'child_sf' => 'Детская фантастика',
        'child_det' => 'Детские остросюжетные',
        'child_adv' => 'Детские приключения',
        'child_education' => 'Детская образовательная литература',
        'children' => 'Детская литература',
        
        // Поэзия, Драматургия
        'poetry' => 'Поэзия',
        'dramaturgy' => 'Драматургия',
        
        // Старинное
        'antique_ant' => 'Античная литература',
        'antique_european' => 'Европейская старинная литература',
        'antique_russian' => 'Древнерусская литература',
        'antique_east' => 'Древневосточная литература',
        'antique_myths' => 'Мифы. Легенды. Эпос',
        'antique' => 'Старинная литература',
        
        // Наука, Образование
        'sci_history' => 'История',
        'sci_psychology' => 'Психология',
        'sci_culture' => 'Культурология',
        'sci_religion' => 'Религиоведение',
        'sci_philosophy' => 'Философия',
        'sci_politics' => 'Политика',
        'sci_business' => 'Деловая литература',
        'sci_juris' => 'Юриспруденция',
        'sci_linguistic' => 'Языкознание',
        'sci_medicine' => 'Медицина',
        'sci_phys' => 'Физика',
        'sci_math' => 'Математика',
        'sci_chem' => 'Химия',
        'sci_biology' => 'Биология',
        'sci_tech' => 'Технические науки',
        'science' => 'Научная литература',
        
        // Компьютеры и Интернет
        'comp_www' => 'Интернет',
        'comp_programming' => 'Программирование',
        'comp_hard' => 'Компьютерное железо',
        'comp_soft' => 'Программы',
        'comp_db' => 'Базы данных',
        'comp_osnet' => 'ОС и Сети',
        'computers' => 'Компьютерная литература',
        
        // Справочная литература
        'ref_encyc' => 'Энциклопедии',
        'ref_dict' => 'Словари',
        'ref_ref' => 'Справочники',
        'ref_guide' => 'Руководства',
        'reference' => 'Справочная литература',
        
        // Документальная литература
        'nonf_biography' => 'Биографии и Мемуары',
        'nonf_publicism' => 'Публицистика',
        'nonf_criticism' => 'Критика',
        'design' => 'Искусство и Дизайн',
        'nonfiction' => 'Документальная литература',
        
        // Религия и духовность
        'religion_rel' => 'Религия',
        'religion_esoterics' => 'Эзотерика',
        'religion_self' => 'Самосовершенствование',
        'religion' => 'Религиозная литература',
        
        // Юмор
        'humor_anecdote' => 'Анекдоты',
        'humor_prose' => 'Юмористическая проза',
        'humor_verse' => 'Юмористические стихи',
        'humor' => 'Юмор',
        
        // Домоводство
        'home_cooking' => 'Кулинария',
        'home_pets' => 'Домашние животные',
        'home_crafts' => 'Хобби и ремесла',
        'home_entertain' => 'Развлечения',
        'home_health' => 'Здоровье',
        'home_garden' => 'Сад и огород',
        'home_diy' => 'Сделай сам',
        'home_sport' => 'Спорт',
        'home_sex' => 'Эротика, Секс',
        'home' => 'Домоводство'
    ];

}

Config::init();
?>