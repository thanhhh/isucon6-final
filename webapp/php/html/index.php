<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/transfomation.php';
require_once __DIR__ . '/../lib/cached.php';
require_once __DIR__ . '/../lib/api-helper.php';
require_once __DIR__ . '/../lib/token.php';

function printAndFlush($content) {
    print($content);
    ob_flush();
    flush();
}

class TokenException extends Exception {}


// Instantiate the app
$settings = [
    'displayErrorDetails' => getenv('ISUCON_ENV') !== 'production',
    'addContentLengthHeader' => false, // Allow the web server to send the content-length header

    // Monolog settings
    'logger' => [
        'name' => 'isucon6',
        'path' => 'php://stdout',
        'level' => \Monolog\Logger::DEBUG,
    ],
];
$app = new \Slim\App(['settings' => $settings]);

$container = $app->getContainer();

// monolog
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Monolog\Logger($settings['name']);
    $logger->pushHandler(new Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
    return $logger;
};

// Routes

$app->post('/api/csrf_token', function ($request, $response, $args) {
    $dbh = getPDO();

    $token = createToken($dbh);

    return $response->withJson(['token' => $token['csrf_token']]);
});

$app->get('/api/rooms', function ($request, $response, $args) {
    $dbh = getPDO();

    $sql = 'SELECT `id`, `name`, `canvas_width`, `canvas_height`, `created_at`, `stroke_count` 
        FROM `rooms` r INNER JOIN (SELECT `room_id`, MAX(`id`) AS `max_id`, count(`id`) as `stroke_count` 
        FROM `strokes` 
        GROUP BY `room_id` ORDER BY `max_id` DESC LIMIT 100) s
        ON r.`id` = s.`room_id`';

    $rooms = selectAll($dbh, $sql);

    // $sql = 'SELECT `room_id`, MAX(`id`) AS `max_id` FROM `strokes`';
    // $sql .= ' GROUP BY `room_id` ORDER BY `max_id` DESC LIMIT 100';
    // $results = selectAll($dbh, $sql);

    // $rooms = [];
    // foreach ($results as $result) {
    //     $room = getRoom($dbh, $result['room_id']);
    //     $room['stroke_count'] = getCountStrokes($dbh, $room['id']);//count(getStrokes($dbh, $room['id'], 0));
    //     $rooms[] = $room;
    // }

    return $response->withJson(['rooms' => array_map('typeCastRoomData', $rooms)]);
});

$app->post('/api/rooms', function ($request, $response, $args) {
    $dbh = getPDO();

    try {
        $token = checkToken($dbh, $request->getHeaderLine('x-csrf-token'));
    } catch (TokenException $e) {
        return $response->withStatus(400)->withJson(['error' => 'トークンエラー。ページを再読み込みしてください。']);
    }

    $postedRoom = $request->getParsedBody();
    if (empty($postedRoom['name']) || empty($postedRoom['canvas_width']) || empty($postedRoom['canvas_height'])) {
        return $response->withStatus(400)->withJson(['error' => 'リクエストが正しくありません。']);
    }

    $dbh->beginTransaction();
    try {
        $sql = 'INSERT INTO `rooms` (`name`, `canvas_width`, `canvas_height`)';
        $sql .= ' VALUES (:name, :canvas_width, :canvas_height)';
        $room_id = execute($dbh, $sql, [
            ':name' => $postedRoom['name'],
            ':canvas_width' => $postedRoom['canvas_width'],
            ':canvas_height' => $postedRoom['canvas_height']
        ]);

        $sql = 'INSERT INTO `room_owners` (`room_id`, `token_id`) VALUES (:room_id, :token_id)';
        execute($dbh, $sql, [
            ':room_id' => $room_id,
            ':token_id' => $token['id'],
        ]);

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollback();
        $this->logger->error($e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'エラーが発生しました。']);
    }

    $room = getRoom($dbh, $room_id);

    $roomData = typeCastRoomData($room);

    $mc = getMemcached();

    $key = ROOM_CACHED.$room_id;

    $mc->set($key, $roomData);

    return $response->withJson(['room' => $roomData]);
});

$app->get('/api/rooms/[{id}]', function ($request, $response, $args) {
    $mc = getMemcached();

    $key = ROOM_CACHED.$args['id'];

    $result = $mc->get($key);

    if ($result) {
        return $response->withJson(['room' => $result]);
    }

    $dbh = getPDO();

    $data = getRoomDetail($dbh, $args['id']);

    if ($data === null) {
        return $response->withStatus(404)->withJson(['error' => 'この部屋は存在しません。']);
    }

    return $response->withJson(['room' => $data]);
});

