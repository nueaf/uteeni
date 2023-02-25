<?php

use Nueaf\Uteeni\Database;

beforeEach(function () {
    $this->dbName = "TEST";
    $this->dbLocation = __DIR__ . "/test.db";
    $dsn = "sqlite:" . $this->dbLocation;
    Database::add($this->dbName, $dsn);
    $PDO = Database::get($this->dbName);
    $PDO->exec("CREATE TABLE test_table (id int)");
    $PDO->exec("INSERT INTO test_table (id) VALUES(5)");

});

afterEach(function () {
    if (file_exists($this->dbLocation)) {
        unlink($this->dbLocation);
    }
});

it('can query a model', function() {
    $class = new class extends \Nueaf\Uteeni\ActiveRecord {
        protected $table_name = "test_table";
        protected $database = "TEST";
        protected $meta = [
            "id" => ["type" => "int"],
        ];
    };
    $object = new $class();
    $all = $object->find_all();

    expect($all)
        ->toBeArray()
        ->not()->toBeEmpty();
    $first = reset($all);
    expect($first->id)->toBe(5);
//    var_dump($object);
});