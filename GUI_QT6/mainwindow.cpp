#include "mainwindow.h"
#include "ui_mainwindow.h"
#include "settingsdialog.h"

#include <QSqlDatabase>
#include <QSqlQuery>
#include <QSqlError>
#include <QFileDialog>
#include <QMessageBox>
#include <QStandardItem>
#include <QDebug>
#include <QBuffer>
#include <QFile>
#include <QDir>
#include <QXmlStreamReader>
#include <archive.h>
#include <archive_entry.h>
#include <QSettings>
#include <QShowEvent>
#include <QResizeEvent>
#include <QStyle>
#include <QApplication>
#include <QPainter>
#include <QFont>
#include <QPen>
#include <QBrush>
#include <QPushButton>
#include <QHBoxLayout>
#include <QButtonGroup>
#include <QTimer>
#include <QShortcut>
#include <QRadioButton>
#include <QGridLayout>
#include <QLabel>
#include <QProgressBar>
#include <QCache>
#include <QTextStream>
#include <QXmlStreamReader>
#include <QDialog>
#include <QVBoxLayout>
#include <QHBoxLayout>
#include <QTextEdit>
#include <QPushButton>
#include <QFileDialog>
#include <QTextStream>

MainWindow::MainWindow(QWidget *parent)
    : QMainWindow(parent)
    , ui(new Ui::MainWindow)
    , booksModel(nullptr)
    , treeModel(nullptr)
    , letterButtonGroup(new QButtonGroup(this))
    , treeModeButtonGroup(new QButtonGroup(this))
    , settingsDialog(new SettingsDialog(this))
    , m_scannerDialog(nullptr)
    , currentTreeMode(TreeViewMode::Authors)
    , coverCache(nullptr)
    , descriptionCache(nullptr)
    , bookContentCache(nullptr)
    , progressBar(nullptr)
    , statusLabel(nullptr)
    , fb2Reader(nullptr)  // Инициализируем указатель
{
    ui->setupUi(this);

    // Инициализируем кэши
    coverCache = new QCache<QString, QPixmap>(100);
    descriptionCache = new QCache<QString, QString>(200);
    bookContentCache = new QCache<QString, BookContent>(50);

    // Создаем прогресс-бар в статусной строке
    progressBar = new QProgressBar(this);
    progressBar->setVisible(false);
    progressBar->setMaximumWidth(200);
    progressBar->setMaximumHeight(16);

    statusLabel = new QLabel(this);

    ui->statusbar->addPermanentWidget(progressBar);
    ui->statusbar->addPermanentWidget(statusLabel);

    // Добавляем действие для переподключения
    QAction *actionReconnect = new QAction("Переподключиться", this);
    connect(actionReconnect, &QAction::triggered, this, &MainWindow::on_actionReconnect_triggered);
    ui->menu->addAction(actionReconnect);

    // Добавляем действие для сканирования
    QAction *actionScan = new QAction("Сканировать книги", this);
    connect(actionScan, &QAction::triggered, this, &MainWindow::on_actionScan_triggered);
    ui->menu->addAction(actionScan);

    // Настройка иконок
    setupIcons();

    // Настройка стилей и интерфейса
    setupStyles();
    setupSplitter();
    setupAlphabetButtons();
    setupTreeViewModeSelector();

    // Инициализация моделей
    booksModel = new QSqlQueryModel(this);
    treeModel = new QStandardItemModel(this);

    // Инициализируем таблицу жанров
    initGenreMap();

    // Подключение сигналов
    connect(ui->searchLineEdit, &QLineEdit::textChanged, this, &MainWindow::on_searchLineEdit_textChanged);
    connect(ui->searchLineEdit, &QLineEdit::returnPressed, this, &MainWindow::on_searchLineEdit_returnPressed);
    connect(ui->btn_search, &QPushButton::clicked, this, &MainWindow::on_btn_search_clicked);
    connect(ui->treeView, &QTreeView::clicked, this, &MainWindow::on_treeView_clicked);
    connect(ui->treeView, &QTreeView::doubleClicked, this, &MainWindow::on_treeView_doubleClicked); // Добавляем двойной клик
    connect(ui->chkLoadAll, &QCheckBox::toggled, this, &MainWindow::onLoadAllChecked);
    connect(ui->treeView, &QTreeView::expanded, this, &MainWindow::onTreeViewExpanded);
    connect(ui->btn_delete, &QPushButton::clicked, this, &MainWindow::on_btn_delete_clicked);
    connect(ui->actionAbout, &QAction::triggered, this, &MainWindow::about);


    // Горячая клавиша для поиска (Ctrl+F)
    QShortcut *searchShortcut = new QShortcut(QKeySequence("Ctrl+F"), this);
    connect(searchShortcut, &QShortcut::activated, this, [this]() {
        ui->searchLineEdit->setFocus();
        ui->searchLineEdit->selectAll();
    });

    // Дополнительные настройки для текстового поля описания
    ui->txtDescription->setReadOnly(true);
    ui->txtDescription->setWordWrapMode(QTextOption::WordWrap);

    // Установка минимальных размеров
    setMinimumSize(800, 600);

    // Открываем базу данных при запуске
    openDatabase();
}

void MainWindow::setupTreeViewModeSelector()
{
    // Создаем контейнер для радиокнопок
    QWidget *modeContainer = new QWidget();
    QHBoxLayout *modeLayout = new QHBoxLayout(modeContainer);
    modeLayout->setSpacing(10);
    modeLayout->setContentsMargins(5, 5, 5, 5);

    // Создаем радиокнопки
    QRadioButton *rbAuthors = new QRadioButton("По авторам");
    QRadioButton *rbSeries = new QRadioButton("По сериям");
    QRadioButton *rbGenres = new QRadioButton("По жанрам");

    // Настраиваем стиль
    QString radioStyle = R"(
        QRadioButton {
            spacing: 5px;
            font-size: 10px;
            color: #333333;
        }
        QRadioButton::indicator {
            width: 14px;
            height: 14px;
        }
        QRadioButton::indicator:unchecked {
            border: 1px solid #cccccc;
            border-radius: 7px;
            background-color: white;
        }
        QRadioButton::indicator:checked {
            border: 1px solid #2196F3;
            border-radius: 7px;
            background-color: #2196F3;
        }
        QRadioButton:hover {
            color: #2196F3;
        }
    )";

    rbAuthors->setStyleSheet(radioStyle);
    rbSeries->setStyleSheet(radioStyle);
    rbGenres->setStyleSheet(radioStyle);

    // Добавляем в группу
    treeModeButtonGroup->addButton(rbAuthors, static_cast<int>(TreeViewMode::Authors));
    treeModeButtonGroup->addButton(rbSeries, static_cast<int>(TreeViewMode::Series));
    treeModeButtonGroup->addButton(rbGenres, static_cast<int>(TreeViewMode::Genres));

    // Добавляем в layout
    modeLayout->addWidget(rbAuthors);
    modeLayout->addWidget(rbSeries);
    modeLayout->addWidget(rbGenres);
    modeLayout->addStretch(); // Выравниваем по левому краю

    // Устанавливаем авторов по умолчанию
    rbAuthors->setChecked(true);

    // Подключаем сигнал
    connect(treeModeButtonGroup, QOverload<QAbstractButton *>::of(&QButtonGroup::buttonClicked),
            this, &MainWindow::onTreeViewModeChanged);

    // Добавляем контейнер в основной layout после алфавитных кнопок
    QVBoxLayout *mainLayout = qobject_cast<QVBoxLayout*>(ui->groupBox_2->layout());
    if (mainLayout) {
        // Вставляем после алфавитных кнопок (индекс 3)
        mainLayout->insertWidget(3, modeContainer);
    }
}


void MainWindow::about()
{
    QMessageBox::about(this, "О программе",
                      "<h3>Электронная библиотека v0.13</h3>"
                      "<p>Приложение для каталогизации электронных книг <br>в формате fb2</p>"
                      "<p><b>Поддерживаемые форматы:</b><br>"
                      "• fb2, epub<br>"
                      "• INPX (Могут быть ошибки при сканировании)<br>"
                      "• zip, 7zip, rar, tar</p>"
                      "<p><b>Возможности:</b><br>"
                      "• Создание коллекции книг<br>"
                      "• Быстрый поиск по названию, автору, серии, жанру<br>"
                      "• Фильтрация по алфавиту (русский алфавит)<br>"
                      "• Несколько режимов просмотра: по авторам, по сериям, по жанрам<br>"
                      "• Статистика коллекции<br>"
                      "• Встроенная читалка FB2<br>"
                      "• Поддержка СУБД: SQLite и MySQL/MariaDB<br>"
                      "• Инкрементальное сканирование - только измененные файлы<br>"
                      "• Контроль целостности архивов через хеширование MD5</p>"
                      "<p><b>Автор:</b><br>"
                      "• LostDragon (ldragon24@gmail.com)</b></p");
}



// Слот для изменения режима отображения
void MainWindow::onTreeViewModeChanged()
{
    int modeId = treeModeButtonGroup->checkedId();
    currentTreeMode = static_cast<TreeViewMode>(modeId);

    // Обновляем дерево
    refreshTreeView();
}

void MainWindow::refreshTreeView()
{
    if (!isDatabaseOpen()) return;

    // Сбрасываем чекбокс "Загрузить всё"
    ui->chkLoadAll->setChecked(false);

    // Очищаем поиск
    ui->searchLineEdit->clear();

    // Загружаем данные в зависимости от выбранного режима
    if (!letterButtonGroup->buttons().isEmpty()) {
        QString firstLetter = letterButtonGroup->buttons().first()->text();

        switch (currentTreeMode) {
        case TreeViewMode::Authors:
            loadAuthorsByLetter(firstLetter);
            break;
        case TreeViewMode::Series:
            loadSeriesByLetter(firstLetter);
            break;
        case TreeViewMode::Genres:
            loadGenresByLetter(firstLetter);
            break;
        }
    }
}



void MainWindow::setupAlphabetButtons()
{
    // Русский алфавит
    QStringList russianAlphabet = {
        "А", "Б", "В", "Г", "Д", "Е", "Ё", "Ж", "З", "И", "Й",
        "К", "Л", "М", "Н", "О", "П", "Р", "С", "Т", "У", "Ф",
        "Х", "Ц", "Ч", "Ш", "Щ", "Ъ", "Ы", "Ь", "Э", "Ю", "Я"
    };

    // Создаем контейнер для кнопок
    QWidget *buttonContainer = new QWidget();
    QGridLayout *buttonLayout = new QGridLayout(buttonContainer);
    buttonLayout->setSpacing(4);
    buttonLayout->setContentsMargins(8, 8, 8, 8);
    buttonLayout->setAlignment(Qt::AlignCenter);

    // Размещаем кнопки в два ряда
    int totalLetters = russianAlphabet.size();
    int lettersPerRow = (totalLetters + 1) / 2; // Округление вверх

    for (int i = 0; i < totalLetters; ++i) {
        int row = i / lettersPerRow;
        int col = i % lettersPerRow;

        QPushButton *button = new QPushButton(russianAlphabet[i]);
        button->setFixedSize(30, 30);
        button->setCheckable(true);

        // Красивый стиль для кнопок
        button->setStyleSheet(QString::fromUtf8(
            "QPushButton {"
            "    border: 1px solid #d0d0d0;"
            "    border-radius: 5px;"
            "    background-color: #ffffff;"
            "    font-size: 11px;"
            "    font-weight: bold;"
            "    color: #333333;"
            "}"
            "QPushButton:hover {"
            "    background-color: #f0f0f0;"
            "    border-color: #a0a0a0;"
            "}"
            "QPushButton:checked {"
            "    background-color: qlineargradient(x1:0, y1:0, x2:0, y2:1,"
            "        stop:0 #4CAF50, stop:1 #45a049);"
            "    color: white;"
            "    border-color: #3d8b40;"
            "}"
            "QPushButton:pressed {"
            "    background-color: #45a049;"
            "}"
        ));

        buttonLayout->addWidget(button, row, col);
        letterButtonGroup->addButton(button);

        connect(button, &QPushButton::clicked, [this, letter = russianAlphabet[i]]() {
            onLetterButtonClicked(letter);
        });
    }

    // Добавляем контейнер в основной layout
    QVBoxLayout *mainLayout = qobject_cast<QVBoxLayout*>(ui->groupBox_2->layout());
    if (mainLayout) {
        mainLayout->insertWidget(2, buttonContainer);

        // Добавляем отступ после кнопок для лучшего визуального разделения
        mainLayout->insertSpacing(3, 5);
    }

    // Автоматически выбираем первую букву
    if (!letterButtonGroup->buttons().isEmpty()) {
        letterButtonGroup->buttons().first()->setChecked(true);
    }

    // qDebug() << "Alphabet buttons setup completed with" << letterButtonGroup->buttons().size() << "buttons";
}

void MainWindow::onLetterButtonClicked(const QString &letter)
{
    if (!isDatabaseOpen()) return;

    // Сбрасываем чекбокс "Загрузить всё"
    ui->chkLoadAll->setChecked(false);

    // Очищаем поиск
    ui->searchLineEdit->clear();

    // Загружаем данные на выбранную букву в зависимости от режима
    switch (currentTreeMode) {
    case TreeViewMode::Authors:
        loadAuthorsByLetter(letter);
        break;
    case TreeViewMode::Series:
        loadSeriesByLetter(letter);
        break;
    case TreeViewMode::Genres:
        loadGenresByLetter(letter);
        break;
    }
}


void MainWindow::onLoadAllChecked(bool checked)
{
    if (!isDatabaseOpen()) return;

    if (checked) {
        // Сбрасываем выбранную букву
        for (QAbstractButton *button : letterButtonGroup->buttons()) {
            button->setChecked(false);
        }

        // Очищаем поиск
        ui->searchLineEdit->clear();

        // Загружаем все данные в зависимости от режима
        switch (currentTreeMode) {
        case TreeViewMode::Authors:
            loadAllAuthors();
            break;
        case TreeViewMode::Series:
            loadAllSeries();
            break;
        case TreeViewMode::Genres:
            loadAllGenres();
            break;
        }
    } else {
        // Возвращаемся к отображению по букве
        if (!letterButtonGroup->buttons().isEmpty()) {
            QString firstLetter = letterButtonGroup->buttons().first()->text();

            switch (currentTreeMode) {
            case TreeViewMode::Authors:
                loadAuthorsByLetter(firstLetter);
                break;
            case TreeViewMode::Series:
                loadSeriesByLetter(firstLetter);
                break;
            case TreeViewMode::Genres:
                loadGenresByLetter(firstLetter);
                break;
            }
        }
    }
}


