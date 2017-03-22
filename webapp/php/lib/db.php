<?php
require_once __DIR__ . '/../vendor/autoload.php';

function getPDO() {
    $host = getenv('MYSQL_HOST') ?: 'localhost';
    $port = getenv('MYSQL_PORT') ?: 3306;
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASS') ?: '';
    $dbname = 'isuketch';
    $dbh = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $dbh;
}

function execute($dbh, $sql, array $params = []) {
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    return (int)$dbh->lastInsertId();
}

function selectOne($dbh, $sql, array $params = []) {
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    return array_pop($stmt->fetchAll());
}

function selectAll($dbh, $sql, array $params = []) {
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}