<?php
$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);

var_dump($env);


$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';
$db   = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';
$socket = $env['DB_SOCKET'] ?? null;

$dsn = $socket
  ? "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4"
  : "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  echo "OK: Connected via DSN = {$dsn}";
} catch (Throwable $e) {
  echo "FAIL: " . htmlspecialchars($e->getMessage()) . "<br>DSN: {$dsn}";
}
