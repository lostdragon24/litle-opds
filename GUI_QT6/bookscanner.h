#ifndef BOOKSCANNER_H
#define BOOKSCANNER_H

#include <QObject>
#include <QSqlDatabase>
#include <QString>
#include <QVector>

// Предварительное объявление классов
class BookParser;
class ArchiveHandler;
struct ArchiveFile;
struct BookMeta;

class BookScanner : public QObject
{
    Q_OBJECT

public:
    explicit BookScanner(QSqlDatabase database, const QString &booksDir, bool useInpx, const QString &inpxFile);
    ~BookScanner();

    // Добавить метод для отмены
    void cancelScanning();

public slots:
    void startScanning();

signals:
    void progressChanged(int value);
    void statusChanged(const QString &status);
    void bookFound(const QString &title, const QString &author, const QString &filename);
    void finished();
    void errorOccurred(const QString &error);

private:
    QSqlDatabase m_database;
    QString m_booksDir;
    bool m_useInpx;
    QString m_inpxFile;
    bool m_abort = false;
    BookParser* m_parser;
    ArchiveHandler* m_archiveHandler;

    void scanDirectory(const QString &path);
    void processFile(const QString &filePath);
    void processArchive(const QString &archivePath);
    void processArchiveFiles(const QString &archivePath, const QVector<ArchiveFile> &files); // Добавить эту строку
    bool processArchiveFile(const QString &archivePath, const ArchiveFile &file); // И эту
    void importInpx(const QString &inpxFile);
    bool isArchiveFile(const QString &filePath);
};

#endif // BOOKSCANNER_H
