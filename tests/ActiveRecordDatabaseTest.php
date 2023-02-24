<?php

use Nueaf\Uteeni\ActiveRecordDatabase;

it('Can get a database config', function() {
    ActiveRecordDatabase::setIniPath(__DIR__ . "/database.ini");
    $config = ActiveRecordDatabase::getDatabaseConfig("testDatabase");
    \Nueaf\Uteeni\Database::connect("testDatabase");
});