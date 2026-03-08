<?php
// Endpoint HTTP para que cron-job.org dispare el bot
$key = $_GET['key'] ?? '';
if ($key !== 'a123456789') { http_response_code(403); die('Acceso denegado'); }

ob_start();
require_once __DIR__ . '/bot.php';
$log = ob_get_clean();

echo json_encode(['ok' => true, 'timestamp' => date('Y-m-d H:i:s'), 'log' => $log]);
