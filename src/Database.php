<?php

namespace Nueaf\Uteeni;

class Database
{
    /**
     * @var \PDO[]
     */
    private static array $connections = [];
    private static array $dsn = [];

    /**
     * @param string $dbName
     * @return \PDO
     * @throws \Exception
     */
    public static function get(string $dbName): \PDO
    {
        return self::$connections[$dbName] ??= self::connect($dbName);
    }

    /**
     * @param string $dbName
     * @return \PDO
     * @throws \Exception'
     */
    public static function reconnect(string $dbName): \PDO
    {
        if(isset(self::$connections[$dbName])) {
            self::$connections[$dbName] = null;
        }
        return self::get($dbName);
    }

    /**
     * @param $dbName
     * @return \PDO
     * @throws \Exception
     */
    private static function connect($dbName): \PDO
    {
        return new \PDO(self::getDSN($dbName));
    }

    /**
     * @param $dbName
     * @return string
     * @throws \Exception
     */
    private static function getDSN($dbName): string
    {
        return self::$dsn[$dbName] ?? throw new \Exception("Unknown DB $dbName");
    }

    /**
     * @param string $dbName
     * @param \PDO $connection
     * @return void
     */
    public static function add(string $dbName, string $dsn): void
    {
        self::$dsn[$dbName] = $dsn;
    }
}
