<?php

[, $databasePath, $readyPath, $holdMilliseconds] = $argv;

$pdo = new PDO('sqlite:'.$databasePath, options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA busy_timeout = 100');
$pdo->exec('BEGIN IMMEDIATE TRANSACTION');

file_put_contents($readyPath, (string) getmypid());
usleep(((int) $holdMilliseconds) * 1_000);

$pdo->exec('ROLLBACK');
