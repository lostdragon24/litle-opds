<?php
// lib/Logger.php - упрощенная версия

class Logger
{
    private static $instance = null;
    private $logFile;
    private $logLevel;
    private $maxSize = 10485760; // 10 MB
    private $maxFiles = 10;
    private $buffer = [];
    private $bufferSize = 0;
    private $inTransaction = false;

    // Уровни логирования
    const EMERGENCY = 0;
    const ALERT     = 1;
    const CRITICAL  = 2;
    const ERROR     = 3;
    const WARNING   = 4;
    const NOTICE    = 5;
    const INFO      = 6;
    const DEBUG     = 7;

    private $levelNames = [
        0 => 'EMERGENCY',
        1 => 'ALERT',
        2 => 'CRITICAL',
        3 => 'ERROR',
        4 => 'WARNING',
        5 => 'NOTICE',
        6 => 'INFO',
        7 => 'DEBUG'
    ];

    private function __construct()
    {
        $this->logFile = Config::getCacheDir() . '/system.log';
        $this->logLevel = defined('LOG_LEVEL') ? LOG_LEVEL : self::INFO;

        // Создаем директорию если нужно
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Регистрируем shutdown функцию для fatal ошибок
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Обработчик завершения скрипта (ловим fatal ошибки)
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->critical($error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);
        }

