#ifndef INPXPARSER_H
#define INPXPARSER_H

#include <QString>
#include <QVector>
#include <QHash>
#include <QSqlDatabase>
#include <functional>

struct InpxBookMeta {
    QString title;
    QString author;
    QString series;
    int seriesNumber = 0;
    QString genre;
    QString language;
    QString publisher;
    int year = 0;
    qint64 fileSize = 0;
    QString fileName;
    QString fileExt;
    QString archiveName;
    QString inpFileName;  // Добавляем имя INP файла
};

class InpxParser
{
public:
    InpxParser(QSqlDatabase database);
    bool importInpxCollection(const QString &inpxFilePath, const QString &booksDir);

    void setProgressCallback(std::function<void(int, const QString&)> callback);

private:
    enum FieldType {
        FieldNone,
        FieldAuthor,
        FieldGenre,
        FieldTitle,
        FieldSeries,
        FieldSeriesNumber,
        FieldFile,
        FieldSize,
        FieldExt,
        FieldLang,
        FieldDate
    };

    struct ImportContext {
        QVector<FieldType> fields;
        int useStoredFolder = 0;
        int genresType = 0;
    };

    QSqlDatabase m_database;
    std::function<void(int, const QString&)> m_progressCallback;

    bool parseInpData(const QString &line, const ImportContext &ctx, InpxBookMeta &meta);
    void getInpxFields(const QString &structureInfo, ImportContext &ctx);
    bool insertBookToDatabase(const InpxBookMeta &meta, const QString &booksDir);
    QString cleanAuthorName(const QString &authorStr);
    QByteArray readFileFromArchive(const QString &archivePath, const QString &internalPath);
};

#endif // INPXPARSER_H
