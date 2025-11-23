#include "scannerdialog.h"
#include "scannerdialog.h"
#include "archivehandler.h"
#include "inpxparser.h"
#include <QDir>
#include <QDirIterator>
#include <QFileInfo>
#include <QSqlQuery>
#include <QSqlError>
#include <QDebug>
#include <QThread>
#include <QElapsedTimer>
#include <QRegularExpression>

BookScanner::BookScanner(QSqlDatabase database, const QString &booksDir, bool useInpx, const QString &inpxFile)
    : m_database(database)
    , m_booksDir(booksDir)
    , m_useInpx(useInpx)
    , m_inpxFile(inpxFile)
    , m_abort(false)
    , m_parser(new BookParser())
    , m_archiveHandler(new ArchiveHandler())
{
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
            emit statusChanged("INPX файл не указан, пропускаем импорт...");
        }

        // РЕЗЕРВНЫЙ СПОСОБ: Сканирование директории
        if (m_abort) return;
        emit statusChanged("Сканирование файлов...");
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
    while (it.hasNext()) {
        files.append(it.next());
        totalFiles++;
    }

    if (totalFiles == 0) {
        emit statusChanged("Файлы не найдены");
        return;
    }

    emit statusChanged(QString("Найдено %1 файлов. Обработка...").arg(totalFiles));

    // Обрабатываем файлы
    for (int i = 0; i < files.size() && !m_abort; ++i) {
        const QString &filePath = files[i];

        // Проверяем, является ли файл архивом
        if (isArchiveFile(filePath)) {
            processArchive(filePath);
        } else {
            processFile(filePath);
        }

        // Обновляем прогресс (от 20% до 100% для файлового сканирования)
        int progress = 20 + (80 * i / files.size());
        emit progressChanged(progress);
        emit statusChanged(QString("Обработано %1 из %2 файлов").arg(i + 1).arg(totalFiles));

        // Небольшая задержка для демонстрации прогресса
        QThread::msleep(10);
    }
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

    // Проверяем, существует ли книга уже в базе
    QSqlQuery checkQuery(m_database);
    checkQuery.prepare("SELECT COUNT(*) FROM books WHERE file_name = ? OR (title = ? AND author = ?)");
    checkQuery.addBindValue(fileName);
    checkQuery.addBindValue(meta.title);
    checkQuery.addBindValue(meta.author);

    if (checkQuery.exec() && checkQuery.next()) {
        if (checkQuery.value(0).toInt() > 0) {
            qDebug() << "Book already exists:" << meta.title << "-" << meta.author;
            return;
        }
    } else {
        qDebug() << "Check query error:" << checkQuery.lastError().text();
    }

    // Добавляем книгу в базу с полной информацией
    QSqlQuery insertQuery(m_database);
    insertQuery.prepare(
        "INSERT INTO books (file_path, file_name, file_size, file_type, title, author, "
        "series, series_number, genre, language, year, added_date) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
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

    QElapsedTimer timer;
    timer.start();

    QFileInfo archiveInfo(archivePath);
    emit statusChanged("Открытие архива: " + archiveInfo.fileName());

    if (!m_archiveHandler->openArchive(archivePath)) {
        QString error = m_archiveHandler->getLastError();
        qDebug() << "Failed to open archive:" << error;
        emit errorOccurred("Не удалось открыть архив: " + error);
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
        return;
    }

    // Обрабатываем только первые 3 файла для тестирования
    for (int i = 0; i < qMin(3, files.size()) && !m_abort; ++i) {
        const ArchiveFile &file = files[i];
        qDebug() << "Testing file:" << file.name;

        QByteArray content = m_archiveHandler->readFile(file.path);
        if (!content.isEmpty()) {
            qDebug() << "Successfully read file:" << file.name << "size:" << content.size();
            emit bookFound("Test Book", "Test Author", file.name + " [архив]");
        } else {
            qDebug() << "Failed to read file:" << file.name;
        }
    }

    m_archiveHandler->closeArchive();
    qDebug() << "=== ARCHIVE PROCESSING COMPLETE ===" << timer.elapsed() << "ms";
}

bool BookScanner::isArchiveFile(const QString &filePath)
{
    QString extension = QFileInfo(filePath).suffix().toLower();
    return (extension == "zip" || extension == "rar" || extension == "7z" ||
            extension == "tar" || extension == "gz" || extension == "bz2");
}

void BookScanner::processArchiveFiles(const QString &archivePath, const QVector<ArchiveFile> &files)
{
    Q_UNUSED(archivePath)
    Q_UNUSED(files)
}

bool BookScanner::processArchiveFile(const QString &archivePath, const ArchiveFile &file)
{
    Q_UNUSED(archivePath)
    Q_UNUSED(file)
    return false;
}