void MainWindow::setupIcons()
{
    // Простой и надежный способ с эмодзи
    QFont font;
    font.setPointSize(10);

    // Автор - иконка человека
    QPixmap authorPixmap(16, 16);
    authorPixmap.fill(Qt::transparent);
    QPainter authorPainter(&authorPixmap);
    authorPainter.setFont(font);
    authorPainter.drawText(authorPixmap.rect(), Qt::AlignCenter, "👤");
    authorIcon = QIcon(authorPixmap);

    // Книга - иконка книги
    QPixmap bookPixmap(16, 16);
    bookPixmap.fill(Qt::transparent);
    QPainter bookPainter(&bookPixmap);
    bookPainter.setFont(font);
    bookPainter.drawText(bookPixmap.rect(), Qt::AlignCenter, "📖");
    bookIcon = QIcon(bookPixmap);

    // Серия - иконка стопки книг
    QPixmap seriesPixmap(16, 16);
    seriesPixmap.fill(Qt::transparent);
    QPainter seriesPainter(&seriesPixmap);
    seriesPainter.setFont(font);
    seriesPainter.drawText(seriesPixmap.rect(), Qt::AlignCenter, "📚");
    seriesIcon = QIcon(seriesPixmap);

    // Жанр - иконка тега
    QPixmap genrePixmap(16, 16);
    genrePixmap.fill(Qt::transparent);
    QPainter genrePainter(&genrePixmap);
    genrePainter.setFont(font);
    genrePainter.drawText(genrePixmap.rect(), Qt::AlignCenter, "🏷️");
    genreIcon = QIcon(genrePixmap);

    // qDebug() << "Icons setup completed successfully";
}

// Новые методы для загрузки серий
void MainWindow::loadSeriesByLetter(const QString &letter)
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << QString("Серии на '%1'").arg(letter));

    QSqlQuery query;
    query.prepare("SELECT DISTINCT series FROM books WHERE series IS NOT NULL AND series != '' AND series LIKE ? ORDER BY series");
    query.addBindValue(letter + "%");

    if (!query.exec()) {
        showError("Ошибка загрузки серий: " + query.lastError().text());
        QApplication::restoreOverrideCursor();
        return;
    }

    int seriesCount = 0;
    while (query.next()) {
        QString series = query.value(0).toString();
        QStandardItem *seriesItem = new QStandardItem(seriesIcon, series);

        // ЗАГРУЖАЕМ КНИГИ СРАЗУ
        QSqlQuery bookQuery;
        bookQuery.prepare("SELECT id, title, author, series_number FROM books WHERE series = ? ORDER BY series_number, title LIMIT 100");
        bookQuery.addBindValue(series);

        if (bookQuery.exec()) {
            while (bookQuery.next()) {
                int bookId = bookQuery.value(0).toInt();
                QString title = bookQuery.value(1).toString();
                QString author = bookQuery.value(2).toString();
                int seriesNumber = bookQuery.value(3).toInt();

                QString displayText = title;
                if (seriesNumber > 0) {
                    displayText += QString(" (#%1)").arg(seriesNumber);
                }
                displayText += " - " + author;

                QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
                bookItem->setData(bookId, Qt::UserRole);
                bookItem->setToolTip(QString("%1\nАвтор: %2\nСерия: %3").arg(title).arg(author).arg(series));

                seriesItem->appendRow(bookItem);
            }
        }

        treeModel->appendRow(seriesItem);
        seriesCount++;
    }

    ui->treeView->setModel(treeModel);
    ui->treeView->expandAll();
    updateSelectionStatistics(letter);
    QApplication::restoreOverrideCursor();

    qDebug() << "Loaded" << seriesCount << "series for letter" << letter;
}

void MainWindow::loadAllSeries()
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << "Все серии");

    QSqlQuery query;
    query.exec("SELECT DISTINCT series FROM books WHERE series IS NOT NULL AND series != '' ORDER BY series");

    int seriesCount = 0;
    while (query.next()) {
        QString series = query.value(0).toString();
        QStandardItem *seriesItem = new QStandardItem(seriesIcon, series);

        // ЗАГРУЖАЕМ КНИГИ СРАЗУ
        QSqlQuery bookQuery;
        bookQuery.prepare("SELECT id, title, author, series_number FROM books WHERE series = ? ORDER BY series_number, title LIMIT 50");
        bookQuery.addBindValue(series);

        if (bookQuery.exec()) {
            while (bookQuery.next()) {
                int bookId = bookQuery.value(0).toInt();
                QString title = bookQuery.value(1).toString();
                QString author = bookQuery.value(2).toString();
                int seriesNumber = bookQuery.value(3).toInt();

                QString displayText = title;
                if (seriesNumber > 0) {
                    displayText += QString(" (#%1)").arg(seriesNumber);
                }
                displayText += " - " + author;

                QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
                bookItem->setData(bookId, Qt::UserRole);
                bookItem->setToolTip(QString("%1\nАвтор: %2\nСерия: %3").arg(title).arg(author).arg(series));

                seriesItem->appendRow(bookItem);
            }
        }

        treeModel->appendRow(seriesItem);
        seriesCount++;
    }

    ui->treeView->setModel(treeModel);
    loadStatistics();
    QApplication::restoreOverrideCursor();

    qDebug() << "Loaded" << seriesCount << "series with books";
}

void MainWindow::loadGenresByLetter(const QString &letter)
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << QString("Жанры на '%1'").arg(letter));

    // Получаем ВСЕ жанры из базы и фильтруем их на клиентской стороне
    QSqlQuery query;
    query.prepare("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");

    if (!query.exec()) {
        showError("Ошибка загрузки жанров: " + query.lastError().text());
        QApplication::restoreOverrideCursor();
        return;
    }

    int genreCount = 0;
    QMap<QString, QString> filteredGenres; // Для сортировки по читаемым названиям

    while (query.next()) {
        QString genreCode = query.value(0).toString();
        QString readableGenre = getReadableGenre(genreCode);

        // Фильтруем по ПЕРВОЙ БУКВЕ читаемого названия
        if (readableGenre.startsWith(letter, Qt::CaseInsensitive)) {
            filteredGenres[readableGenre] = genreCode;
        }
    }

    // Добавляем отфильтрованные жанры в дерево
    for (auto it = filteredGenres.begin(); it != filteredGenres.end(); ++it) {
        QString readableGenre = it.key();
        QString genreCode = it.value();

        QStandardItem *genreItem = new QStandardItem(genreIcon, readableGenre);
        genreItem->setData(genreCode, Qt::UserRole); // Сохраняем оригинальный код жанра

        // ЗАГРУЖАЕМ КНИГИ СРАЗУ
        QSqlQuery bookQuery;
        bookQuery.prepare("SELECT id, title, author FROM books WHERE genre = ? ORDER BY title LIMIT 100");
        bookQuery.addBindValue(genreCode);

        if (bookQuery.exec()) {
            while (bookQuery.next()) {
                int bookId = bookQuery.value(0).toInt();
                QString title = bookQuery.value(1).toString();
                QString author = bookQuery.value(2).toString();

                QString displayText = title + " - " + author;

                QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
                bookItem->setData(bookId, Qt::UserRole);
                bookItem->setToolTip(QString("%1\nАвтор: %2\nЖанр: %3").arg(title).arg(author).arg(readableGenre));

                genreItem->appendRow(bookItem);
            }
        }

        treeModel->appendRow(genreItem);
        genreCount++;
    }

    ui->treeView->setModel(treeModel);
    ui->treeView->expandAll();
    updateSelectionStatistics(letter);
    QApplication::restoreOverrideCursor();

    qDebug() << "Loaded" << genreCount << "genres for letter" << letter;
}

void MainWindow::loadAllGenres()
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << "Все жанры");

    QSqlQuery query;
    query.exec("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");

    int genreCount = 0;
    QMap<QString, QString> sortedGenres; // Для сортировки по читаемым названиям

    // Сначала собираем все жанры и преобразуем их
    while (query.next()) {
        QString genreCode = query.value(0).toString();
        QString readableGenre = getReadableGenre(genreCode);
        sortedGenres[readableGenre] = genreCode;
    }

    // Теперь добавляем в дерево уже отсортированные
    for (auto it = sortedGenres.begin(); it != sortedGenres.end(); ++it) {
        QString readableGenre = it.key();
        QString genreCode = it.value();

        QStandardItem *genreItem = new QStandardItem(genreIcon, readableGenre);
        genreItem->setData(genreCode, Qt::UserRole); // Сохраняем оригинальный код

        // ЗАГРУЖАЕМ КНИГИ СРАЗУ
        QSqlQuery bookQuery;
        bookQuery.prepare("SELECT id, title, author FROM books WHERE genre = ? ORDER BY title LIMIT 50");
        bookQuery.addBindValue(genreCode);

        if (bookQuery.exec()) {
            while (bookQuery.next()) {
                int bookId = bookQuery.value(0).toInt();
                QString title = bookQuery.value(1).toString();
                QString author = bookQuery.value(2).toString();

                QString displayText = title + " - " + author;

                QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
                bookItem->setData(bookId, Qt::UserRole);
                bookItem->setToolTip(QString("%1\nАвтор: %2\nЖанр: %3").arg(title).arg(author).arg(readableGenre));

                genreItem->appendRow(bookItem);
            }
        }

        treeModel->appendRow(genreItem);
        genreCount++;
    }

    ui->treeView->setModel(treeModel);
    loadStatistics();
    QApplication::restoreOverrideCursor();

    qDebug() << "Loaded" << genreCount << "genres with books";
}

void MainWindow::loadGenreBooks(const QString &genreCode, QStandardItem *genreItem)
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    QString readableGenre = getReadableGenre(genreCode);
    // qDebug() << "Loading books for genre:" << genreCode << "->" << readableGenre;

    QSqlQuery bookQuery;
    bookQuery.prepare("SELECT id, title, author FROM books WHERE genre = ? ORDER BY title");
    bookQuery.addBindValue(genreCode);

    int bookCount = 0;
    if (bookQuery.exec()) {
        while (bookQuery.next()) {
            int bookId = bookQuery.value(0).toInt();
            QString title = bookQuery.value(1).toString();
            QString author = bookQuery.value(2).toString();

            QString displayText = title + " - " + author;

            QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
            bookItem->setData(bookId, Qt::UserRole);
            bookItem->setToolTip(QString("%1\nАвтор: %2\nЖанр: %3").arg(title).arg(author).arg(readableGenre));

            genreItem->appendRow(bookItem);
            bookCount++;
        }
    } else {
        // qDebug() << "Error loading genre books:" << bookQuery.lastError().text();
    }

    QApplication::restoreOverrideCursor();
    // qDebug() << "Loaded" << bookCount << "books for genre:" << readableGenre;

    // Если книг не найдено, показываем сообщение
    if (bookCount == 0) {
        QStandardItem *noBooksItem = new QStandardItem("Книги не найдены");
        noBooksItem->setEnabled(false);
        genreItem->appendRow(noBooksItem);
    }
}



void MainWindow::setupTreeView()
{
    if (!isDatabaseOpen()) return;

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << "Коллекция книг");

    // Настройки внешнего вида дерева
    ui->treeView->setIconSize(QSize(18, 18));
    ui->treeView->setAnimated(true);
    ui->treeView->setUniformRowHeights(true);

    // Загружаем авторов на первую букву по умолчанию
    if (!letterButtonGroup->buttons().isEmpty()) {
        QString firstLetter = letterButtonGroup->buttons().first()->text();
        loadAuthorsByLetter(firstLetter);
    }
}

void MainWindow::onTreeViewExpanded(const QModelIndex &index)
{
    if (!isDatabaseOpen()) return;

    QStandardItem *item = treeModel->itemFromIndex(index);
    if (!item) return;

    // qDebug() << "Tree view expanded. Current mode:" << static_cast<int>(currentTreeMode);
    // qDebug() << "Item text:" << item->text() << "Parent:" << (item->parent() ? item->parent()->text() : "NULL");
    // qDebug() << "Item data:" << item->data(Qt::UserRole).toString() << "Item data+1:" << item->data(Qt::UserRole + 1).toString();

    // Для режима "Все авторы/серии/жанры" загружаем книги при раскрытии
    if (item->parent() == nullptr) {
        QString itemText = item->text();
        QString itemData = item->data(Qt::UserRole + 1).toString();

        // qDebug() << "Processing top-level item. Text:" << itemText << "Data:" << itemData;

        // Проверяем, есть ли placeholder
        if (item->rowCount() == 1) {
            QStandardItem *firstChild = item->child(0);
            if (firstChild && firstChild->text() == "Загрузка..." && !firstChild->isEnabled()) {
                // qDebug() << "Found placeholder, removing and loading content";

                // Удаляем placeholder
                item->removeRow(0);

                // Загружаем книги в зависимости от режима
                switch (currentTreeMode) {
                case TreeViewMode::Authors:
                    // qDebug() << "Loading author books for:" << itemText;
                    loadAuthorBooks(itemText, item);
                    break;
                case TreeViewMode::Series:
                    // qDebug() << "Loading series books for:" << itemText;
                    loadSeriesBooks(itemText, item);
                    break;
                case TreeViewMode::Genres:
                    // qDebug() << "Loading genre books for:" << itemData;
                    loadGenreBooks(itemData.isEmpty() ? itemText : itemData, item);
                    break;
                }
            }
        }

        // Если детей нет вообще (может быть в некоторых случаях)
        if (item->rowCount() == 0) {
            // qDebug() << "No children found, loading content";

            // Загружаем книги в зависимости от режима
            switch (currentTreeMode) {
            case TreeViewMode::Authors:
                loadAuthorBooks(itemText, item);
                break;
            case TreeViewMode::Series:
                loadSeriesBooks(itemText, item);
                break;
            case TreeViewMode::Genres:
                loadGenreBooks(itemData.isEmpty() ? itemText : itemData, item);
                break;
            }
        }
    }
}

