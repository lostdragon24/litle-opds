<?php
// lib/SessionInitializer.php

class SessionInitializer {
    private static $initialized = false;
    
    /**
     * Инициализирует сессию с правильным именем
     */
    public static function initialize() {
        if (self::$initialized) {
            return;
        }
        
        // Проверяем, не запущена ли сессия уже
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$initialized = true;
            return;
        }
        
        // Определяем имя сессии
        $isAdmin = self::isAdminRequest();
        $sessionName = $isAdmin ? 'ADMIN_SESSION' : 'USER_SESSION';
        
        // Устанавливаем имя
        session_name($sessionName);
        
        // Запускаем сессию
        session_start();
        
        self::$initialized = true;
        
        error_log("Session initialized with name: " . $sessionName);
    }
    
    /**
     * Определяет, является ли запрос административным
     */
    private static function isAdminRequest() {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        return strpos($scriptName, '/admin/') !== false || 
               strpos($referer, '/admin/') !== false;
    }
}