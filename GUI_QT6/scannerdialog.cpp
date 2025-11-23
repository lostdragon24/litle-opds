#include "scannerdialog.h"
#include "ui_scannerdialog.h"
#include <QFileDialog>
#include <QMessageBox>
#include <QDir>
#include <QSettings>
#include <QSqlQuery>
#include <QSqlError>
#include <QDebug>
#include <QFileInfo>
#include <QTimer>
#include <QDirIterator>
#include <QCryptographicHash>
#include <QTemporaryFile>

// BookScanner implementation
BookScanner::BookScanner(QSqlDatabase database, const QString &booksDir, bool useInpx, const QString &inpxFile)
    : m_database(database)
    , m_booksDir(booksDir)
    , m_useInpx(useInpx)
    , m_inpxFile(inpxFile)
    , m_abort(false)
    , m_parser(new BookParser())
    , m_archiveHandler(new ArchiveHandler())
{
    qDebug() << "BookScanner created with dir:" << booksDir << "useInpx:" << useInpx << "inpxFile:" << inpxFile;
}

BookScanner::~BookScanner()
{
    delete m_parser;
    delete m_archiveHandler;
}

void BookScanner::cancelScanning()
{
    m_abort = true;
    qDebug() << "Scanning cancellation requested";
}

void BookScanner::forceRescanAllArchives()
{
    QSqlQuery query(m_database);
    if (query.exec("UPDATE archives SET needs_rescan = 1")) {
        qDebug() << "All archives marked for rescan";
    } else {
        qDebug() << "Failed to mark archives for rescan:" << query.lastError().text();
    }
}

bool BookScanner::shouldRescanArchive(const QString &archivePath)
{
    QSqlQuery query(m_database);
    query.prepare("SELECT archive_hash, last_modified, needs_rescan FROM archives WHERE archive_path = ?");
    query.addBindValue(archivePath);

    if (!query.exec() || !query.next()) {
        // Архив не найден в базе - нужно сканировать
        qDebug() << "Archive not in database, will scan:" << archivePath;
        return true;
    }

    // Получаем текущую информацию об архиве
    ArchiveInfo currentInfo = m_archiveHandler->getArchiveInfo(archivePath);
    QString currentHash = QString::fromUtf8(currentInfo.hash);
    QString storedHash = query.value(0).toString();
    qint64 storedLastModified = query.value(1).toLongLong();
    bool needsRescan = query.value(2).toBool();

    qDebug() << "Archive check - Current hash:" << currentHash << "Stored hash:" << storedHash;
    qDebug() << "Current modified:" << currentInfo.lastModified << "Stored modified:" << storedLastModified;

    // Проверяем изменения
    if (needsRescan) {
        qDebug() << "Archive marked for rescan:" << archivePath;
        return true;
    }

    if (currentHash != storedHash) {
        qDebug() << "Archive hash changed, rescanning:" << archivePath;
        return true;
    }

    if (currentInfo.lastModified != storedLastModified) {
        qDebug() << "Archive modified, rescanning:" << archivePath;
        return true;
    }

    qDebug() << "Archive unchanged, skipping:" << archivePath;
    return false;
}


void BookScanner::updateArchiveInfo(const QString &archivePath, bool needsRescan)
{
    ArchiveInfo info = m_archiveHandler->getArchiveInfo(archivePath);

    QSqlQuery query(m_database);

    // Используем INSERT OR REPLACE для SQLite и ON DUPLICATE KEY UPDATE для MySQL
    if (m_database.driverName().contains("SQLITE")) {
        query.prepare(
            "INSERT OR REPLACE INTO archives (archive_path, archive_hash, file_count, total_size, last_modified, last_scanned, needs_rescan) "
            "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
        );
    } else {
        query.prepare(
            "INSERT INTO archives (archive_path, archive_hash, file_count, total_size, last_modified, last_scanned, needs_rescan) "
            "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?) "
            "ON DUPLICATE KEY UPDATE archive_hash = VALUES(archive_hash), file_count = VALUES(file_count), "
            "total_size = VALUES(total_size), last_modified = VALUES(last_modified), last_scanned = VALUES(last_scanned), needs_rescan = VALUES(needs_rescan)"
        );
    }

    query.addBindValue(info.path);
    query.addBindValue(QString::fromUtf8(info.hash));
    query.addBindValue(info.fileCount);
    query.addBindValue(info.size);
    query.addBindValue(info.lastModified);
    query.addBindValue(needsRescan);

    if (!query.exec()) {
        qDebug() << "Failed to update archive info:" << query.lastError().text();
    } else {
        qDebug() << "Archive info updated:" << archivePath << "files:" << info.fileCount << "size:" << info.size << "hash:" << QString::fromUtf8(info.hash).left(16) + "...";
    }
}



