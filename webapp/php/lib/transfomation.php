<?php

function typeCastPointData($data) {
    return [
        'id' => (int)$data['id'],
        'stroke_id' => (int)$data['stroke_id'],
        'x' => (float)$data['x'],
        'y' => (float)$data['y'],
    ];
}

function toRFC3339Micro($date) {
    // RFC3339では+00:00のときはZにするという仕様だが、PHPの"P"は準拠していないため
    return str_replace('+00:00', 'Z', date_create($date)->format("Y-m-d\TH:i:s.uP"));
}

function typeCastStrokeData($data) {
    return [
        'id' => (int)$data['id'],
        'room_id' => (int)$data['room_id'],
        'width' => (int)$data['width'],
        'red' => (int)$data['red'],
        'green' => (int)$data['green'],
        'blue' => (int)$data['blue'],
        'alpha' => (float)$data['alpha'],
        'points' => isset($data['points']) ? array_map('typeCastPointData', $data['points']) : [],
        'created_at' => isset($data['created_at']) ? toRFC3339Micro($data['created_at']) : '',
    ];
}

function typeCastRoomData($data) {
    return [
        'id' => (int)$data['id'],
        'name' => $data['name'],
        'canvas_width' => (int)$data['canvas_width'],
        'canvas_height' => (int)$data['canvas_height'],
        'created_at' => isset($data['created_at']) ? toRFC3339Micro($data['created_at']) : '',
        'strokes' => isset($data['strokes']) ? array_map('typeCastStrokeData', $data['strokes']) : [],
        'stroke_count' => (int)$data['stroke_count'],
        'watcher_count' => (int)$data['watcher_count'],
    ];
}