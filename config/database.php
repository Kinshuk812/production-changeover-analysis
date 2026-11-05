<?php

$host = "localhost";
$dbname = "changeover_db";
$username = "root";
$password = "";

$host = getenv('DB_HOST') ?: $host;
$dbname = getenv('DB_NAME') ?: $dbname;
$username = getenv('DB_USER') ?: $username;

$envPassword = getenv('DB_PASSWORD');

if ($envPassword !== false) {
    $password = $envPassword;
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

} catch (PDOException $e) {
    throw $e;
}
