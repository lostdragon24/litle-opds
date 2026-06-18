<?php
// lib/Logger.php

class Logger {
    private static $instance = null;
    private $logFile;
    private $logLevels = [
        'DEBUG' => 7,
        'INFO' => 6,
        'NOTICE' => 5,
        'WARNING' => 4,
        'ERROR' => 3,
        'CRITICAL' => 2,
        'ALERT' => 1,
        'EMERGENCY' => 0
    ];

    public static function getInstance($logFile = null) {
        if (self::$instance === null) {
            self::$instance = new self($logFile);
        }
        return self::$instance;
    }

    private function __construct($logFile = null) {
        $this->logFile = $logFile ?: Config::getCacheDir() . '/system.log';

        // Создаем директорию если нужно
        $dir = dirname($this->logFile);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
    }

        public static function setLogFile($path)
    {
        $instance = self::getInstance();
        $instance->logFile = $path;

        $logDir = dirname($path);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }


    /**
     * Основная функция логирования
     */
    public function log($message, $level = 'INFO', $context = []) {
        $timestamp = $this->formatTimestamp();
        $levelTag = $this->formatLevel($level);
        $processInfo = $this->getProcessInfo();
        $clientInfo = $this->getClientInfo();

        // Форматируем сообщение
        $formattedMessage = $this->formatMessage($message, $context);

        $logLine = sprintf(
            "[%s] [php:%s] [pid %d:tid %d] [client %s] %s\n",
            $timestamp,
            $levelTag,
            getmypid(),
            $this->getThreadId(),
            $_SERVER['REMOTE_ADDR'] . ':' . ($_SERVER['REMOTE_PORT'] ?? '0'),
            $formattedMessage
        );

        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Ротация логов при достижении 10MB
        if (file_exists($this->logFile) && filesize($this->logFile) > 10 * 1024 * 1024) {
            $this->rotateLog();
        }
    }

    /**
     * Форматирует timestamp как в Apache
     */
    private function formatTimestamp() {
        $timestamp = time();
        $microseconds = sprintf("%06d", (microtime(true) - floor(microtime(true))) * 1000000);
        return date('D M d H:i:s.', $timestamp) . $microseconds . ' ' . date('Y', $timestamp);
    }

    /**
     * Форматирует уровень логирования
     */
    private function formatLevel($level) {
        $level = strtoupper($level);
        $map = [
            'EMERGENCY' => 'emergency',
            'ALERT' => 'alert',
            'CRITICAL' => 'critical',
            'ERROR' => 'error',
            'WARNING' => 'warning',
            'NOTICE' => 'notice',
            'INFO' => 'info',
            'DEBUG' => 'debug'
        ];
        return $map[$level] ?? strtolower($level);
    }

    /**
     * Получает информацию о процессе
     */
    private function getProcessInfo() {
        return [
            'pid' => getmypid(),
            'tid' => $this->getThreadId()
        ];
    }

    /**
     * Получает ID потока (если доступно)
     */
    private function getThreadId() {
        if (function_exists('posix_getpid')) {
            return posix_getpid(); // В PHP нет прямого доступа к TID
        }
        return getmypid();
    }

    /**
     * Получает информацию о клиенте
     */
    private function getClientInfo() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';
        $port = $_SERVER['REMOTE_PORT'] ?? '0';
        return $ip . ':' . $port;
    }

    /**
     * Форматирует сообщение
     */
    private function formatMessage($message, $context) {
        if (empty($context)) {
            return $message;
        }

        $parts = [$message];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $parts[] = "$key: $value";
            } elseif (is_array($value)) {
                $parts[] = "$key: " . json_encode($value);
            } elseif (is_bool($value)) {
                $parts[] = "$key: " . ($value ? 'true' : 'false');
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Ротация лог-файла
     */
    private function rotateLog() {
        $backupFile = $this->logFile . '.' . date('Ymd_His');
        rename($this->logFile, $backupFile);

        // Оставляем только последние 10 бэкапов
        $backups = glob($this->logFile . '.*');
        if (count($backups) > 10) {
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            $toDelete = array_slice($backups, 0, count($backups) - 10);
            foreach ($toDelete as $file) {
                unlink($file);
            }
        }
    }

    // Magic методы для удобства
    public function emergency($message, $context = []) { $this->log($message, 'EMERGENCY', $context); }
    public function alert($message, $context = []) { $this->log($message, 'ALERT', $context); }
    public function critical($message, $context = []) { $this->log($message, 'CRITICAL', $context); }
    public function error($message, $context = []) { $this->log($message, 'ERROR', $context); }
    public function warning($message, $context = []) { $this->log($message, 'WARNING', $context); }
    public function notice($message, $context = []) { $this->log($message, 'NOTICE', $context); }
    public function info($message, $context = []) { $this->log($message, 'INFO', $context); }
    public function debug($message, $context = []) { $this->log($message, 'DEBUG', $context); }
}

// Глобальная функция для обратной совместимости
function my_log($message, $level = 'INFO', $context = []) {
    $logger = Logger::getInstance();
    $logger->log($message, $level, $context);
}
