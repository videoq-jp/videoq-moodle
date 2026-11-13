<?php
/** Owned launch links must work without a helper and cannot redirect unrelated activities or bypass permissions. */
declare(strict_types=1);
namespace local_videoq {
    final class course_entry {
        public static ?\stdClass $entry;
        public static bool $valid = true;
        public static function for_launch(int $id): ?\stdClass { return $id === 17 ? self::$entry : null; }
        public static function valid(\stdClass $row): bool { return self::$valid; }
    }
}
namespace {
    final class moodle_url {
        public function __construct(public string $path, public array $params) {}
    }
    final class context_module {
        public static function instance(int $id): int { return $id; }
    }
    $allowed = true; $checked = 0;
    function require_capability(string $name, int $context): void {
        global $allowed, $checked;
        if ($name !== 'mod/lti:view' || $context !== 31) throw new RuntimeException('Wrong permission check');
        $checked++;
        if (!$allowed) throw new RuntimeException('Denied');
    }
    function check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
    require dirname(__DIR__) . '/plugins/local/videoq/classes/native_navigation.php';
    use local_videoq\course_entry as Entry;
    use local_videoq\native_navigation as Navigation;
    Entry::$entry = (object)['enabled' => 1, 'lticmid' => 31, 'courseid' => 7];
    $cm = (object)['modname' => 'lti', 'id' => 31, 'instance' => 17, 'course' => 7];
    foreach (['/mod/lti/view.php', '/mod/lti/launch.php'] as $script) {
        $url = Navigation::target($cm, $script);
        check($url->path === '/local/videoq/view.php' && $url->params === ['course' => 7], 'Owned activity did not use native viewer');
    }
    $before = $checked;
    foreach (['/course/view.php', '/local/videoq/view.php', '/mod/lti/launch.php/other'] as $script) {
        check(Navigation::target($cm, $script) === null, 'Unrelated page redirected');
    }
    check(Navigation::target($cm, '/mod/lti/view.php', true) === null, 'Prevent-redirect ignored');
    foreach (['modname' => 'url', 'id' => 99, 'instance' => 99, 'course' => 99] as $key => $value) {
        $other = clone $cm; $other->$key = $value;
        check(Navigation::target($other, '/mod/lti/view.php') === null, 'Unrelated activity adopted');
    }
    Entry::$valid = false; check(Navigation::target($cm, '/mod/lti/view.php') === null, 'Invalid mapping redirected');
    Entry::$valid = true; Entry::$entry->enabled = 0;
    check(Navigation::target($cm, '/mod/lti/view.php') === null, 'Disabled mapping redirected');
    Entry::$entry->enabled = 1; check($checked === $before, 'Unrelated navigation touched permissions');
    $allowed = false;
    try { Navigation::target($cm, '/mod/lti/view.php'); throw new RuntimeException('Permission bypass'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Denied', 'Unexpected failure'); }
    echo "Native activity links are scoped, preserve Moodle permissions and need no LTI helper.\n";
}
