<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_NAME']),
    $config['DB_USER'],
    $config['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$email = strtolower(trim((string)(getenv('ADMIN_EMAIL') ?: readline('Admin email: '))));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetchColumn()) {
    echo "An account with this email already exists. No changes were made.\n";
    exit(0);
}

function read_hidden(string $prompt): string
{
    $isTty = function_exists('stream_isatty') && stream_isatty(STDIN);
    fwrite(STDOUT, $prompt);
    if ($isTty) {
        shell_exec('stty -echo');
    }
    try {
        $value = trim((string)fgets(STDIN));
    } finally {
        if ($isTty) {
            shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
    }
    return $value;
}

$password = (string)(getenv('ADMIN_PASSWORD') ?: read_hidden('Admin password (minimum 14 characters): '));
if (strlen($password) < 14 || strlen($password) > 4096) {
    fwrite(STDERR, "Password must be at least 14 characters.\n");
    exit(1);
}
$confirmation = read_hidden('Confirm admin password: ');
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$insert = $pdo->prepare("INSERT INTO users (email, password_hash, role, active) VALUES (?, ?, 'admin', 1)");
$insert->execute([$email, $hash]);
unset($password, $confirmation, $hash);
echo "Administrator account created successfully.\n";