// Добавим метод для загрузки книг серии (аналогично автору)
void MainWindow::loadSeriesBooks(const QString &series, QStandardItem *seriesItem)
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    QSqlQuery bookQuery;
    bookQuery.prepare("SELECT id, title, author, series_number FROM books WHERE series = ? ORDER BY series_number, title");
    bookQuery.addBindValue(series);

    if (bookQuery.exec()) {
        while (bookQuery.next()) {
            int bookId = bookQuery.value(0).toInt();
            QString title = bookQuery.value(1).toString();
            QString author = bookQuery.value(2).toString();
            int seriesNumber = bookQuery.value(3).toInt();

            QString displayText = title;
            if (seriesNumber > 0) {
                displayText += QString(" (#%1)").arg(seriesNumber);
            }
            displayText += " - " + author;

            QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
            bookItem->setData(bookId, Qt::UserRole);
            bookItem->setToolTip(QString("%1\nАвтор: %2\nСерия: %3").arg(title).arg(author).arg(series));

            seriesItem->appendRow(bookItem);
        }
    }

    QApplication::restoreOverrideCursor();
    // qDebug() << "Loaded books for series:" << series;
}

void MainWindow::loadAuthorBooks(const QString &author, QStandardItem *authorItem)
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    // Загружаем книги этого автора
    QSqlQuery bookQuery;
    bookQuery.prepare("SELECT id, title, series, series_number FROM books WHERE author = ? ORDER BY series, series_number, title");
    bookQuery.addBindValue(author);

    if (bookQuery.exec()) {
        // Группируем книги по сериям
        QMap<QString, QStandardItem*> seriesItems;
        QList<QStandardItem*> booksWithoutSeries;

        while (bookQuery.next()) {
            int bookId = bookQuery.value(0).toInt();
            QString title = bookQuery.value(1).toString();
            QString series = bookQuery.value(2).toString();
            int seriesNumber = bookQuery.value(3).toInt();

            QString displayText = title;
            if (seriesNumber > 0) {
                displayText += QString(" (#%1)").arg(seriesNumber);
            }

            QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
            bookItem->setData(bookId, Qt::UserRole);
            bookItem->setToolTip(title);

            if (!series.isEmpty()) {
                // Книга в серии
                if (!seriesItems.contains(series)) {
                    QStandardItem *seriesItem = new QStandardItem(seriesIcon, series);
                    seriesItem->setData(-1, Qt::UserRole); // -1 означает серию
                    seriesItems[series] = seriesItem;
                }
                seriesItems[series]->appendRow(bookItem);
            } else {
                // Книга без серии
                booksWithoutSeries.append(bookItem);
            }
        }

        // Добавляем серии в автора
        QList<QString> seriesNames = seriesItems.keys();
        std::sort(seriesNames.begin(), seriesNames.end());

        for (const QString &seriesName : seriesNames) {
            authorItem->appendRow(seriesItems[seriesName]);
        }

        // Добавляем книги без серий
        for (QStandardItem *bookItem : booksWithoutSeries) {
            authorItem->appendRow(bookItem);
        }

        // qDebug() << "Loaded" << (seriesItems.size() + booksWithoutSeries.size()) << "books for author" << author;
    }

    QApplication::restoreOverrideCursor();
}






void MainWindow::loadAuthorsByLetter(const QString &letter)
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << QString("Авторы на '%1'").arg(letter));

    // Загружаем авторов на указанную букву
    QSqlQuery query;
    query.prepare("SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' AND author LIKE ? ORDER BY author");
    query.addBindValue(letter + "%");

    if (!query.exec()) {
        showError("Ошибка загрузки авторов: " + query.lastError().text());
        QApplication::restoreOverrideCursor();
        return;
    }

    int authorCount = 0;
    while (query.next()) {
        QString author = query.value(0).toString();
        QStandardItem *authorItem = new QStandardItem(authorIcon, author);

        // ЗАГРУЖАЕМ КНИГИ СРАЗУ (как было раньше)
        QSqlQuery bookQuery;
        bookQuery.prepare("SELECT id, title, series, series_number FROM books WHERE author = ? ORDER BY series, series_number, title LIMIT 100"); // Ограничиваем для скорости
        bookQuery.addBindValue(author);

        if (bookQuery.exec()) {
            while (bookQuery.next()) {
                int bookId = bookQuery.value(0).toInt();
                QString title = bookQuery.value(1).toString();
                QString series = bookQuery.value(2).toString();
                int seriesNumber = bookQuery.value(3).toInt();

                QString displayText = title;
                if (seriesNumber > 0) {
                    displayText += QString(" (#%1)").arg(seriesNumber);
                }

                QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
                bookItem->setData(bookId, Qt::UserRole);
                bookItem->setToolTip(title);

                authorItem->appendRow(bookItem);
            }
        }

        treeModel->appendRow(authorItem);
        authorCount++;
    }

    ui->treeView->setModel(treeModel);
    ui->treeView->expandAll();

    // Обновляем статистику для текущей выборки
    updateSelectionStatistics(letter);

    QApplication::restoreOverrideCursor();

    qDebug() << "Loaded" << authorCount << "authors for letter" << letter;
}

void MainWindow::loadAllAuthors()
{
    if (!isDatabaseOpen()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << "Все авторы");

    // Загружаем всех авторов
    QSqlQuery query;
    query.exec("SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author");

    int authorCount = 0;
    while (query.next()) {
        QString author = query.value(0).toString();
        QStandardItem *authorItem = new QStandardItem(authorIcon, author);

        // ЗАГРУЖАЕМ КНИГИ СРАЗУ (как было раньше)
        QSqlQuery bookQuery;
        bookQuery.prepare("SELECT id, title, series, series_number FROM books WHERE author = ? ORDER BY series, series_number, title LIMIT 50"); // Ограничиваем для скорости
        bookQuery.addBindValue(author);

        if (bookQuery.exec()) {
            while (bookQuery.next()) {
                int bookId = bookQuery.value(0).toInt();
                QString title = bookQuery.value(1).toString();
                QString series = bookQuery.value(2).toString();
                int seriesNumber = bookQuery.value(3).toInt();

                QString displayText = title;
                if (seriesNumber > 0) {
                    displayText += QString(" (#%1)").arg(seriesNumber);
                }

                QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
                bookItem->setData(bookId, Qt::UserRole);
                bookItem->setToolTip(title);

                authorItem->appendRow(bookItem);
            }
        }

        treeModel->appendRow(authorItem);
        authorCount++;
    }

    ui->treeView->setModel(treeModel);
    // Не раскрываем автоматически в режиме "Все авторы" для скорости
    // ui->treeView->expandAll();

    // Обновляем статистику
    loadStatistics();

    QApplication::restoreOverrideCursor();

    qDebug() << "Loaded" << authorCount << "authors with books";
}


void MainWindow::updateSelectionStatistics(const QString &letter)
{
    if (!isDatabaseOpen()) return;

    QSqlQuery query;

    switch (currentTreeMode) {
    case TreeViewMode::Authors:
        // ... существующий код для авторов ...
        break;

    case TreeViewMode::Series:
        // ... существующий код для серий ...
        break;

    case TreeViewMode::Genres:
        // Новая логика для жанров - подсчет на клиентской стороне
        {
            int genreCount = 0;
            int bookCount = 0;
            int authorCount = 0;

            // Получаем все жанры и фильтруем
            QSqlQuery genreQuery;
            genreQuery.prepare("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != ''");
            if (genreQuery.exec()) {
                while (genreQuery.next()) {
                    QString genreCode = genreQuery.value(0).toString();
                    QString readableGenre = getReadableGenre(genreCode);

                    if (readableGenre.startsWith(letter, Qt::CaseInsensitive)) {
                        genreCount++;
                    }
                }
            }

            // Для книг и авторов используем SQL-запросы
            // Сначала получим все подходящие жанры
            QList<QString> matchingGenres;
            QSqlQuery matchingQuery;
            matchingQuery.prepare("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != ''");
            if (matchingQuery.exec()) {
                while (matchingQuery.next()) {
                    QString genreCode = matchingQuery.value(0).toString();
                    QString readableGenre = getReadableGenre(genreCode);

                    if (readableGenre.startsWith(letter, Qt::CaseInsensitive)) {
                        matchingGenres.append(genreCode);
                    }
                }
            }

            if (!matchingGenres.isEmpty()) {
                // Подсчет книг
                QString placeholders = QStringList(matchingGenres.size(), "?").join(",");
                QString bookQueryStr = QString("SELECT COUNT(*) FROM books WHERE genre IN (%1)").arg(placeholders);
                QSqlQuery bookCountQuery;
                bookCountQuery.prepare(bookQueryStr);
                for (int i = 0; i < matchingGenres.size(); ++i) {
                    bookCountQuery.addBindValue(matchingGenres[i]);
                }
                if (bookCountQuery.exec() && bookCountQuery.next()) {
                    bookCount = bookCountQuery.value(0).toInt();
                }

                // Подсчет авторов
                QString authorQueryStr = QString("SELECT COUNT(DISTINCT author) FROM books WHERE genre IN (%1)").arg(placeholders);
                QSqlQuery authorCountQuery;
                authorCountQuery.prepare(authorQueryStr);
                for (int i = 0; i < matchingGenres.size(); ++i) {
                    authorCountQuery.addBindValue(matchingGenres[i]);
                }
                if (authorCountQuery.exec() && authorCountQuery.next()) {
                    authorCount = authorCountQuery.value(0).toInt();
                }
            }

            ui->statsLabel_autor->setText(QString::number(genreCount));
            ui->statsLabel_book->setText(QString::number(bookCount));
            ui->statsLabel_series->setText(QString::number(authorCount));
        }
        break;
    }
}

MainWindow::~MainWindow()
{
    saveSplitterState();
    if (db.isOpen()) {
        db.close();
    }

    // Освобождаем память кэшей
    delete coverCache;
    delete descriptionCache;
    delete bookContentCache;

    delete ui;
}

void MainWindow::showEvent(QShowEvent *event)
{
    QMainWindow::showEvent(event);
    restoreSplitterState();
}

void MainWindow::resizeEvent(QResizeEvent *event)
{
    QMainWindow::resizeEvent(event);
    // Можно добавить дополнительную логику при изменении размера
}

void MainWindow::setupSplitter()
{
    // Настройка сплиттера
    ui->mainSplitter->setStretchFactor(0, 0); // Левая панель - фиксированная пропорция
    ui->mainSplitter->setStretchFactor(1, 1); // Правая панель - растягивается

    // Установка начальных размеров
    QList<int> sizes;
    sizes << 400 << 800; // Ширина левой и правой панелей
    ui->mainSplitter->setSizes(sizes);

    // Подключаем сигнал перемещения сплиттера
    connect(ui->mainSplitter, &QSplitter::splitterMoved, this, &MainWindow::onSplitterMoved);
}

void MainWindow::setupStyles()
{
    // Дополнительные стили можно задать здесь
    QString style = R"(
        QMainWindow {
            background-color: #f5f5f5;
        }
        QGroupBox {
            font-weight: bold;
            border: 1px solid #cccccc;
            border-radius: 4px;
            margin-top: 0.5em;
            padding-top: 10px;
            background-color: #ffffff;
        }
        QGroupBox::title {
            subcontrol-origin: margin;
            left: 10px;
            padding: 0 5px 0 5px;
            color: #333333;
        }
        QTreeView {
            border: 1px solid #cccccc;
            border-radius: 3px;
            background-color: white;
        }
        QTreeView::item {
            padding: 4px;
        }
        QTreeView::item:selected {
            background-color: #e6f3ff;
            color: #0066cc;
        }
        QLineEdit {
            padding: 5px;
            border: 1px solid #cccccc;
            border-radius: 3px;
            background-color: white;
        }
        QTextEdit {
            border: 1px solid #cccccc;
            border-radius: 3px;
            background-color: white;
            font-size: 14px;
        }
        QLabel[objectName^="lbl_"] {
            color: #333333;
            padding: 2px;
        }
        /* Стили для новых полей */
        QLabel[objectName="lbl_file_path"],
        QLabel[objectName="lbl_file_name"] {
            font-size: 9px;
            color: #555555;
        }
        QLabel[objectName="lbl_file_size"] {
            color: #2196F3;
            font-weight: bold;
        }
        QLabel[objectName="lbl_genre"] {
            color: #FF9800;
            font-weight: bold;
        }
    )";
    this->setStyleSheet(style);
}

void MainWindow::saveSplitterState()
{
    QSettings settings("Squee&Dragon", "BookLibrary");
    settings.setValue("splitterState", ui->mainSplitter->saveState());
    settings.setValue("windowGeometry", saveGeometry());
}

void MainWindow::restoreSplitterState()
{
    QSettings settings("Squee&Dragon", "BookLibrary");
    QByteArray splitterState = settings.value("splitterState").toByteArray();
    if (!splitterState.isEmpty()) {
        ui->mainSplitter->restoreState(splitterState);
    }

    QByteArray geometry = settings.value("windowGeometry").toByteArray();
    if (!geometry.isEmpty()) {
        restoreGeometry(geometry);
    }
}

void MainWindow::onSplitterMoved(int pos, int index)
{
    Q_UNUSED(pos)
    Q_UNUSED(index)
    // Автоматически сохраняем состояние при перемещении
    saveSplitterState();
}

void MainWindow::openDatabase()
{
    if (!setupDatabaseConnection()) {
        // Если подключение не удалось, показываем диалог настроек
        if (settingsDialog->exec() == QDialog::Accepted) {
            if (!setupDatabaseConnection()) {
                showError("Не удалось подключиться к базе данных. Проверьте настройки подключения.");
                return;
            }
        } else {
            showError("Не удалось подключиться к базе данных. Приложение будет закрыто.");
            QTimer::singleShot(0, this, &QMainWindow::close);
            return;
        }
    }

    // Создаем таблицы если нужно (для новых баз)
    createDatabaseTables();

    // Инициализируем диалог сканера после успешного подключения к БД
    initScannerDialog();

    // Настройка treeView
    setupTreeView();
    loadStatistics();
}

