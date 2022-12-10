<?php

use Nueaf\Uteeni\ActiveRecordDatabase;

it('Can get a database config', function() {
    ActiveRecordDatabase::setIniPath("blahblah");
    ActiveRecordDatabase::getDatabaseConfig("mysql");
});