<?php
declare(strict_types=1);

$ADMIN_USER = 'admin';      // <--- Cambia il tuo username
$ADMIN_PASS = 'password';   // <--- Cambia la tua password

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Login
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    if ($_POST['user'] === $ADMIN_USER && $_POST['pass'] === $ADMIN_PASS) {
        $_SESSION['kris_auth'] = true;
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Credenziali non valide.";
    }
}

// Gatekeeper
if (!isset($_SESSION['kris_auth']) || $_SESSION['kris_auth'] !== true) {
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kris CMS</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 350px; text-align: center; }
        h2 { margin: 0 0 20px 0; color: #111827; font-size: 1.5rem; }
        input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
        button { width: 100%; padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #2563eb; }
        .error { background: #fee2e2; color: #b91c1c; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9rem; }
        .brand { font-weight: 800; color: #3b82f6; margin-bottom: 10px; display: inline-block; letter-spacing: -1px; font-size: 1.2rem;}
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">KRIS CMS</div>
        <h2>Accesso Riservato</h2>
        <?php if ($login_error): ?><div class="error"><?= $login_error ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="do_login" value="1">
            <input type="text" name="user" placeholder="Username" required autofocus>
            <input type="password" name="pass" placeholder="Password" required>
            <button type="submit">Accedi</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}