bool MainWindow::setupDatabaseConnection()
{
    // Закрываем предыдущее соединение
    if (db.isOpen()) {
        db.close();
    }

    QSettings settings("Squee&Dragon", "BookLibrary");
    QString dbType = settings.value("database/type", "sqlite").toString();

    if (dbType == "mysql") {
        // Проверяем доступность драйвера MySQL
        if (!QSqlDatabase::isDriverAvailable("QMYSQL") && !QSqlDatabase::isDriverAvailable("QMARIADB")) {
            showError("Драйвер MySQL не доступен. Установите MySQL драйвер для Qt.");
            return false;
        }

        // Используем доступный драйвер
        if (QSqlDatabase::isDriverAvailable("QMARIADB")) {
            db = QSqlDatabase::addDatabase("QMARIADB");
        } else {
            db = QSqlDatabase::addDatabase("QMYSQL");
        }

        db.setHostName(settings.value("mysql/host", "localhost").toString());
        db.setPort(settings.value("mysql/port", 3306).toInt());
        db.setUserName(settings.value("mysql/user", "root").toString());
        db.setPassword(settings.value("mysql/password", "").toString());
        db.setDatabaseName(settings.value("mysql/database", "mybook").toString());

        // Простые настройки подключения
        db.setConnectOptions("MYSQL_OPT_CONNECT_TIMEOUT=3");

    } else {
        // SQLite
        db = QSqlDatabase::addDatabase("QSQLITE");
        QString dbPath = settings.value("sqlite/path", "mybook.db").toString();

        if (QFileInfo(dbPath).isRelative()) {
            dbPath = QDir::currentPath() + "/" + dbPath;
        }

        db.setDatabaseName(dbPath);

        QSqlQuery setQuery(db);
        setQuery.exec("PRAGMA case_sensitive_like = OFF;");

    }

    if (!db.open()) {
        QString errorMsg = QString("Ошибка подключения к базе данных (%1): %2")
                            .arg(dbType)
                            .arg(db.lastError().text());
        showError(errorMsg);
        return false;
    }

    // Устанавливаем кодировку для MySQL
    if (dbType == "mysql") {
        QSqlQuery query(db);
        query.exec("SET NAMES utf8mb4");
    }

    return true;
}

bool MainWindow::createDatabaseTables()
{
    if (!isDatabaseOpen()) return true; // Если БД уже существует и открыта - все хорошо

    QSqlQuery query;

    // Для MySQL просто проверяем существование таблиц, не создаем заново
    if (db.driverName().contains("MYSQL", Qt::CaseInsensitive) ||
        db.driverName().contains("MARIADB", Qt::CaseInsensitive)) {

        // Проверяем существование таблицы books
        if (query.exec("SHOW TABLES LIKE 'books'") && query.next()) {
            qDebug() << "Table 'books' already exists, skipping creation";
            return true;
        }

        // Создаем таблицу только если она не существует
        QString createBooksTable =
            "CREATE TABLE IF NOT EXISTS books ("
            "    id INT AUTO_INCREMENT PRIMARY KEY,"
            "    file_path TEXT,"
            "    file_name TEXT,"
            "    file_size BIGINT,"
            "    file_type VARCHAR(10),"
            "    archive_path TEXT,"
            "    archive_internal_path TEXT,"
            "    file_hash VARCHAR(64),"
            "    title TEXT,"
            "    author TEXT,"
            "    genre TEXT,"
            "    series TEXT,"
            "    series_number INT,"
            "    year INT,"
            "    language VARCHAR(10),"
            "    publisher TEXT,"
            "    description TEXT,"
            "    added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
            "    last_modified TIMESTAMP NULL,"
            "    last_scanned TIMESTAMP NULL,"
            "    file_mtime BIGINT,"
            "    INDEX idx_author (author(50)),"
            "    INDEX idx_series (series(50)),"
            "    INDEX idx_genre (genre(50))"
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!query.exec(createBooksTable)) {
            qDebug() << "Failed to create books table:" << query.lastError().text();
            return false;
        }

        QString createBooksTableArch =
            "CREATE TABLE IF NOT EXISTS archives ("
            "    id INT AUTO_INCREMENT PRIMARY KEY,"
            "    archive_path TEXT,"
            "    archive_hash VARCHAR(64),"
            "    file_count INT,"
            "    total_size BIGINT,"
            "    last_modified BIGINT,"
            "    last_scanned TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
            "    needs_rescan BOOLEAN DEFAULT TRUE,"
            "    UNIQUE KEY unique_archive (archive_path(255))"
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!query.exec(createBooksTableArch)) {
            qDebug() << "Failed to create books table:" << query.lastError().text();
            return false;
        }

    } else {
        // SQLite - создаем таблицу если не существует
        QString createBooksTable =
            "CREATE TABLE IF NOT EXISTS books ("
            "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
            "    file_path TEXT,"
            "    file_name TEXT,"
            "    file_size INTEGER,"
            "    file_type TEXT,"
            "    archive_path TEXT,"
            "    archive_internal_path TEXT,"
            "    file_hash TEXT,"
            "    title TEXT,"
            "    author TEXT,"
            "    genre TEXT,"
            "    series TEXT,"
            "    series_number INTEGER,"
            "    year INTEGER,"
            "    language TEXT,"
            "    publisher TEXT,"
            "    description TEXT,"
            "    added_date DATETIME DEFAULT CURRENT_TIMESTAMP,"
            "    last_modified DATETIME,"
            "    last_scanned DATETIME,"
            "    file_mtime INTEGER,"
            "    UNIQUE(file_path, archive_path, archive_internal_path)"
            ")";

        if (!query.exec(createBooksTable)) {
            qDebug() << "Failed to create books table:" << query.lastError().text();
            return false;
        }

        QString createBooksTableArch =
                "CREATE TABLE IF NOT EXISTS archives ("
                "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                "    archive_path TEXT UNIQUE,"
                "    archive_hash TEXT,"
                "    file_count INTEGER,"
                "    total_size INTEGER,"
                "    last_modified INTEGER,"
                "    last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,"
                "    needs_rescan BOOLEAN DEFAULT 1"
                ");";

        if (!query.exec(createBooksTableArch)) {
            qDebug() << "Failed to create books table:" << query.lastError().text();
            return false;
        }

        // СОЗДАЕМ ИНДЕКСЫ ДЛЯ SQLite
        QStringList indexQueries;
        indexQueries
            << "CREATE INDEX IF NOT EXISTS idx_books_file_hash ON books(file_hash)"
            << "CREATE INDEX IF NOT EXISTS idx_books_file_path ON books(file_path)"
            << "CREATE INDEX IF NOT EXISTS idx_books_archive_path ON books(archive_path)"
            << "CREATE INDEX IF NOT EXISTS idx_books_title ON books(title)"
            << "CREATE INDEX IF NOT EXISTS idx_books_author ON books(author)"
            << "CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre)"
            << "CREATE INDEX IF NOT EXISTS idx_books_series ON books(series)"
            << "CREATE INDEX IF NOT EXISTS idx_books_year ON books(year)"
            << "CREATE INDEX IF NOT EXISTS idx_books_language ON books(language)"
            << "CREATE INDEX IF NOT EXISTS idx_books_publisher ON books(publisher)"
            << "CREATE INDEX IF NOT EXISTS idx_books_file_type ON books(file_type)"
            << "CREATE INDEX IF NOT EXISTS idx_books_series_number ON books(series_number)"
            << "CREATE INDEX IF NOT EXISTS idx_books_added_date ON books(added_date)"
            << "CREATE INDEX IF NOT EXISTS idx_books_last_scanned ON books(last_scanned)"
            << "CREATE INDEX IF NOT EXISTS idx_books_title_author ON books(title, author)";

        for (const QString &indexQuery : indexQueries) {
            if (!query.exec(indexQuery)) {
                qDebug() << "Failed to create index:" << indexQuery << "Error:" << query.lastError().text();
                // Не прерываем выполнение, продолжаем создавать остальные индексы
            } else {
                qDebug() << "Index created successfully:" << indexQuery;
            }
        }

        // Также создаем индексы для таблицы archives
        QStringList archiveIndexQueries;
        archiveIndexQueries
            << "CREATE INDEX IF NOT EXISTS idx_archives_archive_hash ON archives(archive_hash)"
            << "CREATE INDEX IF NOT EXISTS idx_archives_last_modified ON archives(last_modified)"
            << "CREATE INDEX IF NOT EXISTS idx_archives_last_scanned ON archives(last_scanned)"
            << "CREATE INDEX IF NOT EXISTS idx_archives_needs_rescan ON archives(needs_rescan)";

        for (const QString &indexQuery : archiveIndexQueries) {
            if (!query.exec(indexQuery)) {
                qDebug() << "Failed to create archive index:" << indexQuery << "Error:" << query.lastError().text();
            } else {
                qDebug() << "Archive index created successfully:" << indexQuery;
            }
        }
    }

    qDebug() << "Database tables and indexes checked/created successfully";
    return true;
}


void MainWindow::loadStatistics()
{
    if (!isDatabaseOpen()) return;

    QSqlQuery query;

    // Общее количество книг
    query.exec("SELECT COUNT(*) FROM books");
    if (query.next()) {
        ui->statsLabel_book->setText(query.value(0).toString());
    }

    // Количество авторов
    query.exec("SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != ''");
    if (query.next()) {
        ui->statsLabel_autor->setText(query.value(0).toString());
    }

    // Количество серий
    query.exec("SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != ''");
    if (query.next()) {
        ui->statsLabel_series->setText(query.value(0).toString());
    }
}

void MainWindow::loadBookDetails(int bookId)
{
    if (!isDatabaseOpen()) return;

    QSqlQuery query;
    query.prepare("SELECT title, author, series, series_number, year, language, "
                  "publisher, description, file_path, archive_path, archive_internal_path, "
                  "file_name, file_size, genre "  // Добавили новые поля
                  "FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (!query.exec() || !query.next()) {
        showError("Не удалось загрузить информацию о книге");
        clearBookDetails();
        return;
    }

    updateBookDetails(query);

    // Загружаем обложку и полное описание
    loadBookCoverAndDescription(bookId);
}


// Эти методы должны остаться, так как они используются в extractBookContent
QPixmap MainWindow::loadBookCover(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    // Этот метод теперь используется только внутри extractBookContent
    QByteArray fileContent;

    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        // Книга в архиве
        fileContent = extractFileFromArchive(archivePath, internalPath);
    } else {
        // Отдельный файл
        QFile file(filePath);
        if (file.open(QIODevice::ReadOnly)) {
            fileContent = file.readAll();
            file.close();
        }
    }

    if (fileContent.isEmpty()) {
        return QPixmap();
    }

    // Парсим FB2 для извлечения обложки
    return parseCoverFromFB2(fileContent);
}

QString MainWindow::loadFullDescription(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    // Этот метод теперь используется только внутри extractBookContent
    QByteArray fileContent;

    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        fileContent = extractFileFromArchive(archivePath, internalPath);
    } else {
        QFile file(filePath);
        if (file.open(QIODevice::ReadOnly)) {
            fileContent = file.readAll();
            file.close();
        }
    }

    if (fileContent.isEmpty()) {
        return "Не удалось загрузить описание";
    }

    return parseDescriptionFromFB2(fileContent);
}


// Обновленный метод updateBookDetails
void MainWindow::updateBookDetails(const QSqlQuery &query)
{
    // Заполняем основную информацию о книге
    ui->lbl_autor->setText(query.value("author").toString());
    ui->lbl_name_book->setText(query.value("title").toString());

    // Серия
    QString series = query.value("series").toString();
    if (!series.isEmpty()) {
        int seriesNumber = query.value("series_number").toInt();
        if (seriesNumber > 0) {
            series += " (" + QString::number(seriesNumber) + ")";
        }
    }
    ui->lbl_series->setText(series);

    // Год
    int year = query.value("year").toInt();
    ui->lbl_year->setText(year > 0 ? QString::number(year) : "Не указан");

    // Жанр (с подменой на читаемое название)
    QString genreCode = query.value("genre").toString();
    QString readableGenre = getReadableGenre(genreCode);
    ui->lbl_genre->setText(readableGenre);

    // Путь к файлу
    QString filePath = query.value("file_path").toString();
    QString archivePath = query.value("archive_path").toString();

    QString fullPath;
    if (!archivePath.isEmpty()) {
        fullPath = archivePath;
        if (!filePath.isEmpty()) {
            fullPath += " / " + filePath;
        }
    } else {
        fullPath = filePath;
    }
    ui->lbl_file_path->setText(fullPath.isEmpty() ? "Не указан" : fullPath);

    // Имя файла
    QString fileName = query.value("file_name").toString();
    QString internalPath = query.value("archive_internal_path").toString();

    QString displayFileName;
    if (!internalPath.isEmpty()) {
        displayFileName = internalPath;
    } else if (!fileName.isEmpty()) {
        displayFileName = fileName;
    } else {
        // Если имени файла нет, пытаемся извлечь из пути
        QFileInfo fileInfo(filePath);
        displayFileName = fileInfo.fileName();
    }
    ui->lbl_file_name->setText(displayFileName.isEmpty() ? "Не указан" : displayFileName);

    // Размер файла
    qint64 fileSize = query.value("file_size").toLongLong();
    ui->lbl_file_size->setText(formatFileSize(fileSize));

    // Временное описание из БД
    QString description = query.value("description").toString();
    if (description.isEmpty()) {
        description = "Загрузка описания...";
    }
    ui->txtDescription->setPlainText(description);
}

// Метод для форматирования размера файла
QString MainWindow::formatFileSize(qint64 bytes)
{
    if (bytes == 0) return "0 Б";

    static const QStringList units = {"Б", "КБ", "МБ", "ГБ", "ТБ"};
    int unitIndex = 0;
    double size = bytes;

    while (size >= 1024.0 && unitIndex < units.size() - 1) {
        size /= 1024.0;
        unitIndex++;
    }

    return QString("%1 %2").arg(size, 0, 'f', unitIndex > 0 ? 1 : 0).arg(units[unitIndex]);
}

