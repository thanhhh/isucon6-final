<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/cached.php';

function checkToken($dbh, $csrf_token) {
    $mc = getMemcached();

    $value = $mc->get($csrf_token);

    if ($value) {
        return $value;
    }

    $sql = 'SELECT `id`, `csrf_token`, `created_at` FROM `tokens`';
    $sql .= ' WHERE `csrf_token` = :csrf_token AND `created_at` > CURRENT_TIMESTAMP(6) - INTERVAL 1 DAY';
    $token = selectOne($dbh, $sql, [':csrf_token' => $csrf_token]);
    if (is_null($token)) {
        throw new TokenException();
    }

    $mc->set($csrf_token, $token, 86400);

    return $token;
}

function createToken($dbh) {
    $sql = 'INSERT INTO `tokens` (`csrf_token`) VALUES';
    $sql .= ' (SHA2(CONCAT(RAND(), UUID_SHORT()), 256))';

    $id = execute($dbh, $sql);

    $sql = 'SELECT `id`, `csrf_token`, `created_at` FROM `tokens` WHERE id = :id';
    $token = selectOne($dbh, $sql, [':id' => $id]);

    $mc = getMemcached();

    $mc->set($csrf_token, $token, 86400);

    return $token;
}