void BookScanner::startScanning()
{
    m_abort = false;

    emit statusChanged("Подготовка к сканированию...");
    emit progressChanged(0);

    try {
        // ПЕРВЫЙ ПРИОРИТЕТ: INPX импорт
        if (m_useInpx && !m_inpxFile.isEmpty()) {
            emit statusChanged("Запуск импорта из INPX файла...");
            emit progressChanged(10);

            bool importResult = importInpx(m_inpxFile);
            if (importResult) {
                // Если INPX импорт успешен, завершаем сканирование
                emit progressChanged(100);
                emit statusChanged("Импорт INPX завершен успешно");
                emit finished();
                return;
            } else {
                // Если INPX импорт не удался, продолжаем обычное сканирование
                emit statusChanged("INPX импорт не удался, переходим к сканированию файлов...");
                emit progressChanged(20);
            }
        } else if (m_useInpx) {
            emit statusChanged("INPX файл не указан, переходим к сканированию файлов...");
            emit progressChanged(20);
        } else {
            emit statusChanged("INPX отключен, начинаем сканирование файлов...");
            emit progressChanged(20);
        }

        // РЕЗЕРВНЫЙ СПОСОБ: Сканирование директории
        if (m_abort) return;

        scanDirectory(m_booksDir);

        if (!m_abort) {
            emit progressChanged(100);
            emit statusChanged("Сканирование завершено");
            emit finished();
        }

    } catch (const std::exception &e) {
        if (!m_abort) {
            emit errorOccurred(QString("Ошибка сканирования: %1").arg(e.what()));
        }
    }
}

bool BookScanner::importInpx(const QString &inpxFile)
{
    qDebug() << "=== START INPX IMPORT ===";
    qDebug() << "INPX file:" << inpxFile;

    QFileInfo inpxInfo(inpxFile);
    if (!inpxInfo.exists()) {
        qDebug() << "INPX file does not exist:" << inpxFile;
        emit errorOccurred("INPX файл не существует: " + inpxFile);
        return false;
    }

    InpxParser parser(m_database);

    // Подключаем callback для прогресса
    parser.setProgressCallback([this](int progress, const QString& status) {
        // Масштабируем прогресс от 10% до 90% для INPX импорта
        int scaledProgress = 10 + (progress * 80 / 100);
        emit progressChanged(scaledProgress);
        emit statusChanged(status);

        qDebug() << "INPX progress:" << progress << "% -" << status;
    });

    emit statusChanged("Начинаем импорт из INPX: " + inpxInfo.fileName());

    QElapsedTimer timer;
    timer.start();

    bool success = parser.importInpxCollection(inpxFile, m_booksDir);

    qDebug() << "INPX import completed in" << timer.elapsed() << "ms, success:" << success;

    if (success) {
        emit statusChanged("INPX импорт завершен успешно");
        return true;
    } else {
        emit errorOccurred("Не удалось выполнить импорт из INPX файла");
        return false;
    }
}

void BookScanner::scanDirectory(const QString &path)
{
    if (m_abort) return;

    QDir dir(path);
    if (!dir.exists()) {
        emit errorOccurred("Директория не существует: " + path);
        return;
    }

    qDebug() << "Scanning directory:" << path;

    // Поддерживаемые форматы (обычные файлы + архивы)
    QStringList supportedFormats = {
        "*.fb2", "*.epub", "*.pdf", "*.txt", "*.mobi",
        "*.zip", "*.rar", "*.7z", "*.tar", "*.gz", "*.bz2"
    };

    // Рекурсивный поиск файлов
    QDirIterator it(path, supportedFormats, QDir::Files, QDirIterator::Subdirectories);
    int totalFiles = 0;
    QVector<QString> files;

    // Сначала подсчитаем общее количество файлов
    emit statusChanged("Поиск файлов...");
    while (it.hasNext()) {
        files.append(it.next());
        totalFiles++;

        // Обновляем статус каждые 100 файлов
        if (totalFiles % 100 == 0) {
            emit statusChanged(QString("Найдено файлов: %1...").arg(totalFiles));
        }
    }

    if (totalFiles == 0) {
        emit statusChanged("Файлы не найдены");
        emit errorOccurred("В указанной директории не найдено поддерживаемых файлов");
        return;
    }

    emit statusChanged(QString("Найдено %1 файлов. Обработка...").arg(totalFiles));
    qDebug() << "Found" << totalFiles << "files to process";

    // Обрабатываем файлы
    for (int i = 0; i < files.size() && !m_abort; ++i) {
        const QString &filePath = files[i];

        // Проверяем, является ли файл архивом
        if (isArchiveFile(filePath)) {
            qDebug() << "Processing archive:" << filePath;
            processArchive(filePath);
        } else {
            qDebug() << "Processing file:" << filePath;
            processFile(filePath);
        }

        // Обновляем прогресс (от 20% до 100% для файлового сканирования)
        int progress = 20 + (80 * i / files.size());
        emit progressChanged(progress);

        if (i % 10 == 0) { // Обновляем статус каждые 10 файлов
            emit statusChanged(QString("Обработано %1 из %2 файлов").arg(i + 1).arg(totalFiles));
        }
    }

    emit statusChanged(QString("Обработано %1 файлов").arg(totalFiles));
}

