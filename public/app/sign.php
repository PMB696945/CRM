<?php
declare(strict_types=1);

// Public page where a customer reads and signs their agreement (built-in e-signature).
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

security_headers();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
start_session();

$token = (string)query('t');
$contract = contract_by_sign_token($token);
$ip = client_ip();
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$error = null;
$notice = null;

if ($contract && $contract['status'] === 'sent' && !is_post() && query('doc') === '') {
    esign_viewed($contract, $ip, $userAgent);
}

// Downloads: the agreement's Word documents, and the certificate once signed.
if ($contract && !is_post() && in_array($contract['status'], ['sent', 'signed'], true)) {
    $docs = contract_documents($contract);
    if (query('doc') !== '' && isset($docs[(int)query('doc')])) {
        $d = $docs[(int)query('doc')];
        send_download(storage_path('contracts') . '/' . basename($d['file']), $contract['reference'] . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $d['title']) . '.docx');
    }
    if (query('cert') === '1' && $contract['status'] === 'signed' && $contract['signed_file'] && $contract['signed_ip']) {
        send_download(storage_path('contracts') . '/' . basename($contract['signed_file']), $contract['reference'] . ' signature certificate.pdf');
    }
}

if ($contract && is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($contract['status'] !== 'sent') {
            throw new IntegrationException('This agreement can\'t be changed any more.');
        }
        switch ($action) {
            case 'send_code':
                esign_send_code($contract);
                $notice = 'We\'ve emailed you a 6-digit code. It works for ' . ESIGN_CODE_MINUTES . ' minutes.';
                break;
            case 'verify':
                if (!esign_verify_code($contract, (string)($_POST['code'] ?? ''))) {
                    $error = 'That code isn\'t right. Please check it and try again.';
                }
                break;
            case 'sign':
                $name = trim((string)($_POST['name'] ?? ''));
                if (mb_strlen($name) < 2 || empty($_POST['agree'])) {
                    $error = 'Please type your full name and tick the box to sign.';
                    break;
                }
                esign_sign($contract, $name, trim((string)($_POST['position'] ?? '')), $ip, $userAgent);
                break;
            case 'decline':
                esign_decline($contract, trim((string)($_POST['reason'] ?? '')));
                break;
        }
    } catch (IntegrationException $e) {
        $error = $e->getMessage();
    }
    $contract = db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
}

if (!$contract || in_array($contract['status'], ['draft', 'failed'], true)) {
    http_response_code(404);
    $contract = null;
}
$docs = $contract ? contract_documents($contract) : [];
$previews = [];
if ($contract && $contract['status'] === 'sent') {
    foreach ($docs as $i => $d) {
        $previews[$i] = docx_to_html(storage_path('contracts') . '/' . basename($d['file']));
    }
}
render('public_sign', [
    'contract' => $contract,
    'account'  => $contract ? db_one('SELECT name FROM accounts WHERE id = ?', [$contract['account_id']]) : null,
    'docs'     => $docs,
    'previews' => $previews,
    'token'    => $token,
    'verified' => $contract ? esign_is_verified($contract) : false,
    'codeSent' => $contract && $contract['code_hash'] && strtotime((string)$contract['code_expires_at']) > time(),
    'error'    => $error,
    'notice'   => $notice,
]);
