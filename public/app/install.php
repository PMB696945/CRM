<?php
declare(strict_types=1);

/*
 * Web installer for hosts without SSH access.
 *
 * Step 1 (only while config.php does not exist): database details, written to config.php.
 * Step 2: create the tables and the first admin user.
 *
 * Once any user exists the installer is locked and does nothing.
 */

require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/installer.php';

security_headers();
start_session();

$configFile = APP_ROOT . '/config.php';
$hasConfig = is_file($configFile);
$errors = [];
$notice = null;
$manualConfig = null;

// Until the CRM is installed, the installer asks for a setup code saved in a file
// that only someone with access to the hosting account can read.
$codeFile = APP_ROOT . '/install/setup-code.txt';
$installed = $hasConfig && is_installed();
if (!$installed && empty($_SESSION['install_verified'])) {
    if (!is_file($codeFile)) {
        @file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(4))) . "\n");
    }
    $expected = is_file($codeFile) ? strtoupper(trim((string)file_get_contents($codeFile))) : '';
    if (is_post() && isset($_POST['setup_code'])) {
        verify_csrf();
        $given = strtoupper(trim((string)$_POST['setup_code']));
        if (strlen($expected) >= 6 && hash_equals($expected, $given)) {
            session_regenerate_id(true);
            $_SESSION['install_verified'] = true;
            redirect('install.php');
        }
        usleep(500000); // slow down guessing
        $errors[] = 'That setup code isn\'t right.';
    }
}

