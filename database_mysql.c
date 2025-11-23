#include "database_mysql.h"
#include "common.h"
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

MySQLConnection* mysql_conn_connect(Config *config) {
    printf("=== MYSQL_CONN_CONNECT START ===\n");

    if (!config || !config->database.host || !config->database.user) {
        printf("ERROR: Invalid MySQL configuration\n");
        return NULL;
    }

    printf("DEBUG: Connecting to MySQL at %s...\n", config->database.host);

    MySQLConnection *mysql_conn = malloc(sizeof(MySQLConnection));
    if (!mysql_conn) {
        printf("ERROR: Failed to allocate MySQL connection\n");
        return NULL;
    }

    // Инициализируем
    mysql_conn->mysql = NULL;
    mysql_conn->stmt = NULL;

    // Инициализируем MySQL
    mysql_conn->mysql = mysql_init(NULL);
    if (!mysql_conn->mysql) {
        printf("ERROR: mysql_init failed\n");
        free(mysql_conn);
        return NULL;
    }

    // Устанавливаем таймауты (если нужно)
    unsigned int timeout = 28800; // 8 часов
    mysql_options(mysql_conn->mysql, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_READ_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_WRITE_TIMEOUT, &timeout);

    // ПЕРВОЕ ПОДКЛЮЧЕНИЕ: без указания базы данных
    if (!mysql_real_connect(mysql_conn->mysql,
                           config->database.host,
                           config->database.user,
                           config->database.password,
                           NULL,  // не указываем базу данных
                           config->database.port,
                           config->database.socket,
                           config->database.flags)) {
        printf("ERROR: MySQL connection failed: %s\n", mysql_error(mysql_conn->mysql));
        mysql_close(mysql_conn->mysql);
        free(mysql_conn);
        return NULL;
    }

    printf("SUCCESS: Connected to MySQL server\n");

    // Если указана база данных, создаем её если не существует и выбираем
    if (config->database.database) {
        printf("DEBUG: Checking database '%s'...\n", config->database.database);

        // Создаем базу данных если не существует
        char create_db_sql[256];
        snprintf(create_db_sql, sizeof(create_db_sql),
                 "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                 config->database.database);

        if (mysql_query(mysql_conn->mysql, create_db_sql)) {
            printf("ERROR: Failed to create database: %s\n", mysql_error(mysql_conn->mysql));
            mysql_close(mysql_conn->mysql);
            free(mysql_conn);
            return NULL;
        }

        printf("DEBUG: Database '%s' created or already exists\n", config->database.database);

        // Выбираем базу данных
        if (mysql_select_db(mysql_conn->mysql, config->database.database)) {
            printf("ERROR: Failed to select database: %s\n", mysql_error(mysql_conn->mysql));
            mysql_close(mysql_conn->mysql);
            free(mysql_conn);
            return NULL;
        }

        printf("SUCCESS: Using database '%s'\n", config->database.database);
    }

    // Устанавливаем кодировку UTF-8
    if (mysql_set_character_set(mysql_conn->mysql, "utf8mb4")) {
        printf("WARNING: Failed to set UTF-8 character set: %s\n", mysql_error(mysql_conn->mysql));
    }

    return mysql_conn;
}





// database_mysql.c
int mysql_execute_query(MySQLConnection *mysql_conn, const char *sql, Config *config) {
    if (!mysql_conn || !mysql_conn->mysql) {
        printf("ERROR: MySQL connection is not initialized\n");
        return 0;
    }

    printf("DEBUG: Executing MySQL query: %s\n", sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        printf("ERROR: MySQL query failed: %s\n", mysql_error(mysql_conn->mysql));
        log_message(config, "ERROR", "MySQL query failed: %s", mysql_error(mysql_conn->mysql));
        return 0;
    }

    // Освобождаем результат если есть
    MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
    if (result) {
        mysql_free_result(result);
    }

    printf("DEBUG: MySQL query executed successfully\n");
    return 1;
}

void mysql_conn_close(MySQLConnection *mysql_conn) {
    if (!mysql_conn) return;

    printf("DEBUG: Closing MySQL connection...\n");

    // Безопасное закрытие statement
    if (mysql_conn->stmt) {
        printf("DEBUG: Closing MySQL statement...\n");
        mysql_stmt_close(mysql_conn->stmt);
        mysql_conn->stmt = NULL;
    }

    // Безопасное закрытие соединения
    if (mysql_conn->mysql) {
        printf("DEBUG: Closing MySQL connection...\n");
        mysql_close(mysql_conn->mysql);
        mysql_conn->mysql = NULL;
    }

    free(mysql_conn);
    printf("DEBUG: MySQL connection closed\n");
}


int mysql_create_tables(MySQLConnection *mysql_conn, Config *config) {
    const char *create_books_table =
        "CREATE TABLE IF NOT EXISTS books ("
        "    id INT AUTO_INCREMENT PRIMARY KEY,"
        "    file_path TEXT,"
        "    file_name TEXT,"
        "    file_size BIGINT,"
        "    file_type VARCHAR(10),"
        "    archive_path TEXT,"
        "    archive_internal_path TEXT,"
        "    file_hash VARCHAR(64),"
        "    title TEXT,"
        "    author TEXT,"
        "    genre TEXT,"
        "    series TEXT,"
        "    series_number INT,"
        "    year INT,"
        "    language VARCHAR(10),"
        "    publisher TEXT,"
        "    description TEXT,"
        "    added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    last_modified TIMESTAMP NULL,"
        "    last_scanned TIMESTAMP NULL,"
        "    file_mtime BIGINT,"
        "    UNIQUE KEY unique_book (file_path(255), archive_path(255), archive_internal_path(255)),"
        "    UNIQUE KEY unique_title_author (title(255), author(255))"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!mysql_execute_query(mysql_conn, create_books_table, config)) {
        return 0;
    }

    if (!mysql_create_archive_table(mysql_conn, config)) {
        return 0;
    }

    log_message(config, "INFO", "MySQL tables created successfully");
    return 1;
}

