<?php 
session_start();
if (isset($_SESSION['logged']) && $_SESSION['logged'] == true) {
    header("Location: index.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Kris CMS</title>
    <style>
        *, *:before, *:after {
            box-sizing: inherit;
        }

        html {
            box-sizing: border-box;
        }

        :root {
            --primary-color: #42c983;
            --secondary-color: #35495e;
            --accent-color: #547c9c;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: #f8f9fa;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .logo {
            width: 150px;
        }

        .welcome-message h1{
            margin: 10pt;
            color: var(--secondary-color);
        }

        /* Login-specific styles */
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 0 1rem;
            box-sizing: border-box; /* Aggiunto */
        }

        .login-form {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: left;
            box-sizing: border-box; /* Aggiunto */
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            box-sizing: border-box; /* Aggiunto */
        }

        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(66, 201, 131, 0.2);
        }

        .btn-primary {
            width: 100%;
            padding: 12px;
            background-color: var(--primary-color);
            border: none;
            color: white;
            font-size: 1rem;
            cursor: pointer;
        }

        .btn-primary:hover {
            background-color: #38b073;
        }

        .forgot-password {
            text-align: center;
            margin-top: 1rem;
        }

        .forgot-password a {
            color: var(--accent-color);
            text-decoration: none;
            font-size: 0.9em;
        }

        .forgot-password a:hover {
            color: var(--secondary-color);
        }
    </style>
</head>
<body>
    <img editable-img="true" src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48cGF0aCBkPSJNNTAgMTVMMjUgMzBsMjUgNDBMNzUgMzB6IiBmaWxsPSIjNDJjOTgzIi8+PHBhdGggZD0iTTUwIDg1TDI1IDYwbDI1LTQ1IDI1IDQ1eiIgZmlsbD0iIzM1NDk1ZSIvPjwvc3ZnPg==" 
         alt="Kris CMS Logo" 
         class="logo">

    <div class="welcome-message">
        <h1>Login to Kris CMS</h1>
    </div>

    <div class="login-container">
        <form class="login-form" id="loginForm">
            <div class="form-group">
                <label for="email">Username</label>
                <input type="text" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>
    </div>

    <footer>
        <p><editable k-id="footer-version">Kris CMS v1.0.0 · MIT Licensed</editable></p>
    </footer>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            
            try {
                const response = await fetch('login.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = result.redirect;
                } else {
                    alert(result.message || 'Errore durante il login');
                }
            } catch (error) {
                alert('Errore di connessione');
            }
        });
    </script>
</body>

</html>