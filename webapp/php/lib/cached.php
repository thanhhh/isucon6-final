<?php
require_once __DIR__ . '/../vendor/autoload.php';

const ROOM_CACHED = 'room_';

////////////////////////
// Memcached
////////////////////////
static $cached = NULL;

function getMemcached() {
    if ($cached != NULL) {
        return $cached;
    }
    $host = getenv('MEMCACHED_HOST') ?: 'localhost';
    $port = getenv('MEMCACHED_PORT') ?: 11211;

    $cached = new Memcached(); 
    $cached->addServer($host, $port); 
    return $cached;
}

// Data Cached
function addStrokesToCached($room_id, $stroke) {
    $mc = getMemcached();

    $key = ROOM_CACHED.$room_id;

    $room = $mc->get($key);

    if ($room) {
        $room['strokes'][] = $stroke;
    }

    $mc->set($key, $room);
}

function getStokesFromCached($room_id, $greater_than_id) {
    $mc = getMemcached();

    $key = ROOM_CACHED.$room_id;

    $room = $mc->get($key);

    if (!$room) {
        return [];
    }

    $original_strokes = $room['strokes'];

    if ($greater_than_id > 0) {
        $stokes = [];

        foreach($original_strokes as $key => $stroke) {
            if ($stroke['id'] > $greater_than_id) {
                $stokes[] = $stroke;
            }
        }

        return $stokes;
    }

    return $original_strokes;
}