void BookScanner::processFile(const QString &filePath)
{
    if (m_abort) return;

    QFileInfo fileInfo(filePath);
    QString fileName = fileInfo.fileName();
    QString extension = fileInfo.suffix().toLower();

    qDebug() << "Processing file:" << filePath;

    // Парсим метаданные из файла
    BookMeta meta = m_parser->parseMetadata(filePath);

    // Если автор не распознан, пробуем извлечь из имени файла
    if (meta.author == "Неизвестный автор") {
        // Логика извлечения из имени файла
        if (fileName.contains(" - ")) {
            int separatorPos = fileName.indexOf(" - ");
            meta.author = fileName.left(separatorPos).trimmed();
            if (meta.title.isEmpty()) {
                meta.title = fileName.mid(separatorPos + 3, fileName.lastIndexOf('.') - separatorPos - 3).trimmed();
            }
        }
    }

    // Гарантируем, что есть название и автор
    if (meta.title.isEmpty()) {
        meta.title = fileName.left(fileName.lastIndexOf('.'));
    }
    if (meta.author.isEmpty()) {
        meta.author = "Неизвестный автор";
    }

    // Очистка от лишних символов
    meta.title = meta.title.replace('_', ' ').simplified();
    meta.author = meta.author.replace('_', ' ').simplified();

    qDebug() << "Parsed metadata - Title:" << meta.title << "Author:" << meta.author
             << "Series:" << meta.series << "Genre:" << meta.genre;

    // ПРОВЕРКА ДУБЛИКАТОВ С УЧЕТОМ РАЗМЕРА ФАЙЛА
    QSqlQuery checkQuery(m_database);
    checkQuery.prepare(
        "SELECT id, file_size, file_path FROM books WHERE title = ? AND author = ?"
    );
    checkQuery.addBindValue(meta.title);
    checkQuery.addBindValue(meta.author);

    if (checkQuery.exec() && checkQuery.next()) {
        // Книга уже существует в базе
        int existingId = checkQuery.value(0).toInt();
        qint64 existingSize = checkQuery.value(1).toLongLong();
        QString existingPath = checkQuery.value(2).toString();

        qDebug() << "Book already exists in database - ID:" << existingId
                 << "Existing size:" << existingSize << "New size:" << fileInfo.size()
                 << "Existing path:" << existingPath;

        // ЕСЛИ НОВАЯ КНИГА БОЛЬШЕ ПО РАЗМЕРУ - ОБНОВЛЯЕМ
        if (fileInfo.size() > existingSize) {
            qDebug() << "New book is larger, updating record...";

            QSqlQuery updateQuery(m_database);
            updateQuery.prepare(
                "UPDATE books SET file_path = ?, file_name = ?, file_size = ?, file_type = ?, "
                "series = ?, series_number = ?, genre = ?, language = ?, year = ?, "
                "last_modified = CURRENT_TIMESTAMP, file_mtime = ? "
                "WHERE id = ?"
            );

            updateQuery.addBindValue(filePath);
            updateQuery.addBindValue(fileName);
            updateQuery.addBindValue(fileInfo.size());
            updateQuery.addBindValue(extension);
            updateQuery.addBindValue(meta.series);
            updateQuery.addBindValue(meta.seriesNumber);
            updateQuery.addBindValue(meta.genre);
            updateQuery.addBindValue(meta.language);
            updateQuery.addBindValue(meta.year);
            updateQuery.addBindValue(fileInfo.lastModified().toSecsSinceEpoch());
            updateQuery.addBindValue(existingId);

            if (updateQuery.exec()) {
                qDebug() << "Book updated (larger file):" << meta.title << "-" << meta.author
                         << "New size:" << fileInfo.size() << "Old size:" << existingSize;
                emit bookFound(meta.title + " [ОБНОВЛЕНО]", meta.author, fileName);
            } else {
                qDebug() << "Error updating book:" << updateQuery.lastError().text();
            }
        } else {
            qDebug() << "Book already exists with same or larger size, skipping:" << meta.title << "-" << meta.author;
        }
        return; // Книга уже обработана (либо пропущена, либо обновлена)
    }

    // Если книга не найдена - добавляем новую
    QSqlQuery insertQuery(m_database);
    insertQuery.prepare(
        "INSERT INTO books (file_path, file_name, file_size, file_type, title, author, "
        "series, series_number, genre, language, year, added_date, file_mtime) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
    );

    insertQuery.addBindValue(filePath);
    insertQuery.addBindValue(fileName);
    insertQuery.addBindValue(fileInfo.size());
    insertQuery.addBindValue(extension);
    insertQuery.addBindValue(meta.title);
    insertQuery.addBindValue(meta.author);
    insertQuery.addBindValue(meta.series);
    insertQuery.addBindValue(meta.seriesNumber);
    insertQuery.addBindValue(meta.genre);
    insertQuery.addBindValue(meta.language);
    insertQuery.addBindValue(meta.year);
    insertQuery.addBindValue(fileInfo.lastModified().toSecsSinceEpoch());

    if (insertQuery.exec()) {
        qDebug() << "Book added to database:" << meta.title << "-" << meta.author;
        emit bookFound(meta.title, meta.author, fileName);
    } else {
        qDebug() << "Error adding book:" << insertQuery.lastError().text()
                 << "-" << meta.title << "-" << meta.author;
    }
}

