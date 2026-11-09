<?php
/** Offline upload-language form, permission, validation and portable-metadata contracts. */
declare(strict_types=1);
namespace local_videoq {
    final class synchronizer {
        public static function video_file(int $cmid): mixed { return $GLOBALS['currentfile']; }
    }
}
namespace {
    define('PARAM_ALPHA', 1); define('MUST_EXIST', 2);
    class moodle_exception extends RuntimeException {}
    class context_module {
        public function __construct(public int $id) {}
        public static function instance(int $id): self { return new self($id); }
    }
    class context_user {
        public static function instance(int $id): context_module { return new context_module(700 + $id); }
    }
    class moodleform_mod {
        public function __construct(private ?stdClass $cm = null) {}
        public function get_context(): context_module { return new context_module($this->cm->id ?? 42); }
        public function get_coursemodule(): ?stdClass { return $this->cm; }
    }
    class mod_resource_mod_form extends moodleform_mod {}
    class MoodleQuickForm {
        public array $elements = []; public array $defaults = [];
        public function addElement(string $type, string $name, mixed ...$args): void { $this->elements[$name] = [$type, ...$args]; }
        public function setType(string $name, int $type): void {}
        public function setExpanded(string $name, bool $expanded): void {}
        public function setDefault(string $name, string $value): void { $this->defaults[$name] = $value; }
    }
    final class FixtureFile {
        public function __construct(private string $hash) {}
        public function get_contenthash(): string { return $this->hash; }
    }
    final class FixtureDb {
        public array $languages = []; public array $bound = []; public int $writes = 0;
        public function get_field(string $table, string $field, array $where): mixed { return $this->languages[$where['cmid']]->language ?? false; }
        public function get_record(string $table, array $where): mixed { return $this->languages[$where['cmid']] ?? false; }
        public function get_records_select(string $table, string $query, array $where): array { return []; }
        public function record_exists(string $table, array $where): bool { return $this->bound[$where['cmid']] ?? false; }
        public function insert_record(string $table, stdClass $row): void { $this->writes++; $row->id = $row->cmid; $this->languages[$row->cmid] = $row; }
        public function set_field(string $table, string $field, string $value, array $where): void { $this->writes++; $this->languages[$where['id']]->language = $value; }
        public function delete_records(string $table, array $where): void { $this->writes++; unset($this->languages[$where['cmid']]); }
    }
    function get_string(string $key, string $component): string { return $key; }
    function has_capability(string $capability, context_module $context): bool { return $GLOBALS['canedit']; }
    function require_capability(string $capability, context_module $context): void { if (!has_capability($capability, $context)) throw new RuntimeException('Edit capability required'); }
    function get_file_storage(): object {
        return new class {
            public function get_area_files(int $context, string $component, string $area, int $id, string $order, bool $directories): array {
                check($context === 702 && $component === 'user' && $area === 'draft', 'Draft access must stay in the current user context');
                return $GLOBALS['drafts'][$id] ?? [];
            }
        };
    }
    function get_coursemodule_from_id(string $module, int $id, int $course, bool $section, int $strict): stdClass { return (object)['id' => $id, 'course' => 42, 'modname' => 'resource']; }
    function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
    $DB = new FixtureDb(); $USER = (object)['id' => 2]; $CFG = (object)['wwwroot' => 'https://moodle.invalid'];
    $canedit = true; $currentfile = new FixtureFile('a'); $drafts = [1 => [new FixtureFile('a')], 2 => [new FixtureFile('b')]];
    require dirname(__DIR__) . '/plugins/local/videoq/classes/transcription_language.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/restoration.php';
    $class = \local_videoq\transcription_language::class; $field = $class::FIELD;
    check(array_keys($class::options()) === ['', 'ja', 'en', 'zh', 'ko', 'es', 'fr', 'de', 'pt', 'it', 'ru', 'hi', 'ar'], 'Automatic plus the supported VideoQ language choices required');
    $newform = new mod_resource_mod_form(); $quick = new MoodleQuickForm(); $class::form($newform, $quick);
    check(isset($quick->elements[$field]) && $quick->defaults[$field] === '', 'New uploads must offer language selection with automatic default');
    check($class::validate($newform, [$field => 'ja']) === [], 'New upload language rejected');
    check(isset($class::validate($newform, [$field => 'en-US'])[$field]), 'Unsupported language accepted');
    $form = new mod_resource_mod_form((object)['id' => 10]); $DB->bound[10] = true;
    check(isset($class::validate($form, [$field => 'ja', 'files' => 1])[$field]), 'Existing media must not be retranscribed through a setting change');
    check(isset($class::validate($form, [$field => 'ja', 'files' => 999])[$field]), 'Missing/foreign drafts must not bypass upload-time validation');
    check($class::validate($form, [$field => 'ja', 'files' => 2]) === [], 'A different uploaded video must allow a new language');
    $class::saved((object)['modulename' => 'resource', 'coursemodule' => 10, $field => 'ja']);
    check($class::get(10) === 'ja', 'Upload language not persisted');
    check($class::validate($form, [$field => 'ja', 'files' => 1]) === [], 'Unchanged language must allow ordinary editing');
    $class::form($form, $quick); check($quick->defaults[$field] === 'ja', 'Saved selection not restored into the form');
    check(\local_videoq\restoration::describe(10) === ['role' => 'source', 'enabled' => 0, 'sourcekind' => 'file', 'transcriptionlanguage' => 'ja'], 'Language must be portable even before VideoQ opt-in, without remote bindings');
    $writes = $DB->writes; $class::saved((object)['modulename' => 'resource', 'coursemodule' => 10]);
    $class::saved((object)['modulename' => 'assign', 'coursemodule' => 10, $field => 'en']);
    check($DB->writes === $writes, 'Unrelated/programmatic updates changed language');
    $canedit = false; $quick = new MoodleQuickForm(); $class::form($form, $quick);
    check($quick->elements === [], 'Upload language controls exposed without edit permission');
    try { $class::saved((object)['modulename' => 'resource', 'coursemodule' => 10, $field => 'en']); throw new RuntimeException('Permission bypass'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Edit capability required', 'Wrong permission failure'); }
    check($DB->writes === $writes, 'Unauthorized setting write');
    $class::save(10, ''); check($class::get(10) === '' && \local_videoq\restoration::describe(10) === null, 'Automatic language must retain legacy opt-in metadata');
    echo "Upload language: choices, permissions, new-file validation, persistence and portable metadata passed.\n";
}
