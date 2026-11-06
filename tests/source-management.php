<?php
/** Offline direct-action and navigation authorization, without a server connection. */
declare(strict_types=1);
namespace core\lock {
    final class lock_config {
        public static function get_lock_factory(string $name): self { return new self(); }
        public function get_lock(string $key, int $timeout): self { return new self(); }
        public function release(): void {}
    }
}
namespace {
    define('SITEID', 1); define('IGNORE_MISSING', 0); define('MUST_EXIST', 2); define('CONTEXT_MODULE', 70);
    class moodle_exception extends RuntimeException {}
    class context {
        public int $contextlevel = CONTEXT_MODULE;
        public function __construct(public int $instanceid) {}
    }
    final class context_course extends context {
        public static function instance(int $id): self { return new self($id); }
    }
    final class context_module extends context {
        public static function instance(int $id): self { return new self($id); }
    }
    $modules = [10 => (object)['id' => 10, 'modname' => 'resource', 'course' => 42, 'deletioninprogress' => 0],
        11 => (object)['id' => 11, 'modname' => 'resource', 'course' => 43, 'deletioninprogress' => 0],
        12 => (object)['id' => 12, 'modname' => 'resource', 'course' => 42, 'deletioninprogress' => 1],
        13 => (object)['id' => 13, 'modname' => 'url', 'course' => 42, 'deletioninprogress' => 0],
        14 => (object)['id' => 14, 'modname' => 'resource', 'course' => SITEID, 'deletioninprogress' => 0]];
    $grants = ['course:42:local/videoq:managecontent' => true, 'module:10:moodle/course:manageactivities' => true];
    function has_capability(string $name, context $context): bool {
        global $grants;
        $scope = $context instanceof context_course ? 'course' : 'module';
        return $grants[$scope . ':' . $context->instanceid . ':' . $name] ?? false;
    }
    function require_capability(string $name, context $context): void {
        if (!has_capability($name, $context)) throw new RuntimeException('Current capability required');
    }
    function get_coursemodule_from_id(string $module, int $id, int $course, bool $section, int $strict): mixed {
        global $modules;
        $cm = $modules[$id] ?? null;
        if (!$cm || ($module && $cm->modname !== $module) || ($course && (int)$cm->course !== $course)) {
            if ($strict === MUST_EXIST) throw new RuntimeException('Unknown source');
            return false;
        }
        return clone $cm;
    }
    final class FixtureDb {
        public int $writes = 0;
        public int $generation = 5;
        public bool $enabled = true;
        public string $status = 'error';
        public function execute(string $sql, array $values): void {
            check($values[0] === 'queued' && $values[2] === 10, 'Unexpected mutation target');
            $this->writes++;
            if (str_contains($sql, 'SET enabled = 0')) $this->enabled = false;
            else if (!$this->enabled && str_contains($sql, 'AND enabled = 1')) return;
            $this->generation++; $this->status = 'queued';
        }
    }
    class navigation_node { public const TYPE_SETTING = 1; }
    final class settings_navigation {
        public int $links = 0;
        public function find(string $id, int $type): mixed { return false; }
        public function add(mixed ...$arguments): void { $this->links++; }
    }
    final class moodle_url { public function __construct(string $path, array $params) {} }
    function get_string(string $name, string $component): string { return $name; }
    function get_config(string $component, string $key): mixed { return false; }
    function check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
    function rejects(callable $operation): void {
        try { $operation(); } catch (Throwable) { return; }
        throw new RuntimeException('Expected authorization rejection');
    }
    $DB = new FixtureDb();
    $PAGE = (object)['cm' => $modules[10]];
    require dirname(__DIR__) . '/plugins/local/videoq/classes/source.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/source_management.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/synchronizer.php';
    require dirname(__DIR__) . '/plugins/local/videoq/lib.php';
    use local_videoq\source_management as Source;
    use local_videoq\synchronizer as Sync;
    check(Source::can_manage(10), 'Module-only activity grant with course content authority must work');
    Source::require_manager(10);
    $nav = new settings_navigation();
    local_videoq_extend_settings_navigation($nav, context_module::instance(10));
    check($nav->links === 1, 'Authorized source navigation missing');
    foreach (['course:42:local/videoq:managecontent', 'module:10:moodle/course:manageactivities'] as $key) {
        $grants[$key] = false;
        check(!Source::can_manage(10), 'Denied source navigation remains visible');
        // A capability at the wrong scope cannot replace the required grant.
        $grants['module:10:local/videoq:managecontent'] = true;
        $grants['course:43:local/videoq:managecontent'] = true;
        foreach ([fn() => Source::require_manager(10), fn() => Sync::enable(10),
                fn() => Sync::disable(10), fn() => Sync::retry(10)] as $operation) rejects($operation);
        local_videoq_extend_settings_navigation($nav, context_module::instance(10));
        check($nav->links === 1 && $DB->writes === 0 && $DB->generation === 5 && $DB->enabled,
            'Revoked direct action changed a binding or navigation');
        $grants[$key] = true;
    }
    // A browser cannot replace the source's course with one the teacher controls.
    unset($grants['course:43:local/videoq:managecontent']);
    $grants['module:11:moodle/course:manageactivities'] = true;
    foreach ([11, 12, 13, 14, 999] as $cmid) {
        check(!Source::can_manage($cmid), 'Foreign, retiring, unsupported or missing source admitted');
        rejects(fn() => Source::require_manager($cmid));
        rejects(fn() => Sync::enable($cmid));
        rejects(fn() => Sync::disable($cmid));
        rejects(fn() => Sync::retry($cmid));
    }
    check($DB->writes === 0, 'Rejected source had a side effect');
    Sync::retry(10);
    check($DB->generation === 6 && $DB->status === 'queued' && $DB->enabled, 'Authorized retry did not advance generation');
    Sync::disable(10);
    check($DB->generation === 7 && !$DB->enabled, 'Authorized disable failed');
    Sync::retry(10);
    check($DB->generation === 7 && !$DB->enabled, 'Retry changed an explicitly disabled source');
    echo "Source management: course/module grants, direct mutations, navigation, source identity, revocation and disabled retry passed.\n";
}
