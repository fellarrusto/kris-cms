<?php

function checkAuth() {
    session_start();
    if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
        header("Location: editor/signin.php");
        exit();
    }
}