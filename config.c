#include "common.h"
#include "config.h"
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <time.h>
#include <stdarg.h>

Config* read_config(const char *config_path) {
    char actual_config_path[MAX_PATH];

    if (config_path[0] == '/') {
        strncpy(actual_config_path, config_path, sizeof(actual_config_path) - 1);
        actual_config_path[sizeof(actual_config_path) - 1] = '\0';
    } else {
        char exec_dir[MAX_PATH];
        ssize_t len = readlink("/proc/self/exe", exec_dir, sizeof(exec_dir) - 1);
        if (len != -1) {
            exec_dir[len] = '\0';
            char *last_slash = strrchr(exec_dir, '/');
            if (last_slash) {
                *last_slash = '\0';
                int needed = snprintf(actual_config_path, sizeof(actual_config_path),
                                    "%s/%s", exec_dir, config_path);
                if (needed >= (int)sizeof(actual_config_path)) {
                    fprintf(stderr, "Config path too long: %s/%s\n", exec_dir, config_path);
                    return NULL;
                }
            } else {
                strncpy(actual_config_path, config_path, sizeof(actual_config_path) - 1);
                actual_config_path[sizeof(actual_config_path) - 1] = '\0';
            }
        } else {
            strncpy(actual_config_path, config_path, sizeof(actual_config_path) - 1);
            actual_config_path[sizeof(actual_config_path) - 1] = '\0';
        }
    }

    FILE *file = fopen(actual_config_path, "r");
    if (!file) {
        fprintf(stderr, "Cannot open config file: %s\n", actual_config_path);
        return NULL;
    }

    Config *config = calloc(1, sizeof(Config));
    if (!config) {
        fclose(file);
        return NULL;
    }

    // Устанавливаем значения по умолчанию
    config->database.type = strdup("sqlite");
    config->database.port = 0;
    config->scanner.log_file = NULL;
    config->scanner.rescan_unchanged = 0;
    config->scanner.enable_inpx = 0;
    config->scanner.clear_database_inpx = 0;
    config->scanner.hash_algorithm = strdup("md5");
    config->scanner.log_level = LOG_INFO; // По умолчанию INFO уровень
    config->log_stream = stderr;

    char line[MAX_LINE];
    char current_section[64] = {0};

    while (fgets(line, sizeof(line), file)) {
        char *trimmed = line;
        while (*trimmed == ' ' || *trimmed == '\t') trimmed++;

        char *end = trimmed + strlen(trimmed) - 1;
        while (end > trimmed && (*end == '\n' || *end == '\r' || *end == ' ' || *end == '\t')) {
            *end = '\0';
            end--;
        }

        if (strlen(trimmed) == 0 || trimmed[0] == '#' || trimmed[0] == ';') {
            continue;
        }

        if (trimmed[0] == '[' && trimmed[strlen(trimmed)-1] == ']') {
            strncpy(current_section, trimmed + 1, strlen(trimmed) - 2);
            current_section[strlen(trimmed) - 2] = '\0';
            continue;
        }

        char *equals = strchr(trimmed, '=');
        if (!equals) continue;

        *equals = '\0';
        char *key = trimmed;
        char *value = equals + 1;

        while (*key == ' ' || *key == '\t') key++;
        char *key_end = key + strlen(key) - 1;
        while (key_end > key && (*key_end == ' ' || *key_end == '\t')) {
            *key_end = '\0';
            key_end--;
        }

        while (*value == ' ' || *value == '\t') value++;
        char *value_end = value + strlen(value) - 1;
        while (value_end > value && (*value_end == ' ' || *value_end == '\t')) {
            *value_end = '\0';
            value_end--;
        }

        if (strcmp(current_section, "database") == 0) {
            if (strcmp(key, "type") == 0) {
                free(config->database.type);
                config->database.type = strdup(value);
            } else if (strcmp(key, "path") == 0) {
                config->database.path = strdup(value);
            } else if (strcmp(key, "socket") == 0) {
                config->database.socket = strdup(value);
            } else if (strcmp(key, "flags") == 0) {
                config->database.flags = atoi(value);
            } else if (strcmp(key, "host") == 0) {
                config->database.host = strdup(value);
            } else if (strcmp(key, "user") == 0) {
                config->database.user = strdup(value);
            } else if (strcmp(key, "password") == 0) {
                config->database.password = strdup(value);
            } else if (strcmp(key, "database") == 0) {
                config->database.database = strdup(value);
            } else if (strcmp(key, "port") == 0) {
                config->database.port = atoi(value);
            }
        } else if (strcmp(current_section, "scanner") == 0) {
            if (strcmp(key, "books_dir") == 0) {
                config->scanner.books_dir = strdup(value);
            } else if (strcmp(key, "hash_algorithm") == 0) {
                free(config->scanner.hash_algorithm);
                config->scanner.hash_algorithm = strdup(value);
            } else if (strcmp(key, "log_file") == 0) {
                if (strcasecmp(value, "NULL") != 0 && strcasecmp(value, "STDERR") != 0) {
                    config->scanner.log_file = strdup(value);
                }
            } else if (strcmp(key, "rescan_unchanged") == 0) {
                config->scanner.rescan_unchanged = (strcasecmp(value, "yes") == 0 || strcasecmp(value, "true") == 0 || strcmp(value, "1") == 0);
            } else if (strcmp(key, "enable_inpx") == 0) {
                config->scanner.enable_inpx = (strcasecmp(value, "yes") == 0 || strcasecmp(value, "true") == 0 || strcmp(value, "1") == 0);
            } else if (strcmp(key, "clear_database_inpx") == 0) {
                config->scanner.clear_database_inpx = (strcasecmp(value, "yes") == 0 || strcasecmp(value, "true") == 0 || strcmp(value, "1") == 0);
            } else if (strcmp(key, "log_level") == 0) {
                if (strcasecmp(value, "debug") == 0) {
                    config->scanner.log_level = LOG_DEBUG;
                } else if (strcasecmp(value, "info") == 0) {
                    config->scanner.log_level = LOG_INFO;
                } else if (strcasecmp(value, "warning") == 0) {
                    config->scanner.log_level = LOG_WARNING;
                } else if (strcasecmp(value, "error") == 0) {
                    config->scanner.log_level = LOG_ERROR;
                }
            }
        }
    }

    fclose(file);

    if (config->scanner.log_file) {
        config->log_stream = fopen(config->scanner.log_file, "a");
        if (!config->log_stream) {
            fprintf(stderr, "Cannot open log file: %s\n", config->scanner.log_file);
            config->log_stream = stderr;
        }
    }

    return config;
}

