<?php
session_start();
if (!isset($_SESSION['kris_auth']) || $_SESSION['kris_auth'] !== true) {
    http_response_code(403);
    exit;
}

$uploadDir = __DIR__ . '/../assets/uploads/';
$original = preg_replace('/[^a-z0-9-_\.]/i', '', basename($_FILES['file']['name']));
$ext = pathinfo($original, PATHINFO_EXTENSION);
$base = pathinfo($original, PATHINFO_FILENAME);
$name = sprintf('%s_%s.%s', bin2hex(random_bytes(4)), $base, $ext);

move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $name);

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    echo json_encode(['url' => 'assets/uploads/' . $name]);
} else {
    header('Location: index.php?action=media');
    exit;
}