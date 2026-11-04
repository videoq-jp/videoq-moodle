<?php
// Optional lab orchestration; PHP extensions only, no application source imports.
declare(strict_types=1);
namespace VideoQLab;
function ensure(bool $condition, string $label): void { if (!$condition) throw new \RuntimeException($label); }
function data(): string { return getenv('LTI_LAB_DIR') ?: (getenv('HOME').'/.local/share/videoq-moodle-lab'); }
function moodle(): string { return rtrim(getenv('MOODLE_URL') ?: 'https://moodle.localhost:18443', '/'); }
function videoq(): string { return rtrim(getenv('VIDEOQ_URL') ?: 'https://videoq.localhost:18443', '/'); }
function container(): string { return getenv('MOODLE_CONTAINER') ?: 'videoq-lti-lab-moodle-1'; }
function guard(): void { ensure(moodle() === 'https://moodle.localhost:18443' && videoq() === 'https://videoq.localhost:18443', 'Mutating checks require the isolated lab'); }
function command(array $args, ?string $input = null, $output = null): string {
    $process = proc_open($args, [0=>['pipe','r'],1=>$output ?: ['pipe','w'],2=>['file','/dev/null','w']], $pipes);
    ensure(is_resource($process), 'Cannot start lab command');
    if ($input !== null) fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $text = $output ? '' : stream_get_contents($pipes[1]);
    if (!$output) fclose($pipes[1]);
    ensure(proc_close($process) === 0, 'Lab command failed; inspect sanitized service diagnostics');
    return trim($text);
}
function docker(array $args, ?string $input = null, $output = null): string { return command(['sudo','-n','docker',...$args], $input, $output); }
function fixture(string $script, ?string $action = null, ?array $input = null): array {
    return json_decode(docker(['exec','-i',container(),'php','/lab/'.$script,...($action === null ? [] : [$action])], $input === null ? null : json_encode($input, JSON_THROW_ON_ERROR)), true, 32, JSON_THROW_ON_ERROR);
}
function connect(string $database = 'videoq'): \PgSql\Connection {
    guard(); ensure((bool)preg_match('/^[a-z_0-9]+$/D',$database),'Invalid database');
    $override = getenv($database === 'videoq' ? 'VIDEOQ_TEST_DATABASE_URL' : 'MOODLE_TEST_DATABASE_URL');
    if (!$override) {
        $networks = json_decode(docker(['inspect','--format','{{json .NetworkSettings.Networks}}',getenv('LAB_DB_CONTAINER') ?: 'videoq-lti-lab-db-1']),true,16,JSON_THROW_ON_ERROR);
        $address = reset($networks)['IPAddress'];
        ensure(filter_var($address, FILTER_VALIDATE_IP) !== false && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false,'Private lab DB required');
        $override = 'host='.$address.' dbname='.$database.' user=postgres password=lti-lab-only connect_timeout=10';
    }
    $connection = @pg_connect($override, PGSQL_CONNECT_FORCE_NEW);
    ensure($connection !== false, 'Cannot connect to the isolated lab database');
    return $connection;
}
function rows(string $sql, array $params = [], string $database = 'videoq', ?\PgSql\Connection $connection = null): array {
    $own = $connection === null; $connection ??= connect($database);
    try {
        $result = @pg_query_params($connection,$sql,$params);
        ensure($result !== false,'Lab database query failed');
        $rows = pg_fetch_all($result) ?: [];
        // Preserve PostgreSQL types needed by assertions, never serialize credentials.
        foreach ($rows as &$row) foreach ($row as $key=>&$value) {
            if ($value === null) continue;
            $type = pg_field_type($result, array_search($key,array_keys($row),true));
            if ($type === 'bool') $value = $value === 't';
            elseif (in_array($type,['int2','int4','int8'],true)) $value = (int)$value;
            elseif (in_array($type,['json','jsonb'],true)) $value = json_decode($value,true,512,JSON_THROW_ON_ERROR);
        }
        return $rows;
    } finally { if ($own) pg_close($connection); }
}
function wait(callable $condition, int $seconds = 120, int $milliseconds = 250): mixed {
    $until = microtime(true)+$seconds;
    do { $value = $condition(); if ($value) return $value; usleep($milliseconds*1000); } while (microtime(true)<$until);
    throw new \RuntimeException('Acceptance condition timed out');
}
function report(string $name, array $values): void {
    ensure((bool)preg_match('/^[a-z0-9-]+$/D',$name),'Invalid report name');
    $directory = data().'/acceptance'; if (!is_dir($directory)) mkdir($directory,0700,true);
    file_put_contents($directory.'/'.$name.'.json',json_encode($values,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    echo $name . ": passed\n";
}
function request(string $url, ?array $body = null, array $headers = [], bool $verified = true): array {
    $curl = curl_init($url); $list = [];
    foreach ($headers as $key=>$value) $list[] = $key.': '.$value;
    if ($body !== null) { $list[] = 'Content-Type: application/json'; curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body ?: new \stdClass(),JSON_THROW_ON_ERROR)); }
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$list,CURLOPT_TIMEOUT=>300,CURLOPT_FOLLOWLOCATION=>false]);
    if (!$verified) curl_setopt_array($curl,[CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);
    elseif (parse_url($url,PHP_URL_HOST) && str_ends_with(parse_url($url,PHP_URL_HOST),'.localhost')) {
        static $bundle;
        if (!$bundle) { $bundle = tempnam(sys_get_temp_dir(),'videoq-public-ca-'); file_put_contents($bundle,docker(['exec',container(),'cat','/var/www/moodledata/moodleorgca.crt'])); register_shutdown_function(static fn()=>is_file($bundle) && unlink($bundle)); }
        curl_setopt($curl,CURLOPT_CAINFO,$bundle);
    }
    $text = curl_exec($curl); $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $type = curl_getinfo($curl,CURLINFO_CONTENT_TYPE);
    ensure($text !== false,'HTTP transport failed'); curl_close($curl);
    return ['status'=>$status,'contentType'=>$type,'text'=>$text];
}
