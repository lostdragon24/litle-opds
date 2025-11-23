#include "inpxparser.h"
#include <QFile>
#include <QFileInfo>
#include <QDir>
#include <QSqlQuery>
#include <QSqlError>
#include <QDebug>
#include <QTextStream>
#include <QRegularExpression>
#include <archive.h>
#include <archive_entry.h>

InpxParser::InpxParser(QSqlDatabase database)
    : m_database(database)
{
}

void InpxParser::setProgressCallback(std::function<void(int, const QString&)> callback)
{
    m_progressCallback = callback;
}

bool InpxParser::importInpxCollection(const QString &inpxFilePath, const QString &booksDir)
{
    QFileInfo inpxInfo(inpxFilePath);
    if (!inpxInfo.exists()) {
        qDebug() << "INPX file does not exist:" << inpxFilePath;
        if (m_progressCallback) {
            m_progressCallback(100, "INPX файл не существует: " + inpxFilePath);
        }
        return false;
    }

    qDebug() << "=== INPX PARSER START ===";
    qDebug() << "INPX file:" << inpxFilePath;
    qDebug() << "Books directory:" << booksDir;

    if (m_progressCallback) {
        m_progressCallback(0, "Начинаем импорт INPX: " + inpxInfo.fileName());
    }

    // Открываем INPX как архив
    struct archive *a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    int r = archive_read_open_filename(a, inpxFilePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        qDebug() << "Failed to open INPX archive:" << archive_error_string(a);
        if (m_progressCallback) {
            m_progressCallback(100, "Не удалось открыть INPX архив: " + QString(archive_error_string(a)));
        }
        archive_read_free(a);
        return false;
    }

    qDebug() << "Successfully opened INPX archive";
    if (m_progressCallback) {
        m_progressCallback(10, "INPX архив открыт, поиск INP файлов...");
    }

    // Ищем INP файлы в архиве
    QVector<QString> inpFiles;
    struct archive_entry *entry;

    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char *filename = archive_entry_pathname(entry);
        if (filename) {
            QString qfilename = QString::fromUtf8(filename);
            if (qfilename.toLower().endsWith(".inp")) {
                inpFiles.append(qfilename);
                qDebug() << "Found INP file in archive:" << qfilename;
            }
        }
        archive_read_data_skip(a);
    }

    if (inpFiles.isEmpty()) {
        qDebug() << "No INP files found in INPX archive";
        if (m_progressCallback) {
            m_progressCallback(100, "INP файлы не найдены в архиве");
        }
        archive_read_free(a);
        return false;
    }

    qDebug() << "Found" << inpFiles.size() << "INP files in archive";

    // Закрываем и переоткрываем архив для чтения файлов
    archive_read_close(a);
    archive_read_free(a);

    int totalBooks = 0;
    int processedFiles = 0;

    // Базовая структура полей как в C коде
    ImportContext ctx;
    getInpxFields("AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS", ctx);

    qDebug() << "Field structure count:" << ctx.fields.size();

    for (const QString &inpFile : inpFiles) {
        if (m_progressCallback) {
            int progress = 10 + (processedFiles * 80 / inpFiles.size());
            m_progressCallback(progress, "Обработка INP файла: " + inpFile);
        }

        qDebug() << "Processing INP file:" << inpFile;

        // Читаем INP файл из архива
        QByteArray fileContent = readFileFromArchive(inpxFilePath, inpFile);
        if (fileContent.isEmpty()) {
            qDebug() << "Failed to read INP file from archive:" << inpFile;
            processedFiles++;
            continue;
        }

        qDebug() << "Successfully read INP file, size:" << fileContent.size();

        // Пробуем разные кодировки
        QString content;
        content = QString::fromUtf8(fileContent);

        if (content.contains(QChar::ReplacementCharacter)) {
            // Пробуем Windows-1251
            content = QString::fromLocal8Bit(fileContent);
            qDebug() << "Using Windows-1251 encoding for INP file";
        } else {
            qDebug() << "Using UTF-8 encoding for INP file";
        }

        QStringList lines = content.split(QRegularExpression("[\r\n]"), Qt::SkipEmptyParts);
        qDebug() << "INP file lines count:" << lines.size();

        int booksInFile = 0;
        int lineNumber = 0;

        for (const QString &line : lines) {
            QString trimmedLine = line.trimmed();
            lineNumber++;

            if (trimmedLine.isEmpty() || trimmedLine.length() < 10) {
                continue;
            }

            InpxBookMeta meta;
            if (parseInpData(trimmedLine, ctx, meta)) {
                meta.archiveName = QFileInfo(inpxFilePath).fileName();
                meta.inpFileName = inpFile;

                if (insertBookToDatabase(meta, booksDir)) {
                    booksInFile++;
                    totalBooks++;

                    if (totalBooks % 100 == 0) {
                        qDebug() << "Imported" << totalBooks << "books...";
                        if (m_progressCallback) {
                            int progress = 10 + (processedFiles * 80 / inpFiles.size());
                            m_progressCallback(progress, QString("Импортировано %1 книг...").arg(totalBooks));
                        }
                    }
                }
            }

            // Для отладки ограничим количество
            if (lineNumber > 1000 && totalBooks > 0) {
                qDebug() << "Reached line limit for testing, imported" << booksInFile << "books from this file";
                break;
            }
        }

        processedFiles++;
        qDebug() << "Processed INP file:" << inpFile << "books:" << booksInFile;

        if (m_progressCallback) {
            int progress = 10 + (processedFiles * 80 / inpFiles.size());
            m_progressCallback(progress, QString("Обработано %1/%2 INP файлов").arg(processedFiles).arg(inpFiles.size()));
        }
    }

    qDebug() << "INPX import completed. Total books:" << totalBooks;

    if (m_progressCallback) {
        if (totalBooks > 0) {
            m_progressCallback(100, QString("Импорт завершен. Обработано книг: %1").arg(totalBooks));
        } else {
            m_progressCallback(100, "Импорт завершен. Книги не найдены");
        }
    }

    return totalBooks > 0;
}