void BookScanner::processArchive(const QString &archivePath)
{
    if (m_abort) return;

    qDebug() << "=== START PROCESS ARCHIVE ===";
    qDebug() << "Archive:" << archivePath;

    // ПРОВЕРЯЕМ, НУЖНО ЛИ СКАНИРОВАТЬ АРХИВ
    if (!shouldRescanArchive(archivePath)) {
        qDebug() << "Skipping archive (no changes):" << archivePath;
        emit statusChanged("Пропускаем неизмененный архив: " + QFileInfo(archivePath).fileName());
        return;
    }

    QElapsedTimer timer;
    timer.start();

    QFileInfo archiveInfo(archivePath);
    emit statusChanged("Открытие архива: " + archiveInfo.fileName());

    if (!m_archiveHandler->openArchive(archivePath)) {
        QString error = m_archiveHandler->getLastError();
        qDebug() << "Failed to open archive:" << error;
        emit errorOccurred("Не удалось открыть архив: " + error);
        updateArchiveInfo(archivePath, true);
        return;
    }

    qDebug() << "Archive opened successfully, listing files...";
    emit statusChanged("Чтение содержимого архива...");

    QVector<ArchiveFile> files = m_archiveHandler->listFiles();
    qDebug() << "Total files in archive:" << files.size();

    if (files.isEmpty()) {
        qDebug() << "Archive is empty or no supported files found";
        emit statusChanged("Архив пуст или не содержит поддерживаемых файлов");
        m_archiveHandler->closeArchive();
        updateArchiveInfo(archivePath, false);
        return;
    }

    int processedFiles = 0;
    int addedBooks = 0;
    int updatedBooks = 0;
    int skippedBooks = 0;

    for (int i = 0; i < files.size() && !m_abort; ++i) {
        const ArchiveFile &file = files[i];

        // Проверяем поддерживаемые форматы
        QString extension = QFileInfo(file.name).suffix().toLower();
        if (extension != "fb2" && extension != "epub" && extension != "txt" && extension != "pdf") {
            processedFiles++;
            continue;
        }

        qDebug() << "Processing file in archive:" << file.name << "type:" << extension;

        QByteArray content = m_archiveHandler->readFile(file.path);
        if (!content.isEmpty()) {
            qDebug() << "Successfully read file:" << file.name << "size:" << content.size();

            BookMeta meta;

            // ОСОБАЯ ОБРАБОТКА ДЛЯ EPUB В АРХИВЕ
            if (extension == "epub") {
                meta = parseEpubFromMemory(content, file.name);
            } else {
                // Для других форматов используем обычный парсер
                meta = m_parser->parseMetadataFromMemory(content, extension);
            }

            // Если не удалось распарсить, используем имя файла
            if (meta.title.isEmpty()) {
                meta.title = m_parser->extractFromFileName(file.name);
            }
            if (meta.author.isEmpty()) {
                meta.author = "Неизвестный автор";
            }

            // Очистка от лишних символов
            meta.title = meta.title.replace('_', ' ').simplified();
            meta.author = meta.author.replace('_', ' ').simplified();

            qDebug() << "Parsed metadata - Title:" << meta.title << "Author:" << meta.author;

            // ПРОВЕРКА ДУБЛИКАТОВ ДЛЯ АРХИВНЫХ ФАЙЛОВ С УЧЕТОМ РАЗМЕРА
            QSqlQuery checkQuery(m_database);
            checkQuery.prepare(
                "SELECT id, file_size, file_path FROM books WHERE title = ? AND author = ?"
            );
            checkQuery.addBindValue(meta.title);
            checkQuery.addBindValue(meta.author);

            bool bookExists = false;
            bool shouldUpdate = false;
            int existingId = -1;
            qint64 existingSize = 0;
            QString existingPath;

            if (checkQuery.exec() && checkQuery.next()) {
                bookExists = true;
                existingId = checkQuery.value(0).toInt();
                existingSize = checkQuery.value(1).toLongLong();
                existingPath = checkQuery.value(2).toString();

                qDebug() << "Book exists in database - ID:" << existingId
                         << "Existing size:" << existingSize << "New size:" << file.size
                         << "Existing path:" << existingPath;

                // Проверяем размер - если новый файл больше, обновляем
                if (file.size > existingSize) {
                    shouldUpdate = true;
                    qDebug() << "Larger version found in archive, will update - New size:"
                             << file.size << "Old size:" << existingSize;
                } else {
                    qDebug() << "Existing book is same size or larger, skipping";
                }
            }

            if (!bookExists) {
                // Добавляем новую книгу
                if (addBookToDatabase(meta, file, archivePath, archiveInfo)) {
                    addedBooks++;
                }
            } else if (shouldUpdate) {
                // Обновляем существующую книгу (больший размер)
                if (updateBookInDatabase(meta, file, archivePath, archiveInfo, existingId)) {
                    updatedBooks++;
                }
            } else {
                qDebug() << "Book from archive already exists (same or smaller size):" << meta.title << "-" << meta.author;
                skippedBooks++;
            }
        } else {
            qDebug() << "Failed to read file from archive:" << file.name;
        }

        processedFiles++;

        // Обновляем статус каждые 5 файлов
        if (processedFiles % 5 == 0) {
            emit statusChanged(QString("Обработано %1 из %2 файлов в архиве (Добавлено: %3, Обновлено: %4)")
                              .arg(processedFiles).arg(files.size()).arg(addedBooks).arg(updatedBooks));
        }
    }

    m_archiveHandler->closeArchive();
    updateArchiveInfo(archivePath, false);

    qDebug() << "=== ARCHIVE PROCESSING COMPLETE ===" << timer.elapsed() << "ms"
             << "Processed files:" << processedFiles
             << "Added:" << addedBooks
             << "Updated:" << updatedBooks
             << "Skipped:" << skippedBooks;

    emit statusChanged(QString("Архив обработан: %1 файлов (Добавлено: %2, Обновлено: %3, Пропущено: %4)")
                      .arg(processedFiles).arg(addedBooks).arg(updatedBooks).arg(skippedBooks));
}

