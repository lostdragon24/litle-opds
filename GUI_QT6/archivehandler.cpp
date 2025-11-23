#include "archivehandler.h"
#include <archive.h>
#include <archive_entry.h>
#include <QDebug>
#include <QFileInfo>
#include <QDir>
#include <QElapsedTimer>
#include <QCryptographicHash>

ArchiveHandler::ArchiveHandler()
    : m_archive(nullptr)
    , m_isOpen(false)
{
}

ArchiveHandler::~ArchiveHandler()
{
    closeArchive();
}

bool ArchiveHandler::openArchive(const QString &archivePath)
{
    closeArchive();

    qDebug() << "ArchiveHandler: Opening archive:" << archivePath;

    m_archive = archive_read_new();
    if (!m_archive) {
        setError("Failed to create archive reader");
        return false;
    }

    // Более специфичные настройки как в C коде
    archive_read_support_format_zip(m_archive);
    archive_read_support_format_rar(m_archive);
    archive_read_support_format_7zip(m_archive);
    archive_read_support_format_tar(m_archive);
    archive_read_support_format_iso9660(m_archive);
    archive_read_support_format_cpio(m_archive);

    archive_read_support_filter_all(m_archive);

    // Устанавливаем более агрессивные таймауты
    archive_read_set_option(m_archive, "zip", "read_consume", "1");

    int r = archive_read_open_filename(m_archive, archivePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        setError(QString("Failed to open archive: %1").arg(archive_error_string(m_archive)));
        archive_read_free(m_archive);
        m_archive = nullptr;
        return false;
    }

    m_archivePath = archivePath;
    m_isOpen = true;

    qDebug() << "ArchiveHandler: Archive opened successfully";
    return true;
}

void ArchiveHandler::closeArchive()
{
    if (m_archive) {
        qDebug() << "ArchiveHandler: Closing archive";
        archive_read_close(m_archive);
        archive_read_free(m_archive);
        m_archive = nullptr;
    }
    m_isOpen = false;
    // НЕ очищаем m_archivePath здесь!
}

QVector<ArchiveFile> ArchiveHandler::listFiles()
{
    QVector<ArchiveFile> files;

    if (!m_isOpen || !m_archive) {
        setError("Archive is not open");
        return files;
    }

    qDebug() << "ArchiveHandler: Listing files in archive:" << m_archivePath;

    struct archive_entry *entry;
    int fileCount = 0;
    int skippedCount = 0;

    QElapsedTimer timer;
    timer.start();

    // Сбросим позицию чтения архива
    archive_read_free(m_archive);
    m_archive = archive_read_new();
    archive_read_support_format_all(m_archive);
    archive_read_support_filter_all(m_archive);

    int r = archive_read_open_filename(m_archive, m_archivePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        setError(QString("Failed to reopen archive: %1").arg(archive_error_string(m_archive)));
        return files;
    }

    while (true) {
        r = archive_read_next_header(m_archive, &entry);
        if (r == ARCHIVE_EOF) {
            qDebug() << "ArchiveHandler: End of archive reached";
            break;
        }
        if (r != ARCHIVE_OK) {
            setError(QString("Failed to read archive header: %1").arg(archive_error_string(m_archive)));
            break;
        }

        const char* filename = archive_entry_pathname(entry);
        la_int64_t size = archive_entry_size(entry);
        mode_t filetype = archive_entry_filetype(entry);

        if (!filename) {
            qDebug() << "ArchiveHandler: Skipping entry with null filename";
            archive_read_data_skip(m_archive);
            continue;
        }

        QString qfilename = QString::fromUtf8(filename);

        qDebug() << "ArchiveHandler: Found entry:" << qfilename
                 << "type:" << filetype << "size:" << size;

        // Пропускаем директории и специальные файлы
        if (filetype != AE_IFREG) {
            qDebug() << "ArchiveHandler: Skipping non-regular file:" << qfilename;
            skippedCount++;
            archive_read_data_skip(m_archive);
            continue;
        }

        // Пропускаем файлы больше 100MB
        if (size > 104857600) {
            qDebug() << "ArchiveHandler: Skipping large file:" << qfilename << "size:" << size;
            skippedCount++;
            archive_read_data_skip(m_archive);
            continue;
        }

        // Проверяем поддерживаемые форматы
        QString extension = QFileInfo(qfilename).suffix().toLower();
        QStringList supportedFormats = {"fb2", "epub", "pdf", "mobi", "txt"};

        if (supportedFormats.contains(extension)) {
            ArchiveFile file;
            file.name = QFileInfo(qfilename).fileName();
            file.path = qfilename;
            file.size = size;
            file.isDirectory = false;

            files.append(file);
            fileCount++;

            qDebug() << "ArchiveHandler: Added supported file:" << file.name << "size:" << file.size;
        } else {
            qDebug() << "ArchiveHandler: Skipping unsupported format:" << qfilename << "extension:" << extension;
            skippedCount++;
        }

        archive_read_data_skip(m_archive);

        // Защита от бесконечного цикла
        if (fileCount + skippedCount > 10000) {
            qDebug() << "ArchiveHandler: Too many files in archive, stopping";
            break;
        }

        // Прерывание по времени для очень больших архивов
        if (timer.elapsed() > 30000) { // 30 секунд
            qDebug() << "ArchiveHandler: Timeout while listing archive files";
            break;
        }
    }

    qDebug() << "ArchiveHandler: Found" << fileCount << "supported files,"
             << skippedCount << "files skipped. Time:" << timer.elapsed() << "ms";

    return files;
}