void MainWindow::clearBookDetails()
{
    ui->lbl_autor->setText("Автор");
    ui->lbl_name_book->setText("Название");
    ui->lbl_series->setText("Серия");
    ui->lbl_year->setText("Год");
    ui->lbl_genre->setText("Жанр");
    ui->lbl_file_path->setText("Путь к файлу");
    ui->lbl_file_name->setText("Имя файла");
    ui->lbl_file_size->setText("Размер файла");
    ui->txtDescription->clear();
    ui->lbl_cover->setText("обложка");
    ui->lbl_cover->setPixmap(QPixmap());
}

bool MainWindow::isDatabaseOpen()
{
    if (!db.isOpen()) {
        showError("База данных не открыта");
        return false;
    }
    return true;
}

void MainWindow::showError(const QString &message)
{
    QMessageBox::critical(this, "Ошибка", message);
}

void MainWindow::loadBookCoverAndDescription(int bookId)
{
    if (!isDatabaseOpen()) return;

    QSqlQuery query;
    query.prepare("SELECT file_path, archive_path, archive_internal_path FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (!query.exec() || !query.next()) {
        return;
    }

    QString filePath = query.value("file_path").toString();
    QString archivePath = query.value("archive_path").toString();
    QString internalPath = query.value("archive_internal_path").toString();

    // Сразу показываем placeholder
    ui->lbl_cover->setText("Загрузка...");
    ui->txtDescription->setPlainText("Загрузка описания...");

    // Загружаем с индикацией прогресса
    QApplication::setOverrideCursor(Qt::WaitCursor);
    statusLabel->setText("Загрузка данных книги...");
    progressBar->setVisible(true);
    progressBar->setRange(0, 0); // indeterminate progress

    // Получаем все данные книги за один раз
    BookContent* content = getBookContent(filePath, archivePath, internalPath);

    if (content) {
        // Отображаем обложку
        if (content->hasCover && !content->cover.isNull()) {
            displayCover(content->cover);
        } else {
            ui->lbl_cover->setText("Обложка\nне найдена");
        }

        // Отображаем описание
        if (content->hasDescription && !content->description.isEmpty()) {
            ui->txtDescription->setPlainText(content->description);

            // Прокручиваем к началу текста
            QTextCursor cursor = ui->txtDescription->textCursor();
            cursor.setPosition(0);
            ui->txtDescription->setTextCursor(cursor);
        } else {
            ui->txtDescription->setPlainText("Описание отсутствует");
        }
    } else {
        ui->lbl_cover->setText("Ошибка\nзагрузки");
        ui->txtDescription->setPlainText("Не удалось загрузить данные книги");
    }

    QApplication::restoreOverrideCursor();
    progressBar->setVisible(false);
    statusLabel->setText("Готово");
}


MainWindow::BookContent* MainWindow::getBookContent(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    QString cacheKey = getBookContentCacheKey(filePath, archivePath, internalPath);

    // Проверяем кэш
    BookContent* cachedContent = bookContentCache->object(cacheKey);
    if (cachedContent) {
        qDebug() << "Using cached book content for:" << internalPath;
        return cachedContent;
    }

    // Извлекаем данные
    BookContent content = extractBookContent(filePath, archivePath, internalPath);

    // Сохраняем в кэш
    BookContent* newContent = new BookContent(content);
    bookContentCache->insert(cacheKey, newContent);

    return newContent;
}

// Метод для извлечения всех данных книги за один раз
MainWindow::BookContent MainWindow::extractBookContent(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    BookContent result;

    QElapsedTimer timer;
    timer.start();

    QByteArray fileContent;

    // Извлекаем файл из архива или читаем напрямую
    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        qDebug() << "Extracting book content from archive:" << archivePath << "file:" << internalPath;
        fileContent = extractFileFromArchive(archivePath, internalPath);
    } else {
        // Отдельный файл
        QFile file(filePath);
        if (file.open(QIODevice::ReadOnly)) {
            fileContent = file.readAll();
            file.close();
        }
    }

    if (fileContent.isEmpty()) {
        qDebug() << "Failed to extract book content";
        return result;
    }

    result.data = fileContent;

    // Определяем формат и извлекаем обложку и описание
    QString contentStart = QString::fromUtf8(fileContent.left(1000));

    if (contentStart.contains("<?xml") && contentStart.contains("FictionBook")) {
        // FB2 формат
        result.cover = parseCoverFromFB2Content(fileContent);
        result.description = parseDescriptionFromFB2Content(fileContent);
    } else if (contentStart.contains("PK") && contentStart.contains("application/epub+zip")) {
        // EPUB формат
        result.cover = parseCoverFromEpubContent(fileContent);
        result.description = parseDescriptionFromEpubContent(fileContent);
    } else {
        // Другие форматы
        result.cover = QPixmap();
        result.description = "Описание отсутствует";
    }

    result.hasCover = !result.cover.isNull();
    result.hasDescription = !result.description.isEmpty() && result.description != "Описание отсутствует";

    qDebug() << "Book content extraction completed in" << timer.elapsed() << "ms -"
             << "cover:" << result.hasCover << "description:" << result.hasDescription
             << "format:" << (contentStart.contains("FictionBook") ? "FB2" : "EPUB");

    return result;
}

// Метод для генерации ключа кэша содержимого книги
QString MainWindow::getBookContentCacheKey(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    return QString("book_content_%1_%2_%3").arg(filePath).arg(archivePath).arg(internalPath);
}



// Метод для отображения обложки
void MainWindow::displayCover(const QPixmap& cover)
{
    if (!cover.isNull()) {
        int maxWidth = ui->lbl_cover->width() - 10;
        int maxHeight = ui->lbl_cover->height() - 10;

        QPixmap scaledCover = cover.scaled(maxWidth, maxHeight,
                                         Qt::KeepAspectRatio,
                                         Qt::SmoothTransformation);
        ui->lbl_cover->setPixmap(scaledCover);
        ui->lbl_cover->setText("");
    } else {
        ui->lbl_cover->setText("Обложка\nне найдена");
        ui->lbl_cover->setStyleSheet("border: 1px solid #ccc; background-color: #f8f8f8; color: #666; padding: 5px;");
    }
}



QPixmap MainWindow::parseCoverFromFB2(const QByteArray& content)
{
    // Сначала проверяем, это FB2 или EPUB
    QString contentStr = QString::fromUtf8(content.left(1000)); // Берем первые 1000 байт для анализа

    if (contentStr.contains("<?xml") && contentStr.contains("FictionBook")) {
        // Это FB2 файл
        return parseCoverFromFB2Content(content);
    } else if (contentStr.contains("PK") && contentStr.contains("mimetype") && contentStr.contains("application/epub+zip")) {
        // Это EPUB файл
        return parseCoverFromEpubContent(content);
    }

    return QPixmap();
}

QPixmap MainWindow::parseCoverFromFB2Content(const QByteArray& content)
{
    QXmlStreamReader xml(content);
    QString coverId;

    // Ищем ID обложки в FB2
    while (!xml.atEnd() && !xml.hasError()) {
        xml.readNext();

        if (xml.isStartElement() && xml.name().toString() == "coverpage") {
            while (!xml.atEnd() && !xml.hasError()) {
                xml.readNext();

                if (xml.isStartElement() && xml.name().toString() == "image") {
                    QXmlStreamAttributes attrs = xml.attributes();
                    for (const auto& attr : attrs) {
                        if (attr.name().toString().contains("href", Qt::CaseInsensitive)) {
                            QString href = attr.value().toString();
                            if (href.startsWith('#')) {
                                coverId = href.mid(1);
                            }
                            break;
                        }
                    }
                    break;
                }

                if (xml.isEndElement() && xml.name().toString() == "coverpage") {
                    break;
                }
            }
            break;
        }
    }

    if (coverId.isEmpty()) {
        return QPixmap();
    }

    // Ищем binary с найденным ID
    xml.clear();
    xml.addData(content);

    while (!xml.atEnd() && !xml.hasError()) {
        xml.readNext();

        if (xml.isStartElement() && xml.name().toString() == "binary") {
            if (xml.attributes().value("id").toString() == coverId) {
                QString contentType = xml.attributes().value("content-type").toString();

                if (contentType.startsWith("image/")) {
                    QString base64Data = xml.readElementText();
                    QByteArray imageData = QByteArray::fromBase64(base64Data.toUtf8());

                    QPixmap cover;
                    if (cover.loadFromData(imageData)) {
                        return cover;
                    }

                    // Пробуем разные форматы
                    if (contentType.contains("jpeg") || contentType.contains("jpg")) {
                        cover.loadFromData(imageData, "JPEG");
                    } else if (contentType.contains("png")) {
                        cover.loadFromData(imageData, "PNG");
                    } else if (contentType.contains("gif")) {
                        cover.loadFromData(imageData, "GIF");
                    } else {
                        cover.loadFromData(imageData);
                    }

                    return cover;
                }
            }
        }
    }

    return QPixmap();
}

QPixmap MainWindow::parseCoverFromEpubContent(const QByteArray& epubData)
{
    qDebug() << "Parsing cover from EPUB content";

    // Создаем временный файл для парсинга EPUB
    QTemporaryFile tempFile;
    if (!tempFile.open()) {
        qDebug() << "Failed to create temp file for EPUB cover parsing";
        return QPixmap();
    }

    tempFile.write(epubData);
    tempFile.flush();

    // Используем ArchiveHandler для извлечения обложки
    ArchiveHandler archiveHandler;
    if (!archiveHandler.openArchive(tempFile.fileName())) {
        qDebug() << "Failed to open EPUB archive for cover extraction";
        return QPixmap();
    }

    // Получаем список файлов в EPUB
    QVector<ArchiveFile> files = archiveHandler.listFiles();

    // Ищем файлы обложки (обычно называются cover.* или находятся в папке images)
    QString coverPath;
    for (const ArchiveFile &file : files) {
        QString fileName = file.name.toLower();
        QString filePath = file.path.toLower();

        if (fileName.startsWith("cover.") ||
            fileName.contains("cover") ||
            filePath.contains("/cover.") ||
            filePath.contains("/images/") ||
            filePath.contains("/cover/")) {

            // Проверяем расширение изображения
            QString extension = QFileInfo(fileName).suffix().toLower();
            if (extension == "jpg" || extension == "jpeg" || extension == "png" ||
                extension == "gif" || extension == "bmp") {
                coverPath = file.path;
                qDebug() << "Found potential cover:" << coverPath;
                break;
            }
        }
    }

    // Если не нашли по имени, ищем в OPF файле
    if (coverPath.isEmpty()) {
        QString opfContent = readFileFromArchive(tempFile.fileName(), "OEBPS/content.opf");
        if (opfContent.isEmpty()) {
            opfContent = readFileFromArchive(tempFile.fileName(), "content.opf");
        }

        if (!opfContent.isEmpty()) {
            coverPath = parseCoverPathFromOpf(opfContent);
            qDebug() << "Found cover path from OPF:" << coverPath;
        }
    }

    QPixmap cover;
    if (!coverPath.isEmpty()) {
        QByteArray coverData = archiveHandler.readFile(coverPath);
        if (!coverData.isEmpty()) {
            if (cover.loadFromData(coverData)) {
                qDebug() << "Successfully loaded cover from EPUB";
            } else {
                qDebug() << "Failed to load cover image data";
            }
        }
    }

    archiveHandler.closeArchive();
    tempFile.close();

    return cover;
}

QString MainWindow::parseCoverPathFromOpf(const QString& opfContent)
{
    QXmlStreamReader xml(opfContent);
    QString coverId;

    // Ищем cover-id в meta тегах
    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement && xml.name().toString() == "meta") {
            QXmlStreamAttributes attrs = xml.attributes();
            QString name = attrs.value("name").toString();
            QString content = attrs.value("content").toString();

            if (name == "cover") {
                coverId = content;
                qDebug() << "Found cover ID:" << coverId;
                break;
            }
        }
    }

    if (coverId.isEmpty()) {
        return QString();
    }

    // Ищем элемент с этим ID
    xml.clear();
    xml.addData(opfContent);

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement && xml.name().toString() == "item") {
            QXmlStreamAttributes attrs = xml.attributes();
            QString id = attrs.value("id").toString();
            QString href = attrs.value("href").toString();

            if (id == coverId && !href.isEmpty()) {
                qDebug() << "Found cover href:" << href;
                return href;
            }
        }
    }

    return QString();
}




QString MainWindow::parseDescriptionFromFB2(const QByteArray& content)
{
    // Сначала проверяем формат
    QString contentStr = QString::fromUtf8(content.left(1000));

    if (contentStr.contains("<?xml") && contentStr.contains("FictionBook")) {
        // Это FB2 файл
        return parseDescriptionFromFB2Content(content);
    } else if (contentStr.contains("PK") && contentStr.contains("mimetype") && contentStr.contains("application/epub+zip")) {
        // Это EPUB файл
        return parseDescriptionFromEpubContent(content);
    }

    return "Описание отсутствует";
}

QString MainWindow::parseDescriptionFromFB2Content(const QByteArray& content)
{
    QXmlStreamReader xml(content);
    QString description;
    bool inAnnotation = false;
    int depth = 0;

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            if (xml.name().toString() == "annotation") {
                inAnnotation = true;
                depth++;
                continue;
            }

            if (inAnnotation) {
                if (xml.name().toString() == "p") {
                    if (!description.isEmpty() && !description.endsWith('\n')) {
                        description += "\n\n";
                    }
                }
                depth++;
            }
        }
        else if (token == QXmlStreamReader::EndElement) {
            if (xml.name().toString() == "annotation") {
                break;
            }

            if (inAnnotation) {
                depth--;
                if (depth <= 0) {
                    inAnnotation = false;
                }
            }
        }
        else if (token == QXmlStreamReader::Characters && inAnnotation) {
            QString text = xml.text().toString().trimmed();
            if (!text.isEmpty()) {
                description += text + " ";
            }
        }
    }

    if (xml.hasError()) {
        qDebug() << "XML parsing error:" << xml.errorString();
    }

    QString result = description.trimmed();
    return result.isEmpty() ? "Описание отсутствует" : result;
}

