<?php

use Nueaf\Uteeni\Database;

beforeEach(function () {
    $this->dbName = "TEST";
    $this->dbLocation = __DIR__ . "/test.db";
    $dsn = "sqlite:" . $this->dbLocation;
    Database::add($this->dbName, $dsn);
});

afterEach(function () {
    if (file_exists($this->dbLocation)) {
        unlink($this->dbLocation);
    }
});

it('can add and retrieve a connection', function () {
    $fetchedConnection = Database::get($this->dbName);
    expect($fetchedConnection)->toBeInstanceOf(\PDO::class);
});

it('caches connection', function () {
    $firstConnection = Database::get($this->dbName);
    $secondConnection = Database::get($this->dbName);
    expect($firstConnection)->toBe($secondConnection);
});

it('Can reconnect', function () {
    $firstConnection = Database::get($this->dbName);
    $secondConnection = Database::reconnect($this->dbName);
    expect($firstConnection)->not()->toBe($secondConnection);
});