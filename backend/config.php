<?php
declare(strict_types=1);

/**
 * Private deployment configuration.
 * Secrets live in /etc/parse-api.env, never inside the public web root or Git.
 */
$configFile = '/etc/parse-api.env';
if (!is_readable($configFile)) {
    error_log('Parse API configuration is missing or unreadable.');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => 'Service is not configured.']);
    exit;
}
$config = parse_ini_file($configFile, false, INI_SCANNER_RAW);
if (!is_array($config)) {
    error_log('Parse API configuration could not be parsed.');
    http_response_code(503);
    exit;
}
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'CORS_ORIGIN'] as $required) {
    if (!isset($config[$required]) || $config[$required] === '') {
        error_log('Parse API configuration is incomplete: ' . $required);
        http_response_code(503);
        exit;
    }
}
return $config;