QByteArray ArchiveHandler::readFile(const QString &internalPath)
{
    QByteArray content;

    if (!m_archivePath.isEmpty()) {
        qDebug() << "ArchiveHandler: Looking for file in archive:" << internalPath;

        // Сохраняем текущий путь архива
        QString currentArchivePath = m_archivePath;

        // Закрываем текущий архив если открыт
        if (m_isOpen) {
            closeArchive();
        }

        // Открываем архив заново с правильным путем
        if (!openArchive(currentArchivePath)) {
            return content;
        }

        struct archive_entry *entry;
        bool found = false;
        QElapsedTimer timer;
        timer.start();

        while (true) {
            int r = archive_read_next_header(m_archive, &entry);
            if (r == ARCHIVE_EOF) {
                break;
            }
            if (r != ARCHIVE_OK) {
                setError(QString("Failed to read archive header: %1").arg(archive_error_string(m_archive)));
                break;
            }

            const char* filename = archive_entry_pathname(entry);
            if (!filename) {
                archive_read_data_skip(m_archive);
                continue;
            }

            QString entryPath = QString::fromUtf8(filename);
            la_int64_t size = archive_entry_size(entry);
            mode_t filetype = archive_entry_filetype(entry);

            // Пропускаем директории и большие файлы
            if (filetype != AE_IFREG || size > 104857600) {
                archive_read_data_skip(m_archive);
                continue;
            }

            qDebug() << "ArchiveHandler: Checking:" << entryPath;

            if (entryPath == internalPath) {
                qDebug() << "ArchiveHandler: Found target file:" << internalPath << "size:" << size;

                if (size > 0 && size < 104857600) { // До 10MB
                    content.resize(size);
                    la_ssize_t read_size = archive_read_data(m_archive, content.data(), size);
                    qDebug() << "ArchiveHandler: Read" << read_size << "bytes, expected:" << size;

                    if (read_size != size) {
                        setError(QString("Failed to read file: expected %1 bytes, got %2").arg(size).arg(read_size));
                        content.clear();
                    } else {
                        found = true;
                        qDebug() << "ArchiveHandler: Successfully read file:" << internalPath;
                    }
                } else {
                    qDebug() << "ArchiveHandler: Invalid file size:" << size;
                }
                break;
            }

            archive_read_data_skip(m_archive);

            // Таймаут для поиска
            if (timer.elapsed() > 10000) { // 10 секунд
                qDebug() << "ArchiveHandler: Timeout while searching for file";
                break;
            }
        }

        if (!found) {
            qDebug() << "ArchiveHandler: File not found in archive:" << internalPath;
            setError("File not found in archive: " + internalPath);
        }
    } else {
        setError("Archive path is empty");
    }

    return content;
}

bool ArchiveHandler::extractFile(const QString &internalPath, const QString &outputPath)
{
    QByteArray content = readFile(internalPath);
    if (content.isEmpty()) {
        return false;
    }

    // Создаем директорию если нужно
    QFileInfo outputInfo(outputPath);
    QDir().mkpath(outputInfo.absolutePath());

    QFile file(outputPath);
    if (!file.open(QIODevice::WriteOnly)) {
        setError("Failed to create output file: " + outputPath);
        return false;
    }

    qint64 written = file.write(content);
    file.close();

    if (written != content.size()) {
        setError("Failed to write complete file");
        return false;
    }

    qDebug() << "ArchiveHandler: File extracted successfully:" << outputPath;
    return true;
}

void ArchiveHandler::setError(const QString &error)
{
    m_lastError = error;
    qDebug() << "ArchiveHandler error:" << error;
}

// archivehandler.cpp - добавьте эти методы
ArchiveInfo ArchiveHandler::getArchiveInfo(const QString &archivePath)
{
    ArchiveInfo info;
    info.path = archivePath;

    QFileInfo fileInfo(archivePath);
    info.size = fileInfo.size();
    info.lastModified = fileInfo.lastModified().toSecsSinceEpoch();

    // Получаем количество файлов
    if (openArchive(archivePath)) {
        QVector<ArchiveFile> files = listFiles();
        info.fileCount = files.size();
        closeArchive();
    } else {
        info.fileCount = 0;
    }

    // Вычисляем хеш
    info.hash = calculateArchiveHash(archivePath);

    return info;
}

QByteArray ArchiveHandler::calculateArchiveHash(const QString &archivePath)
{
    QFile file(archivePath);
    if (!file.open(QIODevice::ReadOnly)) {
        qDebug() << "Failed to open file for hashing:" << archivePath;
        return QByteArray();
    }

    QCryptographicHash hash(QCryptographicHash::Md5);

    // Читаем файл блоками для больших архивов
    const qint64 bufferSize = 8192;
    char buffer[bufferSize];

    qint64 bytesRead;
    while ((bytesRead = file.read(buffer, bufferSize)) > 0) {
        hash.addData(buffer, bytesRead);
    }

    file.close();
    return hash.result().toHex();
}
