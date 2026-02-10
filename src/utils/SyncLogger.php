<?php
/**
 * Sync Logger Utility
 * Logs all sync activities, API responses, and errors to a file
 */

namespace FAS\Utils;

class SyncLogger
{
    private static $logFile = null;
    private static $isEnabled = false;
    
    /**
     * Initialize the logger
     */
    public static function init($logFilePath = null)
    {
        if ($logFilePath === null) {
            $logFilePath = __DIR__ . '/../../log.txt';
        }
        
        self::$logFile = $logFilePath;
        self::$isEnabled = true;
        
        // Write minimal session start marker
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] ========== eBay Sync Session Started ==========\n";
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * Log a message to the file
     * NOTE: Only errors and warnings should be logged. Informational messages are ignored.
     */
    public static function log($message, $context = '')
    {
        // Intentionally do nothing - only errors and warnings should be logged
        return;
    }
    
    /**
     * Log an API request
     * NOTE: Requests are not logged to reduce log file size
     */
    public static function logRequest($url, $method = 'GET')
    {
        // Intentionally do nothing - only errors are logged
        return;
    }
    
    /**
     * Log an API response
     * NOTE: Responses are not logged to reduce log file size
     */
    public static function logResponse($response, $httpCode = null)
    {
        // Intentionally do nothing - only errors are logged
        return;
    }
    
    /**
     * Log a warning
     */
    public static function logWarning($message)
    {
        if (!self::$isEnabled || self::$logFile === null) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [WARNING] {$message}\n";
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * Log an error
     */
    public static function logError($message, $exception = null)
    {
        if (!self::$isEnabled || self::$logFile === null) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [ERROR] {$message}\n";
        
        if ($exception instanceof \Exception) {
            $logLine .= "[$timestamp] [ERROR] Exception: " . $exception->getMessage() . "\n";
            $logLine .= "[$timestamp] [ERROR] File: " . $exception->getFile() . " Line: " . $exception->getLine() . "\n";
            $logLine .= "[$timestamp] [ERROR] Stack trace:\n";
            $logLine .= $exception->getTraceAsString() . "\n";
        }
        
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * Log PHP errors and warnings
     */
    public static function logPhpError($errno, $errstr, $errfile, $errline)
    {
        if (!self::$isEnabled || self::$logFile === null) {
            return;
        }
        
        // Only log actual errors, not notices or deprecations
        if ($errno !== E_ERROR && $errno !== E_WARNING && $errno !== E_USER_ERROR && $errno !== E_USER_WARNING) {
            return;
        }
        
        $errorTypes = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
        ];
        
        $errorType = $errorTypes[$errno] ?? 'UNKNOWN';
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [PHP_$errorType] $errstr in $errfile on line $errline\n";
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * Finalize the logger
     */
    public static function finalize()
    {
        if (!self::$isEnabled || self::$logFile === null) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] ========== eBay Sync Session Ended ==========\n\n";
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }
}
