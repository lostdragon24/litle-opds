// scannerdialog.h
#ifndef SCANNERDIALOG_H
#define SCANNERDIALOG_H

#include <QDialog>
#include <QThread>
#include <QSqlDatabase>
#include <QFileInfo>

// Перенесем сюда все необходимые объявления
#include "bookparser.h"
#include "archivehandler.h"
#include "inpxparser.h"

namespace Ui {
class ScannerDialog;
}

class BookScanner : public QObject
{
    Q_OBJECT

public:
    explicit BookScanner(QSqlDatabase database, const QString &booksDir, bool useInpx, const QString &inpxFile);
    ~BookScanner();

    void cancelScanning();
    void forceRescanAllArchives(); // Добавляем метод для принудительного пересканирования

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
    bool m_abort;
    BookParser* m_parser;
    ArchiveHandler* m_archiveHandler;

    // Новые методы для работы с архивами
    bool shouldRescanArchive(const QString &archivePath);
    void updateArchiveInfo(const QString &archivePath, bool needsRescan = false);

    bool importInpx(const QString &inpxFile);
    void scanDirectory(const QString &path);
    void processFile(const QString &filePath);
    void processArchive(const QString &archivePath);
    bool isArchiveFile(const QString &filePath);

    BookMeta parseEpubFromMemory(const QByteArray &epubData, const QString &fileName);
    bool addBookToDatabase(const BookMeta &meta, const ArchiveFile &file, const QString &archivePath, const QFileInfo &archiveInfo);
    bool updateBookInDatabase(const BookMeta &meta, const ArchiveFile &file, const QString &archivePath, const QFileInfo &archiveInfo, int existingId);




};

class ScannerDialog : public QDialog
{
    Q_OBJECT

public:
    explicit ScannerDialog(QSqlDatabase database, QWidget *parent = nullptr);
    ~ScannerDialog();

signals:
    void booksUpdated();

private slots:
    void on_btnBrowseBooksDir_clicked();
    void on_btnBrowseInpx_clicked();
    void on_btnStartScan_clicked();
    void on_btnStopScan_clicked();
    //void on_btnForceRescan_clicked(); // Новая кнопка
    void onForceRescanClicked();

    void on_chkUseInpx_toggled(bool checked);

    void onProgressChanged(int value);
    void onStatusChanged(const QString &status);
    void onBookFound(const QString &title, const QString &author, const QString &filename);
    void onFinished();
    void onErrorOccurred(const QString &error);

private:
    Ui::ScannerDialog *ui;
    QSqlDatabase m_database;
    QThread *m_scannerThread;
    BookScanner *m_scanner;

    void updateControls(bool scanning);
};

#endif // SCANNERDIALOG_H
