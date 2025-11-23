#ifndef DATABASE_MYSQL_H
#define DATABASE_MYSQL_H

#include "config.h"
#include "database.h"
#include <mysql/mysql.h>

// Структура для MySQL соединения
typedef struct {
    MYSQL *mysql;
    MYSQL_STMT *stmt;
} MySQLConnection;

// Переименуем функции, чтобы избежать конфликта с MySQL библиотекой
MySQLConnection* mysql_conn_connect(Config *config);
void mysql_conn_close(MySQLConnection *mysql_conn);
int mysql_execute_query(MySQLConnection *mysql_conn, const char *sql, Config *config);
int mysql_create_tables(MySQLConnection *mysql_conn, Config *config);
int mysql_create_archive_table(MySQLConnection *mysql_conn, Config *config);
int mysql_archive_needs_rescan(MySQLConnection *mysql_conn, const char *archive_path, const char *current_hash, Config *config);
void mysql_update_archive_info(MySQLConnection *mysql_conn, const char *archive_path, const char *hash, int file_count, long total_size, Config *config);
int check_book_exists(MySQLConnection *mysql_conn, const char *filepath, BookMeta *meta,
                     const char *archive_path, const char *internal_path, Config *config);
void mysql_insert_book(MySQLConnection *mysql_conn, const char *filepath, BookMeta *meta,
                      const char *archive_path, const char *internal_path, Config *config);
#endif
