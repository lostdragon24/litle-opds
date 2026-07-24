<?php

// lib/GenreManager.php

require_once __DIR__ . '/Translator.php';

class GenreManager
{
    private static $genres = [];
    private static $genresByLanguage = [];
    private static $currentLanguage = null;

    /**
     * Загрузить жанры для текущего языка
     */
    private static function loadGenres()
    {
        $lang = Translator::getInstance()->getCurrentLanguage();

        if (self::$currentLanguage === $lang && !empty(self::$genres)) {
            return;
        }

        if (isset(self::$genresByLanguage[$lang])) {
            self::$genres = self::$genresByLanguage[$lang];
            self::$currentLanguage = $lang;
            my_log("GenreManager: Using cached genres for {$lang}");
            return;
        }

        $genresFile = __DIR__ . "/../lang/genres/{$lang}.php";
        if (file_exists($genresFile)) {
            self::$genres = include $genresFile;
        } else {
            $fallback = __DIR__ . "/../lang/genres/ru.php";
            if (file_exists($fallback)) {
                self::$genres = include $fallback;
            }
        }

        self::$genresByLanguage[$lang] = self::$genres;
        self::$currentLanguage = $lang;
        my_log("GenreManager: Loaded " . count(self::$genres) . " genres");
    }

    /**
     * Получить читаемое название жанра по его коду
     *
     * @param string|null $genreCode Код жанра (может быть null)
     * @return string|null Читаемое название или null
     */
    public static function getReadableName($genreCode): ?string
    {
        //Обрабатываем null и пустые значения
        if (empty($genreCode)) {
            return null;
        }

        //Приводим к строке для безопасности
        $genreCode = (string)$genreCode;

        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return self::$genres[$genreCode] ?? self::formatUnknownGenre($genreCode);
    }

    /**
     * Получить все жанры (код => название) для текущего языка
     */
    public static function getAllGenres(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }
        return self::$genres;
    }

    /**
     * Получить все коды жанров
     */
    public static function getAllCodes(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }
        return array_keys(self::$genres);
    }

    /**
     * Получить все названия жанров для текущего языка
     */
    public static function getAllNames(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }
        return array_values(self::$genres);
    }

    /**
     * Получить группы жанров по категориям (с учетом текущего языка)
     */
    public static function getGenresByCategory(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }


