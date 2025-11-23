#include "inpx_parser.h"
#include "utils.h"
#include <archive.h>
#include <archive_entry.h>
#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <ctype.h>
#include <errno.h>
#include "database.h"

#define FIELD_SEP '\x04'
#define RECORD_SEP1 '\x0D'  // CR
#define RECORD_SEP2 '\x0A'  // LF

static const char *DEFAULT_STRUCTURE = "AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS";

typedef struct {
    TFields field_type;
    const char *field_name;
} FieldMapping;

static const FieldMapping field_mappings[] = {
    {flAuthor, "AUTHOR"},
    {flGenre, "GENRE"},
    {flTitle, "TITLE"},
    {flSeries, "SERIES"},
    {flSerNo, "SERNO"},
    {flFile, "FILE"},
    {flSize, "SIZE"},
    {flLibID, "LIBID"},
    {flDeleted, "DEL"},
    {flExt, "EXT"},
    {flDate, "DATE"},
    {flLang, "LANG"},
    {flKeyWords, "KEYWORDS"},
    {flNone, NULL}
};

void get_inpx_fields(const char *structure_info, TImportContext *ctx) {
    const char *structure = structure_info && strlen(structure_info) > 0 ? structure_info : DEFAULT_STRUCTURE;

    // Подсчет полей
    ctx->fields_count = 1;
    for (const char *p = structure; *p; p++) {
        if (*p == ';') ctx->fields_count++;
    }

    // Заполнение
    ctx->fields = malloc(ctx->fields_count * sizeof(TFields));
    ctx->use_stored_folder = 0;

    char *s = strdup(structure);
    char *token, *saveptr;
    int i = 0;

    token = strtok_r(s, ";", &saveptr);
    while (token && i < ctx->fields_count) {
        TFields field_type = flNone;
        for (int j = 0; field_mappings[j].field_name != NULL; j++) {
            if (strcmp(token, field_mappings[j].field_name) == 0) {
                field_type = field_mappings[j].field_type;
                break;
            }
        }
        ctx->fields[i] = field_type;
        i++;
        token = strtok_r(NULL, ";", &saveptr);
    }
    free(s);
}

// Функция для парсинга CSV строки с разделителем \x04
int parse_csv_line(const char *line, char **fields, int max_fields) {
    if (!line || !fields) return 0;

    int field_count = 0;
    const char *start = line;

    for (int i = 0; line[i] != '\0' && field_count < max_fields; i++) {
        if (line[i] == FIELD_SEP || line[i] == '\0') {
            int len = &line[i] - start;
            fields[field_count] = malloc(len + 1);
            if (fields[field_count]) {
                strncpy(fields[field_count], start, len);
                fields[field_count][len] = '\0';
                field_count++;
            }
            start = &line[i + 1];

            if (line[i] == '\0') break;
        }
    }

    // Добавляем последнее поле если нужно
    if (field_count < max_fields && start < line + strlen(line)) {
        int len = strlen(start);
        fields[field_count] = malloc(len + 1);
        if (fields[field_count]) {
            strcpy(fields[field_count], start);
            field_count++;
        }
    }

    return field_count;
}

void free_csv_fields(char **fields, int count) {
    if (!fields) return;

    for (int i = 0; i < count; i++) {
        free(fields[i]);
    }
}