//EPUB сканер из архива

BookMeta BookScanner::parseEpubFromMemory(const QByteArray &epubData, const QString &fileName)
{
    qDebug() << "=== FULL EPUB PARSING FROM MEMORY ===";
    qDebug() << "File:" << fileName << "Size:" << epubData.size();

    // Используем полноценный парсер EPUB из памяти
    BookMeta meta = m_parser->parseEpubFromMemory(epubData);

    // Если парсинг не удался, используем имя файла как fallback
    if (meta.title.isEmpty()) {
        qDebug() << "EPUB parsing failed, using filename fallback";

        QString baseName = QFileInfo(fileName).completeBaseName();

        // Простая логика извлечения из имени файла
        if (baseName.contains('.')) {
            QStringList parts = baseName.split('.');
            bool isNumber = false;
            if (parts.size() > 0) {
                parts[0].trimmed().toInt(&isNumber);
            }

            if (isNumber && parts.size() >= 2) {
                meta.title = parts.mid(1).join('.').trimmed();
            } else {
                meta.title = baseName;
            }
        } else {
            meta.title = baseName;
        }

        if (meta.author.isEmpty()) {
            meta.author = "Неизвестный автор";
        }
    }

    // Очистка
    meta.title = meta.title.replace('_', ' ').simplified();
    meta.author = meta.author.replace('_', ' ').simplified();

    qDebug() << "=== FINAL METADATA ===";
    qDebug() << "Title:" << meta.title;
    qDebug() << "Author:" << meta.author;
    qDebug() << "Genre:" << meta.genre;

    return meta;
}