QByteArray InpxParser::readFileFromArchive(const QString &archivePath, const QString &internalPath)
{
    QByteArray content;
    struct archive *a;
    struct archive_entry *entry;
    int r;

    a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    r = archive_read_open_filename(a, archivePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        qDebug() << "Failed to open archive for reading:" << archivePath << archive_error_string(a);
        archive_read_free(a);
        return content;
    }

    bool found = false;
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* entry_path = archive_entry_pathname(entry);
        if (entry_path && QString::fromUtf8(entry_path) == internalPath) {
            la_int64_t size = archive_entry_size(entry);
            if (size > 0) {
                content.resize(size);
                la_ssize_t read_size = archive_read_data(a, content.data(), size);
                if (read_size != size) {
                    qDebug() << "Failed to read full content from archive entry. Expected:" << size << "Got:" << read_size;
                    content.clear();
                } else {
                    found = true;
                    qDebug() << "Successfully extracted" << internalPath << "from archive" << archivePath << "size:" << size;
                }
            }
            break;
        } else {
            archive_read_data_skip(a);
        }
    }

    if (!found) {
        qDebug() << "File" << internalPath << "not found in archive" << archivePath;
    }

    archive_read_close(a);
    archive_read_free(a);
    return content;
}

void InpxParser::getInpxFields(const QString &structureInfo, ImportContext &ctx)
{
    QString structure = structureInfo.isEmpty() ?
        "AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS" :
        structureInfo;

    QStringList fieldsList = structure.split(';');
    ctx.fields.clear();

    QHash<QString, FieldType> fieldMap = {
        {"AUTHOR", FieldAuthor},
        {"GENRE", FieldGenre},
        {"TITLE", FieldTitle},
        {"SERIES", FieldSeries},
        {"SERNO", FieldSeriesNumber},
        {"FILE", FieldFile},
        {"SIZE", FieldSize},
        {"EXT", FieldExt},
        {"LANG", FieldLang},
        {"DATE", FieldDate}
    };

    for (const QString &field : fieldsList) {
        ctx.fields.append(fieldMap.value(field, FieldNone));
    }

    qDebug() << "Field mapping:" << structure << "->" << ctx.fields.size() << "fields";
}

bool InpxParser::parseInpData(const QString &line, const ImportContext &ctx, InpxBookMeta &meta)
{
    // Разделяем строку по символу \x04 как в C коде
    QStringList fields;
    int start = 0;
    int end = 0;

    for (int i = 0; i < line.length(); ++i) {
        if (line[i] == QChar(0x04) || i == line.length() - 1) {
            end = (i == line.length() - 1) ? i + 1 : i;
            QString field = line.mid(start, end - start).trimmed();
            fields.append(field);
            start = i + 1;
        }
    }

    if (fields.size() < ctx.fields.size()) {
        qDebug() << "Not enough fields in line:" << fields.size() << "expected:" << ctx.fields.size();
        return false;
    }

    qDebug() << "Parsing line with" << fields.size() << "fields";

    for (int i = 0; i < qMin(fields.size(), ctx.fields.size()); ++i) {
        const QString &fieldValue = fields[i].trimmed();
        if (fieldValue.isEmpty()) continue;

        switch (ctx.fields[i]) {
        case FieldAuthor:
            if (meta.author.isEmpty()) {
                meta.author = cleanAuthorName(fieldValue);
                qDebug() << "Parsed author:" << meta.author;
            }
            break;

        case FieldTitle:
            if (meta.title.isEmpty()) {
                meta.title = fieldValue;
                qDebug() << "Parsed title:" << meta.title;
            }
            break;

        case FieldSeries:
            if (meta.series.isEmpty()) {
                // Убираем часть в скобках как в C коде
                int bracketPos = fieldValue.indexOf('(');
                if (bracketPos > 0) {
                    meta.series = fieldValue.left(bracketPos).trimmed();
                } else {
                    meta.series = fieldValue;
                }
                qDebug() << "Parsed series:" << meta.series;
            }
            break;

        case FieldSeriesNumber:
            if (meta.seriesNumber == 0) {
                bool ok;
                int num = fieldValue.toInt(&ok);
                if (ok && num > 0) {
                    meta.seriesNumber = num;
                    qDebug() << "Parsed series number:" << meta.seriesNumber;
                }
            }
            break;

        case FieldGenre:
            if (meta.genre.isEmpty()) {
                // Берем часть до первого двоеточия как в C коде
                int colonPos = fieldValue.indexOf(':');
                if (colonPos > 0) {
                    meta.genre = fieldValue.left(colonPos).trimmed();
                } else {
                    meta.genre = fieldValue;
                }
                qDebug() << "Parsed genre:" << meta.genre;
            }
            break;

        case FieldFile:
            meta.fileName = fieldValue;
            qDebug() << "Parsed file name:" << meta.fileName;
            break;

        case FieldSize:
            meta.fileSize = fieldValue.toLongLong();
            qDebug() << "Parsed file size:" << meta.fileSize;
            break;

        case FieldExt:
            meta.fileExt = fieldValue;
            qDebug() << "Parsed file extension:" << meta.fileExt;
            break;

        case FieldLang:
            meta.language = fieldValue;
            qDebug() << "Parsed language:" << meta.language;
            break;

        case FieldDate:
            if (fieldValue.length() >= 4) {
                meta.year = fieldValue.left(4).toInt();
                qDebug() << "Parsed year:" << meta.year;
            }
            break;

        default:
            break;
        }
    }

    // Проверяем обязательные поля
    bool hasRequiredFields = !meta.title.isEmpty() && !meta.author.isEmpty() && !meta.fileName.isEmpty();

    if (hasRequiredFields) {
        qDebug() << "Successfully parsed book:" << meta.title << "by" << meta.author;
    } else {
        qDebug() << "Missing required fields - title:" << meta.title
                 << "author:" << meta.author << "file:" << meta.fileName;
    }

    return hasRequiredFields;
}

