#ifndef ARCHIVEHANDLER_H
#define ARCHIVEHANDLER_H

#include <QString>
#include <QVector>
#include <QByteArray>

struct ArchiveFile {
    QString name;
    QString path;  // полный путь внутри архива
    qint64 size;
    bool isDirectory;
};

struct ArchiveInfo {
    QString path;
    qint64 size;
    qint64 fileCount;
    QByteArray hash;
    qint64 lastModified;
};

class ArchiveHandler
{
public:
    ArchiveHandler();
    ~ArchiveHandler();

    bool openArchive(const QString &archivePath);
    void closeArchive();
    QVector<ArchiveFile> listFiles();
    QByteArray readFile(const QString &internalPath);
    bool extractFile(const QString &internalPath, const QString &outputPath);

    QString getLastError() const { return m_lastError; }
    bool isOpen() const { return m_isOpen; }

    ArchiveInfo getArchiveInfo(const QString &archivePath);
    QByteArray calculateArchiveHash(const QString &archivePath);

private:
    struct archive *m_archive;
    QString m_archivePath;
    QString m_lastError;
    bool m_isOpen;



    void setError(const QString &error);
};

#endif // ARCHIVEHANDLER_H