void parse_inpx_data(const char *input, TImportContext *ctx, int online_collection, BookMeta *meta,
                    char **file_name_ptr, char **file_ext_ptr) {
    (void)online_collection;

    if (!input || !ctx || !meta || !file_name_ptr || !file_ext_ptr) return;

    // Парсим CSV строку
    char *fields[20] = {0};
    int field_count = parse_csv_line(input, fields, 20);

    if (field_count == 0) {
        return;
    }

    int max_fields = (field_count <= ctx->fields_count) ? field_count : ctx->fields_count;

    for (int i = 0; i < max_fields; i++) {
        if (!fields[i] || strlen(fields[i]) == 0) continue;

        switch (ctx->fields[i]) {
            case flAuthor: {
                // AUTHOR поле: Фамилия:Имя:Отчество (1-е поле)
                if (!meta->author) {
                    char *author_str = fields[i];
                    char *last_name = author_str;
                    char *first_name = strchr(author_str, ':');

                    if (first_name) {
                        *first_name = '\0';
                        first_name++;
                        char *middle_name = strchr(first_name, ':');

                        // Заменяем запятые на пробелы
                        char clean_last[100] = {0};
                        char clean_first[100] = {0};
                        char clean_middle[100] = {0};

                        // Фамилия
                        char *dst = clean_last;
                        for (char *src = last_name; *src && dst < clean_last + sizeof(clean_last) - 1; src++) {
                            *dst++ = (*src == ',') ? ' ' : *src;
                        }
                        *dst = '\0';

                        if (middle_name) {
                            *middle_name = '\0';
                            middle_name++;

                            // Имя
                            dst = clean_first;
                            for (char *src = first_name; *src && dst < clean_first + sizeof(clean_first) - 1; src++) {
                                *dst++ = (*src == ',') ? ' ' : *src;
                            }
                            *dst = '\0';

                            // Отчество
                            dst = clean_middle;
                            for (char *src = middle_name; *src && dst < clean_middle + sizeof(clean_middle) - 1; src++) {
                                *dst++ = (*src == ',') ? ' ' : *src;
                            }
                            *dst = '\0';

                            meta->author = malloc(strlen(clean_last) + strlen(clean_first) + strlen(clean_middle) + 3);
                            sprintf(meta->author, "%s %s %s", clean_last, clean_first, clean_middle);
                        } else {
                            // Имя
                            dst = clean_first;
                            for (char *src = first_name; *src && dst < clean_first + sizeof(clean_first) - 1; src++) {
                                *dst++ = (*src == ',') ? ' ' : *src;
                            }
                            *dst = '\0';

                            meta->author = malloc(strlen(clean_last) + strlen(clean_first) + 2);
                            sprintf(meta->author, "%s %s", clean_last, clean_first);
                        }
                    } else {
                        // Только фамилия
                        char clean_last[100] = {0};
                        char *dst = clean_last;
                        for (char *src = last_name; *src && dst < clean_last + sizeof(clean_last) - 1; src++) {
                            *dst++ = (*src == ',') ? ' ' : *src;
                        }
                        *dst = '\0';
                        meta->author = strdup(clean_last);
                    }
                }
                break;
            }

            case flTitle:  // 3-е поле
                if (!meta->title) {
                    meta->title = strdup(fields[i]);
                }
                break;

            case flSeries:  // 4-е поле
                if (!meta->series) {
                    char *series_str = fields[i];

                    // Находим первую открывающую скобку
                    char *first_bracket = strchr(series_str, '(');

                    if (first_bracket) {
                        // Берем только часть до первой скобки
                        size_t series_len = first_bracket - series_str;

                        // Убираем пробелы в конце
                        while (series_len > 0 && isspace((unsigned char)series_str[series_len - 1])) {
                            series_len--;
                        }

                        meta->series = malloc(series_len + 1);
                        strncpy(meta->series, series_str, series_len);
                        meta->series[series_len] = '\0';
                    } else {
                        // Нет скобок - берем всю строку
                        meta->series = strdup(series_str);
                    }

                    log_message(NULL, "DEBUG", "Parsed series: '%s' (from: '%s')",
                               meta->series, series_str);
                }
                break;

                case flSize:  // 7-е поле - РАЗМЕР ФАЙЛА
    if (strlen(fields[i]) > 0) {
        meta->file_size = atol(fields[i]);
        printf("DEBUG: [PARSE_INPX_DATA] Field SIZE found: '%s' -> parsed as: %ld\n",
               fields[i], meta->file_size);
    } else {
        printf("DEBUG: [PARSE_INPX_DATA] Field SIZE is empty\n");
    }
    break;

            case flSerNo:  // 5-е поле
                // SERNO поле - номер в серии
                if (strlen(fields[i]) > 0) {
                    int serno_value = atoi(fields[i]);
                    if (serno_value > 0) {
                        meta->series_number = serno_value;
                        log_message(NULL, "DEBUG", "Set series number from SERNO: %d", serno_value);
                    }
                }
                break;

            case flFile:  // 6-е поле - ИМЯ ФАЙЛА
                // Сохраняем FILE для имени файла
                *file_name_ptr = strdup(fields[i]);
                break;

            case flExt:  // 10-е поле - расширение файла
                *file_ext_ptr = strdup(fields[i]);
                break;

            case flGenre:  // 2-е поле
                if (!meta->genre) {
                    char *genre_str = fields[i];
                    // Находим первое двоеточие
                    char *first_colon = strchr(genre_str, ':');
                    if (first_colon) {
                        // Берем только часть до первого двоеточия
                        size_t genre_len = first_colon - genre_str;
                        meta->genre = malloc(genre_len + 1);
                        strncpy(meta->genre, genre_str, genre_len);
                        meta->genre[genre_len] = '\0';
                    } else {
                        // Если двоеточий нет, берем весь жанр
                        meta->genre = strdup(genre_str);
                    }
                }
                break;

            case flLang:  // 12-е поле
                if (!meta->language) {
                    meta->language = strdup(fields[i]);
                }
                break;

            case flDate:  // 11-е поле
                if (strlen(fields[i]) >= 4) {
                    meta->year = atoi(fields[i]);
                }
                break;

            default:
                break;
        }
    }

    free_csv_fields(fields, field_count);
}

