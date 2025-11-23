// scanner_integration.c
#include "scanner_integration.h"
#include "inpx_parser.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <dirent.h>
#include <unistd.h>
#include <stdio.h>

char* find_inpx_file(const char *books_dir) {
    if (!books_dir) {
        printf("DEBUG: books_dir is NULL\n");
        return NULL;
    }

    printf("DEBUG: Searching for ANY .inpx files in: %s\n", books_dir);

    // Рекурсивно ищем все файлы с расширением .inpx
    DIR *dir = opendir(books_dir);
    if (!dir) {
        printf("DEBUG: Cannot open directory %s\n", books_dir);
        return NULL;
    }

    struct dirent *entry;
    char *found_path = NULL;

    while ((entry = readdir(dir)) != NULL && !found_path) {
        // Пропускаем . и ..
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
            continue;

        char full_path[4096];
        snprintf(full_path, sizeof(full_path), "%s/%s", books_dir, entry->d_name);

        struct stat statbuf;
        if (stat(full_path, &statbuf) == -1) {
            continue;
        }

        if (S_ISDIR(statbuf.st_mode)) {
            // Рекурсивно ищем в поддиректориях
            printf("DEBUG: Searching in subdirectory: %s\n", full_path);
            char *subdir_found = find_inpx_file(full_path);
            if (subdir_found) {
                closedir(dir);
                return subdir_found;
            }
        } else if (S_ISREG(statbuf.st_mode)) {
            // Проверяем расширение файла
            const char *ext = strrchr(entry->d_name, '.');
            if (ext && strcasecmp(ext, ".inpx") == 0) {
                printf("DEBUG: Found INPX file: %s\n", full_path);
                found_path = strdup(full_path);
                break;
            }
        }
    }
    closedir(dir);

    if (!found_path) {
        printf("DEBUG: No .inpx files found\n");
    }

    return found_path;
}

int clear_database(DatabaseHandle *db_handle, Config *config) {
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR", "Database handle or connection is NULL");
        return 0;
    }

    const char *tables[] = {"books", "archives", NULL};

    for (int i = 0; tables[i]; i++) {
        char sql[256];

        if (db_handle->db_type == DB_MYSQL) {
            // Для MySQL отключаем проверку внешних ключей для очистки
            if (!db_execute(db_handle, "SET FOREIGN_KEY_CHECKS = 0", config)) {
                log_message(config, "WARNING", "Failed to disable foreign key checks");
            }

            snprintf(sql, sizeof(sql), "DELETE FROM %s", tables[i]);
        } else {
            snprintf(sql, sizeof(sql), "DELETE FROM %s", tables[i]);
        }

        if (!db_execute(db_handle, sql, config)) {
            log_message(config, "ERROR", "Failed to clear table: %s", tables[i]);

            // Для MySQL возвращаем проверку внешних ключей
            if (db_handle->db_type == DB_MYSQL) {
                db_execute(db_handle, "SET FOREIGN_KEY_CHECKS = 1", config);
            }
            return 0;
        }
    }

    // Для MySQL возвращаем проверку внешних ключей
    if (db_handle->db_type == DB_MYSQL) {
        if (!db_execute(db_handle, "SET FOREIGN_KEY_CHECKS = 1", config)) {
            log_message(config, "WARNING", "Failed to enable foreign key checks");
        }
    }

    log_message(config, "INFO", "Database cleared successfully");
    return 1;
}

// ДОБАВЬТЕ ЭТУ ФУНКЦИЮ - она отсутствовала
int process_inpx_if_enabled(DatabaseHandle *db_handle, Config *config) {
    if (!config->scanner.enable_inpx) {
        log_message(config, "DEBUG", "INPX scanner disabled");
        return -1;  // Возвращаем -1 если отключено
    }

    log_message(config, "INFO", "INPX scanner enabled, looking for INPX files...");
    printf("INFO: INPX scanner enabled, looking for INPX files in: %s\n", config->scanner.books_dir);

    char *inpx_file = find_inpx_file(config->scanner.books_dir);
    if (!inpx_file) {
        log_message(config, "INFO", "No INPX file found in books directory, proceeding with regular scan");
        printf("INFO: No INPX file found, proceeding with regular scan\n");
        return -1;  // Возвращаем -1 если файл не найден
    }

    log_message(config, "INFO", "Found INPX file: %s", inpx_file);
    printf("INFO: Found INPX file: %s\n", inpx_file);

    // Очищаем базу если требуется
    if (config->scanner.clear_database_inpx) {
        log_message(config, "INFO", "Clearing database before INPX import");
        printf("INFO: Clearing database before INPX import\n");
        if (!clear_database(db_handle, config)) {
            log_message(config, "ERROR", "Failed to clear database, aborting INPX import");
            printf("ERROR: Failed to clear database, aborting INPX import\n");
            free(inpx_file);
            return -1;  // Возвращаем -1 при ошибке очистки
        }
    }

    // Импортируем INPX коллекцию
    printf("INFO: Starting INPX import from: %s\n", inpx_file);
    int imported_count = import_inpx_collection(inpx_file, db_handle, config);

    free(inpx_file);

    // Возвращаем количество импортированных книг (может быть 0)
    return imported_count;
}