QString InpxParser::cleanAuthorName(const QString &authorStr)
{
    // Формат: Фамилия:Имя:Отчество
    QStringList parts = authorStr.split(':');

    if (parts.isEmpty()) return authorStr;

    // Очищаем от запятых
    for (QString &part : parts) {
        part = part.replace(',', ' ').trimmed();
    }

    QString result;
    if (parts.size() >= 3) {
        result = QString("%1 %2 %3").arg(parts[0], parts[1], parts[2]);
    } else if (parts.size() == 2) {
        result = QString("%1 %2").arg(parts[0], parts[1]);
    } else {
        result = parts[0];
    }

    return result.trimmed();
}

bool InpxParser::insertBookToDatabase(const InpxBookMeta &meta, const QString &booksDir)
{
    // Формируем имя архива на основе INP файла
    QString archiveBaseName = QFileInfo(meta.inpFileName).completeBaseName();
    QString archivePath = booksDir + "/" + archiveBaseName + ".zip";

    // Формируем внутренний путь к файлу в архиве
    QString internalPath = meta.fileName;
    if (!meta.fileExt.isEmpty()) {
        internalPath += "." + meta.fileExt;
    } else {
        internalPath += ".fb2"; // По умолчанию
    }

    qDebug() << "Inserting book to DB - Archive:" << archivePath << "Internal:" << internalPath;

    // Проверяем, существует ли книга уже в базе
    QSqlQuery checkQuery(m_database);
    checkQuery.prepare("SELECT COUNT(*) FROM books WHERE file_name = ? OR (title = ? AND author = ?)");
    checkQuery.addBindValue(internalPath);
    checkQuery.addBindValue(meta.title);
    checkQuery.addBindValue(meta.author);

    if (checkQuery.exec() && checkQuery.next()) {
        if (checkQuery.value(0).toInt() > 0) {
            qDebug() << "Book already exists:" << meta.title << "-" << meta.author;
            return false; // Книга уже существует
        }
    } else {
        qDebug() << "Check query error:" << checkQuery.lastError().text();
    }

    // Добавляем книгу в базу
    QSqlQuery insertQuery(m_database);
    insertQuery.prepare(
        "INSERT INTO books (file_path, file_name, file_size, file_type, title, author, "
        "series, series_number, genre, language, year, archive_path, archive_internal_path, added_date) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );

    // file_path - для отображения в интерфейсе
    QString displayPath = archivePath + "/" + internalPath;

    insertQuery.addBindValue(displayPath);
    insertQuery.addBindValue(internalPath);
    insertQuery.addBindValue(meta.fileSize);
    insertQuery.addBindValue(meta.fileExt.isEmpty() ? "fb2" : meta.fileExt);
    insertQuery.addBindValue(meta.title);
    insertQuery.addBindValue(meta.author);
    insertQuery.addBindValue(meta.series);
    insertQuery.addBindValue(meta.seriesNumber);
    insertQuery.addBindValue(meta.genre);
    insertQuery.addBindValue(meta.language);
    insertQuery.addBindValue(meta.year);
    insertQuery.addBindValue(archivePath);
    insertQuery.addBindValue(internalPath);

    if (insertQuery.exec()) {
        qDebug() << "Book added from INPX:" << meta.title << "-" << meta.author;
        return true;
    } else {
        qDebug() << "Error adding book from INPX:" << insertQuery.lastError().text();
        return false;
    }
}