if ($installed) {
    $step = 'done';
    @unlink($codeFile);
} elseif (empty($_SESSION['install_verified'])) {
    $step = 'code';
} elseif (!$hasConfig) {
    $step = 'database';
    $db = [
        'host' => trim((string)($_POST['host'] ?? 'localhost')),
        'port' => trim((string)($_POST['port'] ?? '3306')),
        'name' => trim((string)($_POST['name'] ?? '')),
        'user' => trim((string)($_POST['user'] ?? '')),
        'pass' => (string)($_POST['pass'] ?? ''),
    ];
    if (is_post()) {
        verify_csrf();
        if ($db['name'] === '' || $db['user'] === '') {
            $errors[] = 'Database name and user are required.';
        } elseif (!ctype_digit($db['port'])) {
            $errors[] = 'Port must be a number.';
        } else {
            try {
                new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
                    $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $source = build_config($db);
                if (@file_put_contents($configFile, $source, LOCK_EX) !== false) {
                    @chmod($configFile, 0640);
                    redirect('install.php');
                }
                $manualConfig = $source;
            } catch (PDOException $e) {
                $errors[] = 'Could not connect to the database: ' . $e->getMessage();
            }
        }
    }
} else {
    $step = 'admin';
    try {
        db();
    } catch (PDOException $e) {
        $step = 'db_error';
        $errors[] = $e->getMessage();
    }
    $admin = [
        'name'  => trim((string)($_POST['admin_name'] ?? '')),
        'email' => trim((string)($_POST['admin_email'] ?? '')),
        'demo'  => !empty($_POST['demo']),
    ];
    if ($step === 'admin' && is_post()) {
        verify_csrf();
        $password = (string)($_POST['admin_password'] ?? '');
        if ($admin['name'] === '') {
            $errors[] = 'Your name is required.';
        }
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($password !== ($_POST['admin_password_confirm'] ?? '')) {
            $errors[] = 'Passwords do not match.';
        }
        if (!$errors) {
            install_schema();
            // Re-check under the installed schema in case someone else finished first.
            if (is_installed()) {
                redirect('install.php');
            }
            create_admin($admin['name'], $admin['email'], $password);
            if ($admin['demo']) {
                require APP_ROOT . '/install/demo_data.php';
                ob_start();
                seed_demo_data();
                ob_end_clean();
            }
            @unlink($codeFile);
            unset($_SESSION['install_verified']);
            $_SESSION['flash'] = ['message' => 'Installation complete. Sign in with the account you just created.', 'type' => 'success'];
            redirect(url('login'));
        }
    }
}
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install · <?= h(config('app_name')) ?></title>
<?= theme_head() ?>
</head>
<body>
<div class="auth-page">
<div class="auth-form">
<form class="login-card" method="post" action="install.php" autocomplete="off">
  <a class="brand" href="install.php"><span class="brand-mark"><?= icon('phone') ?></span><?= h(config('app_name')) ?> setup</a>
  <?php foreach ($errors as $error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endforeach; ?>
  <?= csrf_field() ?>

  <?php if ($step === 'code'): ?>
    <p class="muted">To prove you own this website, enter the setup code. In your hosting control panel, open <b>File Manager</b>, go to the CRM folder, then <code>install/setup-code.txt</code>, and copy the code inside.</p>
    <?php if (!is_file($codeFile)): ?><p class="text-warning">The installer couldn't create that file. Create <code>install/setup-code.txt</code> yourself, containing a code of at least 6 letters or numbers, then enter it here.</p><?php endif; ?>
    <label>Setup code<input name="setup_code" required autocomplete="off" spellcheck="false" autofocus></label>
    <button class="btn btn-primary btn-block">Continue</button>

  <?php elseif ($step === 'done'): ?>
    <div class="flash flash-success">The CRM is already installed.</div>
    <p class="muted">For extra safety you can delete <code>public/install.php</code> from your hosting. It is locked and does nothing now.</p>
    <a class="btn btn-primary btn-block" href="<?= h(url('login')) ?>">Go to sign in</a>

  <?php elseif ($step === 'database' && $manualConfig !== null): ?>
    <div class="flash flash-success">Database connection works.</div>
    <p>The installer can't write <code>config.php</code> because the folder isn't writable. Copy the text below into a new file called <code>config.php</code> in the CRM's top folder (next to <code>config.sample.php</code>), then reload this page.</p>
    <textarea rows="14" readonly class="code" data-select-all><?= h($manualConfig) ?></textarea>
    <a class="btn btn-primary btn-block" href="install.php">I've uploaded config.php, continue</a>

  <?php elseif ($step === 'database'): ?>
    <p class="muted">Step 1 of 2: enter the MySQL database you created in your hosting control panel (for example cPanel → <em>MySQL Databases</em>).</p>
    <label>Database host<input name="host" value="<?= h($db['host']) ?>" required></label>
    <label>Port<input name="port" value="<?= h($db['port']) ?>" inputmode="numeric" required></label>
    <label>Database name<input name="name" value="<?= h($db['name']) ?>" required placeholder="e.g. cpaneluser_crm"></label>
    <label>Database user<input name="user" value="<?= h($db['user']) ?>" required placeholder="e.g. cpaneluser_crm"></label>
    <label>Database password<input type="password" name="pass" value="<?= h($db['pass']) ?>"></label>
    <button class="btn btn-primary btn-block">Test connection &amp; continue</button>

  <?php elseif ($step === 'db_error'): ?>
    <p><code>config.php</code> exists, but the CRM can't connect to the database with it. Fix the details in <code>config.php</code> using your hosting file manager, then reload this page.</p>
    <a class="btn btn-primary btn-block" href="install.php">Try again</a>

  <?php else: ?>
    <p class="muted">Step 2 of 2: create your administrator account.</p>
    <label>Your name<input name="admin_name" value="<?= h($admin['name']) ?>" required></label>
    <label>Email<input type="email" name="admin_email" value="<?= h($admin['email']) ?>" required></label>
    <label>Password<input type="password" name="admin_password" required minlength="8" autocomplete="new-password"></label>
    <label>Confirm password<input type="password" name="admin_password_confirm" required minlength="8" autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="demo" value="1" <?= $admin['demo'] ? 'checked' : '' ?>> Load demo data (sample customers, lines, tickets, deals)</label>
    <button class="btn btn-primary btn-block">Install</button>
  <?php endif; ?>
</form>
</div>
<?php render('_auth_panel'); ?>
</div>
</body>
</html>
