<?php

use Nueaf\Uteeni\SqlSyntaxor;

it("Can get a select statement", function() {
    $tableName = uniqid();
    $sql = SqlSyntaxor::getSelectSQL(["TABLE" => $tableName]);
    expect($sql)
        ->toBeString()
        ->and($sql)
        ->toBe("SELECT * FROM $tableName");
});