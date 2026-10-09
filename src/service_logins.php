<?php
declare(strict_types=1);

/*
 * Service logins: the username, password (stored encrypted) and IP address(es) of a customer's service,
 * e.g. a broadband line's PPP login. Shown on the service and the customer's page, changeable by staff,
 * and emailed to the customer when they need them (such as once the line is live and its IPs are known).
 */

/** The service's login and IP details: ['username', 'password', 'ip']. */
function service_login(array $service): array
{
    return [
        'username' => (string)($service['login_username'] ?? ''),
        'password' => (string)(decrypt_secret($service['login_password'] ?? null) ?? ''),
        'ip' => (string)($service['ip_details'] ?? ''),
    ];
}

/** Does the service have any login details worth showing? */
function service_has_login(array $service): bool
{
    return !empty($service['login_username']) || !empty($service['login_password']) || !empty($service['ip_details']);
}

/** Store a new password for the service (in the CRM). */
function service_set_password(array $service, string $password): void
{
    if (strlen($password) < 6 || strlen($password) > 64 || preg_match('/\s/', $password)) {
        throw new IntegrationException('Use 6 to 64 characters, with no spaces.');
    }
    db_exec('UPDATE services SET login_password = ? WHERE id = ?', [encrypt_secret($password), $service['id']]);
    audit('update', "Login password changed for service {$service['identifier']}", 'services', (int)$service['id'], null, null, (int)$service['account_id']);
}

/** Email the service's login and IP details to someone at the customer. Returns the address used. */
function service_email_login(array $service, string $to, string $name = ''): string
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new IntegrationException('Enter a valid email address.');
    }
    $l = service_login($service);
    $rows = array_filter(['Username' => $l['username'], 'Password' => $l['password'], 'IP address' => $l['ip']], fn($v) => $v !== '');
    if (!$rows) {
        throw new IntegrationException('This service has no login details to send.');
    }
    $product = $service['product_id'] ? (string)db_value('SELECT name FROM products WHERE id = ?', [$service['product_id']]) : '';
    $what = $product ?: (SERVICE_TYPES[$service['service_type']] ?? 'service');
    $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;background:#f9fafb;border:1px solid #e4e7ec">';
    foreach ($rows as $label => $value) {
        $table .= '<tr><td style="padding:8px 12px;color:#667085;white-space:nowrap">' . h($label) . '</td><td style="padding:8px 12px;' . ($label !== 'IP address' ? 'font-family:monospace;font-size:15px' : '') . '">' . h($value) . '</td></tr>';
    }
    $table .= '</table>';
    $first = trim(explode(' ', trim($name))[0] ?? '') ?: 'there';
    $company = company('name', config('app_name'));
    $body = '<p>Hi ' . h($first) . ',</p><p>Here are the setup details for your ' . h($what) . ' (' . h((string)$service['identifier']) . '):</p>' . $table
        . ($service['service_type'] === 'broadband' ? '<p style="color:#667085;font-size:13px">If your router wasn\'t supplied ready to use, enter the username and password in its broadband (PPP) settings.</p>' : '')
        . '<p style="color:#667085;font-size:13px">Please keep these details safe. Questions? Just reply to this email.</p>';
    send_mail($to, $name, "Your setup details from $company", email_layout('Your setup details', $body));
    log_activity((int)$service['account_id'], 'email', "Setup details for {$service['identifier']} sent to " . ($name !== '' ? "$name <$to>" : $to));
    audit('update', "Setup details for service {$service['identifier']} emailed to $to", 'services', (int)$service['id'], null, null, (int)$service['account_id']);
    return $to;
}

/** Staff: change a service's password, or email its login details (POST from the service's page). */
function service_login_controller(): void
{
    require_permission('services.edit');
    if (!is_post()) {
        redirect(url('services'));
    }
    verify_csrf();
    $service = find('services', query_int('id') ?? 0) ?? not_found('Service not found.');
    try {
        if (query('do') === 'password') {
            $pw = (string)($_POST['password'] ?? '');
            if ($pw !== (string)($_POST['confirm'] ?? '')) {
                throw new IntegrationException('The two passwords don\'t match.');
            }
            service_set_password($service, $pw);
            flash('Password changed in the CRM. Remember to change it with the supplier too, so the line keeps working.');
        } elseif (query('do') === 'email') {
            $to = service_email_login($service, trim((string)($_POST['email'] ?? '')), trim((string)($_POST['name'] ?? '')));
            flash("Setup details emailed to $to.");
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('services', ['action' => 'view', 'id' => $service['id']]));
}
