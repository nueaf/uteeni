<?php

namespace Nueaf\Uteeni;

class Database
{

    static $conn = null;
    static $host = null;
    static $db      = null;
    static $user = null;
    static $pass = null;
    static $port = null;
    private static array $connections = [];

    public static function connect(string $dbName)
    {
        $config = ActiveRecordDatabase::getDatabaseConfig($dbName);
        $host = $config["host"];
        if(!array_key_exists($host, self::$connections)) {
            self::$connections[$host] = new \PDO(
                $config['dsn'],
                $config['user'],
                $config['pass'],
                $config['driver_options']
            );
        }
        return self::$connections[$host];
    }
}
