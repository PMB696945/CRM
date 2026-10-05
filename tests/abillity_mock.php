<?php
declare(strict_types=1);

/*
 * Stand-in for the aBILLity REST API used by the tests:
 *   MOCK_STATE=/tmp/a.json php -S 127.0.0.1:8996 tests/abillity_mock.php
 * Login headers: SystemInformation TESTSYS, username api-user, password api-pass.
 * Like the real API, creating a company or a service charge doesn't return the new ID, so the CRM has to find it.
 * Set "no_cp" in the state to refuse AccountRef changes (a non-CP user).
 */

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/abillity_mock.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$state += ['companies' => [], 'sites' => [], 'contacts' => [], 'types' => [], 'charges' => [], 'billing' => [], 'calls' => [], 'next' => 5000];
$save = function () use (&$state, $stateFile) { file_put_contents($stateFile, json_encode($state)); };
$out = function (int $status, mixed $body = null) use (&$state, $save): never {
    $save();
    http_response_code($status);
    header('Content-Type: application/json');
    echo $body === null ? '' : json_encode($body);
    exit;
};
$id = function () use (&$state): int { return $state['next']++; };

$method = $_SERVER['REQUEST_METHOD'];
$path = preg_replace('#^/api/#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$body = json_decode((string)file_get_contents('php://input'), true) ?? [];
$state['calls'][] = "$method $path";

if (($_SERVER['HTTP_SYSTEMINFORMATION'] ?? '') !== 'TESTSYS' || ($_SERVER['HTTP_USERNAME'] ?? '') !== 'api-user' || ($_SERVER['HTTP_PASSWORD'] ?? '') !== 'api-pass') {
    $out(401, ['Message' => 'Authorization has been denied for this request.']);
}

$siteRow = function (array $site) use (&$state): array {
    $c = $state['companies'][$site['CompanyId']];
    return ['CompanyId' => $site['CompanyId'], 'SiteId' => $site['Id'], 'CompanyName' => $c['Name'], 'SiteName' => $site['SiteName'] ?? $c['Name'],
        'SiteRef' => $site['ShortName'], 'AccountRef' => $site['AccountRef'] ?? null, 'CompanyType' => ['Customer' => !empty($c['IsCustomer'])]];
};

switch (true) {
    case $method === 'GET' && $path === 'common/frequencytype':
        $out(200, [['Id' => 1, 'Description' => 'Annual', 'Months' => 12], ['Id' => 2, 'Description' => 'Monthly', 'Months' => 1],
            ['Id' => 3, 'Description' => 'One off', 'Months' => 0], ['Id' => 4, 'Description' => 'Quarterly', 'Months' => 3]]);

    case $method === 'GET' && $path === 'company/MyCompany':
        $out(200, ['Id' => 1, 'Name' => 'Netcomm UK (test)']);

    case $method === 'GET' && $path === 'company/CompanySearch':
        $rows = [];
        foreach ($state['sites'] as $s) {
            $r = $siteRow($s);
            if (isset($_GET['AccountRef']) && strcasecmp((string)$r['AccountRef'], $_GET['AccountRef']) !== 0) continue;
            if (isset($_GET['SiteRef']) && strcasecmp((string)$r['SiteRef'], $_GET['SiteRef']) !== 0) continue;
            if (isset($_GET['SiteName']) && stripos((string)$r['SiteName'], $_GET['SiteName']) === false) continue;
            $rows[] = $r;
        }
        $out(200, ['TotalRecords' => count($state['sites']), 'FilteredRecords' => count($rows), 'CurrentPageNumber' => 1, 'Data' => $rows]);

    case $method === 'POST' && $path === 'company':
        if (empty($body['CompanyName'])) {
            $out(400, ['Message' => 'The request is invalid.', 'ModelState' => ['model.CompanyName' => ['The CompanyName field is required.']]]);
        }
        $cid = $id();
        $state['companies'][$cid] = ['Id' => $cid, 'Name' => $body['CompanyName'], 'IsProspect' => true];
        $sid = $id();
        $state['sites'][$sid] = ['Id' => $sid, 'CompanyId' => $cid, 'ShortName' => $body['SiteShortName'] ?? '', 'MainSite' => true];
        $out(201); // the real API answers with an HttpResponseMessage, not the new ID

    case $method === 'GET' && (bool)preg_match('#^company/(\d+)/Site$#', $path, $m):
        $out(200, array_values(array_filter($state['sites'], fn($s) => (int)$s['CompanyId'] === (int)$m[1])));

    case $method === 'PATCH' && (bool)preg_match('#^company/(\d+)$#', $path, $m):
        isset($state['companies'][$m[1]]) || $out(404, ['Message' => "No company found with id = {$m[1]}"]);
        $state['companies'][$m[1]] = $body + $state['companies'][$m[1]];
        $out(200);

    case $method === 'PATCH' && (bool)preg_match('#^site/(\d+)$#', $path, $m):
        isset($state['sites'][$m[1]]) || $out(404, ['Message' => "Site with id = {$m[1]} not found"]);
        if (isset($body['AccountRef']) && !empty($state['no_cp'])) {
            $out(403, ['Message' => 'Only a CP user can update the AccountRef']);
        }
        $state['sites'][$m[1]] = $body + $state['sites'][$m[1]];
        $out(200);

    case $method === 'POST' && $path === 'sitecontact':
        if (empty($body['Email']) || empty($body['Christian'])) {
            $out(400, ['Message' => 'The request is invalid.', 'ModelState' => ['model.Email' => ['The Email field is required.']]]);
        }
        $cid = $id();
        $state['contacts'][$cid] = ['Id' => $cid] + $body;
        $out(200, ['Id' => $cid]);

    case $method === 'PATCH' && (bool)preg_match('#^sitecontact/(\d+)$#', $path, $m):
        isset($state['contacts'][$m[1]]) || $out(404, ['Message' => 'Site contact not found']);
        $state['contacts'][$m[1]] = $body + $state['contacts'][$m[1]];
        $out(200);

    case $method === 'PATCH' && (bool)preg_match('#^site/(\d+)/billinginformation$#', $path, $m):
        $state['billing'][$m[1]] = $body;
        $out(200);

    case $method === 'POST' && $path === 'servicechargetype':
        foreach ($state['types'] as $t) {
            if (strcasecmp($t['RecurringChargeType'], $body['RecurringChargeType']) === 0) {
                $out(409, ['Message' => 'This Service Charge Type already exists']);
            }
        }
        if (($body['FrequencyTypeId'] ?? 0) === 3 && !empty($body['Rental'])) {
            $out(409, ['Message' => 'Invalid Frequency Type']);
        }
        $tid = $id();
        $state['types'][$tid] = ['Id' => $tid] + $body;
        $out(200, ['Id' => $tid]);

    case $method === 'PATCH' && (bool)preg_match('#^servicechargetype/(\d+)$#', $path, $m):
        isset($state['types'][$m[1]]) || $out(404, ['Message' => 'Service charge type not found']);
        $state['types'][$m[1]] = $body + $state['types'][$m[1]];
        $out(200);

    case $method === 'GET' && $path === 'servicechargetype':
        $q = (string)($_GET['search_text'] ?? '');
        $list = array_values(array_filter($state['types'], fn($t) => $q === '' || stripos($t['RecurringChargeType'], $q) !== false));
        $out(200, ['servicechargetypelist' => $list, 'FilteredRecords' => count($list)]);

    case $method === 'POST' && $path === 'servicecharge/v2':
        if (!isset($state['sites'][$body['SiteId'] ?? 0])) {
            $out(404, ['Message' => 'Site with id ' . ($body['SiteId'] ?? '?') . ' not found']);
        }
        if (empty($body['FirstPayment'])) {
            $out(400, ['Message' => 'The First Payment is mandatory']);
        }
        if (!empty($body['LastPayment']) && $body['LastPayment'] < $body['FirstPayment']) {
            $out(400, ['Message' => 'The Last Payment can\'t be set before the First Payment']);
        }
        if (($body['FrequencyTypeId'] ?? 0) === 3 && !empty($body['Rental'])) {
            $out(400, ['Message' => 'Attempted to set a one-off charge as a rental, or vice-versa']);
        }
        if (isset($body['ChargeId']) && !isset($state['types'][$body['ChargeId']])) {
            $out(404, ['Message' => 'No ChargeIds found in aBILLity']);
        }
        $cid = $id();
        $state['charges'][$cid] = ['Id' => $cid] + $body;
        $out(200); // no ID in the response

    case $method === 'GET' && (bool)preg_match('#^site/(\d+)/servicecharge$#', $path, $m):
        $q = (string)($_GET['search_text'] ?? '');
        $list = empty($state['hide_charges']) ? array_values(array_filter($state['charges'], fn($c) => (int)$c['SiteId'] === (int)$m[1] && ($q === '' || stripos((string)$c['Description'], $q) !== false))) : [];
        $out(200, ['serviceChargeList' => $list, 'FilteredRecords' => count($list)]);

    case $method === 'PATCH' && (bool)preg_match('#^servicecharge/V2/(\d+)$#', $path, $m):
        isset($state['charges'][$m[1]]) || $out(404, ['Message' => 'Service charge not found']);
        $merged = $body + $state['charges'][$m[1]];
        if (!empty($merged['LastPayment']) && $merged['LastPayment'] < $merged['FirstPayment']) {
            $out(400, ['Message' => 'The Last Payment can\'t be set before the First Payment']);
        }
        $state['charges'][$m[1]] = $merged;
        $out(200);
}
$out(404, ['Message' => "No HTTP resource was found that matches the request URI '$path'."]);