$categories = [
  'Фантастика'=> ['sf', 'sf_','sf_history','sf_action','sf_epic','sf_heroic','sf_detective','sf_cyberpunk','sf_space','sf_social','sf_horror','sf_humor','sf_fantasy','sf','sf_fantasy_city','sf_postapocalyptic','sf_litrpg','sf_etc','russian_fantasy','sf_technofantasy','fairy_fantasy','hronoopera','sf_mystic','sf_stimpank','modern_tale','popadancy','sf_writing','sf_su','foreign_fantasy','everyday_fantasy','asian_fantasy','dark_fantasy','sf_space_opera','fantasy_det','city_fantasy','popadanec','sf_all','adventure_fantasy','foreign_sf','sf_industrial_magic','slavic_fantasy','fantasy_alt_hist','nsf','sf_fantasy_irony','sf_irony','historical_fantasy','humor_fantasy','utopia','dystopian','dorama','boyar_anime','magic_school','sf_realrpg'],
  'Детективы'=> ['det', 'thriller','det_classic','det_police','det_action','det_irony','det_history','det_espionage','det_crime','det_political','det_maniac','det_hard','thriller','detective','det_su','det_all','det_artifact','det_other','thriller_mystery','det_cozy','det_lady','thriller_legal','thriller_medical','thriller_techno','thriller_psychology'],
  'Проза'=> ['prose', 'roman', 'novel', 'story','prose','prose_classic','prose_history','prose_contemporary','prose_counter','prose_rus_classic','prose_su_classics','prose_military','foreign_prose','foreign_antique','literature_18','literature_19','literature_20','gothic_novel','prose_magic','epistolary_fiction','prose_neformatny','aphorisms','great_story','story','prose_abs','short_story','roman','essay','dissident','sagas','prose_sentimental','prose_epic','extravaganza','prose_all'],
  'Любовные романы'=> ['love_contemporary','love_history','love_detective','love_short','love_erotica','love','love_sf','love_hard','love_all','love_fantasy'],
  'Приключения'=> ['adv', 'adventure','adv_history','adv_indian','adv_maritime','adv_geo','adv_animal','adventure','child_adv','adv_modern','tale_chivalry','adv_story','adv_all','adv_western','child_adv_animal'],
  'Детская литература'=> ['child', 'children','child_tale','child_verse','child_prose','child_sf','child_det','child_education','children','child_classical','child_tale_rus','foreign_children','prose_game','child_all','child_dramaturgy','child_prose_history','child_prose_humor','child_prose_romantic','child_det_children_detectives','child_det_other','child_det_animal_detectives','ya','child_tale_russian_writers','child_tale_foreign_writers','child_sf_space','child_sf_hronoopera','child_sf_horror','child_sf_fantasy'],
  'Религия и эзотерика'=> ['religion','religion_budda','sci_religion','religion_esoterics','religion_self','religion','religion_christianity','religion_orthodoxy','religion_protestantism','religion_catholicism','religion_judaism','religion_hinduism','religion_islam','religion_paganism','astrology','religion_rel','religion_all','palmistry'],
  'Поэзия и драматургия'=> ['poetry', 'dramaturgy','humor_verse','poetry_classical','poetry_modern','poetry_rus_classical','poetry_rus_modern','poetry_for_classical','poetry_for_modern','poetry_east','lyrics','song_poetry','poem','palindromes','dramaturgy','comedy','tragedy','drama','drama_antique','screenplays','vaudeville','poetry_all','in_verse','epic_poetry','fable','experimental_poetry','vers_libre','visual_poetry','dramaturgy_all','scenarios','mystery'],
  'Старинная литература'=> ['antique_ant','antique_european','antique_russian','antique_east','antique','antique_myths','antique_all'],
  'Фольклор'=> ['folklore','folk_tale','epic','proverbs','folk_songs','child_folklore','limerick','folklore_all','riddles'],
  'Наука'=> ['sci', 'science','sci_history','sci_psychology','sci_philosophy','sci_politics','sci_juris','sci_linguistic','sci_medicine','sci_phys','sci_math','sci_chem','sci_biology','science','sci_cosmos','sci_geo','sci_state','sci_economy','sci_medicine_alternative','sci_philology','sci_popular','military_history','sci_social_studies','sci_zoo','sci_botany','sci_ecology','sci_oriental','sci_theories','sci_veterinary','sci_culture','sci_psychology_popular','tech_all','sci_all','sci_business','sci_crib','foreign_language','sci_biochem','psy_childs','sci_physchem','psy_sex_and_family','psy_theraphy','sci_biophys','sci_orgchem','sci_anachem','sci_abstract'],
  'Искусство'=> ['notes','nonf_criticism','design','music','painting','architecture_book','art_world_culture','cine','theatre','art_criticism','visual_arts','culture_all'],
  'Техника'=> ['sci_tech','sci_build','sci_radio','sci_metal','sci_transport','auto_business','equ_history'],
  'Военное дело'=> ['military_weapon','military_special','military_arts','military_all','military'],
  'Компьютеры'=> ['comp', 'computers','comp_www','comp_hard','comp_db','computers','tbg_computers','comp_all','comp_programming','comp_osnet','comp_soft','comp_dsp'],
  'Справочники'=> ['ref','ref_encyc','ref_dict','ref_ref','ref_guide','reference','geo_guides','ref_all'],
  'Документальная'=> ['nonf_biography','nonf_publicism','nonfiction','nonf_military','travel_notes','nonf_all','nonf_biography_writers','nonf_biography_celebrities','nonf_biography_historical','about_musicians','nonf_biography_military_figures'],
  'Юмор'=> ['humor_anecdote','humor_prose','humor','humor_satire','humor_all'],
  'Дом и быт'=> ['home_cooking','home_pets','home_crafts','home_entertain','home_health','home_garden','home_diy','home_sport','home_sex','home','sci_pedagogy','auto_regulations','home_collecting','family','home_all'],
  'Прочее'=> ['other','periodic','comics','unfinished','fanfiction','network_literature','other_all','diafilm','computer_translation','fan_translation'],
  'Бизнес'=> ['business','banking','org_behavior','popular_business','economics_ref','economics','marketing','management','economics_all','stock','accounting','personal_finance','job_hunting','small_business','paper_work','industries','real_estate','global_economy','trade'],
  'Искусство и культура' => ['art', 'culture', 'искусство', 'музыка', 'кино'],
  'Учебники'=> ['sci_textbook','tbg_school','tbg_secondary','tbg_higher'],
  'Другое' => []
];

        $result = [];
        foreach ($categories as $category => $patterns) {
            $result[$category] = [];
        }

        foreach (self::$genres as $code => $name) {
            $codeLower = strtolower($code);
            $nameLower = strtolower($name);
            $assigned = false;

            foreach ($categories as $category => $patterns) {
                foreach ($patterns as $pattern) {
                    if (strpos($codeLower, $pattern) !== false || strpos($nameLower, $pattern) !== false) {
                        $result[$category][$code] = $name;
                        $assigned = true;
                        break 2;
                    }
                }
            }

            if (!$assigned) {
                $result['Другое'][$code] = $name;
            }
        }

        // Сортируем каждую категорию по названию
        foreach ($result as &$genres) {
            asort($genres);
        }

        return $result;
    }

    /**
     * Проверить, существует ли жанр
     */
    public static function genreExists(string $genreCode): bool
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }
        return isset(self::$genres[$genreCode]);
    }

    /**
     * Получить статистику по жанрам (для админки)
     */
    public static function getGenreStats(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return [
            'total_genres' => count(self::$genres),
            'categories' => array_keys(self::getGenresByCategory()),
            'sample' => array_slice(self::$genres, 0, 10, true)
        ];
    }

    /**
     * Отформатировать неизвестный код жанра
     */
    private static function formatUnknownGenre(string $genreCode): string
    {
        // Заменяем подчеркивания на пробелы и делаем заглавными первые буквы
        $formatted = str_replace('_', ' ', $genreCode);
        $formatted = ucwords($formatted);

        return $formatted;
    }

    /**
     * Поиск жанров по названию или коду
     */
    public static function searchGenres(string $query): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        $query = mb_strtolower($query);
        $results = [];

        foreach (self::$genres as $code => $name) {
            if (mb_strpos(mb_strtolower($code), $query) !== false ||
                mb_strpos(mb_strtolower($name), $query) !== false) {
                $results[$code] = $name;
            }
        }

        return $results;
    }

    /**
     * Принудительно перезагрузить жанры (при смене языка)
     */
    public static function reload()
    {
        self::$genres = [];
        self::loadGenres();
    }