int mysql_create_archive_table(MySQLConnection *mysql_conn, Config *config) {
    const char *create_archives_table =
        "CREATE TABLE IF NOT EXISTS archives ("
        "    id INT AUTO_INCREMENT PRIMARY KEY,"
        "    archive_path TEXT,"
        "    archive_hash VARCHAR(64),"
        "    file_count INT,"
        "    total_size BIGINT,"
        "    last_modified BIGINT,"
        "    last_scanned TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    needs_rescan BOOLEAN DEFAULT TRUE,"
        "    UNIQUE KEY unique_archive (archive_path(255))"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return mysql_execute_query(mysql_conn, create_archives_table, config);
}

// Исправленная функция в database_mysql.c
int mysql_archive_needs_rescan(MySQLConnection *mysql_conn, const char *archive_path, const char *current_hash, Config *config) {
    printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] START for: %s\n", archive_path);

    if (!mysql_conn || !mysql_conn->mysql) {
        printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] No MySQL connection\n");
        return 1;
    }

    struct stat st;
    if (stat(archive_path, &st) == -1) {
        printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Cannot stat archive: %s\n", archive_path);
        return 1;
    }

    if (config->scanner.rescan_unchanged) {
        printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Forced rescan enabled\n");
        return 1;
    }

    // Проверяем соединение
    if (mysql_ping(mysql_conn->mysql)) {
        printf("WARNING: [MYSQL_ARCHIVE_NEEDS_RESCAN] Connection lost, reconnecting...\n");
        if (!mysql_reconnect(mysql_conn, config)) {
            printf("ERROR: [MYSQL_ARCHIVE_NEEDS_RESCAN] Reconnection failed\n");
            return 1;
        }
    }

    // Используем простой запрос вместо prepared statement для надежности
    char *escaped_path = malloc(strlen(archive_path) * 2 + 1);
    if (!escaped_path) {
        printf("ERROR: [MYSQL_ARCHIVE_NEEDS_RESCAN] Memory allocation failed\n");
        return 1;
    }

    mysql_real_escape_string(mysql_conn->mysql, escaped_path, archive_path, strlen(archive_path));

    char sql[2048];
    snprintf(sql, sizeof(sql),
        "SELECT archive_hash, last_modified, needs_rescan FROM archives WHERE archive_path = '%s'",
        escaped_path);

    printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Executing SQL: %s\n", sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        printf("ERROR: [MYSQL_ARCHIVE_NEEDS_RESCAN] Query failed: %s\n", mysql_error(mysql_conn->mysql));
        free(escaped_path);
        return 1;
    }

    MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
    if (!result) {
        printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Archive not in database or no result: %s\n", archive_path);
        free(escaped_path);
        return 1;
    }

    MYSQL_ROW row = mysql_fetch_row(result);
    int needs_rescan = 1; // По умолчанию нужно сканировать

    if (row) {
        const char *stored_hash = row[0];
        const char *mtime_str = row[1];
        const char *needs_rescan_str = row[2];

        time_t stored_mtime = mtime_str ? atol(mtime_str) : 0;
        int needs_rescan_flag = needs_rescan_str ? atoi(needs_rescan_str) : 0;

        printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Found in DB: hash=%s, mtime=%ld, needs_rescan=%d\n",
               stored_hash ? stored_hash : "NULL", stored_mtime, needs_rescan_flag);

        // Если явно установлен флаг needs_rescan
        if (needs_rescan_flag) {
            printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Flag needs_rescan=TRUE\n");
            mysql_free_result(result);
            free(escaped_path);
            return 1;
        }

        // Проверяем хеш и время модификации
        if (stored_hash && current_hash && strcmp(stored_hash, current_hash) == 0 &&
            stored_mtime == st.st_mtime) {

            printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Archive unchanged, skipping: %s\n", archive_path);

            // Обновляем время сканирования
            char update_sql[1024];
            snprintf(update_sql, sizeof(update_sql),
                     "UPDATE archives SET last_scanned = NOW() WHERE archive_path = '%s'",
                     escaped_path);

            if (mysql_query(mysql_conn->mysql, update_sql)) {
                printf("WARNING: [MYSQL_ARCHIVE_NEEDS_RESCAN] Failed to update last_scanned: %s\n",
                       mysql_error(mysql_conn->mysql));
            }

            needs_rescan = 0;
        } else {
            printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Archive changed\n");
            printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Hash match: %d, Mtime match: %d\n",
                   (stored_hash && current_hash && strcmp(stored_hash, current_hash) == 0),
                   (stored_mtime == st.st_mtime));
        }
    } else {
        printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Archive not in database: %s\n", archive_path);
    }

    mysql_free_result(result);
    free(escaped_path);

    printf("DEBUG: [MYSQL_ARCHIVE_NEEDS_RESCAN] Needs rescan: %d\n", needs_rescan);
    return needs_rescan;
}