bool BookScanner::addBookToDatabase(const BookMeta &meta, const ArchiveFile &file,
                                   const QString &archivePath, const QFileInfo &archiveInfo)
{
    QSqlQuery insertQuery(m_database);
    insertQuery.prepare(
        "INSERT INTO books (file_path, file_name, file_size, file_type, title, author, "
        "series, series_number, genre, language, year, archive_path, archive_internal_path, "
        "added_date, file_mtime) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
    );

    QString extension = QFileInfo(file.name).suffix().toLower();
    QString displayPath = archivePath + "/" + file.name;

    insertQuery.addBindValue(displayPath);
    insertQuery.addBindValue(file.name);
    insertQuery.addBindValue(file.size);
    insertQuery.addBindValue(extension);
    insertQuery.addBindValue(meta.title);
    insertQuery.addBindValue(meta.author);
    insertQuery.addBindValue(meta.series);
    insertQuery.addBindValue(meta.seriesNumber);
    insertQuery.addBindValue(meta.genre);
    insertQuery.addBindValue(meta.language);
    insertQuery.addBindValue(meta.year);
    insertQuery.addBindValue(archivePath);
    insertQuery.addBindValue(file.name);
    insertQuery.addBindValue(archiveInfo.lastModified().toSecsSinceEpoch());

    if (insertQuery.exec()) {
        qDebug() << "Book from archive added to database:" << meta.title << "-" << meta.author;
        emit bookFound(meta.title, meta.author, file.name + " [архив]");
        return true;
    } else {
        qDebug() << "Error adding book from archive:" << insertQuery.lastError().text();
        return false;
    }
}

bool BookScanner::updateBookInDatabase(const BookMeta &meta, const ArchiveFile &file,
                                      const QString &archivePath, const QFileInfo &archiveInfo,
                                      int existingId)
{
    QSqlQuery updateQuery(m_database);
    updateQuery.prepare(
        "UPDATE books SET file_path = ?, file_name = ?, file_size = ?, file_type = ?, "
        "archive_path = ?, archive_internal_path = ?, "
        "series = ?, series_number = ?, genre = ?, language = ?, year = ?, "
        "last_modified = CURRENT_TIMESTAMP, file_mtime = ? "
        "WHERE id = ?"
    );

    QString extension = QFileInfo(file.name).suffix().toLower();
    QString displayPath = archivePath + "/" + file.name;

    updateQuery.addBindValue(displayPath);
    updateQuery.addBindValue(file.name);
    updateQuery.addBindValue(file.size);
    updateQuery.addBindValue(extension);
    updateQuery.addBindValue(archivePath);
    updateQuery.addBindValue(file.name);
    updateQuery.addBindValue(meta.series);
    updateQuery.addBindValue(meta.seriesNumber);
    updateQuery.addBindValue(meta.genre);
    updateQuery.addBindValue(meta.language);
    updateQuery.addBindValue(meta.year);
    updateQuery.addBindValue(archiveInfo.lastModified().toSecsSinceEpoch());
    updateQuery.addBindValue(existingId);

    if (updateQuery.exec()) {
        qDebug() << "Book from archive updated (larger file):" << meta.title << "-" << meta.author;
        emit bookFound(meta.title + " [ОБНОВЛЕНО]", meta.author, file.name + " [архив]");
        return true;
    } else {
        qDebug() << "Error updating book from archive:" << updateQuery.lastError().text();
        return false;
    }
}



//окончание EPUB сканер из архива



bool BookScanner::isArchiveFile(const QString &filePath)
{
    QString extension = QFileInfo(filePath).suffix().toLower();
    return (extension == "zip" || extension == "rar" || extension == "7z" ||
            extension == "tar" || extension == "gz" || extension == "bz2");
}