/**
 * Найти код жанра по читаемому названию
 */
private function findGenreCodesByName($query)
{
    $codes = [];
    $query = trim($query);
    
    // Получаем все жанры
    $allGenres = GenreManager::getAllGenres();
    
    // 1. СНАЧАЛА ПРОВЕРЯЕМ ПРЯМОЕ СОВПАДЕНИЕ ПО КОДУ
    if (isset($allGenres[$query])) {
        return [$query];
    }
    
    // 2. Ищем по названию (читаемому)
    $queryLower = mb_strtolower($query, 'UTF-8');
    
    foreach ($allGenres as $code => $readable) {
        $readableLower = mb_strtolower($readable, 'UTF-8');
        $codeLower = mb_strtolower($code, 'UTF-8');
        
        // Точное совпадение по названию
        if ($readableLower === $queryLower) {
            $codes[] = $code;
            continue;
        }
        
        // Частичное совпадение по названию
        if (strpos($readableLower, $queryLower) !== false) {
            $codes[] = $code;
            continue;
        }
        
        // Частичное совпадение по коду
        if (strpos($codeLower, $queryLower) !== false && strlen($queryLower) > 3) {
            $codes[] = $code;
            continue;
        }
    }
    
    // 3. Если ничего не нашли, но запрос похож на код жанра (только латиница и подчёркивание)
    if (empty($codes) && preg_match('/^[a-z_]+$/', $query)) {
        $codes[] = $query;
        error_log("Genre code fallback: " . $query);
    }
    
    return array_unique($codes);
}


/**
 * Найти все коды жанров по части названия
 */
public static function findCodesByPartialName($name)
{
    if (empty($name)) {
        return [];
    }
    
    $name = mb_strtolower(trim($name), 'UTF-8');
    $results = [];
    
    foreach (self::$genres as $code => $readable) {
        $readableLower = mb_strtolower($readable, 'UTF-8');
        if (strpos($readableLower, $name) !== false) {
            $results[] = $code;
        }
    }
    
    return $results;
}

}