QString MainWindow::parseDescriptionFromEpubContent(const QByteArray& epubData)
{
    qDebug() << "Parsing description from EPUB content";

    // Создаем временный файл
    QTemporaryFile tempFile;
    if (!tempFile.open()) {
        qDebug() << "Failed to create temp file for EPUB description";
        return "Описание отсутствует";
    }

    tempFile.write(epubData);
    tempFile.flush();

    // Ищем OPF файл
    ArchiveHandler archiveHandler;
    if (!archiveHandler.openArchive(tempFile.fileName())) {
        qDebug() << "Failed to open EPUB archive for description";
        return "Описание отсутствует";
    }

    QString opfContent = readFileFromArchive(tempFile.fileName(), "OEBPS/content.opf");
    if (opfContent.isEmpty()) {
        opfContent = readFileFromArchive(tempFile.fileName(), "content.opf");
    }

    if (opfContent.isEmpty()) {
        qDebug() << "Cannot find OPF file in EPUB";
        archiveHandler.closeArchive();
        return "Описание отсутствует";
    }

    // Парсим описание из OPF
    QString description = parseDescriptionFromOpf(opfContent);

    archiveHandler.closeArchive();
    tempFile.close();

    return description.isEmpty() ? "Описание отсутствует" : description;
}

QString MainWindow::parseDescriptionFromOpf(const QString& opfContent)
{
    QXmlStreamReader xml(opfContent);
    QString description;

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            QString elementName = xml.name().toString();

            if (elementName == "description" || elementName == "dc:description") {
                description = xml.readElementText().trimmed();
                qDebug() << "Found description in OPF:" << description.left(100) + "...";
                break;
            }
        }
    }

    return description;
}

QString MainWindow::readFileFromArchive(const QString& archivePath, const QString& internalPath)
{
    ArchiveHandler archiveHandler;
    if (!archiveHandler.openArchive(archivePath)) {
        return QString();
    }

    QByteArray content = archiveHandler.readFile(internalPath);
    archiveHandler.closeArchive();

    return QString::fromUtf8(content);
}



