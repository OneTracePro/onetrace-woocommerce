<?php
// Fake platform for smoke tests of the built plugin: logs every request as a JSON line and answers like the API.
$line = json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => json_decode((string) file_get_contents('php://input'), true)]);
file_put_contents('/tmp/receiver.log', $line . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/api/v1/key') {
    echo json_encode(['type' => 'secret', 'name' => 'Shop', 'project' => ['uid' => 'abc', 'name' => 'Demo'], 'scopes' => ['products.write']]);
} else {
    http_response_code(202);
    echo '{"accepted":1,"duplicates":0}';
}
