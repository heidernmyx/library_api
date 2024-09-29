<?php

$host = 'localhost';
$db   = 'fritz_library';
$user = 'root'; 
$pass = ''; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // echo "Connected successfully";
} catch (\PDOException $e) {
    exit('Database connection failed: ' . $e->getMessage());
}
?>