QByteArray MainWindow::extractFileFromArchive(const QString& archivePath, const QString& internalPath)
{
    QByteArray content;
    struct archive *a;
    struct archive_entry *entry;
    int r;

    a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_format_tar(a);
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
        if (entry_path && QString(entry_path) == internalPath) {
            la_ssize_t size = archive_entry_size(entry);
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
            break; // Выходим сразу после нахождения файла
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



void MainWindow::downloadBook(int bookId)
{
    if (!isDatabaseOpen()) return;

    QSqlQuery query;
    query.prepare("SELECT title, file_path, archive_path, archive_internal_path FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (!query.exec() || !query.next()) {
        showError("Не удалось получить информацию о книге для скачивания");
        return;
    }

    QString title = query.value("title").toString();
    QString filePath = query.value("file_path").toString();
    QString archivePath = query.value("archive_path").toString();
    QString internalPath = query.value("archive_internal_path").toString();

    // Определяем расширение файла
    QString extension = "fb2"; // по умолчанию
    if (!internalPath.isEmpty()) {
        QFileInfo fileInfo(internalPath);
        extension = fileInfo.suffix();
    } else {
        QFileInfo fileInfo(filePath);
        extension = fileInfo.suffix();
    }

    if (extension.isEmpty()) extension = "fb2";

    // Запрашиваем путь для сохранения
    QString fileName = QFileDialog::getSaveFileName(this,
        "Сохранить книгу",
        QDir::homePath() + "/" + title + "." + extension,
        "Файлы книг (*." + extension + ")");

    if (fileName.isEmpty()) return;

    // Скачиваем файл
    bool success = false;
    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        // Книга в архиве - извлекаем
        QByteArray fileContent = extractFileFromArchive(archivePath, internalPath);
        if (!fileContent.isEmpty()) {
            QFile file(fileName);
            if (file.open(QIODevice::WriteOnly)) {
                file.write(fileContent);
                file.close();
                success = true;
            } else {
                showError("Не удалось сохранить файл");
            }
        } else {
            showError("Не удалось извлечь книгу из архива");
        }
    } else {
        // Отдельный файл - копируем
        if (QFile::copy(filePath, fileName)) {
            success = true;
        } else {
            showError("Не удалось скопировать файл");
        }
    }

    if (success) {
        QMessageBox::information(this, "Успех", "Книга успешно скачана в:\n" + fileName);
    }
}

void MainWindow::on_actionSettings_triggered()
{
    if (settingsDialog->exec() == QDialog::Accepted) {
        // Переподключаемся к базе данных с новыми настройками
        openDatabase();
    }
}

void MainWindow::on_searchLineEdit_textChanged(const QString &text)
{
    // Теперь очищаем поиск только когда поле пустое
    if (text.isEmpty()) {
        // Возвращаемся к обычному режиму отображения
        if (ui->chkLoadAll->isChecked()) {
            loadAllAuthors();
        } else {
            // Находим первую выбранную букву
            for (QAbstractButton *button : letterButtonGroup->buttons()) {
                if (button->isChecked()) {
                    onLetterButtonClicked(button->text());
                    break;
                }
            }
        }
    }
}

void MainWindow::on_searchLineEdit_returnPressed()
{
    performSearch(ui->searchLineEdit->text().trimmed());
}

void MainWindow::on_btn_search_clicked()
{
    performSearch(ui->searchLineEdit->text().trimmed());
}

void MainWindow::performSearch(const QString &queryText)
{
    if (!isDatabaseOpen()) return;

    if (queryText.isEmpty()) {
        // Если поисковый запрос пустой, возвращаемся к обычному режиму
        if (ui->chkLoadAll->isChecked()) {
            loadAllAuthors();
        } else {
            for (QAbstractButton *button : letterButtonGroup->buttons()) {
                if (button->isChecked()) {
                    onLetterButtonClicked(button->text());
                    break;
                }
            }
        }
        return;
    }

    // Блокируем кнопку поиска на время выполнения
    ui->btn_search->setEnabled(false);
    ui->btn_search->setText("Поиск...");
    QApplication::setOverrideCursor(Qt::WaitCursor);

    // Сбрасываем выбор букв и чекбокс при поиске
    for (QAbstractButton *button : letterButtonGroup->buttons()) {
        button->setChecked(false);
    }
    ui->chkLoadAll->setChecked(false);

    treeModel->clear();
    treeModel->setHorizontalHeaderLabels(QStringList() << "Результаты поиска");


    QSqlQuery query;
    query.prepare(
        "SELECT id, author, title, series, series_number, file_path "
        "FROM books "
        "WHERE author LIKE ? OR title LIKE ? OR series LIKE ? "
        "ORDER BY author, title"
    );

    QString searchPattern = "%" + queryText + "%";
    query.addBindValue(searchPattern);
    query.addBindValue(searchPattern);
    query.addBindValue(searchPattern);

    if (!query.exec()) {
        showError("Ошибка поиска: " + query.lastError().text());
        // Восстанавливаем кнопку
        ui->btn_search->setEnabled(true);
        ui->btn_search->setText("Поиск");
        QApplication::restoreOverrideCursor();
        return;
    }

    QMap<QString, QStandardItem*> authors;
    int resultCount = 0;

    while (query.next()) {
        int bookId = query.value(0).toInt();
        QString author = query.value(1).toString();
        QString title = query.value(2).toString();
        QString series = query.value(3).toString();
        int seriesNumber = query.value(4).toInt();

        QString displayText = title;
        if (seriesNumber > 0) {
            displayText += QString(" (#%1)").arg(seriesNumber);
        }
        if (!series.isEmpty()) {
            displayText += " (" + series + ")";
        }

        QStandardItem *bookItem = new QStandardItem(bookIcon, displayText);
        bookItem->setData(bookId, Qt::UserRole);
        bookItem->setToolTip(QString("%1\nАвтор: %2").arg(title).arg(author));

        // Группируем по авторам
        if (!authors.contains(author)) {
            authors[author] = new QStandardItem(authorIcon, author);
        }
        authors[author]->appendRow(bookItem);
        resultCount++;
    }

    // Добавляем авторов в модель
    QList<QString> authorNames = authors.keys();
    std::sort(authorNames.begin(), authorNames.end());

    for (const QString &authorName : authorNames) {
        treeModel->appendRow(authors[authorName]);
    }

    ui->treeView->setModel(treeModel);
    ui->treeView->expandAll();

    // Обновляем статистику для результатов поиска
    ui->statsLabel_book->setText(QString::number(resultCount));
    ui->statsLabel_autor->setText(QString::number(authors.size()));

    // Для поиска показываем только количество найденных книг и авторов
    QSqlQuery countQuery;
    countQuery.prepare("SELECT COUNT(DISTINCT series) FROM books WHERE author LIKE ? OR title LIKE ? OR series LIKE ?");
    countQuery.addBindValue(searchPattern);
    countQuery.addBindValue(searchPattern);
    countQuery.addBindValue(searchPattern);
    if (countQuery.exec() && countQuery.next()) {
        ui->statsLabel_series->setText(countQuery.value(0).toString());
    } else {
        ui->statsLabel_series->setText("-");
    }

    // Восстанавливаем кнопку
    ui->btn_search->setEnabled(true);
    ui->btn_search->setText("Поиск");
    QApplication::restoreOverrideCursor();

    // Показываем сообщение ТОЛЬКО здесь, один раз
    if (resultCount == 0) {
        showInfo(QString("По запросу \"%1\" ничего не найдено").arg(queryText));
    } else {
        // УБИРАЕМ showInfo отсюда - статистика и так видна в интерфейсе
        // showInfo(QString("Найдено книг: %1, авторов: %2").arg(resultCount).arg(authors.size()));

        // Вместо этого можно показать в статусной строке или просто обновить статистику
        statusBar()->showMessage(QString("Найдено книг: %1, авторов: %2").arg(resultCount).arg(authors.size()), 3000);
    }
}


void MainWindow::on_treeView_clicked(const QModelIndex &index)
{
    if (!index.isValid()) return;

    QStandardItem *item = treeModel->itemFromIndex(index);
    if (item && item->parent()) { // Клик на книге (не на авторе)
        int bookId = item->data(Qt::UserRole).toInt();
        if (bookId > 0) {
            loadBookDetails(bookId);
        }
    }
}

void MainWindow::on_btn_download_clicked()
{
    QModelIndex index = ui->treeView->currentIndex();
    if (!index.isValid()) {
        QMessageBox::information(this, "Скачать", "Выберите книгу для скачивания");
        return;
    }

    QStandardItem *item = treeModel->itemFromIndex(index);
    if (!item || !item->parent()) {
        QMessageBox::information(this, "Скачать", "Выберите конкретную книгу");
        return;
    }

    int bookId = item->data(Qt::UserRole).toInt();
    if (bookId <= 0) return;

    downloadBook(bookId);
}

void MainWindow::on_pushButton_clicked()
{
    // Кнопка "..." рядом с поиском - очистка поиска
    ui->searchLineEdit->clear();
}



void MainWindow::on_btn_delete_clicked()
{
    QModelIndex index = ui->treeView->currentIndex();
    if (!index.isValid()) {
        showError("Выберите книгу для удаления");
        return;
    }

    QStandardItem *item = treeModel->itemFromIndex(index);
    if (!item || !item->parent()) {
        showError("Выберите конкретную книгу для удаления");
        return;
    }

    int bookId = item->data(Qt::UserRole).toInt();
    if (bookId <= 0) {
        showError("Неверный идентификатор книги");
        return;
    }

    // Получаем информацию о книге для подтверждения
    QSqlQuery query;
    query.prepare("SELECT title, author FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (!query.exec() || !query.next()) {
        showError("Не удалось получить информацию о книге");
        return;
    }

    QString title = query.value(0).toString();
    QString author = query.value(1).toString();

    // Запрос подтверждения
    int result = QMessageBox::question(this,
        "Подтверждение удаления",
        QString("Вы уверены, что хотите удалить книгу:\n\"%1\"\nАвтор: %2\n\nУдаление невозможно отменить!")
            .arg(title)
            .arg(author),
        QMessageBox::Yes | QMessageBox::No,
        QMessageBox::No);

    if (result == QMessageBox::Yes) {
        if (deleteBook(bookId)) {
            // УБИРАЕМ showInfo отсюда - показываем в статусной строке
            // showInfo(QString("Книга \"%1\" успешно удалена из базы данных").arg(title));
            statusBar()->showMessage(QString("Книга \"%1\" успешно удалена").arg(title), 3000);

            // Удаляем книгу из дерева
            QStandardItem *parent = item->parent();
            if (parent) {
                parent->removeRow(item->row());

                // Если у автора не осталось книг, удаляем и автора
                if (parent->rowCount() == 0 && parent->parent() == nullptr) {
                    treeModel->removeRow(parent->row());
                }

                // Обновляем статистику
                if (ui->chkLoadAll->isChecked()) {
                    loadStatistics();
                } else {
                    // Находим текущую выбранную букву
                    for (QAbstractButton *button : letterButtonGroup->buttons()) {
                        if (button->isChecked()) {
                            updateSelectionStatistics(button->text());
                            break;
                        }
                    }
                }
            }

            // Очищаем детальную информацию
            clearBookDetails();
        }
    }
}

bool MainWindow::deleteBook(int bookId)
{
    if (!isDatabaseOpen()) return false;

    QSqlQuery query;
    query.prepare("DELETE FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (!query.exec()) {
        showError("Ошибка при удалении книги: " + query.lastError().text());
        return false;
    }

    // qDebug() << "Book deleted successfully, ID:" << bookId;
    return true;
}

void MainWindow::showInfo(const QString &message)
{
    QMessageBox::information(this, "Информация", message);
}

void MainWindow::initGenreMap()
{
    genreMap["adv_animal"] = "Природа и животные";
    genreMap["adv_geo"] = "Путешествия и география";
    genreMap["adv_history"] = "История";
    genreMap["adv_indian"] = "Вестерн";
    genreMap["adv_maritime"] = "Море";
    genreMap["adv_modern"] = "Приключения в современном мире";
    genreMap["adv_story"] = "Авантюрный роман";
    genreMap["adv_western"] = "Вестерн";
    genreMap["adventure"] = "Приключения";
    genreMap["child_adv"] = "Приключения для детей и подростков";
    genreMap["tale_chivalry"] = "Рыцарский роман";
    genreMap["antique"] = "Старинное";
    genreMap["antique_ant"] = "Античность";
    genreMap["antique_east"] = "Древневосточная литература";
    genreMap["antique_european"] = "Старая европейская литература";
    genreMap["antique_russian"] = "Древнерусская литература";
    genreMap["architecture_book"] = "Архитектура";
    genreMap["art_criticism"] = "Искусствоведение";
    genreMap["art_world_culture"] = "Мировая художественная культура";
    genreMap["cine"] = "Кино";
    genreMap["cinema_theatre"] = " theatre";
    genreMap["design"] = "Дизайн";
    genreMap["music"] = "Музыка";
    genreMap["music_dancing"] = " dancing";
    genreMap["nonf_criticism"] = "Критика";
    genreMap["notes"] = "Партитуры";
    genreMap["painting"] = "Живопись";
    genreMap["sci_culture"] = "Культура";
    genreMap["theatre"] = "Театр";
    genreMap["visual_arts"] = "Живопись";
    genreMap["child_classical"] = "Классическая детская литература";
    genreMap["child_det"] = "Детектив";
    genreMap["child_education"] = "Образовательная литература";
    genreMap["child_prose"] = "Детская проза";
    genreMap["child_sf"] = "Детская научная фантастика";
    genreMap["child_tale"] = "Сказки";
    genreMap["child_tale_rus"] = "Русские сказки";
    genreMap["child_verse"] = "Стихи";
    genreMap["children"] = "Детская литература";
    genreMap["foreign_children"] = "Детские книги";
    genreMap["prose_game"] = "Игры";
    genreMap["comp_db"] = "Базы данных";
    genreMap["comp_hard"] = "Компьютеры";
    genreMap["comp_osnet"] = "Операционные системы";
    genreMap["comp_programming"] = "Программирование";
    genreMap["comp_soft"] = "Программы";
    genreMap["comp_www"] = "Интернет";
    genreMap["computers"] = "Компьютеры";
    genreMap["tbg_computers"] = "Учебные пособия";
    genreMap["det_action"] = "Боевик";
    genreMap["det_classic"] = "Классический";
    genreMap["det_crime"] = "Криминал";
    genreMap["det_espionage"] = "Шпионаж";
    genreMap["det_hard"] = "Крутой";
    genreMap["det_history"] = "Исторический";
    genreMap["det_irony"] = "Иронический";
    genreMap["det_maniac"] = "Про маньяков";
    genreMap["det_police"] = "Полицейский";
    genreMap["det_political"] = "Политический";
    genreMap["det_su"] = "Советский детектив";
    genreMap["detective"] = "Детектив";
    genreMap["thriller"] = "Триллер";
    genreMap["comedy"] = "Комедия";
    genreMap["drama"] = "Драма";
    genreMap["drama_antique"] = "Античная драма";
    genreMap["dramaturgy"] = "Драматургия";
    genreMap["foreign_dramaturgy"] = "Драматургия";
    genreMap["screenplays"] = "Сценарий";
    genreMap["tragedy"] = "Трагедия";
    genreMap["vaudeville"] = "Мистерия";
    genreMap["accounting"] = "Экономика";
    genreMap["banking"] = "Банкинг";
    genreMap["economics"] = "Экономика";
    genreMap["economics_ref"] = "Деловая литература";
    genreMap["global_economy"] = "Глобальная экономика";
    genreMap["marketing"] = "Маркетинг";
    genreMap["org_behavior"] = "Организация";
    genreMap["personal_finance"] = "Личные финансы";
    genreMap["popular_business"] = "Бизнес";
    genreMap["real_estate"] = "Недвижимость";
    genreMap["small_business"] = "Малый бизнес";
    genreMap["stock"] = "Биржа";
    genreMap["auto_business"] = "Автодело";
    genreMap["equ_history"] = "История техники";
    genreMap["military_weapon"] = "Военная техника и вооружение";
    genreMap["sci_build"] = "Строительство и сопромат";
    genreMap["sci_metal"] = "Металлургия";
    genreMap["sci_radio"] = "Радиоэлектроника";
    genreMap["sci_tech"] = "Техника";
    genreMap["sci_transport"] = "Транспорт и авиация";
    genreMap["city_fantasy"] = "Городское фэнтези";
    genreMap["dragon_fantasy"] = "Драконы";
    genreMap["fairy_fantasy"] = "Мифологическое фэнтези";
    genreMap["fantasy_fight"] = "Битвы";
    genreMap["historical_fantasy"] = "Историческое фэнтези";
    genreMap["modern_tale"] = "Современная сказка";
    genreMap["russian_fantasy"] = "Русское фэнтези";
    genreMap["sf_fantasy"] = "Фэнтези";
    genreMap["sf_fantasy_city"] = "Городское фэнтези";
    genreMap["sf_mystic"] = "Мистика";
    genreMap["sf_stimpank"] = "Стимпанк";
    genreMap["sf_technofantasy"] = "Технофэнтези";
    genreMap["antique_myths"] = "Мифы";
    genreMap["child_folklore"] = "Детский фольклор";
    genreMap["epic"] = "Былины";
    genreMap["folk_songs"] = "Народные песни";
    genreMap["folk_tale"] = "Народные сказки";
    genreMap["folklore"] = "Фольклор";
    genreMap["limerick"] = "Частушки";
    genreMap["proverbs"] = "Пословицы";
    genreMap["foreign_action"] = "Боевик";
    genreMap["foreign_adventure"] = "Приключения";
    genreMap["foreign_business"] = "Бизнес";
    genreMap["foreign_comp"] = "Компьютеры";
    genreMap["foreign_contemporary"] = "Современное";
    genreMap["foreign_contemporary_lit"] = "Современная литература";
    genreMap["foreign_desc"] = "Описания";
    genreMap["foreign_detective"] = "Детектив";
    genreMap["foreign_edu"] = "Образование";
    genreMap["foreign_fantasy"] = "Фэнтези";
    genreMap["foreign_home"] = "Дом";
    genreMap["foreign_humor"] = "Юмор";
    genreMap["foreign_language"] = "Языкознание";
    genreMap["foreign_love"] = "Любовное";
    genreMap["foreign_novel"] = "Новеллы";
    genreMap["foreign_other"] = "Другое";
    genreMap["foreign_psychology"] = "Психология";
    genreMap["foreign_publicism"] = "Публицистика";
    genreMap["foreign_sf"] = "Научная фантастика";
    genreMap["geo_guides"] = "Справочники";
    genreMap["geography_book"] = "География";
    genreMap["family"] = "Семейные отношения";
    genreMap["home"] = "Дом";
    genreMap["home_collecting"] = "Коллекционирование";
    genreMap["home_cooking"] = "Кулинария";
    genreMap["home_crafts"] = "Увлечения";
    genreMap["home_diy"] = "Сделай сам";
    genreMap["home_entertain"] = "Развлечения";
    genreMap["home_garden"] = "Сад";
    genreMap["home_health"] = "Здоровье";
    genreMap["home_pets"] = "Домашние животные";
    genreMap["home_sex"] = "Секс";
    genreMap["home_sport"] = "Спорт";
    genreMap["sci_pedagogy"] = "Педагогика";
    genreMap["humor"] = "Юмор";
    genreMap["humor_anecdote"] = "Анекдоты";
    genreMap["humor_fantasy"] = "Фэнтези";
    genreMap["humor_prose"] = "Юмористическая проза";
    genreMap["humor_satire"] = "Сатира";
    genreMap["love"] = "Любовные романы";
    genreMap["love_contemporary"] = "Современные любовные романы";
    genreMap["love_detective"] = "Остросюжетные любовные романы";
    genreMap["love_erotica"] = "Эротика";
    genreMap["love_fantasy"] = "Любовное фэнтези";
    genreMap["love_hard"] = "Порно";
    genreMap["love_history"] = "Любовные исторические романы";
    genreMap["love_sf"] = "Любовно-фантастические романы";
    genreMap["love_short"] = "Короткое";
    genreMap["military_special"] = "Военное дело";
    genreMap["nonf_biography"] = "Биографии и Мемуары";
    genreMap["nonf_military"] = "Военная документалистика и аналитика";
    genreMap["nonf_publicism"] = "Публицистика";
    genreMap["nonfiction"] = "Художественная литература";
    genreMap["travel_notes"] = "Путевые заметки";
    genreMap["aphorism_quote"] = "Афоризмы";
    genreMap["auto_regulations"] = "Автомобили";
    genreMap["beginning_authors"] = "Начинающие авторы";
    genreMap["comics"] = "Комиксы";
    genreMap["essays"] = "Эссе";
    genreMap["fanfiction"] = "Фанфик";
    genreMap["industries"] = "Промышленность";
    genreMap["job_hunting"] = "Поиск работы";
    genreMap["magician_book"] = "Магия";
    genreMap["management"] = "Менеджмент";
    genreMap["narrative"] = "Повествовательное";
    genreMap["network_literature"] = "Самиздат";
    genreMap["newspapers"] = "Газеты";
    genreMap["other"] = "Неотсортированное";
    genreMap["paper_work"] = "Бумажная работа";
    genreMap["pedagogy_book"] = "Педагогика";
    genreMap["periodic"] = "Периодические издания";
    genreMap["russian_contemporary"] = "Современная российская литература";
    genreMap["short_story"] = "Короткие истории";
    genreMap["sketch"] = "Скетч";
    genreMap["unfinished"] = "Незавершенное";
    genreMap["unrecognised"] = "Неизвестный";
    genreMap["upbringing_book"] = "Воспитание";
    genreMap["vampire_book"] = "Вампиры";
    genreMap["foreign_poetry"] = "Поэзия";
    genreMap["humor_verse"] = "Стихи";
    genreMap["lyrics"] = "Лирика";
    genreMap["palindromes"] = "Визуальная и экспериментальная поэзия";
    genreMap["poem"] = "Поэма";
    genreMap["poetry"] = "Поэзия";
    genreMap["poetry_classical"] = "Классическая поэзия";
    genreMap["poetry_east"] = "Поэзия Востока";
    genreMap["poetry_for_classical"] = "Классическая зарубежная поэзия";
    genreMap["poetry_for_modern"] = "Современная зарубежная поэзия";
    genreMap["poetry_modern"] = "Современная поэзия";
    genreMap["poetry_rus_classical"] = "Классическая русская поэзия";
    genreMap["poetry_rus_modern"] = "Современная русская поэзия";
    genreMap["song_poetry"] = "Песенная поэзия";
    genreMap["aphorisms"] = "Афоризмы";
    genreMap["epistolary_fiction"] = "Эпистолярная проза";
    genreMap["foreign_antique"] = "Средневековая классическая проза";
    genreMap["foreign_prose"] = "Зарубежная классическая проза";
    genreMap["gothic_novel"] = "Готический роман";
    genreMap["great_story"] = "Роман";
    genreMap["literature_18"] = "Классическая проза XVII-XVIII веков";
    genreMap["literature_19"] = "Классическая проза ХIX века";
    genreMap["literature_20"] = "Классическая проза ХX века";
    genreMap["prose"] = "Проза";
    genreMap["prose_abs"] = "Фантасмагория";
    genreMap["prose_classic"] = "Классика";
    genreMap["prose_contemporary"] = "Современная проза";
    genreMap["prose_counter"] = "Контр-проза";
    genreMap["prose_history"] = "История";
    genreMap["prose_magic"] = "Магический реализм";
    genreMap["prose_military"] = "Военная проза";
    genreMap["prose_neformatny"] = "Экспериментальная";
    genreMap["prose_rus_classic"] = "Русская классика";
    genreMap["prose_su_classics"] = "Советская классика";
    genreMap["story"] = "Малые литературные формы";
    genreMap["psy_alassic"] = "Психология";
    genreMap["psy_childs"] = "Дети";
    genreMap["psy_generic"] = "Общее";
    genreMap["psy_personal"] = "Личное";
    genreMap["psy_sex_and_family"] = "Секс и семья";
    genreMap["psy_social"] = "Социальное";
    genreMap["psy_theraphy"] = "Терапия";
    genreMap["ref_dict"] = "Словари";
    genreMap["ref_encyc"] = "Энциклопедии";
    genreMap["ref_guide"] = "Инструкции";
    genreMap["ref_ref"] = "Справочники";
    genreMap["reference"] = "Справочники";
    genreMap["astrology"] = "Астрология и хиромантия";
    genreMap["foreign_religion"] = "Иностранная религиозная литература";
    genreMap["religion"] = "Религия";
    genreMap["religion_budda"] = "Буддизм";
    genreMap["religion_catholicism"] = "Католицизм";
    genreMap["religion_christianity"] = "Христианство";
    genreMap["religion_esoterics"] = "Эзотерика";
    genreMap["religion_hinduism"] = "Индуизм";
    genreMap["religion_islam"] = "Islam";
    genreMap["religion_judaism"] = "Иудаизм";
    genreMap["religion_orthodoxy"] = "Православие";
    genreMap["religion_paganism"] = "Язычество";
    genreMap["religion_protestantism"] = "Протестантизм";
    genreMap["religion_rel"] = "Отношения";
    genreMap["religion_self"] = "Самопознание";
    genreMap["military_history"] = "Военная история";
    genreMap["sci_biology"] = "Биология";
    genreMap["sci_botany"] = "Ботаника";
    genreMap["sci_chem"] = "Химия";
    genreMap["sci_cosmos"] = "Астрономия и Космос";
    genreMap["sci_ecology"] = "Экология";
    genreMap["sci_economy"] = "Экономика";
    genreMap["sci_geo"] = "Геология и география";
    genreMap["sci_history"] = "История";
    genreMap["sci_juris"] = "Юриспруденция";
    genreMap["sci_linguistic"] = "Лингвистика";
    genreMap["sci_math"] = "Математика";
    genreMap["sci_medicine"] = "Медицина";
    genreMap["sci_medicine_alternative"] = "Альтернативная (не)медицина";
    genreMap["sci_oriental"] = "Востоковедение";
    genreMap["sci_philology"] = "Литературоведение";
    genreMap["sci_philosophy"] = "Философия";
    genreMap["sci_phys"] = "Физика";
    genreMap["sci_politics"] = "Политика";
    genreMap["sci_popular"] = "Научно-популярная литература";
    genreMap["sci_psychology"] = "Психология и психотерапия";
    genreMap["sci_religion"] = "Религия";
    genreMap["sci_social_studies"] = "Обществознание";
    genreMap["sci_state"] = "Государство и право";
    genreMap["sci_theories"] = "Альтернативные (не)науки и (не)научные теории";
    genreMap["sci_veterinary"] = "Ветеринария";
    genreMap["sci_zoo"] = "Зоология";
    genreMap["science"] = "Наука";
    genreMap["sociology_book"] = "Социология";
    genreMap["hronoopera"] = "Хроноопера";
    genreMap["popadancy"] = "Уе… попаданцы(1)";
    genreMap["popadanec"] = "Уе… попаданцы(2)";
    genreMap["sf"] = "Научная фантастика";
    genreMap["sf_action"] = "Боевая фантастика";
    genreMap["sf_cyberpunk"] = "Киберпанк";
    genreMap["sf_detective"] = "Детектив";
    genreMap["sf_epic"] = "Эпическая фантастика";
    genreMap["sf_etc"] = "Фантастика";
    genreMap["sf_heroic"] = "Героическое";
    genreMap["sf_history"] = "История";
    genreMap["sf_horror"] = "Ужас";
    genreMap["sf_humor"] = "Юмор";
    genreMap["sf_litrpg"] = "ЛитРПГ";
    genreMap["sf_postapocalyptic"] = "Постапокалипсис";
    genreMap["sf_social"] = "Социально-психологическая фантастика";
    genreMap["sf_space"] = "Космос";
    genreMap["sci_textbook"] = "Учебники и пособия";
    genreMap["tbg_higher"] = "Учебники и пособия ВУЗов";
    genreMap["tbg_school"] = "Школьные учебники и пособия";
    genreMap["tbg_secondary"] = "Учебники и пособия для среднего и специального образования";

    genreMap["vers_libre"] = "Верлибры";
    genreMap["trade"] = "Торговля";
    genreMap["sci_crib"] = "Шпаргалки";
    genreMap["sci_biophys"] = "Биофизика";
    genreMap["sci_biochem"] = "Биохимия";
    genreMap["scenarios"] = "Сценарии";
    genreMap["roman"] = "Романы";
    genreMap["riddles"] = "Фольклор";
    genreMap["military_arts"] = "Боевые исскуства";
    genreMap["military_all"] = "Тактика и стратеги";
    genreMap["military"] = "Организация и тактика боевых действий";
    genreMap["Islam"] = "Ислам религия";
    genreMap["islam"] = "Ислам религия";
    genreMap["in_verse"] = "Трагедии";
    genreMap["fantasy_alt_hist"] = "Фентези альтернативная история";
    genreMap["fable"] = "Байки";
    genreMap["Extravaganza"] = "Феерия";
    genreMap["essay"] = "Эссе";
    genreMap["dissident"] = "Диссидентская литература";
    genreMap["det_cozy"] = "Дамский роман";
    genreMap["det_all"] = "Дамский роман";
    genreMap["comp_all"] = "Компьютерная литература";
    genreMap["sagas"] = "Саги";
    genreMap["palmistry"] = "Хиромантия";
    genreMap["mystery"] = "Тайны";
    genreMap["Ref_all"] = "Всё о бо всём";
    genreMap["Sci_business"] = "О бизнесе";
    genreMap["Adv_all"] = "Детская литература";
    genreMap["Nonf_all"] = "О бо всём";

}

// Методы для кэширования
QString MainWindow::getCoverCacheKey(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    return QString("cover_%1_%2_%3").arg(filePath).arg(archivePath).arg(internalPath);
}

QString MainWindow::getDescriptionCacheKey(const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    return QString("desc_%1_%2_%3").arg(filePath).arg(archivePath).arg(internalPath);
}



QString MainWindow::getReadableGenre(const QString &genreCode)
{
    if (genreCode.isEmpty()) {
        return "Без жанра";
    }

    // Если жанр уже в читаемом формате, возвращаем как есть
    if (!genreCode.contains('_') && !genreCode.contains(' ')) {
        // Это вероятно код, проверяем в мапе
        if (    genreMap.contains(genreCode)) {
            return     genreMap[genreCode];
        }
    }

    // Проверяем точное соответствие
    if (    genreMap.contains(genreCode)) {
        return     genreMap[genreCode];
    }

    // Если не нашли, пытаемся найти частичное соответствие
    for (auto it =     genreMap.begin(); it !=     genreMap.end(); ++it) {
        if (genreCode.contains(it.key(), Qt::CaseInsensitive)) {
            return it.value();
        }
    }

    // Если ничего не нашли, возвращаем оригинальное значение с заглавной буквы
    QString result = genreCode;
    if (!result.isEmpty()) {
        result[0] = result[0].toUpper();
    }
    return result;
}

void MainWindow::initScannerDialog()
{
    if (!m_scannerDialog && db.isOpen()) {
        m_scannerDialog = new ScannerDialog(db, this);
        connect(m_scannerDialog, &ScannerDialog::booksUpdated, this, &MainWindow::refreshTreeView);
        connect(m_scannerDialog, &ScannerDialog::booksUpdated, this, &MainWindow::loadStatistics);
        qDebug() << "Scanner dialog initialized successfully";
    }
}

void MainWindow::on_actionScan_triggered()
{
    if (!isDatabaseOpen()) {
        QMessageBox::warning(this, "Ошибка", "База данных не открыта");
        return;
    }

    // Гарантируем, что диалог инициализирован
    if (!m_scannerDialog) {
        initScannerDialog();
    }

    if (!m_scannerDialog) {
        QMessageBox::critical(this, "Ошибка", "Не удалось инициализировать диалог сканера");
        return;
    }

    m_scannerDialog->exec();
    // Данные обновятся автоматически через сигнал booksUpdated
}

// Также добавляем инициализацию в метод переподключения:
void MainWindow::on_actionReconnect_triggered()
{
    // Закрываем текущее соединение
    if (db.isOpen()) {
        db.close();
    }

    // Удаляем старый диалог сканера
    if (m_scannerDialog) {
        delete m_scannerDialog;
        m_scannerDialog = nullptr;
    }

    // Переоткрываем базу данных
    openDatabase();
}











// Слот для двойного клика
void MainWindow::on_treeView_doubleClicked(const QModelIndex &index)
{
    if (!index.isValid()) return;

    QStandardItem *item = treeModel->itemFromIndex(index);
    if (item && item->parent()) { // Клик на книге (не на авторе/серии/жанре)
        int bookId = item->data(Qt::UserRole).toInt();
        if (bookId > 0) {
            openBook(bookId);
        }
    }
}

// Метод для открытия книги
void MainWindow::openBook(int bookId)
{
    if (!isDatabaseOpen()) return;

    QSqlQuery query;
    query.prepare("SELECT title, file_path, archive_path, archive_internal_path, file_type FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (!query.exec() || !query.next()) {
        showError("Не удалось получить информацию о книге");
        return;
    }

    QString title = query.value("title").toString();
    QString filePath = query.value("file_path").toString();
    QString archivePath = query.value("archive_path").toString();
    QString internalPath = query.value("archive_internal_path").toString();
    QString fileType = query.value("file_type").toString().toLower();

    // Проверяем поддерживаемые форматы для чтения
    if (fileType != "fb2" && fileType != "txt") {
        showInfo("Чтение поддерживается только для FB2 и TXT файлов");
        return;
    }

    QApplication::setOverrideCursor(Qt::WaitCursor);
    statusLabel->setText("Загрузка книги...");

    try {
        openBookFile(filePath, archivePath, internalPath, title);
    } catch (const std::exception &e) {
        showError(QString("Ошибка при открытии книги: %1").arg(e.what()));
    }

    QApplication::restoreOverrideCursor();
    statusLabel->setText("Готово");
}

void MainWindow::openBookFile(const QString& filePath, const QString& archivePath, const QString& internalPath, const QString& title)
{
    QByteArray content;

    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        // Книга в архиве
        content = extractFileFromArchive(archivePath, internalPath);
    } else {
        // Отдельный файл
        QFile file(filePath);
        if (file.open(QIODevice::ReadOnly)) {
            content = file.readAll();
            file.close();
        }
    }

    if (content.isEmpty()) {
        showError("Не удалось загрузить содержимое книги");
        return;
    }

    // Проверяем формат файла
    QString contentStr = QString::fromUtf8(content);
    if (contentStr.contains("<?xml") && contentStr.contains("FictionBook")) {
        // Это FB2 файл - открываем в красивом ридере
        openFB2Reader(content, title);
    } else {
        // Простой текстовый файл - открываем в простом ридере
        showSimpleBookReader(title, content);
    }
}

