#include "common.h"
#include "config.h"
#include "database.h"
#include "scanner.h"
#include "scanner_integration.h"
#include "utils.h"
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>

int main(int argc, char *argv[]) {
    printf("=== SCANNER STARTING ===\n");

    char *config_path;

    if (argc == 1) {
        config_path = find_config_file();
        if (!config_path) {
            fprintf(stderr, "No config file specified and no default config found\n");
            return 1;
        }
        printf("Using auto-detected config file: %s\n", config_path);
    } else {
        config_path = strdup(argv[1]);
    }

    printf("DEBUG: Reading config from: %s\n", config_path);
    Config *config = read_config(config_path);
    free(config_path);

    if (!config) {
        fprintf(stderr, "Failed to read config file\n");
        return 1;
    }

    printf("=== CONFIGURATION DEBUG ===\n");
    printf("DB Type: %s\n", config->database.type ? config->database.type : "NULL");
    if (config->database.type && strcmp(config->database.type, "mysql") == 0) {
        printf("MySQL Host: %s\n", config->database.host ? config->database.host : "NULL");
        printf("MySQL User: %s\n", config->database.user ? config->database.user : "NULL");
        printf("MySQL Database: %s\n", config->database.database ? config->database.database : "NULL");
        printf("MySQL Port: %d\n", config->database.port);
    }
    printf("Books Dir: %s\n", config->scanner.books_dir ? config->scanner.books_dir : "NULL");
    printf("===========================\n");

    if (!config->scanner.books_dir) {
        printf("ERROR: books_dir is not specified in config\n");
        free_config(config);
        return 1;
    }

    printf("DEBUG: Connecting to database...\n");
    DatabaseHandle *db_handle = db_connect(config);
    if (!db_handle) {
        printf("ERROR: Failed to connect to database!\n");
        free_config(config);
        return 1;
    } else {
        printf("SUCCESS: Connected to database\n");
    }

    printf("DEBUG: Creating database tables...\n");
    if (!create_database_tables(db_handle, config)) {
        printf("ERROR: Failed to create database tables!\n");
        db_close(db_handle);
        free_config(config);
        return 1;
    } else {
        printf("SUCCESS: Database tables created\n");
    }

    printf("DEBUG: Starting INPX processing...\n");
    int inpx_imported = process_inpx_if_enabled(db_handle, config);


    // Проверяем, был ли выполнен импорт INPX
if (inpx_imported == -1) {
    // INPX отключен или файл не найден - выполняем обычное сканирование
    printf("DEBUG: Starting regular directory scan...\n");
    scan_directory(config->scanner.books_dir, db_handle, config);
} else {
    // INPX импорт выполнен (даже если imported_count = 0)
    printf("DEBUG: INPX processing completed - imported %d books\n", inpx_imported);
    if (inpx_imported == 0) {
        printf("DEBUG: No books imported from INPX, but INPX file was processed\n");
    }
}



    if (inpx_imported == 0) {
        printf("DEBUG: Starting regular directory scan...\n");
        scan_directory(config->scanner.books_dir, db_handle, config);
    } else {
        printf("DEBUG: Skipping regular scan - imported %d books from INPX\n", inpx_imported);
    }

    printf("DEBUG: Book scanning completed\n");

    db_close(db_handle);
    free_config(config);

    printf("=== SCANNER FINISHED ===\n");
    return 0;
}
