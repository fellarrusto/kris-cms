<?php
session_start();

header('Content-Type: application/json');

$valid_user = "Admin";
$valid_password = "password";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === $valid_user && $password === $valid_password) {
        $_SESSION['logged'] = true;
        echo json_encode([
            'success' => true,
            'redirect' => 'index.php'
        ]);
        exit();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Credenziali non valide'
    ]);
    exit();
}

echo json_encode([
    'success' => false,
    'message' => 'Richiesta non valida'
]);
exit();
?>