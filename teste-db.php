<?php
$senha = '321';
foreach (['127.0.0.1', 'localhost', '::1'] as $host) {
    try {
        new PDO("mysql:host=$host;port=3306", 'root', $senha);
        echo "$host: OK\n";
    } catch (Throwable $e) {
        echo "$host: " . $e->getMessage() . "\n";
    }
}