// Добавим метод для открытия FB2 ридера:
void MainWindow::openFB2Reader(const QByteArray &content, const QString &title)
{
    if (!fb2Reader) {
        fb2Reader = new FB2Reader(this);
    }

    fb2Reader->loadFB2Content(content, title);
    fb2Reader->show();
    fb2Reader->raise();
    fb2Reader->activateWindow();
}


// Метод для показа простого окна чтения
void MainWindow::showSimpleBookReader(const QString& title, const QByteArray& content)
{
    // Создаем диалоговое окно для чтения
    QDialog *readerDialog = new QDialog(this);
    readerDialog->setWindowTitle(title + " - Просмотр");
    readerDialog->setMinimumSize(800, 600);

    QVBoxLayout *layout = new QVBoxLayout(readerDialog);

    // Добавляем текстовое поле для содержимого
    QTextEdit *textEdit = new QTextEdit(readerDialog);

    // Пробуем разные кодировки
    QString textContent = QString::fromUtf8(content);
    if (textContent.contains(QChar::ReplacementCharacter)) {
        textContent = QString::fromLocal8Bit(content);
    }

    // Если это FB2, парсим его
    if (textContent.contains("<?xml") && textContent.contains("FictionBook")) {
        textContent = parseFB2Content(textContent);
    }

    textEdit->setPlainText(textContent);
    textEdit->setReadOnly(true);
    textEdit->setWordWrapMode(QTextOption::WordWrap);
    textEdit->setFont(QFont("Arial", 11));

    // Добавляем кнопки
    QHBoxLayout *buttonLayout = new QHBoxLayout();
    QPushButton *btnClose = new QPushButton("Закрыть", readerDialog);
    QPushButton *btnSave = new QPushButton("Сохранить", readerDialog);

    connect(btnClose, &QPushButton::clicked, readerDialog, &QDialog::close);
    connect(btnSave, &QPushButton::clicked, [this, textContent, title]() {
        QString fileName = QFileDialog::getSaveFileName(
            this,
            "Сохранить книгу",
            QDir::homePath() + "/" + title + ".txt",
            "Текстовые файлы (*.txt)"
        );

        if (!fileName.isEmpty()) {
            QFile file(fileName);
            if (file.open(QIODevice::WriteOnly | QIODevice::Text)) {
                QTextStream stream(&file);
                stream << textContent;
                file.close();
                showInfo("Книга успешно сохранена: " + fileName);
            } else {
                showError("Не удалось сохранить файл");
            }
        }
    });

    buttonLayout->addWidget(btnSave);
    buttonLayout->addStretch();
    buttonLayout->addWidget(btnClose);

    layout->addWidget(textEdit);
    layout->addLayout(buttonLayout);

    readerDialog->setLayout(layout);
    readerDialog->exec();

    delete readerDialog;
}

// Метод для парсинга FB2 содержимого
QString MainWindow::parseFB2Content(const QString& content)
{
    QString result;
    QXmlStreamReader xml(content);
    bool inBody = false;
    bool inTitle = false;
    bool inSection = false;
    int depth = 0;

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            QString elementName = xml.name().toString();

            if (elementName == "body") {
                inBody = true;
                depth++;
            } else if (elementName == "title" && inBody) {
                inTitle = true;
                depth++;
                if (!result.isEmpty()) {
                    result += "\n\n";
                }
            } else if (elementName == "section") {
                inSection = true;
                depth++;
                if (!result.isEmpty() && !result.endsWith("\n\n")) {
                    result += "\n\n";
                }
            } else if ((elementName == "p" || elementName == "poem" || elementName == "subtitle") &&
                      (inBody || inTitle || inSection)) {
                if (!result.isEmpty() && !result.endsWith('\n')) {
                    result += "\n\n";
                }
                depth++;
            } else if (elementName == "emphasis" && (inBody || inTitle || inSection)) {
                result += " *";
                depth++;
            }

        } else if (token == QXmlStreamReader::EndElement) {
            QString elementName = xml.name().toString();

            if (elementName == "body") {
                inBody = false;
                depth--;
            } else if (elementName == "title" && inTitle) {
                inTitle = false;
                depth--;
            } else if (elementName == "section") {
                inSection = false;
                depth--;
            } else if ((elementName == "p" || elementName == "poem" || elementName == "subtitle") &&
                      depth > 0) {
                depth--;
            } else if (elementName == "emphasis" && depth > 0) {
                result += "* ";
                depth--;
            }

        } else if (token == QXmlStreamReader::Characters &&
                  (inBody || inTitle || inSection) &&
                  depth > 0) {
            QString text = xml.text().toString().trimmed();
            if (!text.isEmpty()) {
                // Заменяем множественные пробелы на одинарные
                text = text.simplified();
                result += text + " ";
            }
        }
    }

    if (xml.hasError()) {
        qDebug() << "XML parsing error:" << xml.errorString();
        // Если парсинг не удался, возвращаем исходный текст
        return content;
    }

    // Очищаем результат от лишних пробелов
    result = result.trimmed();

    // Заменяем множественные переводы строк на двойные
    result.replace(QRegularExpression("\n{3,}"), "\n\n");

    return result.isEmpty() ? "Не удалось извлечь текст из FB2 файла" : result;
}

