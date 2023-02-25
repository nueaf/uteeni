#!/usr/bin/php
<?php
echo <<<EOM
Velkommen til class builder
This script generates models based on existing tables. Edit database.php and this file to set available databases.
EOM;

$options = array("db.ini", "Database", "Table name", "repository", "prefix", "suffix");
foreach($options as $key => $option){
    switch($option){
    case 'db.ini':
        $db_ini_path = count($argv)>1?$argv[1]:"";
        while(!file_exists($db_ini_path)){
            echo "\n\nSELECT db.ini path: ";
            $db_ini_path = trim(fread(STDIN, 1024));
        }
        ActiveRecordDatabase::parsedbini($db_ini_path, true);
        $databases = array_keys(ActiveRecordDatabase::$dbconfig);
        $databases = str_replace("Database", "", $databases);
        array_unshift($databases, 'CANCEL');
        break;
    case 'Database':
        echo "Select database: \n";
        foreach($databases as $db_key => $database){
            echo $db_key . ": " . $database  . "\n";
        }
        $database = $databases[trim(fread(STDIN, 2))];
        if($database == "CANCEL") {
            exit;
        }
        break;
    case 'Table name':
        echo "Enter tablename(komma seperates): ";
        $table = trim(fread(STDIN, 1024));
        break;
    }
}

echo "\n";
if (trim($table)=="*") {
    $conn = Database::connect($database);
    $q = $conn->query("SHOW TABLES;");
    $tables = array();
    while ($r = $q->fetch(PDO::FETCH_COLUMN)) {
        $tables[] = $r;
    }
} else {
    $tables = explode(",", $table);
}

foreach($tables as $table){
    $builder = new \Nueaf\Uteeni\ClassBuilder($table, $database);
    $builder->parseTable();
    echo $builder->buildClass();
}
echo "\n";