<?php
/** Build English-only ZIPs and exercise a disposable installed-Moodle database lifecycle. */
declare(strict_types=1);
require dirname(__DIR__) . '/scripts/runtime-images.php';
$mode = $argv[1] ?? '';
if (!in_array($mode, ['pg', 'mysql'], true)) throw new InvalidArgumentException('Expected pg or mysql and a fresh external work directory');
$root = dirname(__DIR__);
$requested = $argv[2] ?? '';
if (!$requested || !str_starts_with($requested, '/') || is_link($requested)) throw new RuntimeException('Use an absolute external work directory');
if (!is_dir($requested) && !mkdir($requested, 0700, true)) throw new RuntimeException('Cannot create work directory');
$work = realpath($requested);
if (!$work || $work === $root || str_starts_with($work, $root . '/') || iterator_count(new FilesystemIterator($work)) !== 0) {
    throw new RuntimeException('Work directory must be empty and outside the checkout');
}
chmod($work, 0700);
$id = 'videoq-matrix-' . bin2hex(random_bytes(6));
file_put_contents($work . '/.videoq-matrix-owned', $id);
$images = runtime_images();
$pins = json_decode(file_get_contents($root . '/.github/moodle-matrix.json'), true, 32, JSON_THROW_ON_ERROR);
foreach (['moodleRevision', 'baselineRevision'] as $key) {
    if (!preg_match('/^[a-f0-9]{40}$/D', $pins[$key])) throw new RuntimeException('Expected pinned source commits');
}
if ($pins['moodleRepository'] !== 'https://github.com/moodle/moodle.git'
        || $pins['baselineRepository'] !== 'https://github.com/videoq-jp/videoq-moodle.git') {
    throw new RuntimeException('Unexpected source repositories');
}
$docker = getenv('MATRIX_DOCKER_SUDO') === '1' ? ['sudo', '-n', 'docker'] : ['docker'];
function matrix_run(array $args, string $log, bool $required = true): int {
    $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'],
        2 => ['file', $log . '.stderr', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch fixture command');
    $code = proc_close($process);
    if ($required && $code !== 0) throw new RuntimeException('Fixture command failed: ' . basename($log));
    return $code;
}
function matrix_diagnostics(string $log): void {
    $output = file_get_contents($log) . file_get_contents($log . '.stderr');
    if (preg_match('/Warning:|Deprecated:|Notice:|Debug info:|Stack trace:|!!!|XMLDB has detected/i', $output)) {
        throw new RuntimeException('Developer diagnostics found: ' . basename($log));
    }
}
function matrix_source(string $repo, string $revision, string $target, ?string $cache, string $work, string $label): void {
    if ($cache) {
        // Archive the committed object; local modifications are never copied.
        matrix_run(['git', '-C', $cache, 'archive', '--format=tar', '--output=' . $work . '/' . $label . '.tar', $revision], $work . '/' . $label . '-archive.log');
        mkdir($target, 0700, true);
        matrix_run(['tar', '-xf', $work . '/' . $label . '.tar', '-C', $target], $work . '/' . $label . '-extract.log');
        unlink($work . '/' . $label . '.tar');
        return;
    }
    matrix_run(['git', 'init', $target], $work . '/' . $label . '-git-init.log');
    matrix_run(['git', '-C', $target, 'fetch', '--depth=1', $repo, $revision], $work . '/' . $label . '-git-fetch.log');
    matrix_run(['git', '-C', $target, 'checkout', '--detach', 'FETCH_HEAD'], $work . '/' . $label . '-git-checkout.log');
    matrix_run(['git', '-C', $target, 'rev-parse', 'HEAD'], $work . '/' . $label . '-revision.log');
    if (trim(file_get_contents($work . '/' . $label . '-revision.log')) !== $revision) throw new RuntimeException('Source revision mismatch');
}
function matrix_stage(string $source, string $site, ?string $retire): void {
    foreach (['local' => 'local', 'ltisource' => 'mod/lti/source'] as $type => $relative) {
        $target = $site . '/public/' . $relative . '/videoq';
        if (is_link($target)) throw new RuntimeException('Unexpected plugin symlink');
        if (is_dir($target)) {
            if (!$retire) throw new RuntimeException('Expected empty plugin location');
            if (!is_dir($retire)) mkdir($retire, 0700, true);
            if (!rename($target, $retire . '/' . $type)) throw new RuntimeException('Cannot retire fixture code');
        }
        $zip = new ZipArchive();
        if ($zip->open($source . '/' . $type . '_videoq.zip') !== true || !$zip->extractTo(dirname($target))) {
            throw new RuntimeException('Cannot stage component ZIP');
        }
        $zip->close();
    }
}
$network = $id;
$database = $id . '-db';
$networkcreated = false;
try {
    echo "$mode: prepare pinned Moodle and actual old-main baseline\n";
    matrix_source($pins['moodleRepository'], $pins['moodleRevision'], $work . '/site', getenv('MATRIX_MOODLE_GIT_SOURCE') ?: null, $work, 'moodle');
    matrix_source($pins['baselineRepository'], $pins['baselineRevision'], $work . '/baseline', getenv('MATRIX_BASELINE_GIT_SOURCE') ?: null, $work, 'baseline');
    mkdir($work . '/data', 0700);
    mkdir($work . '/archives', 0700);
    mkdir($work . '/baseline-archives', 0700);
    $builder = [...$docker, 'run', '--rm', '--user', posix_geteuid() . ':' . posix_getegid(),
        '--network', 'none', '--entrypoint', 'php', '-v', $root . ':/src:ro', '-v', $work . ':/audit', $images['php']];
    matrix_run([...$builder, '/src/scripts/package-plugins.php', '/audit/archives', '--marketplace'], $work . '/candidate-packages.log');
    matrix_run([...$builder, '/audit/baseline/scripts/package-plugins.php', '/audit/baseline-archives'], $work . '/baseline-packages.log');
    // Only deterministic synthetic fixture bytes, never teaching/learner data.
    file_put_contents($work . '/source-fixture.mp4', "VideoQ isolated byte-preservation fixture\n");
    $password = bin2hex(random_bytes(24));
    $env = 'POSTGRES_DB=moodleaudit_upgrade' . "\nPOSTGRES_USER=moodleaudit\nPOSTGRES_PASSWORD=$password\n"
        . "MYSQL_DATABASE=moodleaudit_upgrade\nMYSQL_USER=moodleaudit\nMYSQL_PASSWORD=$password\n"
        . 'MYSQL_ROOT_PASSWORD=' . bin2hex(random_bytes(24)) . "\nMATRIX_DB_PASSWORD=$password\n"
        . 'AUDIT_ADMIN_PASSWORD=Aa1!' . bin2hex(random_bytes(20)) . "\n";
    file_put_contents($work . '/database.env', $env); chmod($work . '/database.env', 0600);
    $dbtype = $mode === 'pg' ? 'pgsql' : 'mysqli';
    $config = <<<'CONFIG'
<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'DBTYPE';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'audit-db';
$CFG->dbname = 'moodleaudit_upgrade';
$CFG->dbuser = 'moodleaudit';
$CFG->dbpass = getenv('MATRIX_DB_PASSWORD');
$CFG->prefix = 'mdl_';
$CFG->dboptions = ['dbpersist' => false, 'dbport' => '', 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
$CFG->wwwroot = 'https://marketplace-audit.invalid';
$CFG->dataroot = '/audit/data';
$CFG->admin = 'admin';
$CFG->directorypermissions = 0700;
$CFG->debug = 32767;
$CFG->debugdisplay = true;
$CFG->pathtophp = '/usr/local/bin/php';
$CFG->disableupdatenotifications = true;
require_once(__DIR__ . '/lib/setup.php');
CONFIG;
    file_put_contents($work . '/site/config.php', str_replace('DBTYPE', $dbtype, $config));
    matrix_stage($work . '/baseline-archives', $work . '/site', null);
    matrix_run([...$docker, 'network', 'create', '--internal', '--label', 'videoq.marketplace.matrix=' . $id, $network], $work . '/network-create.log');
    $networkcreated = true;
    $dbargs = $mode === 'pg' ? ['postgres', '-c', 'shared_buffers=16MB', '-c', 'max_connections=20']
        : ['--innodb-buffer-pool-size=64M', '--innodb-log-buffer-size=8M', '--max-connections=20', '--performance-schema=OFF',
            '--mysqlx=OFF', '--skip-log-bin', '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci'];
    matrix_run([...$docker, 'run', '-d', '--name', $database, '--label', 'videoq.marketplace.matrix=' . $id,
        '--network', $network, '--network-alias', 'audit-db', '--env-file', $work . '/database.env', '--memory', '512m', '--cpus', '1',
        $images[$mode === 'pg' ? 'db' : 'mysql'], ...$dbargs], $work . '/database-start.log');
    $ready = false;
    for ($attempt = 0; $attempt < 120; $attempt++) {
        // Database entrypoints use socket-only temporary servers during init.
        // Wait for TCP, as the separate Moodle container cannot use that socket.
        $probe = $mode === 'pg' ? ['psql', '-h', '127.0.0.1', '-U', 'moodleaudit', '-d', 'moodleaudit_upgrade', '-Atc', 'SELECT 1']
            : ['sh', '-c', 'MYSQL_PWD="$MYSQL_PASSWORD" mysql --protocol=TCP --host=127.0.0.1 -u "$MYSQL_USER" "$MYSQL_DATABASE" -Nse "SELECT 1"'];
        if (matrix_run([...$docker, 'exec', $database, ...$probe], $work . '/database-ready.log', false) === 0) { $ready = true; break; }
        usleep(500000);
    }
    if (!$ready) throw new RuntimeException('Database readiness timed out');
    $php = [...$docker, 'run', '--rm', '--user', posix_geteuid() . ':' . posix_getegid(), '--network', $network,
        '--env-file', $work . '/database.env', '--memory', '512m', '--cpus', '1', '--entrypoint', 'php',
        '-v', $work . ':/audit', '-v', $work . '/site:/site:ro', '-v', $root . ':/src:ro', $images['php'],
        '-d', 'max_input_vars=5000', '-d', 'memory_limit=256M'];
    $reports = [];
    $check = static function(string $name, array $args) use ($php, $work, &$reports): void {
        $log = $work . '/' . $name . '.json';
        matrix_run([...$php, ...$args], $log);
        if (trim(file_get_contents($log . '.stderr')) !== '') throw new RuntimeException('Unexpected check diagnostics');
        $report = json_decode(file_get_contents($log), true, 64, JSON_THROW_ON_ERROR);
        if (($report['passed'] ?? false) !== true) throw new RuntimeException('Runtime check failed: ' . $name);
        $reports[$name] = $report;
    };
    echo "$mode: install old-main and seed teaching data\n";
    matrix_run([...$php, '/src/lab/install-marketplace-fixture.php'], $work . '/baseline-install.log');
    $check('baseline-seed', ['/src/lab/check-marketplace-upgrade.php', '/site', 'seed']);
    echo "$mode: update to current English-only submission ZIPs\n";
    matrix_stage($work . '/archives', $work . '/site', $work . '/retired-baseline');
    matrix_run([...$php, '/site/admin/cli/upgrade.php', '--non-interactive'], $work . '/candidate-upgrade.log');
    matrix_diagnostics($work . '/candidate-upgrade.log');
    $check('upgraded-preservation', ['/src/lab/check-marketplace-upgrade.php', '/site', 'verify']);
    $check('upgraded-runtime', ['/src/lab/check-marketplace-runtime.php', '/site']);
    foreach (['defaults', 'revoked', 'restored'] as $phase) {
        $check('upgraded-capabilities-' . $phase, ['/src/lab/check-marketplace-capabilities.php', '/site', $phase]);
    }
    $check('privacy-requests', ['/src/lab/check-marketplace-privacy.php', '/site']);
    echo "$mode: uninstall companion before local; preserve course and source bytes\n";
    foreach (['ltisource_videoq' => 'mod/lti/source', 'local_videoq' => 'local'] as $component => $relative) {
        $log = $work . '/uninstall-' . $component . '.log';
        matrix_run([...$php, '/site/admin/cli/uninstall_plugins.php', '--plugins=' . $component, '--run'], $log);
        matrix_diagnostics($log);
        rename($work . '/site/public/' . $relative . '/videoq', $work . '/retired-' . $component);
    }
    $check('uninstalled-runtime', ['/src/lab/check-marketplace-runtime.php', '/site', 'uninstalled']);
    $check('uninstalled-preservation', ['/src/lab/check-marketplace-upgrade.php', '/site', 'verify-uninstalled']);
    echo "$mode: reinstall current submission ZIPs and verify fresh state\n";
    matrix_stage($work . '/archives', $work . '/site', null);
    matrix_run([...$php, '/site/admin/cli/upgrade.php', '--non-interactive'], $work . '/candidate-reinstall.log');
    matrix_diagnostics($work . '/candidate-reinstall.log');
    $check('reinstalled-runtime', ['/src/lab/check-marketplace-runtime.php', '/site']);
    foreach (['defaults', 'revoked', 'restored'] as $phase) {
        $check('reinstalled-capabilities-' . $phase, ['/src/lab/check-marketplace-capabilities.php', '/site', $phase]);
    }
    $check('reinstalled-preservation', ['/src/lab/check-marketplace-upgrade.php', '/site', 'verify-reinstalled']);
    $hashes = [];
    foreach (['local_videoq.zip', 'ltisource_videoq.zip'] as $file) $hashes[$file] = hash_file('sha256', $work . '/archives/' . $file);
    $summary = ['passed' => true, 'database' => $mode, 'moodleRevision' => $pins['moodleRevision'],
        'baselineRevision' => $pins['baselineRevision'], 'phpImage' => $images['php'],
        'databaseImage' => $images[$mode === 'pg' ? 'db' : 'mysql'], 'archiveSha256' => $hashes,
        'candidateDeveloperDiagnostics' => 0, 'reports' => $reports,
        'limits' => ['Old-main baseline may emit known XMLDB empty-default repair diagnostics.',
            'Synthetic source bytes test preservation, not video playback/decoding.',
            'No browser launch, paid AI, external data request or Marketplace approval exercised.']];
    file_put_contents($work . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo "$mode: full installed-Moodle lifecycle passed\n";
} finally {
    // Touch only resources carrying this invocation's unpredictable ownership label.
    if (matrix_run([...$docker, 'inspect', '--format', '{{index .Config.Labels "videoq.marketplace.matrix"}}', $database], $work . '/cleanup-owner.log', false) === 0
            && trim(file_get_contents($work . '/cleanup-owner.log')) === $id) {
        matrix_run([...$docker, 'rm', '--force', $database], $work . '/cleanup-database.log', false);
    }
    if ($networkcreated
            && matrix_run([...$docker, 'network', 'inspect', '--format', '{{index .Labels "videoq.marketplace.matrix"}}', $network], $work . '/cleanup-network-owner.log', false) === 0
            && trim(file_get_contents($work . '/cleanup-network-owner.log')) === $id) {
        matrix_run([...$docker, 'network', 'rm', $network], $work . '/cleanup-network.log', false);
    }
}