void mysql_update_archive_info(MySQLConnection *mysql_conn, const char *archive_path, const char *hash,
                              int file_count, long total_size, Config *config) {
    if (!mysql_conn || !mysql_conn->mysql) return;

    struct stat st;
    if (stat(archive_path, &st) != 0) return;

    const char *sql = "INSERT INTO archives (archive_path, archive_hash, file_count, total_size, last_modified, last_scanned, needs_rescan) "
                      "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, FALSE) "
                      "ON DUPLICATE KEY UPDATE archive_hash = VALUES(archive_hash), file_count = VALUES(file_count), "
                      "total_size = VALUES(total_size), last_modified = VALUES(last_modified), "
                      "last_scanned = VALUES(last_scanned), needs_rescan = VALUES(needs_rescan)";

    MYSQL_STMT *stmt = mysql_stmt_init(mysql_conn->mysql);
    if (!stmt) return;

    if (mysql_stmt_prepare(stmt, sql, strlen(sql))) {
        mysql_stmt_close(stmt);
        return;
    }

    // Привязываем параметры
    MYSQL_BIND bind[5];
    unsigned long lengths[5];

    memset(bind, 0, sizeof(bind));

    // archive_path
    lengths[0] = strlen(archive_path);
    bind[0].buffer_type = MYSQL_TYPE_STRING;
    bind[0].buffer = (char*)archive_path;
    bind[0].buffer_length = lengths[0];
    bind[0].length = &lengths[0];

    // archive_hash
    lengths[1] = hash ? strlen(hash) : 0;
    bind[1].buffer_type = MYSQL_TYPE_STRING;
    bind[1].buffer = (char*)hash;
    bind[1].buffer_length = lengths[1];
    bind[1].length = &lengths[1];

    // file_count
    bind[2].buffer_type = MYSQL_TYPE_LONG;
    bind[2].buffer = &file_count;

    // total_size
    bind[3].buffer_type = MYSQL_TYPE_LONGLONG;
    bind[3].buffer = &total_size;

    // last_modified
    bind[4].buffer_type = MYSQL_TYPE_LONGLONG;
    bind[4].buffer = &st.st_mtime;

    if (mysql_stmt_bind_param(stmt, bind)) {
        mysql_stmt_close(stmt);
        return;
    }

    if (mysql_stmt_execute(stmt)) {
        log_message(config, "ERROR", "Failed to update archive info: %s", mysql_stmt_error(stmt));
    } else {
        log_message(config, "DEBUG", "Updated archive info: %s (%d files, %ld bytes)",
                   archive_path, file_count, total_size);
    }

    mysql_stmt_close(stmt);
}

