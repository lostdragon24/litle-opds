#ifndef COMMON_H
#define COMMON_H

#ifndef _POSIX_C_SOURCE
#define _POSIX_C_SOURCE 200809L
#endif

#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <time.h>
#include <ctype.h>
#include <sys/stat.h>
#include <dirent.h>
#include <fcntl.h>


#ifndef LOGGING_H
#define LOGGING_H

#include "config.h"

// Макросы для удобного логирования
#define LOG_DEBUG(config, ...) log_message(config, "DEBUG", __VA_ARGS__)
#define LOG_INFO(config, ...) log_message(config, "INFO", __VA_ARGS__)
#define LOG_WARNING(config, ...) log_message(config, "WARNING", __VA_ARGS__)
#define LOG_ERROR(config, ...) log_message(config, "ERROR", __VA_ARGS__)

// Макрос для отладочных сообщений (только при DEBUG режиме)
#ifdef DEBUG
#define DBG(...) printf("DEBUG: " __VA_ARGS__)
#else
#define DBG(...) // ничего не делать в release
#endif

#endif


#endif
