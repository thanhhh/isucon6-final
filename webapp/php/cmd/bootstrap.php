<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/cached.php';
require_once __DIR__ . '/../lib/api-helper.php';

function cachedAll() {
    $mc = getMemcached();

    //Clean cached
    $mc->flush();

    $dbh = getPDO();
    
    $sql = 'SELECT `id` FROM `rooms`';

    $rooms = selectAll($dbh, $sql);

    foreach($rooms as $room) {
        getRoomDetail($dbh, $room['id']);
    }
}

cachedAll();