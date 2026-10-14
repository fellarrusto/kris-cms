<?php
// Servito dal framework in kris/: questo file resta fisso.
if (is_file(__DIR__ . '/kris/agent.php')) {
    require __DIR__ . '/kris/agent.php';
} else {
    http_response_code(404);
}
