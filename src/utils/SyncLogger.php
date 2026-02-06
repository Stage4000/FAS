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
        
        // Write session start marker
        self::log("========================================");
        self::log("eBay Sync Session Started");
        self::log("Time: " . date('Y-m-d H:i:s'));
        self::log("========================================");
    }
    
    /**
     * Log a message to the file
     */
    public static function log($message, $context = '')
    {
        if (!self::$isEnabled || self::$logFile === null) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? "[$context] " : '';
        $logLine = "[$timestamp] {$contextStr}{$message}\n";
        
        // Append to file
        file_put_contents(self::$logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * Log an API request
     */
    public static function logRequest($url, $method = 'GET')
    {
        self::log("API Request: $method $url", 'REQUEST');
    }
    
    /**
     * Log an API response
     */
    public static function logResponse($response, $httpCode = null)
    {
        $codeStr = $httpCode ? " (HTTP $httpCode)" : '';
        self::log("API Response$codeStr:", 'RESPONSE');
        self::log("--- BEGIN RESPONSE ---", 'RESPONSE');
        self::log($response, 'RESPONSE');
        self::log("--- END RESPONSE ---", 'RESPONSE');
    }
    
    /**
     * Log a warning
     * 
     * @param string $message The warning message to log
     * @return void
     */
    public static function logWarning($message)
    {
        self::log("WARNING: $message", 'WARNING');
    }
    
    /**
     * Log an error
     */
    public static function logError($message, $exception = null)
    {
        self::log("ERROR: $message", 'ERROR');
        if ($exception instanceof \Exception) {
            self::log("Exception: " . $exception->getMessage(), 'ERROR');
            self::log("File: " . $exception->getFile() . " Line: " . $exception->getLine(), 'ERROR');
            self::log("Stack trace:", 'ERROR');
            self::log($exception->getTraceAsString(), 'ERROR');
        }
    }
    
    /**
     * Log PHP errors and warnings
     */
    public static function logPhpError($errno, $errstr, $errfile, $errline)
    {
        $errorTypes = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED',
        ];
        
        $errorType = $errorTypes[$errno] ?? 'UNKNOWN';
        self::log("PHP $errorType: $errstr in $errfile on line $errline", 'PHP_ERROR');
    }
    
    /**
     * Finalize the logger
     */
    public static function finalize()
    {
        self::log("========================================");
        self::log("eBay Sync Session Ended");
        self::log("Time: " . date('Y-m-d H:i:s'));
        self::log("========================================");
        self::log(""); // Empty line for readability
    }
}
