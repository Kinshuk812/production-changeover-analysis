<?php

header("Content-Type: application/json");

try {

    require_once "config/database.php";

    $stmt = $pdo->query("SELECT 1");
    $stmt->fetch();

    http_response_code(200);

    echo json_encode(array(
        "status" => "healthy",
        "application" => "running",
        "database" => "connected"
    ));

} catch (Throwable $e) {

    http_response_code(503);

    echo json_encode(array(
        "status" => "unhealthy",
        "application" => "running",
        "database" => "unavailable"
    ));
}