<?php declare(strict_types=1);

/**
 * Kreblu Browser Installer
 *
 * Guided setup wizard that runs when kb-config.php doesn't exist.
 * Steps: system check → database config → site info → admin account → install → done
 */

// Prevent access if already installed
if (file_exists(dirname(__DIR__) . '/kb-config.php')) {
    header('Location: /');
    exit;
}

$step = $_GET['step'] ?? '1';
$error = '';
$success = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '1';

    if ($step === '2') {
        // Database configuration submitted
        $dbHost   = trim($_POST['db_host'] ?? 'localhost');
        $dbPort   = (int) ($_POST['db_port'] ?? 3306);
        $dbName   = trim($_POST['db_name'] ?? '');
        $dbUser   = trim($_POST['db_user'] ?? '');
        $dbPass   = $_POST['db_pass'] ?? '';
        $dbPrefix = trim($_POST['db_prefix'] ?? 'kb_');

        if ($dbName === '' || $dbUser === '') {
            $error = 'Database name and username are required.';
            $step = '2';
        } else {
            // Test connection
            try {
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo = null;

                // Store in session for next step
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                $_SESSION['kb_install'] = [
                    'db_host'   => $dbHost,
                    'db_port'   => $dbPort,
                    'db_name'   => $dbName,
                    'db_user'   => $dbUser,
                    'db_pass'   => $dbPass,
                    'db_prefix' => $dbPrefix,
                ];
                $step = '3';
            } catch (PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
                $step = '2';
            }
        }
    } elseif ($step === '3') {
        // Site info submitted
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['kb_install']['site_name'] = trim($_POST['site_name'] ?? 'My Kreblu Site');
        $_SESSION['kb_install']['site_url']  = rtrim(trim($_POST['site_url'] ?? ''), '/');
        $step = '4';
    } elseif ($step === '4') {
        // Admin account submitted
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $adminUser  = trim($_POST['admin_user'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass  = $_POST['admin_pass'] ?? '';

        if ($adminUser === '' || $adminEmail === '' || strlen($adminPass) < 8) {
            $error = 'All fields required. Password must be at least 8 characters.';
            $step = '4';
        } else {
            $_SESSION['kb_install']['admin_user']  = $adminUser;
            $_SESSION['kb_install']['admin_email'] = $adminEmail;
            $_SESSION['kb_install']['admin_pass']  = $adminPass;

            // Run the actual installation
            try {
                $config = $_SESSION['kb_install'];
                $result = runInstallation($config);

                if ($result === true) {
                    $step = '5';
                    session_destroy();
                } else {
                    $error = $result;
                    $step = '4';
                }
            } catch (Throwable $e) {
                $error = 'Installation failed: ' . $e->getMessage();
                $step = '4';
            }
        }
    }
}

/**
 * Run the full installation process.
 *
 * @return true|string True on success, error message on failure
 */
function runInstallation(array $config): true|string
{
    $root = dirname(__DIR__);

    // 1. Generate auth salt
    $authSalt = bin2hex(random_bytes(32));

    // 2. Write kb-config.php
    $configContent = '<?php' . "\n"
        . '// Kreblu Configuration - Generated ' . date('Y-m-d H:i:s') . "\n"
        . "define('KREBLU_DB_HOST', " . var_export($config['db_host'], true) . ");\n"
        . "define('KREBLU_DB_PORT', " . var_export($config['db_port'], true) . ");\n"
        . "define('KREBLU_DB_NAME', " . var_export($config['db_name'], true) . ");\n"
        . "define('KREBLU_DB_USER', " . var_export($config['db_user'], true) . ");\n"
        . "define('KREBLU_DB_PASS', " . var_export($config['db_pass'], true) . ");\n"
        . "define('KREBLU_DB_PREFIX', " . var_export($config['db_prefix'], true) . ");\n"
        . "define('KREBLU_SITE_URL', " . var_export($config['site_url'], true) . ");\n"
        . "define('KREBLU_SITE_NAME', " . var_export($config['site_name'], true) . ");\n"
        . "define('KREBLU_AUTH_SALT', " . var_export($authSalt, true) . ");\n"
        . "define('KREBLU_DEBUG', false);\n";

    if (!@file_put_contents($root . '/kb-config.php', $configContent)) {
        return 'Could not write kb-config.php. Check file permissions.';
    }

    // 3. Load the config we just wrote
    require $root . '/kb-config.php';

    // 4. Connect to database and run migrations
    require_once $root . '/vendor/autoload.php';
    require_once $root . '/kb-core/autoload.php';

    $db = new Kreblu\Core\Database\Connection(
        host: $config['db_host'],
        port: $config['db_port'],
        name: $config['db_name'],
        user: $config['db_user'],
        pass: $config['db_pass'],
        prefix: $config['db_prefix'],
    );

    $migrationsPath = $root . '/kb-core/Database/migrations';
    $schema = new Kreblu\Core\Database\Schema($db, $migrationsPath);
    $schema->ensureMigrationsTable();
    $schema->runPending();

    // 5. Create admin user
    $auth = new Kreblu\Core\Auth\AuthManager($db);
    $users = new Kreblu\Core\Auth\UserManager($db, $auth);

    $users->create([
        'email'    => $config['admin_email'],
        'username' => $config['admin_user'],
        'password' => $config['admin_pass'],
        'role'     => 'admin',
    ]);

    // 6. Seed default options
    $defaults = [
        ['option_key' => 'site_name',        'option_value' => $config['site_name'], 'autoload' => 1],
        ['option_key' => 'site_url',         'option_value' => $config['site_url'],  'autoload' => 1],
        ['option_key' => 'site_description', 'option_value' => 'Just another Kreblu site', 'autoload' => 1],
        ['option_key' => 'active_template',  'option_value' => 'developer-default',  'autoload' => 1],
        ['option_key' => 'posts_per_page',   'option_value' => '10',                 'autoload' => 1],
        ['option_key' => 'date_format',      'option_value' => 'F j, Y',             'autoload' => 1],
        ['option_key' => 'permalink_structure', 'option_value' => '/{slug}',         'autoload' => 1],
        ['option_key' => 'kreblu_version',   'option_value' => '1.0.0-dev',          'autoload' => 1],
    ];

    foreach ($defaults as $opt) {
        $db->table('options')->insert($opt);
    }

    // 7. Create default "Hello World" post
    $posts = new Kreblu\Core\Content\PostManager($db);
    $adminId = $db->table('users')->where('role', '=', 'admin')->first()->id;

    $posts->create([
        'title'     => 'Hello World',
        'body'      => '<p>Welcome to Kreblu! This is your first post. Edit or delete it, then start writing.</p>',
        'author_id' => (int) $adminId,
        'type'      => 'post',
        'status'    => 'published',
    ]);

    // 8. Create default "About" page
    $posts->create([
        'title'     => 'About',
        'body'      => '<p>This is an example page. You can edit this in K Hub.</p>',
        'author_id' => (int) $adminId,
        'type'      => 'page',
        'status'    => 'published',
    ]);

    // 9. Create default category
    $taxonomy = new Kreblu\Core\Content\TaxonomyManager($db);
    $taxonomy->createTerm([
        'taxonomy' => 'category',
        'name'     => 'Uncategorized',
    ]);

    return true;
}

// System check function
function checkSystem(): array
{
    $checks = [];

    // PHP version
    $checks[] = [
        'name'   => 'PHP Version',
        'value'  => PHP_VERSION,
        'ok'     => version_compare(PHP_VERSION, '8.5.0', '>='),
        'needed' => '8.5.0+',
    ];

    // Required extensions
    $extensions = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo', 'gd', 'intl'];
    foreach ($extensions as $ext) {
        $checks[] = [
            'name'   => "ext-{$ext}",
            'value'  => extension_loaded($ext) ? 'Loaded' : 'Missing',
            'ok'     => extension_loaded($ext),
            'needed' => 'Required',
        ];
    }

    // Writable directories
    $root = dirname(__DIR__);
    $writablePaths = [
        $root => 'Project root (for kb-config.php)',
        $root . '/kb-content/cache' => 'Cache directory',
        $root . '/kb-content/uploads' => 'Uploads directory',
        $root . '/kb-content/logs' => 'Logs directory',
    ];

    foreach ($writablePaths as $path => $label) {
        $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
        $checks[] = [
            'name'   => $label,
            'value'  => $writable ? 'Writable' : 'Not writable',
            'ok'     => $writable,
            'needed' => 'Writable',
        ];
    }

    return $checks;
}

$allPassed = true;
if ($step === '1') {
    $checks = checkSystem();
    foreach ($checks as $check) {
        if (!$check['ok']) {
            $allPassed = false;
        }
    }
}

// Auto-detect site URL
$detectedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($step !== '1' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Kreblu</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .installer { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 600px; width: 100%; padding: 2.5rem; }
        .logo { text-align: center; margin-bottom: 2rem; }
        .logo h1 { font-size: 2rem; font-weight: 800; color: #1a1a2e; letter-spacing: -0.5px; }
        .logo p { color: #666; margin-top: 0.25rem; font-size: 0.95rem; }
        .steps { display: flex; gap: 0.5rem; margin-bottom: 2rem; justify-content: center; }
        .steps span { width: 2rem; height: 2rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600; background: #e8ecf1; color: #666; }
        .steps span.active { background: #1a1a2e; color: #fff; }
        .steps span.done { background: #22c55e; color: #fff; }
        h2 { font-size: 1.25rem; margin-bottom: 1rem; }
        .field { margin-bottom: 1rem; }
        .field label { display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.9rem; }
        .field input { width: 100%; padding: 0.6rem 0.75rem; border: 1.5px solid #d1d5db; border-radius: 6px; font-size: 0.95rem; transition: border-color 0.2s; }
        .field input:focus { outline: none; border-color: #1a1a2e; }
        .field small { color: #888; font-size: 0.8rem; }
        .btn { display: inline-block; padding: 0.7rem 1.5rem; background: #1a1a2e; color: #fff; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn:hover { background: #2d2d4e; }
        .btn:disabled { background: #999; cursor: not-allowed; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        td { padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; }
        td:last-child { text-align: right; }
        .ok { color: #22c55e; font-weight: 600; }
        .fail { color: #ef4444; font-weight: 600; }
        .actions { margin-top: 1.5rem; text-align: right; }
        .done-box { text-align: center; padding: 2rem 0; }
        .done-box .checkmark { font-size: 3rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="installer">
    <div class="logo">
        <h1>Kreblu</h1>
        <p>Installation Wizard</p>
    </div>

    <div class="steps">
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <span class="<?= $i < (int)$step ? 'done' : ($i === (int)$step ? 'active' : '') ?>"><?= $i ?></span>
        <?php endfor; ?>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step === '1'): ?>
        <h2>System Check</h2>
        <table>
            <?php foreach ($checks as $check): ?>
            <tr>
                <td><?= htmlspecialchars($check['name']) ?></td>
                <td class="<?= $check['ok'] ? 'ok' : 'fail' ?>"><?= htmlspecialchars($check['value']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="actions">
            <?php if ($allPassed): ?>
                <a href="?step=2" class="btn">Continue</a>
            <?php else: ?>
                <p style="color:#ef4444;font-size:0.9rem;">Please fix the issues above before continuing.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($step === '2'): ?>
        <h2>Database Configuration</h2>
        <form method="POST">
            <input type="hidden" name="step" value="2">
            <div class="field">
                <label>Database Host</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
            </div>
            <div class="field">
                <label>Database Port</label>
                <input type="number" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
            </div>
            <div class="field">
                <label>Database Name</label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Database Username</label>
                <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Database Password</label>
                <input type="password" name="db_pass" value="">
            </div>
            <div class="field">
                <label>Table Prefix</label>
                <input type="text" name="db_prefix" value="<?= htmlspecialchars($_POST['db_prefix'] ?? 'kb_') ?>">
                <small>Change if running multiple Kreblu installs on one database.</small>
            </div>
            <div class="actions">
                <button type="submit" class="btn">Test Connection & Continue</button>
            </div>
        </form>

    <?php elseif ($step === '3'): ?>
        <h2>Site Information</h2>
        <form method="POST">
            <input type="hidden" name="step" value="3">
            <div class="field">
                <label>Site Name</label>
                <input type="text" name="site_name" value="<?= htmlspecialchars($_SESSION['kb_install']['site_name'] ?? 'My Kreblu Site') ?>" required>
            </div>
            <div class="field">
                <label>Site URL</label>
                <input type="url" name="site_url" value="<?= htmlspecialchars($_SESSION['kb_install']['site_url'] ?? $detectedUrl) ?>" required>
                <small>The full URL where Kreblu will be accessible.</small>
            </div>
            <div class="actions">
                <button type="submit" class="btn">Continue</button>
            </div>
        </form>

    <?php elseif ($step === '4'): ?>
        <h2>Admin Account</h2>
        <form method="POST">
            <input type="hidden" name="step" value="4">
            <div class="field">
                <label>Username</label>
                <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required minlength="3">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="admin_pass" required minlength="8">
                <small>Minimum 8 characters.</small>
            </div>
            <div class="actions">
                <button type="submit" class="btn">Install Kreblu</button>
            </div>
        </form>

    <?php elseif ($step === '5'): ?>
        <div class="done-box">
            <div class="checkmark">&#10003;</div>
            <h2>Kreblu is installed!</h2>
            <p style="color:#666;margin:1rem 0;">Your site is ready. Log in to K Hub to start creating.</p>
            <a href="/kb-admin/" class="btn">Go to K Hub</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>