$app->get('/api/stream/rooms/[{id}]', function ($request, $response, $args) {
    header('Content-Type: text/event-stream');

    $dbh = getPDO();

    try {
        $token = checkToken($dbh, $request->getQueryParam('csrf_token'));
    } catch (TokenException $e) {
        printAndFlush(
            "event:bad_request\n" .
            "data:トークンエラー。ページを再読み込みしてください。\n\n"
        );
        return;
    }


    $room = getRoom($dbh, $args['id']);

    if ($room === null) {
        printAndFlush(
            "event:bad_request\n" .
            "data:この部屋は存在しません\n\n"
        );
        return;
    }

    updateRoomWatcher($dbh, $room['id'], $token['id']);
    $watcher_count = getWatcherCount($dbh, $room['id']);

    printAndFlush(
        "retry:500\n\n" .
        "event:watcher_count\n" .
        'data:' . $watcher_count . "\n\n"
    );

    $last_stroke_id = 0;
    if ($request->hasHeader('Last-Event-ID')) {
        $last_stroke_id = (int)$request->getHeaderLine('Last-Event-ID');
    }

    $loop = 6;
    while ($loop > 0) {
        $loop--;
        usleep(500 * 1000); // 500ms

        // $strokes = getStrokes($dbh, $room['id'], $last_stroke_id);
        // //$this->logger->info(var_export($strokes, true));

        // foreach ($strokes as $stroke) {
        //     $stroke['points'] = getStrokePoints($dbh, $stroke['id']);
        //     printAndFlush(
        //         'id:' . $stroke['id'] . "\n\n" .
        //         "event:stroke\n" .
        //         'data:' . json_encode(typeCastStrokeData($stroke)) . "\n\n"
        //     );
        //     $last_stroke_id = $stroke['id'];
        // }

        $strokes = getStokesFromCached($room['id'], $last_stroke_id);

        foreach ($strokes as $stroke) {
            printAndFlush(
                'id:' . $stroke['id'] . "\n\n" .
                "event:stroke\n" .
                'data:' . json_encode($stroke) . "\n\n"
            );

            $last_stroke_id = $stroke['id'];
        }

        updateRoomWatcher($dbh, $room['id'], $token['id']);
        $new_watcher_count = getWatcherCount($dbh, $room['id']);
        if ($new_watcher_count !== $watcher_count) {
            $watcher_count = $new_watcher_count;
            printAndFlush(
                "event:watcher_count\n" .
                'data:' . $watcher_count . "\n\n"
            );
        }
    }
});

$app->post('/api/strokes/rooms/[{id}]', function ($request, $response, $args) {
    $dbh = getPDO();

    try {
        $token = checkToken($dbh, $request->getHeaderLine('x-csrf-token'));
    } catch (TokenException $e) {
        return $response->withStatus(400)->withJson(['error' => 'トークンエラー。ページを再読み込みしてください。']);
    }

    $room = getRoom($dbh, $args['id']);

    if ($room === null) {
        return $response->withStatus(404)->withJson(['error' => 'この部屋は存在しません。']);
    }

    $postedStroke = $request->getParsedBody();
    if (empty($postedStroke['width']) || empty($postedStroke['points'])) {
        return $response->withStatus(400)->withJson(['error' => 'リクエストが正しくありません。']);
    }

    $stroke_count = getCountStrokes($dbh, $room['id']);//count(getStrokes($dbh, $room['id'], 0));
    if ($stroke_count == 0) {
        $sql = 'SELECT COUNT(*) AS cnt FROM `room_owners` WHERE `room_id` = :room_id AND `token_id` = :token_id';
        $result = selectOne($dbh, $sql, [':room_id' => $room['id'], ':token_id' => $token['id']]);
        if ($result['cnt'] == 0) {
            return $response->withStatus(400)->withJson(['error' => '他人の作成した部屋に1画目を描くことはできません']);
        }
    }

    $dbh->beginTransaction();
    try {
        $sql = 'INSERT INTO `strokes` (`room_id`, `width`, `red`, `green`, `blue`, `alpha`)';
        $sql .= ' VALUES(:room_id, :width, :red, :green, :blue, :alpha)';
        $stroke_id = execute($dbh, $sql, [
            ':room_id' => $room['id'],
            ':width' => $postedStroke['width'],
            ':red' => $postedStroke['red'],
            ':green' => $postedStroke['green'],
            ':blue' => $postedStroke['blue'],
            ':alpha' => $postedStroke['alpha']
        ]);

        $sql = 'INSERT INTO `points` (`stroke_id`, `x`, `y`) VALUES (:stroke_id, :x, :y)';
        foreach ($postedStroke['points'] as $point) {
            execute($dbh, $sql, [
                ':stroke_id' => $stroke_id,
                ':x' => $point['x'],
                ':y' => $point['y']
            ]);
        }

        $dbh->commit();
    } catch (Exception $e) {
        $dbh->rollback();
        $this->logger->error($e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'エラーが発生しました。']);
    }

    $sql = 'SELECT `id`, `room_id`, `width`, `red`, `green`, `blue`, `alpha`, `created_at` FROM `strokes`';
    $sql .= ' WHERE `id` = :stroke_id';
    $stroke = selectOne($dbh, $sql, [':stroke_id' => $stroke_id]);

    $stroke['points'] = getStrokePoints($dbh, $stroke_id);

    $strokeData = typeCastStrokeData($stroke);

    addStrokesToCached($args['id'], $strokeData);

    return $response->withJson(['stroke' => $strokeData]);
});

// Run app
$app->run();
