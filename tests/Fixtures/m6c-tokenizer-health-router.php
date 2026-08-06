<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/tokenizer/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'healthy', 'version' => 1]);

    return true;
}

http_response_code(404);

return true;
