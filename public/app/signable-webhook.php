<?php
declare(strict_types=1);

// Signable calls this when an envelope changes. We only use it as a prompt to
// re-check the contract with Signable's API, so a forged call can't mark
// anything signed.
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

header('Content-Type: text/plain');
$secret = (string)setting('signable_webhook_secret');
if ($secret === '' || !hash_equals($secret, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
$payload = $_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: []);
$fingerprint = (string)($payload['envelope_fingerprint'] ?? $payload['fingerprint'] ?? '');
if (preg_match('/^[a-z0-9]{16,64}$/i', $fingerprint)) {
    $contract = db_one('SELECT * FROM contracts WHERE signable_fingerprint = ?', [$fingerprint]);
    if ($contract) {
        try {
            contract_sync($contract);
        } catch (Throwable $e) {
            error_log('Signable webhook sync failed: ' . $e->getMessage());
        }
    }
}
echo 'ok';
