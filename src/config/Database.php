<?php
/**
 * Database Connection Handler
 */

namespace FAS\Config;

class Database
{
    private static $instance = null;
    private $connection;
    
    private function __construct()
    {
        $config = require __DIR__ . '/config.php';
        $dbConfig = $config['database'];
        
        try {
            $this->connection = new \PDO(
                "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4",
                $dbConfig['username'],
                $dbConfig['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (\PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new \Exception('Database connection failed');
        }
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection()
    {
        return $this->connection;
    }
    
    private function __clone() {}
    
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