int import_inpx_collection(const char *inpx_filename, DatabaseHandle *db_handle, Config *config) {
    printf("=== INPX IMPORT DEBUG ===\n");
    printf("DEBUG: Starting INPX import from: %s\n", inpx_filename);
    log_message(config, "INFO", "Starting CSV-based INPX import: %s", inpx_filename);

    // Проверяем существование и доступность файла
    if (access(inpx_filename, R_OK) != 0) {
        printf("ERROR: Cannot access INPX file: %s (errno: %d)\n", inpx_filename, errno);
        log_message(config, "ERROR", "Cannot access INPX file: %s", inpx_filename);
        return 0;
    }

    // Проверяем размер файла
    struct stat st;
    if (stat(inpx_filename, &st) == 0) {
        printf("DEBUG: INPX file size: %lld bytes\n", (long long)st.st_size);
        if (st.st_size == 0) {
            printf("ERROR: INPX file is empty: %s\n", inpx_filename);
            log_message(config, "ERROR", "INPX file is empty: %s", inpx_filename);
            return 0;
        }
    } else {
        printf("ERROR: Cannot stat INPX file: %s (errno: %d)\n", inpx_filename, errno);
        return 0;
    }

    struct archive *a = archive_read_new();

    // Пробуем разные форматы поддержки
    archive_read_support_format_zip(a);
    archive_read_support_format_all(a);  // Поддержка всех форматов
    archive_read_support_filter_all(a);

    printf("DEBUG: Attempting to open INPX archive...\n");
    int r = archive_read_open_filename(a, inpx_filename, 10240);

    if (r != ARCHIVE_OK) {
        printf("ERROR: Cannot open INPX file as archive: %s\n", archive_error_string(a));
        printf("ERROR: Archive error code: %d\n", r);
        log_message(config, "ERROR", "Cannot open INPX file: %s", archive_error_string(a));

        // Попробуем определить реальный тип файла
        printf("DEBUG: Checking file type...\n");
        FILE *test_file = fopen(inpx_filename, "rb");
        if (test_file) {
            unsigned char header[4];
            if (fread(header, 1, 4, test_file) == 4) {
                printf("DEBUG: File header: %02X %02X %02X %02X\n",
                       header[0], header[1], header[2], header[3]);

                // Проверяем сигнатуры разных архивных форматов
                if (header[0] == 0x50 && header[1] == 0x4B) {
                    printf("DEBUG: File is ZIP archive (PK header)\n");
                } else if (header[0] == 0x52 && header[1] == 0x61 && header[2] == 0x72 && header[3] == 0x21) {
                    printf("DEBUG: File is RAR archive\n");
                } else if (header[0] == 0x37 && header[1] == 0x7A) {
                    printf("DEBUG: File is 7-Zip archive\n");
                } else {
                    printf("DEBUG: Unknown file format\n");
                }
            }
            fclose(test_file);
        }

        archive_read_free(a);
        return 0;
    }

    printf("DEBUG: Successfully opened INPX archive\n");
    log_message(config, "DEBUG", "Successfully opened INPX archive");

    TImportContext ctx = {0};
    get_inpx_fields(DEFAULT_STRUCTURE, &ctx);

    struct archive_entry *entry;
    int books_imported = 0;
    int files_processed = 0;
    int total_entries = 0;

    printf("DEBUG: Reading archive contents...\n");

    // Читаем все записи в архиве
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        total_entries++;
        const char *filename = archive_entry_pathname(entry);
        long long size = archive_entry_size(entry);
        int filetype = archive_entry_filetype(entry);

        printf("DEBUG: Archive entry %d: %s (size: %lld, type: %d)\n",
               total_entries, filename, size, filetype);

        // Пропускаем служебные файлы
        if (strstr(filename, "structure.info") || strstr(filename, "collection.info") ||
            strstr(filename, "version.info")) {
            printf("DEBUG: Skipping info file: %s\n", filename);
            archive_read_data_skip(a);
            continue;
        }

        // Ищем INP файлы
        const char *ext = strrchr(filename, '.');
        if (!ext) {
            printf("DEBUG: Skipping file without extension: %s\n", filename);
            archive_read_data_skip(a);
            continue;
        }

        if (strcasecmp(ext, ".inp") != 0) {
            printf("DEBUG: Skipping non-INP file: %s\n", filename);
            archive_read_data_skip(a);
            continue;
        }

        files_processed++;
        printf("DEBUG: >>> Found INP file: %s (size: %lld)\n", filename, size);
        log_message(config, "INFO", "Processing INP file: %s", filename);

        // Читаем содержимое INP файла
        if (size == 0) {
            printf("DEBUG: INP file is empty: %s\n", filename);
            archive_read_data_skip(a);
            continue;
        }

        if (size > 100 * 1024 * 1024) { // Ограничение 100MB
            printf("DEBUG: INP file too large: %s (%lld bytes)\n", filename, size);
            archive_read_data_skip(a);
            continue;
        }

        char *content = malloc(size + 1);
        if (!content) {
            printf("ERROR: Cannot allocate %lld bytes for INP file: %s\n", size, filename);
            archive_read_data_skip(a);
            continue;
        }

        la_ssize_t bytes_read = archive_read_data(a, content, size);
        if (bytes_read != size) {
            printf("ERROR: Failed to read INP file: %s (read %zd of %lld bytes)\n",
                   filename, bytes_read, size);
            free(content);
            archive_read_data_skip(a);
            continue;
        }
        content[size] = '\0';

        printf("DEBUG: Successfully read INP file: %s (%zd bytes)\n", filename, bytes_read);
        printf("DEBUG: First 500 chars:\n%.500s\n", content);
        printf("DEBUG: Last 100 chars:\n%.100s\n", content + (bytes_read - 100 > 0 ? bytes_read - 100 : 0));

        // Обрабатываем каждую строку (книгу)
        char *line = content;
        int line_num = 0;
        int books_in_file = 0;

        while (line && *line ) { // Ограничим для отладки
            // Находим конец строки (CR LF)
            char *line_end = line;
            while (*line_end && !(*line_end == RECORD_SEP1 || *line_end == RECORD_SEP2)) {
                line_end++;
            }

            if (*line_end == '\0') {
                // Последняя строка в файле
                break;
            }

            // Сохраняем текущую позицию для следующей строки
            char *next_line = line_end;
            if (*next_line == RECORD_SEP1) next_line++;
            if (*next_line == RECORD_SEP2) next_line++;

            // Завершаем текущую строку
            *line_end = '\0';

            // Пропускаем пустые строки
            if (strlen(line) > 10) {
                BookMeta meta = {0};
                char *file_name = NULL;
                char *file_ext = NULL;

                printf("DEBUG: Line %d: %.200s\n", line_num, line);

                parse_inpx_data(line, &ctx, 0, &meta, &file_name, &file_ext);

                printf("DEBUG: Parsed - Title: '%s', Author: '%s', File: '%s', Ext: '%s'\n",
                       meta.title ? meta.title : "NULL",
                       meta.author ? meta.author : "NULL",
                       file_name ? file_name : "NULL",
                       file_ext ? file_ext : "NULL");

                // Добавляем книгу только если есть название, автор и имя файла
                if (meta.title && strlen(meta.title) > 0 &&
                    meta.author && strlen(meta.author) > 0 &&
                    file_name && strlen(file_name) > 0) {

                    // Формируем путь к файлу: FILE + EXT
                    char internal_path[256];
                    if (file_ext && strlen(file_ext) > 0) {
                        snprintf(internal_path, sizeof(internal_path), "%s.%s", file_name, file_ext);
                    } else {
                        snprintf(internal_path, sizeof(internal_path), "%s.fb2", file_name);
                    }

                    // Формируем имя ZIP архива из имени INP файла
                    char zip_filename[256];
                    const char *inp_ext = strrchr(filename, '.');
                    snprintf(zip_filename, sizeof(zip_filename), "%.*s.zip",
                            (int)(inp_ext - filename), filename);

                    // Путь к архиву
                    char archive_path[512];
                    snprintf(archive_path, sizeof(archive_path), "%s/%s",
                            config->scanner.books_dir, zip_filename);

                    // Путь к файлу для отображения
                    char file_path[512];
                    snprintf(file_path, sizeof(file_path), "%s/%s",
                            config->scanner.books_dir, zip_filename);

                    printf("DEBUG: >>> IMPORTING BOOK: '%s' by '%s' (file: %s)\n",
                           meta.title, meta.author, internal_path);

                    insert_book_to_db(db_handle, file_path, &meta, archive_path, internal_path, config);
                    books_imported++;
                    books_in_file++;

                    if (books_imported % 10 == 0) {
                        printf("INFO: Imported %d books...\n", books_imported);
                        log_message(config, "INFO", "Imported %d books...", books_imported);
                    }
                }

                free(file_name);
                free(file_ext);
                free_book_meta(&meta);
            }

            line = next_line;
            line_num++;

            // Ограничим для отладки
        //    if (books_imported >= 50) {
        //        printf("DEBUG: Reached limit of 50 books for debugging\n");
        //        break;
        //    }
        }

        printf("DEBUG: Processed %d lines in INP file, imported %d books\n", line_num, books_in_file);
        free(content);

        //if (books_imported >= 50) {
        //    break;
        //}
    }

    printf("DEBUG: Total archive entries processed: %d\n", total_entries);
    printf("DEBUG: INP files processed: %d\n", files_processed);
    printf("DEBUG: Books imported: %d\n", books_imported);

    archive_read_close(a);
    archive_read_free(a);
    free_import_context(&ctx);

    if (books_imported > 0) {
        printf("INFO: INPX import completed: %d books from %d INP files\n", books_imported, files_processed);
        log_message(config, "INFO", "INPX import completed: %d books from %d INP files",
                    books_imported, files_processed);
    } else {
        printf("WARNING: No books imported from INPX file\n");
        log_message(config, "WARNING", "No books imported from INPX file");
    }

    return books_imported;
}

void free_import_context(TImportContext *ctx) {
    if (ctx->fields) free(ctx->fields);
    memset(ctx, 0, sizeof(TImportContext));
}

// Заглушки для неиспользуемых функций (можно удалить)
char** extract_strings(const char *content, char separator, int *count) {
    (void)content;
    (void)separator;
    *count = 0;
    return NULL;
}

void free_strings_array(char **array, int count) {
    (void)array;
    (void)count;
}

char* read_inp_file_from_zip(struct archive *a, const char *filename, Config *config) {
    (void)a;
    (void)filename;
    (void)config;
    return NULL;
}
