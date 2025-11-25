<?php
/**
 * API تبديل اللغة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$language = $_POST['language'] ?? '';

if (!in_array($language, SUPPORTED_LANGUAGES)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid language']);
    exit;
}

session_start();
$_SESSION['language'] = $language;

echo json_encode(['success' => true, 'language' => $language]);