// ScannerDialog implementation
ScannerDialog::ScannerDialog(QSqlDatabase database, QWidget *parent) :
    QDialog(parent),
    ui(new Ui::ScannerDialog),
    m_database(database),
    m_scannerThread(nullptr),
    m_scanner(nullptr)
{
    ui->setupUi(this);

    // Настройка таблицы
    ui->tableBooks->setColumnCount(3);
    ui->tableBooks->setHorizontalHeaderLabels(QStringList() << "Название" << "Автор" << "Файл");
    ui->tableBooks->horizontalHeader()->setStretchLastSection(true);

    // Загрузка настроек
    QSettings settings("Squee&Dragon", "BookLibrary");
    ui->txtBooksDir->setText(settings.value("scanner/books_dir", QDir::homePath()).toString());
    ui->txtInpxFile->setText(settings.value("scanner/inpx_file", "").toString());
    ui->chkUseInpx->setChecked(settings.value("scanner/use_inpx", false).toBool());

    // ИСПОЛЬЗУЕМ СУЩЕСТВУЮЩУЮ КНОПКУ ИЗ UI, А НЕ СОЗДАЕМ НОВУЮ

    // Включаем кнопку (если она была отключена в UI)
    ui->btnForceRescan->setEnabled(true);

    // Исправляем CSS (если есть ошибки)
    ui->btnForceRescan->setStyleSheet(
        "QPushButton {"
        "    background-color: #f78222;"
        "    color: white;"
        "    font-weight: bold;"
        "    padding: 8px 16px;"
        "    border: none;"
        "    border-radius: 4px;"
        "}"
        "QPushButton:hover {"
        "    background-color: #e6731a;"
        "}"
        "QPushButton:pressed {"
        "    background-color: #d56412;"
        "}"
    );

    // ПОДКЛЮЧАЕМ СИГНАЛ К СУЩЕСТВУЮЩЕЙ КНОПКЕ ИЗ UI
    connect(ui->btnForceRescan, &QPushButton::clicked, [this]() {
        qDebug() << "Force rescan button clicked!";

        if (QMessageBox::question(this, "Подтверждение",
            "Вы уверены, что хотите пометить все архивы для принудительного пересканирования?") == QMessageBox::Yes) {

            qDebug() << "Executing force rescan...";

            BookScanner tempScanner(m_database, "", false, "");
            tempScanner.forceRescanAllArchives();

            QMessageBox::information(this, "Пересканирование", "Все архивы помечены для пересканирования");

            qDebug() << "Force rescan completed";
        }
    });

    updateControls(false);

    // Отладочная информация
    qDebug() << "ScannerDialog initialized";
    qDebug() << "Force rescan button enabled:" << ui->btnForceRescan->isEnabled();
    qDebug() << "Force rescan button visible:" << ui->btnForceRescan->isVisible();
}

void ScannerDialog::onForceRescanClicked()
{
    if (QMessageBox::question(this, "Подтверждение",
        "Вы уверены, что хотите пометить все архивы для принудительного пересканирования?") == QMessageBox::Yes) {

        if (m_scanner) {
            m_scanner->forceRescanAllArchives();
            QMessageBox::information(this, "Пересканирование", "Все архивы помечены для пересканирования");
        } else {
            // Создаем временный сканер для выполнения операции
            BookScanner tempScanner(m_database, "", false, "");
            tempScanner.forceRescanAllArchives();
            QMessageBox::information(this, "Пересканирование", "Все архивы помечены для пересканирования");
        }
    }
}


ScannerDialog::~ScannerDialog()
{
    if (m_scannerThread) {
        m_scannerThread->quit();
        m_scannerThread->wait();
    }
    delete ui;
}

void ScannerDialog::on_btnBrowseBooksDir_clicked()
{
    QString dir = QFileDialog::getExistingDirectory(this, "Выберите директорию с книгами",
                                                   ui->txtBooksDir->text());
    if (!dir.isEmpty()) {
        ui->txtBooksDir->setText(dir);

        // Автоматически ищем INPX файл
        QDir booksDir(dir);
        QStringList inpxFiles = booksDir.entryList(QStringList() << "*.inpx", QDir::Files);
        if (!inpxFiles.isEmpty()) {
            ui->txtInpxFile->setText(dir + "/" + inpxFiles.first());
            ui->chkUseInpx->setChecked(true);
        }

        // Сохраняем настройки
        QSettings settings("Squee&Dragon", "BookLibrary");
        settings.setValue("scanner/books_dir", dir);
    }
}

void ScannerDialog::on_btnBrowseInpx_clicked()
{
    QString file = QFileDialog::getOpenFileName(this, "Выберите INPX файл",
                                               ui->txtInpxFile->text(),
                                               "INPX files (*.inpx)");
    if (!file.isEmpty()) {
        ui->txtInpxFile->setText(file);
        ui->chkUseInpx->setChecked(true);

        QSettings settings("Squee&Dragon", "BookLibrary");
        settings.setValue("scanner/inpx_file", file);
    }
}

