<?php
/** Render real plugin and pinned Moodle message templates for an isolated browser fixture. */
declare(strict_types=1);
define('MOODLE_INTERNAL', true);
interface renderable {}
interface templatable { public function export_for_template(renderer_base $output); }
final class moodle_url {
    public function __construct(private string $path, array $params = []) {
        if ($params) $this->path .= '?' . http_build_query($params);
    }
    public function out(bool $escaped = true): string { return $this->path; }
}
class renderer_base { public function image_url(string $name, string $component): moodle_url { return new moodle_url('/logo.svg'); } }
function current_language(): string { return 'en'; }
function get_config(string $component, string $name): bool { return false; }
function userdate(int $time, string $format): string { return date('H:i', $time); }
function get_string(string $id, string $component): string {
    global $nativefixturestrings;
    return $nativefixturestrings[$id] ?? ['writeamessage' => 'Write a message', 'sendmessage' => 'Send message'][$id] ?? $id;
}
$plugin = dirname(__DIR__, 2) . '/plugins/local/videoq';
$string = []; require $plugin . '/lang/en/local_videoq.php'; $nativefixturestrings = $string;
require $plugin . '/classes/ui_strings.php';
require $plugin . '/classes/output/workspace.php';
$core = rtrim((string)getenv('MOODLE_SOURCE'), '/');
if (is_dir($core . '/public')) $core .= '/public';
if (!is_file($core . '/lib/mustache/src/Mustache/Engine.php')) throw new RuntimeException('Pinned Moodle template source is required');
spl_autoload_register(static function(string $class) use ($core): void {
    if (str_starts_with($class, 'Mustache_')) require $core . '/lib/mustache/src/' . str_replace('_', '/', $class) . '.php';
});
final class NativeFixtureLoader implements Mustache_Loader {
    public function __construct(private string $plugin, private string $core) {}
    public function load($name) {
        if (!preg_match('~^(local_videoq|core_message|core)/([a-z0-9_/]+)$~D', $name, $match)) throw new RuntimeException('Invalid fixture partial');
        $base = match ($match[1]) { 'local_videoq' => $this->plugin . '/templates', 'core_message' => $this->core . '/message/templates', 'core' => $this->core . '/lib/templates' };
        return file_get_contents($base . '/' . $match[2] . '.mustache');
    }
}
$catalog = !empty($_GET['empty']) ? [] : [['lesson' => 'sync_11', 'title' => 'Owned Moodle File', 'kind' => 'file',
    'mime' => 'video/mp4', 'url' => '/fixture.mp4', 'activityUrl' => '/mod/resource/view.php?id=11', 'sourceGeneration' => 1]];
$page = new \local_videoq\output\workspace(42, $catalog, $catalog[0]['lesson'] ?? '', [], true);
$data = $page->export_for_template(new renderer_base());
$engine = new Mustache_Engine(['loader' => new NativeFixtureLoader($plugin, $core), 'helpers' => [
    'str' => static function($text, $helper): string { $args = array_map('trim', explode(',', $helper->render($text))); return get_string($args[0], $args[1] ?? 'core'); },
    'pix' => static fn($text, $helper) => '<span aria-hidden="true">•</span>',
]]);
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self' 'unsafe-inline'; connect-src 'self' blob:; frame-src 'none'; img-src 'self' data:");
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/styles.css">';
echo '<style>body{font:16px system-ui;margin:16px}.hidden{display:none}.d-flex{display:flex}.flex-column{flex-direction:column}.p-3{padding:1rem}.card{border:1px solid #ccc}.btn{padding:.4rem .7rem;margin:.2rem}textarea{width:90%}video{width:100%}.message{margin:1rem;background:#eee;padding:.5rem}.send{background:#def}</style>';
echo $engine->render('local_videoq/workspace', $data);
echo '<script>window.nativeFixtureConfig=' . json_encode(['copy' => \local_videoq\ui_strings::data(), 'empty' => !$catalog], JSON_HEX_TAG | JSON_THROW_ON_ERROR) . ';</script>';
echo '<script src="/fixture.js"></script><script src="/player.js"></script><script src="/learning.js"></script><script defer src="/checks.js"></script>';
