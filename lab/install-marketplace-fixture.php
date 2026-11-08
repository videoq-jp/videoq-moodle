<?php
/** Install only the CLI fixture prepared by the matrix runner; keep passwords out of orchestration arguments. */
declare(strict_types=1);
if (!is_file('/audit/.videoq-matrix-owned') || !getenv('AUDIT_ADMIN_PASSWORD')) {
    throw new RuntimeException('Use the owned matrix fixture');
}
$command = [PHP_BINARY, '-d', 'max_input_vars=5000', '-d', 'memory_limit=256M', '/site/admin/cli/install_database.php',
    '--agree-license', '--lang=en', '--adminuser=auditadmin', '--adminpass=' . getenv('AUDIT_ADMIN_PASSWORD'),
    '--adminemail=audit@example.invalid', '--fullname=VideoQ marketplace audit', '--shortname=VideoQAudit'];
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot launch the fixture installer');
exit(proc_close($process));
