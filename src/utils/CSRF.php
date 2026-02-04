<?php
/**
 * CSRF Token Manager
 * Provides protection against Cross-Site Request Forgery attacks
 */

namespace FAS\Utils;

class CSRF
{
    /**
     * Generate a new CSRF token for the current session
     */
    public static function generateToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Get the current CSRF token
     */
    public static function getToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return $_SESSION['csrf_token'] ?? self::generateToken();
    }
    
    /**
     * Validate a CSRF token
     */
    public static function validateToken($token)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        // Use hash_equals for timing-attack safe comparison
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generate an HTML hidden input field with the CSRF token
     */
    public static function tokenField()
    {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Validate token from POST request
     */
    public static function validateRequest()
    {
        $token = $_POST['csrf_token'] ?? '';
        
        if (!self::validateToken($token)) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
        
        return true;
    }
}