int mysql_book_exists(MySQLConnection *mysql_conn, const char *filepath, const char *archive_path,
                     const char *internal_path, const char *file_hash, Config *config) {
    (void)config;

    if (!mysql_conn || !mysql_conn->mysql) return 0;

    printf("DEBUG: [MYSQL_BOOK_EXISTS] Checking if book exists: %s\n", filepath);

    // Экранируем filepath
    char *escaped_filepath = malloc(strlen(filepath) * 2 + 1);
    if (!escaped_filepath) return 0;

    mysql_real_escape_string(mysql_conn->mysql, escaped_filepath, filepath, strlen(filepath));

    char sql[1024];
    snprintf(sql, sizeof(sql),
             "SELECT id FROM books WHERE file_path = '%s'",
             escaped_filepath);

    printf("DEBUG: [MYSQL_BOOK_EXISTS] Executing SQL: %s\n", sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        printf("ERROR: [MYSQL_BOOK_EXISTS] Query failed: %s\n", mysql_error(mysql_conn->mysql));
        free(escaped_filepath);
        return 0;
    }

    MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
    if (!result) {
        free(escaped_filepath);
        return 0;
    }

    int exists = (mysql_num_rows(result) > 0);
    mysql_free_result(result);
    free(escaped_filepath);

    printf("DEBUG: [MYSQL_BOOK_EXISTS] Book %s exists: %s\n", filepath, exists ? "YES" : "NO");
    return exists;
}

int mysql_reconnect(MySQLConnection *mysql_conn, Config *config) {
  //  printf("DEBUG: [MYSQL_RECONNECT] Attempting to reconnect...\n");

    if (mysql_conn->mysql) {
        mysql_close(mysql_conn->mysql);
        mysql_conn->mysql = NULL;
    }

    // Создаем новое соединение
    mysql_conn->mysql = mysql_init(NULL);
    if (!mysql_conn->mysql) {
      //  printf("ERROR: [MYSQL_RECONNECT] mysql_init failed\n");
        return 0;
    }

    // Устанавливаем таймауты
    unsigned int timeout = 28800;
    mysql_options(mysql_conn->mysql, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_READ_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_WRITE_TIMEOUT, &timeout);

    // Подключаемся
    if (!mysql_real_connect(mysql_conn->mysql,
                           config->database.host,
                           config->database.user,
                           config->database.password,
                           config->database.database,
                           config->database.port,
                           config->database.socket,
                           config->database.flags)) {
    //    printf("ERROR: [MYSQL_RECONNECT] Reconnection failed: %s\n", mysql_error(mysql_conn->mysql));
        mysql_close(mysql_conn->mysql);
        mysql_conn->mysql = NULL;
        return 0;
    }

    // Устанавливаем кодировку
    mysql_set_character_set(mysql_conn->mysql, "utf8mb4");

 //   printf("DEBUG: [MYSQL_RECONNECT] Successfully reconnected\n");
    return 1;
}