        // Сбрасываем буфер при завершении
        if ($this->bufferSize > 0) {
            $this->flush();
        }
    }

    /**
     * Основной метод логирования
     */
    public function log($level, $message, array $context = [])
    {
        if ($level > $this->logLevel) {
            return;
        }

        $logEntry = $this->formatLogEntry($level, $message, $context);

        // Если в транзакции - буферизируем
        if ($this->inTransaction) {
            $this->buffer[] = $logEntry;
            $this->bufferSize++;
            return;
        }

        $this->writeLog($logEntry);
    }

    /**
     * Форматирование записи лога
     */
    private function formatLogEntry($level, $message, array $context = [])
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelName = $this->levelNames[$level] ?? 'UNKNOWN';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $pid = getmypid();

        // Подстановка контекста в сообщение
        if (!empty($context)) {
            $message = $this->interpolateContext($message, $context);
        }

        // Форматируем JSON для структурированного логирования
        $logData = [
            'timestamp' => $timestamp,
            'level' => $levelName,
            'level_code' => $level,
            'message' => $message,
            'ip' => $ip,
            'pid' => $pid,
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'memory' => memory_get_usage(true),
            'context' => $context
        ];

        // Добавляем информацию о запросе если есть
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $logData['referer'] = $_SERVER['HTTP_REFERER'];
        }

        // Для CLI добавляем аргументы
        if (php_sapi_name() === 'cli' && isset($_SERVER['argv'])) {
            $logData['argv'] = $_SERVER['argv'];
        }

        return json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Подстановка контекста в сообщение
     */
    private function interpolateContext($message, array $context = [])
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Запись в лог-файл
     */
    private function writeLog($logEntry)
    {
        // Проверяем размер файла
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxSize) {
            $this->rotateLog();
        }

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Ротация лог-файлов
     */
    private function rotateLog()
    {
        for ($i = $this->maxFiles - 1; $i > 0; $i--) {
            $oldFile = $this->logFile . '.' . $i;
            $newFile = $this->logFile . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }

        if (file_exists($this->logFile)) {
            rename($this->logFile, $this->logFile . '.1');
        }
    }

    /**
     * Начать транзакцию логирования (буферизация)
     */
    public function beginTransaction()
    {
        $this->inTransaction = true;
    }

    /**
     * Завершить транзакцию и сбросить буфер
     */
    public function commit()
    {
        if ($this->inTransaction) {
            $this->flush();
            $this->inTransaction = false;
        }
    }

    /**
     * Отменить транзакцию и очистить буфер
     */
    public function rollback()
    {
        $this->buffer = [];
        $this->bufferSize = 0;
        $this->inTransaction = false;
    }

    /**
     * Сбросить буфер в файл
     */
    private function flush()
    {
        if (empty($this->buffer)) {
            return;
        }

        $content = implode('', $this->buffer);
        $this->writeLog($content);
        $this->buffer = [];
        $this->bufferSize = 0;
    }

    /**
     * Получить последние N строк лога
     */
    public function getLastLines($lines = 100, $level = null)
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $result = [];
        $handle = fopen($this->logFile, 'r');

        if (!$handle) {
            return [];
        }

        // Переходим в конец файла
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        $buffer = '';

        while ($pos >= 0 && count($result) < $lines) {
            // Читаем по 1KB назад
            $chunkSize = min(1024, $pos);
            $pos -= $chunkSize;
            fseek($handle, $pos);

            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;

            // Разбиваем на строки
            $linesArray = explode("\n", $buffer);

            // Последний элемент может быть неполным
            $buffer = array_shift($linesArray);

            foreach (array_reverse($linesArray) as $line) {
                if (empty(trim($line))) {
                    continue;
                }

                $logEntry = $this->parseLogLine($line);
                if ($level === null || $logEntry['level_code'] <= $level) {
                    array_unshift($result, $logEntry);
                    if (count($result) >= $lines) {
                        break 2;
                    }
                }
            }
        }

        fclose($handle);
        return $result;
    }

    /**
     * Парсинг строки лога
     */
    private function parseLogLine($line)
    {
        $data = json_decode($line, true);
        if ($data && isset($data['timestamp'])) {
            return $data;
        }

        // Fallback для старых логов
        if (preg_match('/\[(.*?)\]\s+\[(.*?)\]\s+(.*)/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => $matches[3],
                'ip' => 'unknown',
                'pid' => 0,
                'level_code' => self::INFO
            ];
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'UNKNOWN',
            'message' => $line,
            'ip' => 'unknown',
            'pid' => 0,
            'level_code' => self::INFO
        ];
    }

    /**
     * Очистить лог
     */
    public function clear()
    {
        if (file_exists($this->logFile)) {
            // Создаем бэкап перед очисткой
            $backupFile = $this->logFile . '.backup.' . date('Ymd_His');
            copy($this->logFile, $backupFile);

            // Очищаем
            file_put_contents($this->logFile, '');
            return true;
        }
        return false;
    }

    /**
     * Получить информацию о лог-файле
     */
    public function getInfo()
    {
        $info = [
            'file' => $this->logFile,
            'exists' => file_exists($this->logFile),
            'size' => 0,
            'size_formatted' => '0 B',
            'level' => $this->levelNames[$this->logLevel] ?? 'INFO'
        ];

        if ($info['exists']) {
            $info['size'] = filesize($this->logFile);
            $info['size_formatted'] = $this->formatBytes($info['size']);
            $info['modified'] = date('Y-m-d H:i:s', filemtime($this->logFile));
        }

        return $info;
    }

    /**
     * Форматирование байтов
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    // Magic методы для удобного вызова
    public function emergency($message, array $context = [])
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = [])
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->log(self::INFO, $message, $context);
    }

    public function debug($message, array $context = [])
    {
        $this->log(self::DEBUG, $message, $context);
    }
}

/**
 * Вспомогательные функции для глобального использования
 */
function log_emergency($message, array $context = [])
{
    Logger::getInstance()->emergency($message, $context);
}

function log_alert($message, array $context = [])
{
    Logger::getInstance()->alert($message, $context);
}

function log_critical($message, array $context = [])
{
    Logger::getInstance()->critical($message, $context);
}

function log_error($message, array $context = [])
{
    Logger::getInstance()->error($message, $context);
}

function log_warning($message, array $context = [])
{
    Logger::getInstance()->warning($message, $context);
}

function log_notice($message, array $context = [])
{
    Logger::getInstance()->notice($message, $context);
}

function log_info($message, array $context = [])
{
    Logger::getInstance()->info($message, $context);
}

function log_debug($message, array $context = [])
{
    Logger::getInstance()->debug($message, $context);
}
