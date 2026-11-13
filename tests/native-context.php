<?php
/** Current Moodle actor, anchor visibility and capability checks for native calls. */
declare(strict_types=1);
namespace local_videoq {
    final class course_entry {
        public static bool $valid = true;
        public static function find(int $course): object { return (object)['enabled' => true, 'lticmid' => 101, 'generation' => 5]; }
        public static function valid(object $entry): bool { return self::$valid; }
    }
    final class lesson {
        public static function for_course(int $course): array { return self::find('sync_102'); }
        public static function find(string $id): array {
            return ['id' => $id, 'videoCmid' => $id === 'other_course' ? 999 : 102, 'ltiCmid' => 103];
        }
    }
    final class source {
        public static function matches(object $cm, array $lesson, bool $youtube): bool { return $cm->id === 102; }
        public static function view_capability(object $cm): string { return 'mod/resource:view'; }
    }
    final class course_access {
        public static array $permissions = [];
        public static array $calls = [];
        public static function lessons(int $course): array { return [['id' => 'sync_102', 'generation' => 2]]; }
        public static function launch(int $instance, int $userid): array {
            self::$calls[] = [$instance, $userid];
            return ['custom_videoq_access' => 'private-test-proof'];
        }
        public static function resolve(string $proof, string $subject, string $course, string $instance, bool $youtube): array {
            global $USER;
            \check($proof === 'private-test-proof' && $subject === (string)$USER->id && $course === '42', 'Current actor proof');
            return ['resources' => [['resourceLinkId' => '503', 'learning' => true]], 'permissions' => self::$permissions];
        }
    }
}
namespace {
    define('SITEID', 1); define('MUST_EXIST', 1); define('LTI_VERSION_1P3', '1.3.0'); define('LTI_TOOL_STATE_CONFIGURED', 1);
    final class moodle_exception extends RuntimeException {
        public function __construct(string $errorcode, string $component) { parent::__construct($errorcode); }
    }
    final class context_module {
        public function __construct(public int $id) {}
        public static function instance(int $id): self { return new self($id); }
    }
    function check(bool $valid, string $label): void { if (!$valid) throw new RuntimeException($label); }
    function rejects(callable $action, string $label): void {
        try { $action(); } catch (Throwable) { return; }
        throw new RuntimeException($label);
    }
    $config = ['apiurl' => 'https://videoq.example', 'typeid' => 3];
    $capabilities = [];
    $guest = false;
    $enrolment = 'active';
    function get_config(string $component, string $name): mixed { global $config; return $config[$name] ?? false; }
    function get_course(int $course): object {
        if ($course !== 42) throw new RuntimeException('Unexpected course');
        return (object)['id' => $course];
    }
    function require_login(object $course): void {
        global $enrolment;
        check($course->id === 42, 'Current course login required');
        if ($enrolment !== 'active') throw new moodle_exception('requireloginerror', 'error');
    }
    function isguestuser(): bool { global $guest; return $guest; }
    function get_fast_modinfo(object $course, int $userid): object {
        global $info, $USER;
        check($course->id === 42 && $userid === $USER->id, 'Current actor availability');
        return $info;
    }
    function has_capability(string $name, context_module $context): bool {
        global $capabilities;
        return $capabilities[$name . ':' . $context->id] ?? true;
    }
    final class FixtureDb {
        public string $clientid = 'client-1';
        public string $toolurl = '';
        public function get_record(string $table, array $where, string $fields, int $strict): object {
            if ($table === 'lti') {
                check($where['course'] === 42 && in_array($where['id'], [501, 503], true), 'Course-bound LTI lookup');
                return (object)['id' => $where['id'], 'typeid' => 3, 'toolurl' => $this->toolurl];
            }
            check($table === 'lti_types' && $where['id'] === 3, 'Registered tool lookup');
            return (object)['id' => 3, 'ltiversion' => LTI_VERSION_1P3, 'state' => 1, 'clientid' => $this->clientid,
                'baseurl' => 'https://videoq.example/api/lti/launch'];
        }
    }
    $directory = sys_get_temp_dir() . '/videoq-native-context-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700); mkdir($directory . '/mod'); mkdir($directory . '/mod/lti');
    file_put_contents($directory . '/mod/lti/locallib.php', '<?php');
    $CFG = (object)['dirroot' => $directory, 'wwwroot' => 'https://moodle.example/'];
    $USER = (object)['id' => 7]; $DB = new FixtureDb();
    $info = (object)['cms' => []];
    foreach ([101 => 501, 102 => 502, 103 => 503] as $cmid => $instance) {
        $info->cms[$cmid] = (object)['id' => $cmid, 'instance' => $instance, 'uservisible' => true, 'deletioninprogress' => false];
    }
    require dirname(__DIR__) . '/plugins/local/videoq/classes/client.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/native_context.php';
    use local_videoq\native_context as Context;
    try {
        $context = Context::resolve(42, 'sync_102');
        check($context['resourceLinkId'] === '501' && $context['generation'] === 5 && $context['subject'] === '7', 'Course entry authority');
        check($context['issuer'] === 'https://moodle.example' && $context['deploymentId'] === '3', 'Registration identity');
        $info->cms[102]->uservisible = false;
        check(Context::resolve(42, 'sync_102')['resourceLinkId'] === '501', 'One hidden source disabled the whole course entry');
        $info->cms[102]->uservisible = true;
        $info->cms[101]->uservisible = false;
        $legacy = Context::resolve(42, 'sync_102');
        check($legacy['resourceLinkId'] === '503' && $legacy['generation'] === 2, 'Available legacy anchor');
        $capabilities['mod/lti:view:103'] = false;
        rejects(fn() => Context::resolve(42, 'sync_102'), 'Revoked LTI capability accepted');
        $capabilities['mod/lti:view:103'] = true;
        $capabilities['mod/resource:view:102'] = false;
        rejects(fn() => Context::resolve(42, 'sync_102'), 'Revoked source capability accepted');
        $capabilities['mod/resource:view:102'] = true;
        $info->cms[102]->deletioninprogress = true;
        rejects(fn() => Context::resolve(42, 'sync_102'), 'Deleting source accepted');
        $info->cms[102]->deletioninprogress = false;
        rejects(fn() => Context::resolve(42, 'other_course'), 'Anchor from another course accepted');
        $info->cms[101]->uservisible = true;
        $USER->id = 8;
        check(Context::resolve(42)['subject'] === '8' && end(local_videoq\course_access::$calls) === [501, 8], 'Stale actor reused');
        foreach (['suspended', 'unenrolled'] as $state) {
            $enrolment = $state;
            $launches = count(local_videoq\course_access::$calls);
            rejects(fn() => Context::resolve(42), 'Revoked enrolment reused previous authority');
            check(count(local_videoq\course_access::$calls) === $launches, 'Revoked enrolment issued an access proof');
            $enrolment = 'active';
            check(Context::resolve(42)['subject'] === '8', 'Restored enrolment did not recover for the same actor');
        }
        local_videoq\course_access::$permissions = ['manageContent' => true];
        Context::require_operation(Context::resolve(42), 'course-history', ['action' => 'csv']);
        local_videoq\course_access::$permissions = ['manageContent' => false];
        rejects(fn() => Context::require_operation(Context::resolve(42), 'course-history', ['action' => 'csv']),
            'CSV reused revoked teacher authority');
        foreach (['deleted', 'suspended'] as $property) {
            $USER->$property = true;
            rejects(fn() => Context::resolve(42), 'Inactive actor accepted');
            $USER->$property = false;
        }
        $guest = true; rejects(fn() => Context::resolve(42), 'Guest actor accepted'); $guest = false;
        rejects(fn() => Context::resolve(SITEID), 'Site front page accepted');
        rejects(fn() => Context::resolve(42, '../foreign'), 'Malformed lesson accepted');
        $DB->clientid = '';
        rejects(fn() => Context::resolve(42), 'Unregistered tool accepted'); $DB->clientid = 'client-1';
        $DB->toolurl = 'https://foreign.example/api/lti/launch';
        rejects(fn() => Context::resolve(42), 'Foreign tool URL accepted'); $DB->toolurl = '';
        $config['typeid'] = 4;
        rejects(fn() => Context::resolve(42), 'Different configured tool accepted'); $config['typeid'] = 3;
        $config['apiurl'] = 'http://videoq.example';
        rejects(fn() => Context::resolve(42), 'Unverified origin accepted'); $config['apiurl'] = 'https://videoq.example';
        foreach (['transcript', 'conversations', 'management', 'reports', 'teacher/connect', 'course-history'] as $operation) {
            rejects(fn() => Context::require_operation($context, $operation, []), 'Missing operation capability accepted');
        }
        Context::require_operation($context, 'materials', []);
        Context::require_operation($context, 'tags', ['action' => 'list']);
        rejects(fn() => Context::require_operation($context, 'tags', ['action' => 'create']), 'Learner tag mutation accepted');
        $context['scope']['permissions']['viewQa'] = true;
        Context::require_operation($context, 'qa', ['action' => 'list']);
        foreach (['ask', 'reply', 'vote', 'feature', 'deleteQuestion'] as $action) {
            rejects(fn() => Context::require_operation($context, 'qa', ['action' => $action]), 'Missing Q&A capability accepted');
        }
        $context['scope']['resources'] = [['learning' => false]];
        // Local Moodle files do not determine linked VideoQ course availability.
        Context::require_operation($context, 'messages/stream', []);
        Context::require_operation($context, 'playback', []);
        echo "Native Moodle actor, current anchor availability, registration and operation capabilities passed.\n";
    } finally {
        unlink($directory . '/mod/lti/locallib.php'); rmdir($directory . '/mod/lti'); rmdir($directory . '/mod'); rmdir($directory);
    }
}
