<?php

namespace Nueaf\Uteeni;

class ActiveRecordDatabase
{
    static array $dbConfig;
    private static string $iniFilePath = "";

    /**
     * @param  string $path
     * @return void
     */
    public static function setIniPath(string $path)
    {
        self::$iniFilePath = $path;
    }

    public static function parseINIFile($file_path, $strict_names = false)
    {
        self::$dbConfig = parse_ini_file($file_path, true) ?: [];
        if (!$strict_names) {
            foreach (array_keys(self::$dbConfig) as $db) {
                self::$dbConfig[strtolower($db)] = self::$dbConfig[$db];
            }
        }
    }

    /**
     * @param  $name
     * @return void
     * @throws \Exception
     */
    public static function load($name)
    {
        $config = self::getConfig();

        if (!array_key_exists($name, $config)) {
            $name = strtolower($name);
        }

        if (array_key_exists($name, $config)) {
            $dbConfig = $config[$name];
            $dbConfig['DBNAME'] = $name;
            if (!array_key_exists("dsn", $dbConfig)) {
                $dbConfig = self::addDSNToConfig($dbConfig);
            }
            $dbConfig['port'] ??= "null";
            $clsStr = file_get_contents(dirname(__FILE__) . "/database_template.txt");

            foreach ($dbConfig as $conf_key => $conf) {
                $clsStr = str_replace("#$conf_key#", $conf, $clsStr);
            }
            eval($clsStr);
        }
    }

    public static function getDatabaseDriver($name)
    {
        self::getConfig();
        $name .= "Database";
        if (!array_key_exists($name, self::$dbConfig)) {
            $name = strtolower($name);
        }
        return self::$dbConfig[$name]['type'];
    }

    /**
     * @param  array $dbConfig
     * @return array
     * @throws \Exception
     */
    public static function addDSNToConfig(array $dbConfig): array
    {
        //new PDO(#dsn#, self::$user, self::$pass, self::$driver_options);
        $charset = isset($dbConfig['charset']) && $dbConfig['charset'] ? ".';charset={$dbConfig['charset']}'" : '';
        $dbConfig['driver_options'] = 'array()';
        switch ($dbConfig["type"]) {
        case 'mysql':
            $dbConfig['driver_options'] = []; ;
            if (isset($dbConfig['charset']) && $dbConfig['charset']) {
                $charset = $dbConfig['charset'];
                $dbConfig['driver_options'] = array(\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset");
            }
            $dbConfig['dsn'] = $dbConfig["type"] . ":host=" . $dbConfig["host"] . ($dbConfig["port"] ? ":" . $dbConfig["port"] : "") . ";dbname=" . $dbConfig["db"];
            break;
        case 'oci':
            $dbConfig['dsn'] = 'self::$type.":dbname=//".self::$host.(self::$port?":".self::$port:"")."/".self::$db' . $charset;
            break;
        case 'tnsnames':
            $dbConfig['dsn'] = '"oci:dbname=".self::$db';
            break;
        case 'dblib':
            $dbConfig['dsn'] = 'self::$type.":host=".self::$host.(self::$port?":".self::$port:"").";dbname=".self::$db' . $charset;
            break;
        default:
            throw new \Exception("Unknown database type {$dbConfig['type']}");
                break;
        }
        return $dbConfig;
    }

    /**
     * @return array
     */
    public static function getConfig(): array
    {
        $path = self::$iniFilePath ?: $_SERVER["dbini_dir"] . $_SERVER["dbini_file"];
        if (!isset(self::$dbConfig)) {
            self::parseINIFile($path);
        }
        return self::$dbConfig;
    }

    /**
     * @param  string $name
     * @return array
     * @throws \Exception
     */
    public static function getDatabaseConfig(string $name): array
    {
        return self::addDSNToConfig(self::getConfig()[$name]);
    }
}