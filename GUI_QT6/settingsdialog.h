#ifndef SETTINGSDIALOG_H
#define SETTINGSDIALOG_H

#include <QDialog>
#include <QSettings>

namespace Ui {
class SettingsDialog;
}

class SettingsDialog : public QDialog
{
    Q_OBJECT

public:
    explicit SettingsDialog(QWidget *parent = nullptr);
    ~SettingsDialog();

    QString getDatabaseType() const;
    QString getSqlitePath() const;
    QString getMysqlHost() const;
    int getMysqlPort() const;
    QString getMysqlUser() const;
    QString getMysqlPassword() const;
    QString getMysqlDatabase() const;

private slots:
    void onDatabaseTypeChanged();
    void onBrowseSqliteClicked();
    void onTestConnectionClicked();
    void accept() override;

private:
    Ui::SettingsDialog *ui;
    QSettings settings;

    void loadSettings();
    void saveSettings();
    bool testConnection();
};

#endif // SETTINGSDIALOG_H