void mysql_insert_book(MySQLConnection *mysql_conn, const char *filepath, BookMeta *meta,
                      const char *archive_path, const char *internal_path, Config *config) {
    DBG("[MYSQL_INSERT_BOOK] ENTER FUNCTION\n");

    if (!mysql_conn || !mysql_conn->mysql) {
         LOG_ERROR(config, "MySQL connection is not valid");
        return;
    }

    if (!meta || !filepath) {
        LOG_ERROR(config, "Invalid parameters for book insertion");
        return;
    }

    // Проверяем соединение
    if (mysql_ping(mysql_conn->mysql)) {
        LOG_WARNING(config, "MySQL connection lost, attempting to reconnect...");
        if (!mysql_reconnect(mysql_conn, config)) {
           LOG_ERROR(config, "Reconnection failed");
            return;
        }
    }


    LOG_INFO(config, "Inserting book: %s", filepath);
    DBG("[MYSQL_INSERT_BOOK] Book data - Title: '%s', Author: '%s'\n",
        meta->title ? meta->title : "Unknown",
        meta->author ? meta->author : "Unknown");

    int should_skip = check_book_exists_smart(mysql_conn, meta, config);

    if (should_skip) {
        printf("DEBUG: [MYSQL_INSERT_BOOK] Book should be skipped based on smart check\n");
        return;
    }






    // Подготавливаем данные
    const char *filename = "unknown";
    if (internal_path) {
        filename = internal_path;
    } else {
        const char *slash = strrchr(filepath, '/');
        if (slash) {
            filename = slash + 1;
        } else {
            filename = filepath;
        }
    }

    // Определяем file_type
    const char *file_type = "unknown";
    const char *ext = strrchr(filename, '.');
    if (ext && strlen(ext) > 1) {
        file_type = ext + 1;
    }

    // Безопасные значения
    const char *title = meta->title ? meta->title : "Unknown Title";
    const char *author = meta->author ? meta->author : "Unknown Author";
    const char *genre = meta->genre ? meta->genre : "";
    const char *series = meta->series ? meta->series : "";
    const char *language = meta->language ? meta->language : "";
    const char *publisher = meta->publisher ? meta->publisher : "";

    long file_size = meta->file_size > 0 ? meta->file_size : 0;
    int series_number = meta->series_number > 0 ? meta->series_number : 0;
    int year = meta->year > 0 ? meta->year : 0;

    printf("DEBUG: [MYSQL_INSERT_BOOK] Book data - Title: '%s', Author: '%s'\n", title, author);

    // Экранирование строк
    char escaped_filepath[4096] = {0};
    char escaped_filename[1024] = {0};
    char escaped_filetype[64] = {0};
    char escaped_title[2048] = {0};
    char escaped_author[1024] = {0};
    char escaped_genre[512] = {0};
    char escaped_series[512] = {0};
    char escaped_language[64] = {0};
    char escaped_publisher[1024] = {0};
    char escaped_archive[4096] = {0};
    char escaped_internal[1024] = {0};

    // Экранируем основные поля
    mysql_real_escape_string(mysql_conn->mysql, escaped_filepath, filepath, strlen(filepath));
    mysql_real_escape_string(mysql_conn->mysql, escaped_filename, filename, strlen(filename));
    mysql_real_escape_string(mysql_conn->mysql, escaped_filetype, file_type, strlen(file_type));
    mysql_real_escape_string(mysql_conn->mysql, escaped_title, title, strlen(title));
    mysql_real_escape_string(mysql_conn->mysql, escaped_author, author, strlen(author));
    mysql_real_escape_string(mysql_conn->mysql, escaped_genre, genre, strlen(genre));
    mysql_real_escape_string(mysql_conn->mysql, escaped_series, series, strlen(series));
    mysql_real_escape_string(mysql_conn->mysql, escaped_language, language, strlen(language));
    mysql_real_escape_string(mysql_conn->mysql, escaped_publisher, publisher, strlen(publisher));

    // Используем INSERT IGNORE для избежания дубликатов
    char sql[16384];

    if (archive_path && internal_path) {
        mysql_real_escape_string(mysql_conn->mysql, escaped_archive, archive_path, strlen(archive_path));
        mysql_real_escape_string(mysql_conn->mysql, escaped_internal, internal_path, strlen(internal_path));

        snprintf(sql, sizeof(sql),
            "INSERT IGNORE INTO books (file_path, file_name, file_size, file_type, "
            "archive_path, archive_internal_path, title, author, genre, series, "
            "series_number, year, language, publisher, last_modified) VALUES ("
            "'%s', '%s', %ld, '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', '%s', NOW())",
            escaped_filepath, escaped_filename, file_size, escaped_filetype,
            escaped_archive, escaped_internal, escaped_title, escaped_author,
            escaped_genre, escaped_series, series_number, year, escaped_language,
            escaped_publisher);
    } else {
        snprintf(sql, sizeof(sql),
            "INSERT IGNORE INTO books (file_path, file_name, file_size, file_type, "
            "title, author, genre, series, series_number, year, language, publisher, last_modified) VALUES ("
            "'%s', '%s', %ld, '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', '%s', NOW())",
            escaped_filepath, escaped_filename, file_size, escaped_filetype,
            escaped_title, escaped_author, escaped_genre, escaped_series,
            series_number, year, escaped_language, escaped_publisher);
    }

    //printf("DEBUG: [MYSQL_INSERT_BOOK] Executing INSERT IGNORE...\n");

    // Выполняем запрос
    if (mysql_query(mysql_conn->mysql, sql)) {
        LOG_ERROR(config, "INSERT failed: %s", mysql_error(mysql_conn->mysql));
    } else {
        my_ulonglong affected_rows = mysql_affected_rows(mysql_conn->mysql);
        LOG_INFO(config, "Book inserted successfully. Affected rows: %llu", affected_rows);

        if (affected_rows == 0) {
            //printf("DEBUG: [MYSQL_INSERT_BOOK] Book already existed (INSERT IGNORE worked)\n");
        }
    }

   //LOG_INFO(config, "Book inserted successfully. Affected rows: %llu", affected_rows);
}


