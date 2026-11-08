<?php
declare(strict_types=1);
require dirname(__DIR__) . '/scripts/runtime-images.php';
function policy(bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); }
$root = dirname(__DIR__);
$images = runtime_images();
policy(count($images) === 4, 'Incomplete dependency coverage');
$original = file_get_contents($root . '/lab/images/compose.yaml');
$temporary = tempnam(sys_get_temp_dir(), 'videoq-images-');
try {
    file_put_contents($temporary, preg_replace('/  mysql:\R    image: [^\r\n]+\R/', '', $original));
    policy(count(runtime_images($temporary)) === 3, 'Historical release image manifests must remain supported');
    foreach ([preg_replace('/@sha256:[a-f0-9]{64}/', '', $original, 1), str_replace(':8.3@', ':9.0@', $original),
            $original . "  unknown:\n    image: example:1@sha256:" . str_repeat('a',64) . "\n"] as $invalid) {
        file_put_contents($temporary, $invalid);
        $rejected = false; try { runtime_images($temporary); } catch (RuntimeException) { $rejected = true; }
        policy($rejected, 'Invalid runtime manifest accepted');
    }
} finally { unlink($temporary); }
foreach (['lab/compose.yaml', 'lab/compose.restore.yaml'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    policy(!preg_match('/^\s+image:/m', $source) && str_contains($source, 'file: images/compose.yaml'), 'Image pins diverged from the canonical manifest');
}
$workflow = file_get_contents($root . '/.github/workflows/ci.yml');
policy(!str_contains($workflow, 'PHP_IMAGE:') && str_contains($workflow, 'php scripts/runtime-images.php php'), 'CI bypasses canonical images');
policy(str_contains($workflow, 'merge_group:') && str_contains($workflow, 'schedule:') && substr_count($workflow, 'timeout-minutes:') === 4, 'Missing maintenance/merge gates');
policy(str_contains($workflow, 'needs: [tooling, database]') && str_contains($workflow, 'if: always()')
    && str_contains($workflow, 'database: [pg, mysql]'), 'Required verification must include both installed database lifecycles');
policy(str_contains($workflow, 'cancel-in-progress: false') && str_contains($workflow, 'GH_TOKEN: ${{ github.token }}'), 'Release safety contract changed');
policy(!str_contains($workflow, 'pull_request_target:'), 'Untrusted PR privilege escalation');
$rules = json_decode(file_get_contents($root . '/.github/main-ruleset.json'), true, 32, JSON_THROW_ON_ERROR);
policy($rules['enforcement'] === 'active' && $rules['bypass_actors'] === [] && in_array('refs/heads/main', $rules['conditions']['ref_name']['include'], true), 'Main protection scope');
$checks = array_values(array_filter($rules['rules'], fn($rule) => $rule['type'] === 'required_status_checks'))[0]['parameters'];
policy($checks['required_status_checks'] === [['context' => 'PHP-only tooling and native browser checks', 'integration_id' => 15368]], 'Required CI context does not match the actual job');
$config = file_get_contents($root . '/.github/dependabot.yml');
policy(str_contains($config, 'package-ecosystem: docker-compose') && str_contains($config, 'directory: /lab/images'), 'Dependabot cannot reach the manifest');
echo "Immutable image channels, shared Compose/CI dependencies, release safety and main protection contracts passed.\n";
