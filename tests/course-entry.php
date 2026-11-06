<?php
/** Offline teacher setup, backend negotiation and no-media creation contracts. */
declare(strict_types=1);
namespace core\lock {
    final class lock_config {
        public static function get_lock_factory(string $name): self { return new self(); }
        public function get_lock(string $key, int $timeout): self { return new self(); }
        public function release(): void {}
    }
}
namespace local_videoq {
    final class synchronizer {
        public static array $entries = [];
        public static function check_connection(): \stdClass { return (object)['id' => 3, 'state' => 1, 'baseurl' => 'https://api.example/api/lti/launch']; }
        public static function hide_transport_entries(int $id, array $cms): void {}
        public static function ensure_course_entry(int $id): void { self::$entries[] = $id; }
    }
}
namespace {
    define('SITEID', 1); define('IGNORE_MISSING', 0); define('FORMAT_HTML', 1); define('LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS', 3);
    define('LTI_TOOL_STATE_CONFIGURED',1); define('LTI_VERSION_1P3','1.3.0');
    class moodle_exception extends RuntimeException {}
    final class context_course {
        public function __construct(public int $id) {}
        public static function instance(int $id): self { return new self($id); }
    }
    $config = ['version' => 2026110701, 'typeid' => 3, 'apiurl' => 'https://api.example', 'servicemode' => 'lti', 'synctoken' => str_repeat('a',64)];
    $capabilities = ['local/videoq:managecontent' => true, 'moodle/course:manageactivities' => true];
    function get_config(string $component, string $name): mixed { global $config; return $config[$name] ?? false; }
    function require_capability(string $name, context_course $context): void {
        global $capabilities;
        if (!($capabilities[$name] ?? false) || $context->id !== 42) throw new RuntimeException('Course capability required');
    }
    function get_course(int $id): stdClass { return (object)['id' => $id]; }
    function get_string(string $name, string $component): string { return $name; }
    function get_coursemodule_from_id(string $module, int $id, int $course, bool $section, int $strict): mixed {
        global $created;
        return $created ? (object)['id' => 101, 'instance' => 501, 'course' => 42, 'deletioninprogress' => 0] : false;
    }
    $created = [];
    function add_moduleinfo(stdClass $module, stdClass $course): stdClass {
        global $created; $created[] = clone $module;
        return (object)['coursemodule' => 101, 'instance' => 501];
    }
    final class FixtureDb {
        public ?stdClass $row = null;
        public int $toolstate = 1;
        public function get_record(string $table, array $where): mixed {
            if ($table === 'lti_types') return (object)['state'=>$this->toolstate,'ltiversion'=>LTI_VERSION_1P3,'baseurl'=>'https://api.example/api/lti/launch'];
            return $this->row ? clone $this->row : false;
        }
        public function record_exists(string $table, array $where): bool { return $table === 'course'; }
        public function get_field(string $table, string $field, array $where): mixed {
            return $field === 'toolurl' ? 'https://api.example/api/lti/launch' : 3;
        }
        public function start_delegated_transaction(): object { return new class { public function allow_commit(): void {} }; }
        public function insert_record(string $table, stdClass $value): int {
            if ($table !== 'local_videoq_courses') throw new RuntimeException('Unexpected media/learner data write');
            $this->row = clone $value; $this->row->id = 1; return 1;
        }
        public function update_record(string $table, stdClass $value): void { $this->row = clone $value; }
        public function execute(string $sql, array $values): void {
            if (!str_contains($sql, 'SET enabled = 0')) throw new RuntimeException('Unexpected update');
            $this->row->enabled = 0; $this->row->generation++;
        }
    }
    final class curl {
        public static array $metadata = ['toolOrigin' => 'https://api.example', 'courseEntries' => true];
        public static bool $oversize = false;
        private int $error = 0;
        public function setHeader(array $headers): void {}
        public function get(string $url, array $body, array $options): string {
            if ($url === 'https://api.example/api/lms/connection') return json_encode(self::$metadata);
            check($url === 'https://api.example/api/lms/pairing/discovery', 'Configured endpoint only');
            check($options['CURLOPT_FOLLOWLOCATION'] === 0 && $options['CURLOPT_SSL_VERIFYPEER'] === true
                && $options['CURLOPT_SSL_VERIFYHOST'] === 2, 'Verified TLS and no redirects');
            $chunk = self::$oversize ? str_repeat('x',8193) : json_encode(self::$metadata);
            if ($options['CURLOPT_WRITEFUNCTION'](null,$chunk) !== strlen($chunk)) $this->error = 23;
            return '';
        }
        public function get_errno(): int { return $this->error; }
        public function get_info(): array { return ['http_code' => 200]; }
    }
    function check(bool $valid, string $label): void { if (!$valid) throw new RuntimeException($label); }
    function rejects(callable $action): void { try { $action(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
    $temporary = sys_get_temp_dir() . '/videoq-course-' . bin2hex(random_bytes(8));
    mkdir($temporary,0700); mkdir($temporary.'/course'); mkdir($temporary.'/mod'); mkdir($temporary.'/mod/lti');
    file_put_contents($temporary.'/filelib.php','<?php'); file_put_contents($temporary.'/course/modlib.php','<?php');
    file_put_contents($temporary.'/mod/lti/locallib.php','<?php');
    $CFG = (object)['dirroot' => $temporary, 'libdir' => $temporary]; $DB = new FixtureDb();
    require dirname(__DIR__).'/plugins/local/videoq/classes/client.php';
    require dirname(__DIR__).'/plugins/local/videoq/classes/course_entry.php';
    use local_videoq\course_entry as Entry;
    try {
        rejects(fn()=>Entry::require_backend('youtubeSources'));
        curl::$metadata['youtubeSourceProtocol'] = true;
        curl::$metadata['youtubeSources'] = false;
        Entry::require_backend('youtubeSourceProtocol');
        rejects(fn()=>Entry::require_backend('youtubeSources'));
        curl::$metadata['youtubeSources'] = true;
        Entry::require_backend('youtubeSources');
        $capabilities['moodle/course:manageactivities'] = false;
        rejects(fn()=>Entry::configure(42,true));
        $capabilities['moodle/course:manageactivities'] = true;
        $capabilities['local/videoq:managecontent'] = false;
        rejects(fn()=>Entry::configure(42,true));
        $capabilities['local/videoq:managecontent'] = true;
        rejects(fn()=>Entry::configure(SITEID,true));
        curl::$metadata['courseEntries'] = false;
        rejects(fn()=>Entry::configure(42,true));
        curl::$metadata['courseEntries'] = true; curl::$oversize = true;
        rejects(fn()=>Entry::configure(42,true));
        curl::$oversize = false;
        check(!$created && !$DB->row, 'Rejected setup created an activity');
        Entry::configure(42,true);
        check(count($created) === 1 && $created[0]->modulename === 'lti' && $created[0]->instructorchoicesendname === 0
            && $created[0]->instructorchoicesendemailaddr === 0, 'Only a private real LTI entry is created');
        check($DB->row->generation === 1 && $DB->row->enabled === 1, 'Initial durable state');
        $DB->toolstate = 3; check(!Entry::valid($DB->row), 'Rejected tool still authorizes an entry'); $DB->toolstate = 1;
        Entry::configure(42,true);
        check(count($created) === 1 && $DB->row->generation === 2, 'Repair duplicated the activity');
        check(local_videoq\synchronizer::$entries === [42,42], 'Stable course URL helper not called');
        $config['servicemode'] = '';
        curl::$metadata['courseEntries'] = false;
        rejects(fn()=>Entry::configure(42,true));
        curl::$metadata['courseEntries'] = true;
        Entry::configure(42,true);
        check(count($created) === 1 && $DB->row->generation === 3, 'Legacy backend needs materials/S3 discovery or duplicates an entry');
        curl::$metadata['courseEntries'] = false;
        Entry::configure(42,false);
        check($DB->row->enabled === 0 && $DB->row->generation === 4, 'Disable requires backend availability or lost generation');
        $config['version'] = 2026110700;
        check(Entry::find(42) === null && Entry::for_launch(501) === null, 'Pre-upgrade table guard');
        echo "Course entry: course-wide capabilities, old backend refusal, bounded TLS discovery, no media, idempotent repair and offline disable passed.\n";
    } finally {
        unlink($temporary.'/filelib.php'); unlink($temporary.'/course/modlib.php'); unlink($temporary.'/mod/lti/locallib.php');
        rmdir($temporary.'/mod/lti'); rmdir($temporary.'/mod'); rmdir($temporary.'/course'); rmdir($temporary);
    }
}
