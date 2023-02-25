<?php

namespace Nueaf\Uteeni;

class SqlSyntaxor
{

    static function getSelectSQL(array $options = [], string $driver = "mysql"): string
    {
        $driver = $driver ?: "mysql";
        $options['SELECT'] = (isset($options['SELECT']) && $options['SELECT']) ? $options['SELECT'] : '*';
        if (!isset($options["TABLE"])) {
            throw new \Exception("Table option is required");
        }
        switch (strtoupper($driver)) {
        case 'SQLITE';
        case 'MYSQL':
            $sql = "SELECT [+SELECT+] FROM [+TABLE+] [+JOINS+] [+WHERE+] [+ORDERFIELD+] [+ORDERTYPE+] [+LIMIT+]";
            foreach ($options as $key => $option) {
                switch ($key) {
                case 'WHERE':
                    $option = "WHERE $option";
                    break;
                case 'ORDERFIELD':
                    $option = "ORDER BY $option";
                    break;
                case 'ORDERTYPE':
                    if (!array_key_exists("ORDERFIELD", $options)) {
                        continue 2;
                    }
                    break;
                case 'LIMIT':
                    $option = "LIMIT " . (array_key_exists('OFFSET', $options) ? "$options[OFFSET], " : "") . $option;
                    break;
                }
                $sql = str_replace("[+$key+]", $option, $sql);
            }
            break;
        case 'OCI':
            $limit = 0;
            $offset = 0;
            $sql = "SELECT [+SELECT+] FROM [+TABLE+] [+JOINS+] [+WHERE+] [+LIMIT+] [+ORDERFIELD+] [+ORDERTYPE+]";
            foreach ($options as $key => $option) {
                switch ($key) {
                case 'WHERE':
                    $option = "WHERE $option";
                    break;
                case 'ORDERFIELD':
                    $option = "ORDER BY $option";
                    break;
                case 'ORDERTYPE':
                    if (!array_key_exists("ORDERFIELD", $options)) {
                        continue 2;
                    }
                    break;
                case 'LIMIT':
                    if (isset($options['OFFSET']) && $options['OFFSET']) {
                        $limit = $option + $options['OFFSET'];
                        $offset = $options['OFFSET'];
                        continue 2;
                    } else {
                        $option = (!array_key_exists("WHERE", $options) ? "WHERE " : " AND ") . "ROWNUM <= '" . $option . "' ";
                    }
                    break;
                }
                $sql = str_replace("[+$key+]", $option, $sql);
            }
            if ($offset) {
                $where_limit = (!array_key_exists("WHERE", $options) ? "WHERE " : " AND ") . "ROWNUM < '" . $limit . "'";
                $where_offset = (!array_key_exists("WHERE", $options) ? "WHERE " : " AND ") . "ROWNUM < '" . $offset . "'";
                $sql = $sql . $where_limit . ' minus ' . $sql . $where_offset;
            }
            break;
        case 'DBLIB':
            $limit = 0;
            $offset = 0;
            $sql = "SELECT [+LIMIT+] [+SELECT+] FROM [+TABLE+] [+JOINS+] [+WHERE+]  [+ORDERFIELD+] [+ORDERTYPE+]";
            foreach ($options as $key => $option) {
                switch ($key) {
                case 'WHERE':
                    $option = "WHERE $option";
                    break;
                case 'ORDERFIELD':
                    $option = "ORDER BY $option";
                    break;
                case 'ORDERTYPE':
                    if (!array_key_exists("ORDERFIELD", $options)) {
                        continue 2;
                    }
                    break;
                case 'LIMIT':
                    $option = "TOP $option";
                    break;
                case 'OFFSET':
                    if ($option) {
                        trigger_error("Offset not supported for MSSQL");
                    }
                    break;
                }
                $sql = str_replace("[+$key+]", $option, $sql);
            }
            break;
        default:
            throw new \Exception("Unsupported driver");

        }
        return trim(preg_replace("/\[\+[A-Z]*\+\]/", "", $sql));
    }

    /**
     * @throws \Exception
     */
    static function getUpdateSQL($options = array(), $driver = "mysql"): string
    {
        $driver = $driver ?: "mysql";
        switch (strtoupper($driver)) {
        case 'OCI':
        case 'DBLIB':
        case 'MYSQL':
            $sql = "UPDATE [+TABLE+] SET [+VALUES+] [+WHERE+]";
            foreach ($options as $key => $option) {
                switch ($key) {
                case 'WHERE':
                    $option = "WHERE $option";
                    break;
                }
                $sql = str_replace("[+$key+]", $option, $sql);
            }
            break;
        default:
            throw new \Exception("Unsupported driver");
        }
        return $sql;
    }

    /**
     * @param  array  $options
     * @param  string $driver
     * @return array|string|string[]
     * @throws \Exception
     */
    static function getCreateSQL(array $options = array(), string $driver = "mysql"): string
    {
        /*
         * TODO: Allow for inserting multiple rows in one statement.
         */
        $driver = $driver ? $driver : "mysql";
        switch (strtoupper($driver)) {
        case 'OCI':
        case 'DBLIB':
        case 'MYSQL':
            $sql = "INSERT INTO [+TABLE+] ([+FIELDS+]) VALUES([+VALUES+]);";
            foreach ($options as $key => $option) {
                switch ($key) {
                case 'WHERE':
                    $option = "WHERE $option";
                    break;
                }
                $sql = str_replace("[+$key+]", $option, $sql);
            }
            break;
        default:
            throw new \Exception("Unsupported driver");
        }
        return $sql;
    }

    static function getDestroySQL(array $options = array(), string $driver = "mysql"): string
    {
        $driver = $driver ? $driver : "mysql";
        switch (strtoupper($driver)) {
        case 'OCI':
        case 'DBLIB':
        case 'MYSQL':
            $sql = "DELETE FROM [+TABLE+] [+WHERE+]";
            foreach ($options as $key => $option) {
                switch ($key) {
                case 'WHERE':
                    $option = "WHERE $option";
                    break;
                }
                $sql = str_replace("[+$key+]", $option, $sql);
            }
            break;
        default:
            throw new \Exception("Unsupported driver");

        }
        return $sql;
    }

    /**
     * @param  string $driver
     * @return string
     * @throws \Exception
     */
    static function getLastInsertIdSQL(string $driver = "mysql"): string
    {
        $driver = $driver ? $driver : "mysql";
        switch (strtoupper($driver)) {
        case 'DBLIB':
            $sql = "SELECT SCOPE_IDENTITY() AS mixLastId";
            break;
        default:
            throw new \Exception("Unsupported driver");
        }
        return $sql;
    }

    static function addGnyfToKey($key, $driver = "mysql")
    {
        switch (strtoupper($driver)) {
        case 'MYSQL':
            return "`$key`";
        case 'DBLIB':
        case 'OCI':
            return "\"$key\"";
        }
    }
}
