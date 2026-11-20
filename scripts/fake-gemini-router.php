<?php

// Tiny fake Gemini REST endpoint for offline smoke tests.
// Handles ONLY :generateContent calls and returns a schema-valid
// structured-output payload. Everything else gets 404.
//
// Usage: php -S 127.0.0.1:<port> scripts/fake-gemini-router.php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($path ?? '', ':generateContent') !== false) {
    header('Content-Type: application/json');
    echo json_encode([
        'candidates' => [[
            'content' => ['parts' => [[
                'text' => json_encode([
                    'landmark_name' => 'Eiffel Tower',
                    'city' => 'Paris',
                    'country' => 'France',
                    'region' => 'Ile-de-France',
                    'latitude' => 48.8584,
                    'longitude' => 2.2945,
                    'confidence' => 90,
                    'reasoning' => 'Distinctive iron lattice tower with riveted structure',
                    'description' => 'The Eiffel Tower',
                    'vantage_point' => 'ground',
                    'visual_clues' => ['iron lattice', 'tower', 'rivets', 'Seine', 'Paris'],
                    'tags' => ['landmark', 'tower', 'paris'],
                    'candidates' => [],
                ]),
            ]]],
            'finishReason' => 'STOP',
        ]],
    ]);

    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{}';