int check_book_exists(MySQLConnection *mysql_conn, const char *filepath, BookMeta *meta,
                     const char *archive_path, const char *internal_path, Config *config) {
    printf("DEBUG: [CHECK_BOOK_EXISTS] START - SIMPLIFIED VERSION\n");

    // Подавляем предупреждения
    (void)filepath;
    (void)archive_path;
    (void)internal_path;

    if (!mysql_conn || !mysql_conn->mysql) {
        printf("ERROR: [CHECK_BOOK_EXISTS] MySQL connection is not valid\n");
        return 0;
    }

    if (!meta) {
        printf("ERROR: [CHECK_BOOK_EXISTS] BookMeta is NULL\n");
        return 0;
    }

    if (!meta->title || !meta->author) {
        printf("DEBUG: [CHECK_BOOK_EXISTS] Missing title or author\n");
        return 0;
    }

    const char *title = meta->title;
    const char *author = meta->author;

    printf("DEBUG: [CHECK_BOOK_EXISTS] Checking: '%s' by '%s'\n", title, author);

    // ВРЕМЕННО: всегда возвращаем 0 для отладки
    printf("DEBUG: [CHECK_BOOK_EXISTS] TEMPORARY: returning 0 for debugging\n");
    return 0;
}



int check_book_exists_smart(MySQLConnection *mysql_conn, BookMeta *meta, Config *config) {
    if (!mysql_conn || !mysql_conn->mysql || !meta || !meta->title || !meta->author) {
        return 0;
    }

    printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] Checking: '%s' by '%s' (size: %ld)\n",
           meta->title, meta->author, meta->file_size);

    // Экранируем строки
    char *escaped_title = malloc(strlen(meta->title) * 2 + 1);
    char *escaped_author = malloc(strlen(meta->author) * 2 + 1);

    mysql_real_escape_string(mysql_conn->mysql, escaped_title, meta->title, strlen(meta->title));
    mysql_real_escape_string(mysql_conn->mysql, escaped_author, meta->author, strlen(meta->author));

    // Ищем книги с тем же автором и названием
    char sql[4096];
    snprintf(sql, sizeof(sql),
        "SELECT id, file_size, file_path FROM books WHERE title = '%s' AND author = '%s' ORDER BY file_size DESC",
        escaped_title, escaped_author);

    printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] SQL: %s\n", sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        printf("ERROR: [CHECK_BOOK_EXISTS_SMART] Query failed: %s\n", mysql_error(mysql_conn->mysql));
        free(escaped_title);
        free(escaped_author);
        return 0;
    }

    MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
    if (!result) {
        printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] No existing books found\n");
        free(escaped_title);
        free(escaped_author);
        return 0;
    }

    int existing_count = mysql_num_rows(result);
    printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] Found %d existing books\n", existing_count);

    if (existing_count == 0) {
        mysql_free_result(result);
        free(escaped_title);
        free(escaped_author);
        return 0;
    }

    // Анализируем найденные книги
    MYSQL_ROW row;
    int should_skip = 0;
    char decision_reason[256] = {0};

    while ((row = mysql_fetch_row(result))) {
        int existing_id = atoi(row[0]);
        long existing_size = row[1] ? atol(row[1]) : 0;
        const char *existing_path = row[2] ? row[2] : "unknown";

        printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] Existing book: ID=%d, Size=%ld, Path=%s\n",
               existing_id, existing_size, existing_path);

        // ЛОГИКА ПРИНЯТИЯ РЕШЕНИЯ:

        // 1. Если новая книга значительно меньше существующей (менее 50%) - вероятно, это сокращенная версия
        if (meta->file_size > 0 && existing_size > 0 && meta->file_size < existing_size * 0.5) {
            snprintf(decision_reason, sizeof(decision_reason),
                     "new book is much smaller (%ld vs %ld) - probably abridged version",
                     meta->file_size, existing_size);
            should_skip = 1;
            break;
        }

        // 2. Если новая книга значительно больше существующей (более 150%) - вероятно, это полная версия
        if (meta->file_size > 0 && existing_size > 0 && meta->file_size > existing_size * 1.1) {
            snprintf(decision_reason, sizeof(decision_reason),
                     "new book is much larger (%ld vs %ld) - probably full version, will replace",
                     meta->file_size, existing_size);
            // Удаляем старую (меньшую) версию
            char delete_sql[512];
            snprintf(delete_sql, sizeof(delete_sql), "DELETE FROM books WHERE id = %d", existing_id);
            mysql_query(mysql_conn->mysql, delete_sql);
            printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] Deleted smaller version: ID=%d\n", existing_id);
            should_skip = 0; // Продолжаем с добавлением новой книги
            break;
        }

        // 3. Если размеры сопоставимы (±50%) - считаем это той же книгой
        if (meta->file_size > 0 && existing_size > 0 &&
            meta->file_size >= existing_size * 0.5 &&
            meta->file_size <= existing_size * 1.5) {
            snprintf(decision_reason, sizeof(decision_reason),
                     "sizes are comparable (%ld vs %ld) - probably same book",
                     meta->file_size, existing_size);
            should_skip = 1;
            break;
        }
    }

    mysql_free_result(result);
    free(escaped_title);
    free(escaped_author);

    printf("DEBUG: [CHECK_BOOK_EXISTS_SMART] Decision: %s (%s)\n",
           should_skip ? "SKIP" : "INSERT", decision_reason);

    return should_skip;
}
