<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/transfomation.php';
require_once __DIR__ . '/../lib/cached.php';

function getStrokePoints($dbh, $stroke_id) {
    $sql = 'SELECT `id`, `stroke_id`, `x`, `y` FROM `points` WHERE `stroke_id` = :stroke_id ORDER BY `id` ASC';
    return selectAll($dbh, $sql, [':stroke_id' => $stroke_id]);
}

function getStrokes($dbh, $room_id, $greater_than_id) {
    $sql = 'SELECT `id`, `room_id`, `width`, `red`, `green`, `blue`, `alpha`, `created_at` FROM `strokes`';

    $sql .= ' WHERE `room_id` = :room_id';

    if ($greater_than_id > 0) {
        $sql .= ' AND `id` > :greater_than_id ORDER BY `id` ASC'; 

        return selectAll($dbh, $sql, [':room_id' => $room_id, ':greater_than_id' => $greater_than_id]);
    }

    $sql .= ' ORDER BY `id` ASC';
    
    return selectAll($dbh, $sql, [':room_id' => $room_id]);
}

function getCountStrokes($dbh, $room_id) {
    $sql = 'SELECT COUNT(*) as `stroke_count` FROM `strokes` WHERE `room_id` = :room_id';
    
    $result = selectOne($dbh, $sql, [':room_id' => $room_id]);
    return $result['stroke_count'];
}

function getRoom($dbh, $room_id) {
    $sql = 'SELECT `id`, `name`, `canvas_width`, `canvas_height`, `created_at` FROM `rooms` WHERE `id` = :room_id';
    return selectOne($dbh, $sql, [':room_id' => $room_id]);
}

function getWatcherCount($dbh, $room_id) {
    // $sql = 'SELECT COUNT(*) AS `watcher_count` FROM `room_watchers`';
    // $sql .= ' WHERE `room_id` = :room_id AND `updated_at` > CURRENT_TIMESTAMP(6) - INTERVAL 3 SECOND';
    // $result = selectOne($dbh, $sql, [':room_id' => $room_id]);
    // return $result['watcher_count'];

    $watcher_count = 0;

    $mc = getMemcached();

    $watcher_key = 'watcher_' . $room_id;

    $keys = $mc->get($watcher_key);
    if ($keys) {
        $result = $mc->getMulti($keys);

        if ($result) {
            $new_keys = [];
            foreach($result as $key => $value) {
                $watcher_count++;
                $new_keys[] = $key;
            }

            if (count($new_keys) > 0) {
                $mc->set($watcher_key, $new_keys, 3);
            }
        }
    }

    return $watcher_count;
}

function updateRoomWatcher($dbh, $room_id, $token_id) {
    // $sql = 'INSERT INTO `room_watchers` (`room_id`, `token_id`) VALUES (:room_id, :token_id)';
    // $sql .= ' ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP(6)';
    // execute($dbh, $sql, [':room_id' => $room_id, ':token_id' => $token_id]);

    $watcher_key = 'watcher_' . $room_id;

    $mc = getMemcached();
    $key = 'rw_' . $room_id . '_' . $token_id;

    $mc->set($key, $key, 3);

    $keys = $mc->get($watcher_key);
    if ($keys) {
        array_push($keys, $key);
    }
    else {
        $keys = [];
        $keys[] = $key;
    }

    $mc->set($watcher_key, $keys, 3);
}

function getRoomDetail($dbh, $room_id) {
    $room = getRoom($dbh, $room_id);

    if ($room === null) {
        return $room;
    }

    $strokes = getStrokes($dbh, $room['id'], 0);

    foreach ($strokes as $i => $stroke) {
        $strokes[$i]['points'] = getStrokePoints($dbh, $stroke['id']);
    }

    $room['strokes'] = $strokes;
    $room['watcher_count'] = getWatcherCount($dbh, $room['id']);

    $data = typeCastRoomData($room);

    $mc = getMemcached();

    $mc->set($key, $data);

    return $data;
}