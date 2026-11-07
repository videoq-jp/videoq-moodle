<?php
/** Pure source identity and current binding contracts; no provider network access. */
declare(strict_types=1);
namespace local_videoq {
    final class synchronizer {
        public static function video_file(int $cmid): ?object { return $cmid === 10 ? (object)['fixture' => true] : null; }
    }
}
namespace {
    define('IGNORE_MISSING', 0);
    $version = 2026110704;
    $modules = [10 => (object)['id' => 10, 'modname' => 'resource', 'course' => 42, 'instance' => 101, 'deletioninprogress' => 0],
        20 => (object)['id' => 20, 'modname' => 'url', 'course' => 42, 'instance' => 201, 'deletioninprogress' => 0],
        21 => (object)['id' => 21, 'modname' => 'url', 'course' => 43, 'instance' => 202, 'deletioninprogress' => 1],
        22 => (object)['id' => 22, 'modname' => 'page', 'course' => 42, 'instance' => 203, 'deletioninprogress' => 0]];
    function get_config(string $component, string $key): int { global $version; return $version; }
    function get_coursemodule_from_id(string $module, int $id, int $course, bool $section, int $strict): mixed {
        global $modules;
        $cm = $modules[$id] ?? null;
        return $cm && (!$module || $cm->modname === $module) ? clone $cm : false;
    }
    final class FixtureDb {
        public string $url = 'https://www.youtube.com/watch?v=abcDEF123_-';
        public bool $bound = false;
        public function get_field(string $table, string $column, array $where): string {
            check($table === 'url' && $column === 'externalurl' && $where === ['id' => 201, 'course' => 42], 'Source lookup widened');
            return $this->url;
        }
        public function get_record(string $table, array $where): mixed {
            check($table === 'local_videoq_sync' && $where === ['cmid' => 20], 'Binding lookup widened');
            return $this->bound ? (object)['sourcekind' => 'youtube'] : false;
        }
    }
    function check(bool $valid, string $label): void { if (!$valid) throw new RuntimeException($label); }
    $DB = new FixtureDb();
    require dirname(__DIR__) . '/plugins/local/videoq/classes/source.php';
    use local_videoq\source as Source;
    $id = 'abcDEF123_-';
    $valid = ['https://www.youtube.com/watch?v=' . $id, 'https://youtube.com/watch?feature=share&v=' . $id . '&list=ignored',
        'https://m.youtube.com/shorts/' . $id, 'https://youtube.com/embed/' . $id . '/',
        'https://youtu.be/' . $id . '?t=10', 'http://www.youtu.be/' . $id, 'https://WWW.YOUTUBE.COM/watch?v=' . $id];
    foreach ($valid as $url) check(Source::youtube_id($url) === $id, 'Supported URL not recognized');
    $invalid = ['', '//youtube.com/watch?v=' . $id, 'file:///watch?v=' . $id, 'https://evil.invalid/watch?v=' . $id,
        'https://youtube.com.evil.invalid/watch?v=' . $id, 'https://youtube.com@evil.invalid/watch?v=' . $id,
        'https://viewer@youtube.com/watch?v=' . $id, 'https://youtube.com:443/watch?v=' . $id,
        'https://youtube.com/playlist?list=PLfixture', 'https://youtu.be/' . $id . '/extra', 'https://youtube.com/watch?v=short',
        'https://youtube.com/watch?v=' . $id . '&%76=' . $id, 'https://youtube.com/watch?v=' . $id . '&v=xyzUVW987_-',
        'https://youtube.com/embed/' . $id . '?v=' . $id, 'https://youtube.com/watch?v=-----------',
        'https://youtube.com/watch?v=___________', 'https://youtube.com/watch?v=' . $id . "\n&x=y",
        'https://youtube.com\\@evil.invalid/watch?v=' . $id, str_repeat('a', 2049)];
    foreach ($invalid as $url) check(Source::youtube_id($url) === null, 'Ambiguous or unsafe source accepted');
    check(Source::read(10)['kind'] === 'file', 'Existing File descriptor changed');
    check(Source::read(20) === ['kind' => 'youtube', 'youtubeVideoId' => $id], 'Current provider identity missing');
    $binding = ['sourceKind' => 'youtube', 'youtubeVideoId' => $id];
    check(!Source::matches($modules[20], $binding), 'Old caller received a new source');
    check(Source::matches($modules[20], $binding, true), 'Opted-in current binding denied');
    check(!Source::matches($modules[20], $binding + ['extra' => true], false), 'Missing negotiation accepted');
    $DB->url = 'https://youtu.be/xyzUVW987_-';
    check(!Source::matches($modules[20], $binding, true), 'URL edit kept the old provider grant');
    $DB->url = 'https://unrelated.invalid/'; $DB->bound = true;
    check(Source::read(20) === null && Source::supports($modules[20]), 'Invalidated binding cannot be disabled');
    $DB->bound = false;
    check(!Source::supports($modules[20]) && Source::read(21) === null && Source::read(22) === null, 'Unbound or deleted activity admitted');
    $version = 2026110703;
    check(!Source::youtube_available() && Source::read(20) === null && !Source::supports($modules[20]), 'Pre-upgrade source admitted');
    check(Source::view_capability($modules[20]) === 'mod/url:view' && Source::view_capability($modules[10]) === 'mod/resource:view',
        'Wrong core view capability');
    echo "YouTube source: strict IDs, current canonical URL, negotiation, source revision, disabled recovery and schema guard passed.\n";
}