void ScannerDialog::on_btnStartScan_clicked()
{
    if (ui->txtBooksDir->text().isEmpty()) {
        QMessageBox::warning(this, "Ошибка", "Укажите директорию с книгами");
        return;
    }

    if (!m_database.isOpen()) {
        QMessageBox::warning(this, "Ошибка", "База данных не открыта");
        return;
    }

    // Очищаем таблицу
    ui->tableBooks->setRowCount(0);
    ui->progressBar->setValue(0);

    // Создаем и запускаем сканер в отдельном потоке
    m_scannerThread = new QThread(this);
    m_scanner = new BookScanner(m_database,
                               ui->txtBooksDir->text(),
                               ui->chkUseInpx->isChecked(),
                               ui->txtInpxFile->text());
    m_scanner->moveToThread(m_scannerThread);

    connect(m_scannerThread, &QThread::started, m_scanner, &BookScanner::startScanning);
    connect(m_scanner, &BookScanner::progressChanged, this, &ScannerDialog::onProgressChanged);
    connect(m_scanner, &BookScanner::statusChanged, this, &ScannerDialog::onStatusChanged);
    connect(m_scanner, &BookScanner::bookFound, this, &ScannerDialog::onBookFound);
    connect(m_scanner, &BookScanner::finished, this, &ScannerDialog::onFinished);
    connect(m_scanner, &BookScanner::errorOccurred, this, &ScannerDialog::onErrorOccurred);

    connect(m_scannerThread, &QThread::finished, m_scanner, &BookScanner::deleteLater);

    updateControls(true);
    m_scannerThread->start();
}

void ScannerDialog::on_btnStopScan_clicked()
{
    if (m_scanner) {
        qDebug() << "Stopping scanner...";
        m_scanner->cancelScanning();
        ui->statusLabel->setText("Остановка сканирования...");
        ui->btnStopScan->setEnabled(false);

        // Даем сканеру время на завершение
        QTimer::singleShot(1000, this, [this]() {
            if (m_scannerThread && m_scannerThread->isRunning()) {
                m_scannerThread->terminate();
                m_scannerThread->wait();
            }
            onFinished();
        });
    }
}

void ScannerDialog::on_chkUseInpx_toggled(bool checked)
{
    ui->txtInpxFile->setEnabled(checked);
    ui->btnBrowseInpx->setEnabled(checked);

    QSettings settings("Squee&Dragon", "BookLibrary");
    settings.setValue("scanner/use_inpx", checked);
}

void ScannerDialog::onProgressChanged(int value)
{
    ui->progressBar->setValue(value);
}

void ScannerDialog::onStatusChanged(const QString &status)
{
    ui->statusLabel->setText(status);
}

void ScannerDialog::onBookFound(const QString &title, const QString &author, const QString &filename)
{
    qDebug() << "Book found:" << title << "by" << author;

    int row = ui->tableBooks->rowCount();
    ui->tableBooks->insertRow(row);
    ui->tableBooks->setItem(row, 0, new QTableWidgetItem(title));
    ui->tableBooks->setItem(row, 1, new QTableWidgetItem(author));
    ui->tableBooks->setItem(row, 2, new QTableWidgetItem(filename));

    // Автопрокрутка к последней добавленной книге
    ui->tableBooks->scrollToBottom();

    // Обновляем статус
    ui->statusLabel->setText(QString("Найдено книг: %1").arg(row + 1));
}

void ScannerDialog::onFinished()
{
    updateControls(false);
    ui->statusLabel->setText("Сканирование завершено");

    if (m_scannerThread) {
        m_scannerThread->quit();
        m_scannerThread->wait();
        m_scannerThread->deleteLater();
        m_scannerThread = nullptr;
    }
    m_scanner = nullptr;

    QMessageBox::information(this, "Готово", "Сканирование коллекции завершено!");

    // Сигнализируем главному окну об обновлении данных
    emit booksUpdated();
}

void ScannerDialog::onErrorOccurred(const QString &error)
{
    QMessageBox::critical(this, "Ошибка", error);
    onFinished();
}

void ScannerDialog::updateControls(bool scanning)
{
    ui->btnStartScan->setEnabled(!scanning);
    ui->btnStopScan->setEnabled(scanning);
    ui->txtBooksDir->setEnabled(!scanning);
    ui->btnBrowseBooksDir->setEnabled(!scanning);
    ui->chkUseInpx->setEnabled(!scanning);
    ui->txtInpxFile->setEnabled(!scanning && ui->chkUseInpx->isChecked());
    ui->btnBrowseInpx->setEnabled(!scanning && ui->chkUseInpx->isChecked());
}
