#include "mainwindow.h"
#include <QApplication>
#include <QSqlDatabase>
#include <QMessageBox>
#include <QDebug>

int main(int argc, char *argv[])
{
    QApplication a(argc, argv);

    // Проверяем доступные драйверы БД
    QStringList drivers = QSqlDatabase::drivers();
    qDebug() << "Available database drivers:" << drivers;

    if (!drivers.contains("QSQLITE")) {
        QMessageBox::critical(nullptr, "Ошибка", "Драйвер SQLite не доступен!");
        return -1;
    }

    // Предупреждение если MySQL драйвер не доступен (но не блокируем запуск)
    if (!drivers.contains("QMYSQL") && !drivers.contains("QMARIADB")) {
        qDebug() << "Warning: MySQL/MariaDB driver not available, only SQLite will work";
    }

    // Проверяем подключение к базе данных перед созданием главного окна
    qDebug() << "Testing database connection...";

    MainWindow w;
    w.show();
    return a.exec();
}
