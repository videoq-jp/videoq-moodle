<?php
/** Behavioural privacy contracts. Real Moodle interface compatibility is checked by privacy.php. */
declare(strict_types=1);

namespace mod_lti\local\ltiopenid {
    final class jwks_helper {
        public static function get_private_key(): array { return ['key' => 'fixture-private-key', 'kid' => 'fixture-kid']; }
    }
}
namespace Firebase\JWT {
    final class JWT {
        public static function encode($claims, $key, $alg, $kid, $header): string {
            \check($key === 'fixture-private-key' && $alg === 'RS256' && $kid === 'fixture-kid', 'Platform key selection');
            \check($header['typ'] === 'videoq-privacy+jwt', 'Privacy purpose');
            return json_encode($claims, JSON_THROW_ON_ERROR);
        }
    }
}
namespace core_privacy\local\metadata { interface provider {} }
namespace core_privacy\local\request\plugin { interface provider {} }
namespace core_privacy\local\request {
    interface core_userlist_provider {}
    class contextlist {
        public array $ids = [];
        public function add_from_sql($sql, $params): void { $this->ids[] = $params['contextid']; }
    }
    class approved_contextlist {
        public function __construct(private int $userid, private array $ids) {}
        public function get_user(): \stdClass { return (object)['id' => $this->userid]; }
        public function get_contextids(): array { return $this->ids; }
    }
    class userlist {
        public array $ids = [];
        public function __construct(private \context $context) {}
        public function get_context(): \context { return $this->context; }
        public function add_user(int $id): void { $this->ids[] = $id; }
    }
    class approved_userlist extends userlist {
        public function get_userids(): array { return $this->ids; }
    }
    class writer {
        public static array $files = [];
        public static function with_context(\context $context): self { return new self(); }
        public function export_custom_file(array $path, string $filename, string $content): self {
            self::$files[implode('/', [...$path, $filename])] = $content; return $this;
        }
        public function export_data(array $path, \stdClass $value): self { return $this; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') throw new \RuntimeException('CLI only');
    define('IGNORE_MISSING', 0);
    class moodle_exception extends \Exception { public function __construct(string $identifier, string $component) { parent::__construct($identifier); } }
    class context { public function __construct(public int $id) {} }
    class context_system extends context { public static function instance(): self { return new self(1); } }
    class context_course extends context {
        public static function instance(int $id, $strict): self|false { return in_array($id, [2, 3]) ? new self(100 + $id) : false; }
    }
    function check(bool $ok, string $label): void { if (!$ok) throw new \RuntimeException($label); }
    function rejects(callable $action): void {
        try { $action(); } catch (moodle_exception $error) {
            check($error->getMessage() === 'privacyunavailable', 'Sensitive failure details escaped'); return;
        }
        throw new \RuntimeException('Expected privacy request failure');
    }
    $config = [];
    function get_config(string $component, string $key) { global $config; return $config[$key] ?? ''; }
    function set_config(string $key, $value, string $component): void { global $config; $config[$key] = $value; }
    function get_string($key, $component): string { return $key; }
    final class records implements \IteratorAggregate {
        public bool $closed = false;
        public function getIterator(): \Traversable { for ($i = 1; $i <= 205; $i++) yield (object)['id' => $i]; }
        public function close(): void { $this->closed = true; }
    }
    $DB = new class {
        public records $recordset;
        public function get_recordset_select(...$args): records { return $this->recordset = new records(); }
        public function get_record($table, $args): \stdClass { return (object)['id' => 7, 'clientid' => 'client']; }
    };
    final class curl {
        public static array $requests = [];
        public static string $text = '';
        public static bool $corrupt = false;
        public static bool $oversize = false;
        public static int $status = 200;
        private int $errno = 0;
        public function setHeader($headers): void {}
        public function post($endpoint, $body, $options): void {
            $claims = json_decode(json_decode($body, true)['assertion'], true);
            check($endpoint === 'https://videoq.example/api/lms/privacy' && $claims['aud'] === $endpoint, 'Fixed audience');
            check($claims['iss'] === 'https://moodle.example' && $claims['sub'] === 'client' && $claims['deploymentId'] === '7', 'Exact public tuple');
            check($claims['exp'] - $claims['iat'] === 60 && preg_match('/^[a-f0-9-]{36}$/D', $claims['jti']) === 1, 'Short lifetime and nonce');
            check($options['CURLOPT_SSL_VERIFYPEER'] === true && $options['CURLOPT_SSL_VERIFYHOST'] === 2 && $options['CURLOPT_FOLLOWLOCATION'] === 0, 'Verified bounded transport');
            $r = $claims['request']; self::$requests[] = $r;
            $response = match ($r['action']) {
                'contexts', 'catalog' => ['contexts' => array_values(array_filter(['2', '3', '@orphan'], fn($id) => strcmp($id, $r['after'] ?? '') > 0)), 'nextAfter' => null],
                'users' => ['subjects' => array_values(array_intersect($r['subjects'], ['11', '202']))],
                'erase' => ['erased' => ['logs' => 1]],
                'export' => self::export($r),
            };
            $body = self::$oversize ? str_repeat('x', 524289) : json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($options['CURLOPT_WRITEFUNCTION'](null, $body) !== strlen($body)) $this->errno = 23;
        }
        private static function export(array $r): array {
            if ($r['collection'] !== 'logs' || isset($r['cursor']['after'])) return ['part' => null, 'next' => null];
            $offset = $r['cursor']['record']['offset'] ?? 0;
            $total = mb_strlen(self::$text, 'UTF-8');
            $revision = self::$corrupt ? str_repeat('a', 64) : hash('sha256', self::$text);
            $end = min($total, $offset + 65536);
            return ['part' => ['id' => '1', 'context' => $r['contexts'][0], 'offset' => $offset, 'total' => $total,
                'revision' => $revision, 'chunk' => mb_substr(self::$text, $offset, 65536, 'UTF-8')],
                'next' => $end < $total ? ['record' => ['id' => '1', 'offset' => $end, 'revision' => $revision]] : ['after' => '1']];
        }
        public function get_errno(): int { return $this->errno; }
        public function get_info(): array { return ['http_code' => self::$status]; }
    }
    $temporary = sys_get_temp_dir() . '/videoq-privacy-' . bin2hex(random_bytes(8));
    mkdir($temporary, 0700); file_put_contents($temporary . '/filelib.php', '<?php');
    $CFG = (object)['wwwroot' => 'https://moodle.example', 'libdir' => $temporary];
    $root = dirname(__DIR__) . '/plugins/local/videoq/classes/';
    foreach (['client', 'privacy_api', 'privacy_export', 'privacy/provider'] as $file) require $root . $file . '.php';
    use local_videoq\privacy_api as Api;
    use local_videoq\privacy\provider as Provider;
    use core_privacy\local\request\writer as Writer;
    use core_privacy\local\request\approved_contextlist as Approved;
    try {
        Api::remember('https://videoq.example', (object)['id' => 7, 'clientid' => 'client']);
        $server = array_values(Api::servers())[0];
        $contexts = Provider::get_contexts_for_userid(11);
        check($contexts->ids === [102, 103, 1], 'Former users and residual contexts must be discoverable');
        curl::$text = json_encode(['question' => str_repeat('漢字😀', 30000)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        Provider::export_user_data(new Approved(11, [102]));
        $files = array_filter(Writer::$files, fn($key) => str_ends_with($key, '.ndjson'), ARRAY_FILTER_USE_KEY);
        check(count($files) === 1 && json_decode(trim(array_values($files)[0]), true)['data']['question'] === str_repeat('漢字😀', 30000), 'Unicode fragments must reassemble exactly');
        foreach (curl::$requests as $r) if ($r['action'] === 'export') check($r['subject'] === '11' && $r['contexts'] === ['2'], 'Export exceeded approved course');
        Writer::$files = []; curl::$text = json_encode(['large' => str_repeat('x', 4194305)], JSON_THROW_ON_ERROR);
        \local_videoq\privacy_export::write($server, 11, '2');
        $parts = array_filter(Writer::$files, fn($key) => str_ends_with($key, '.txt'), ARRAY_FILTER_USE_KEY);
        check(implode('', $parts) === curl::$text, 'Large record fragments must be complete and ordered');
        $manifests = array_filter(Writer::$files, fn($key) => str_ends_with($key, '-manifest.json'), ARRAY_FILTER_USE_KEY);
        check(count($manifests) === 1 && json_decode(array_values($manifests)[0], true)['sha256'] === hash('sha256', curl::$text), 'Large record checksum');
        curl::$requests = [];
        Provider::delete_data_for_user(new Approved(11, [1]));
        $erase = array_values(array_filter(curl::$requests, fn($r) => $r['action'] === 'erase'));
        check(count($erase) === 1 && $erase[0]['contexts'] === ['@orphan'], 'System approval must not erase surviving courses');
        $list = new \core_privacy\local\request\userlist(new context(102)); Provider::get_users_in_context($list);
        check($list->ids === [11, 202] && $DB->recordset->closed, 'Former users beyond first batch must be included');
        curl::$requests = []; $bulk = new \core_privacy\local\request\approved_userlist(new context(103)); $bulk->ids = [202];
        Provider::delete_data_for_users($bulk);
        $erase = array_values(array_filter(curl::$requests, fn($r) => $r['action'] === 'erase'));
        check(count($erase) === 1 && $erase[0]['subject'] === '202' && $erase[0]['contexts'] === ['3'], 'Bulk deletion exceeded approved identities');
        curl::$corrupt = true; curl::$text = '{"question":"secret"}'; rejects(fn() => \local_videoq\privacy_export::write($server, 11, '2')); curl::$corrupt = false;
        curl::$oversize = true; rejects(fn() => Api::request($server, ['action' => 'catalog'])); curl::$oversize = false;
        curl::$status = 500; rejects(fn() => Api::request($server, ['action' => 'catalog'])); curl::$status = 200;
        $CFG->wwwroot = 'https://cloned.example'; $before = count(curl::$requests);
        rejects(fn() => Api::request($server, ['action' => 'erase', 'subject' => '11', 'contexts' => ['2']]));
        check(count(curl::$requests) === $before, 'A cloned issuer must not contact the former connection');
        $CFG->wwwroot = 'https://moodle.example';
        $savedhistory = $config['privacyservers'];
        $config['apiurl'] = 'https://retired.example'; $config['typeid'] = '7';
        check(count(Api::servers()) === 2 && $config['privacyservers'] === $savedhistory, 'Ordinary requests must not race connection-history writes');
        check(count(Api::servers(true)) === 2 && $config['privacyservers'] !== $savedhistory, 'Connection setup must remember the retiring public tuple');
        echo "Privacy approved scopes, former users, TLS assertions, Unicode/large exports and failure contracts passed\n";
    } finally { unlink($temporary . '/filelib.php'); rmdir($temporary); }
}