void log_message(Config *config, const char *level, const char *format, ...) {
    if (!config || !config->log_stream) {
        return;
    }

    // Проверяем уровень логирования
    LogLevel message_level;
    if (strcmp(level, "DEBUG") == 0) message_level = LOG_DEBUG;
    else if (strcmp(level, "INFO") == 0) message_level = LOG_INFO;
    else if (strcmp(level, "WARNING") == 0) message_level = LOG_WARNING;
    else if (strcmp(level, "ERROR") == 0) message_level = LOG_ERROR;
    else message_level = LOG_INFO;

    // Если уровень сообщения ниже установленного в конфиге - пропускаем
    if (message_level < config->scanner.log_level) {
        return;
    }

    time_t now = time(NULL);
    struct tm *tm_info = localtime(&now);
    char timestamp[20];
    strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", tm_info);

    fprintf(config->log_stream, "[%s] %s: ", timestamp, level);

    va_list args;
    va_start(args, format);
    vfprintf(config->log_stream, format, args);
    va_end(args);

    fprintf(config->log_stream, "\n");
    fflush(config->log_stream);
}

char* find_config_file() {
    char *possible_paths[] = {
        "./config.ini",
        "./config/config.ini",
        "/etc/config.ini",
        NULL
    };

    for (int i = 0; possible_paths[i]; i++) {
        if (access(possible_paths[i], R_OK) == 0) {
            return strdup(possible_paths[i]);
        }
    }

    char exec_path[MAX_PATH];
    ssize_t len = readlink("/proc/self/exe", exec_path, sizeof(exec_path) - 1);
    if (len != -1) {
        exec_path[len] = '\0';
        char *last_slash = strrchr(exec_path, '/');
        if (last_slash) {
            *last_slash = '\0';
            char config_path[MAX_PATH];
            snprintf(config_path, sizeof(config_path), "%s/config.ini", exec_path);
            if (access(config_path, R_OK) == 0) {
                return strdup(config_path);
            }
        }
    }

    return NULL;
}

void free_config(Config *config) {
    if (!config) return;

    free(config->database.type);
    free(config->database.path);
    free(config->database.host);
    free(config->database.user);
    free(config->database.password);
    free(config->database.database);
    free(config->database.socket);
    free(config->scanner.books_dir);
    free(config->scanner.log_file);
    free(config->scanner.hash_algorithm);

    if (config->log_stream && config->log_stream != stderr) {
        fclose(config->log_stream);
    }

    free(config);
}
