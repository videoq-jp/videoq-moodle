<?php
// Real Moodle learners; concurrent real questions and authenticated media ranges.
declare(strict_types=1);
require __DIR__ . '/support/browser.php';
require __DIR__ . '/support/stream.php';
use VideoQLab\{Browser, Events};
use function VideoQLab\{guard, rows, docker, container, ensure, videoq, moodle, report, answer};
function percentile(array $values, float $fraction): int {
    if (!$values) return 0;
    sort($values); return (int)$values[max(0, (int)ceil(count($values) * $fraction) - 1)];
}
guard(); \VideoQLab\ready();
$options = getopt('', ['count:', 'live']); $count = (int)($options['count'] ?? 5); $live = isset($options['live']);
$videoBytes = 4 * 1024 * 1024; $videoRate = 256 * 1024;
ensure($count >= 1 && $count <= 200, 'Learner count must be 1–200');
if ($live) {
    $quota = rows("SELECT ai_answers_limit,used_ai_answers FROM users WHERE id='lti-lab-owner'")[0];
    ensure($quota['ai_answers_limit'] - $quota['used_ai_answers'] >= $count, 'Insufficient reserved quota');
}
$accounts = rows('SELECT count(*) AS n FROM users')[0]['n'];
$courseid = rows("SELECT course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='82'")[0]['course_id'];
$loadQuestion = 'この講座で説明する画像の標本化と量子化の違いを短く説明してください。 検証ID: ' . bin2hex(random_bytes(16));
$existingLogs = rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id', [$courseid]);
$users = $sessions = $results = $mediaResults = $handles = $buffers = $bytes = $firstText = $parsers = []; $multi = null;
$observer = null; $playback = null; $summary = null;
try {
    $users = json_decode(docker(['exec', container(), 'php', '/lab/acceptance-users.php', 'create', (string)$count, '5']), true, 32, JSON_THROW_ON_ERROR);
    $b = new Browser();
    try {
        foreach ($users as $index => $user) {
            if ($index) $b->clearCookies();
            $b->login($user['username']); $b->course();
            ensure($b->js('return M.cfg.userId') === $user['id'], 'Unexpected Moodle learner identity');
            $b->wait("return document.querySelector('video').currentSrc");
            $media = $b->jsAsync("const done=arguments[arguments.length-1];fetch(document.querySelector('video').currentSrc,{method:'HEAD',credentials:'same-origin'}).then(r=>done({url:r.url,status:r.status,bytes:Number(r.headers.get('content-length'))})).catch(()=>done(null))");
            ensure(($media['status'] ?? 0) === 200, 'Moodle media HEAD failed');
            ensure(($media['bytes'] ?? 0) >= $videoBytes, 'A course video of at least 4 MiB is required');
            $url = $media['url'];
            ensure(str_starts_with($url, moodle() . '/pluginfile.php/'), 'Expected Moodle-owned media URL');
            $cookies = [];
            foreach ($b->cookies() as $cookie) {
                if (ltrim($cookie['domain'], '.') === parse_url(moodle(), PHP_URL_HOST)) $cookies[] = $cookie['name'] . '=' . $cookie['value'];
            }
            ensure((bool)$cookies, 'Moodle session cookie missing');
            $token = $b->tool(static function($f) {
                $f->js(<<<'JS'
const original=window.fetch.bind(window);window.fetch=(input,init)=>{
 if(new URL(String(input),location.href).pathname.endsWith('/api/lti/messages/stream')){
  window.vqLoadToken=new Headers(init.headers).get('Authorization');
  return Promise.resolve(new Response('data: {"type":"text_delta","segmentIndex":0,"text":"Authentication capture only"}\n\ndata: {"type":"done"}\n\n',{headers:{'Content-Type':'text/event-stream'}}));
 }return original(input,init);
};document.querySelector('#question').value='Authentication capture';document.querySelector('#form').requestSubmit();
JS);
                return $f->wait('return window.vqLoadToken');
            });
            $sessions[] = ['token' => $token, 'cookies' => implode('; ', $cookies), 'url' => $url];
            echo 'Signed learner launch ready: ' . ($index + 1) . '/' . $count . ".\n";
        }
    } finally { $b->close(); }
    ensure(count(array_unique(array_column($sessions, 'token'))) === $count, 'Distinct signed learner sessions required');
    if ($live) {
        $observer = new Browser();
        $observer->login($users[0]['username']); $observer->course();
        $observer->wait("const v=document.querySelector('video');return Number.isFinite(v.duration)&&v.duration>5&&v.readyState>=1");
        ensure($observer->jsAsync("const done=arguments[arguments.length-1],v=document.querySelector('video');v.muted=true;v.currentTime=Math.min(30,v.duration/2);v.play().then(()=>done(true)).catch(()=>done(false))"), 'Native browser could not start Moodle playback');
        $startPosition = $observer->js("return document.querySelector('video').currentTime");
        $observer->wait("return document.querySelector('video').currentTime>arguments[0]+2", 30, [$startPosition]);
        $observer->js("window.vqPlayback={waiting:0,errors:0};const v=document.querySelector('video');v.addEventListener('waiting',()=>vqPlayback.waiting++);v.addEventListener('error',()=>vqPlayback.errors++)");
        $startPosition = $observer->js("return document.querySelector('video').currentTime");
        $bundle = tempnam(sys_get_temp_dir(), 'videoq-public-ca-');
        file_put_contents($bundle, docker(['exec', container(), 'cat', '/var/www/moodledata/moodleorgca.crt']));
        $multi = curl_multi_init(); $began = microtime(true);
        foreach ($sessions as $index => $session) {
            $buffers[$index] = ''; $bytes[$index] = 0; $parsers[$index] = new Events();
            $curl = curl_init(videoq() . '/api/lti/messages/stream');
            curl_setopt_array($curl, [CURLOPT_TIMEOUT => 300, CURLOPT_CAINFO => $bundle,
                CURLOPT_HTTPHEADER => ['Authorization: ' . $session['token'], 'Content-Type: application/json', 'Origin: ' . videoq()],
                CURLOPT_POSTFIELDS => json_encode(['messages' => [['role' => 'user', 'content' => $loadQuestion]]]),
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$buffers, &$firstText, $parsers, $index, $began): int {
                    $buffers[$index] .= $chunk;
                    foreach ($parsers[$index]->feed($chunk) as $raw) {
                        $event = json_decode($raw, true);
                        if (($event['type'] ?? '') === 'text_delta' && !isset($firstText[$index])) $firstText[$index] = (int)((microtime(true) - $began) * 1000);
                    }
                    return strlen($chunk);
                }]);
            $handles[] = ['curl' => $curl, 'kind' => 'question', 'index' => $index]; curl_multi_add_handle($multi, $curl);
            $media = curl_init($session['url']);
            curl_setopt_array($media, [CURLOPT_TIMEOUT => 90, CURLOPT_CAINFO => $bundle, CURLOPT_RANGE => '0-' . ($videoBytes - 1),
                CURLOPT_MAX_RECV_SPEED_LARGE => $videoRate,
                CURLOPT_COOKIE => $session['cookies'], CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$bytes, $index, $videoBytes): int {
                    $bytes[$index] += strlen($chunk);
                    return $bytes[$index] > $videoBytes + 131072 ? 0 : strlen($chunk); // Bound traffic even if a server ignores Range.
                }]);
            $handles[] = ['curl' => $media, 'kind' => 'media', 'index' => $index]; curl_multi_add_handle($multi, $media);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 0.5);
        } while ($running && $status === CURLM_OK);
        ensure($status === CURLM_OK, 'Concurrent HTTP driver failed');
        $elapsed = microtime(true) - $began;
        $playback = $observer->js("const v=document.querySelector('video'),q=v.getVideoPlaybackQuality();v.pause();return {position:v.currentTime,waitingEvents:vqPlayback.waiting,errors:vqPlayback.errors,droppedFrames:q.droppedVideoFrames,totalFrames:q.totalVideoFrames}");
        $playback['advancedSeconds'] = round($playback['position'] - $startPosition, 3);
        $playback['observedSeconds'] = round($elapsed, 3); unset($playback['position']);
        $playback['ok'] = $playback['errors'] === 0 && $playback['advancedSeconds'] >= $elapsed * 0.9;
        $observer->close(); $observer = null;
        foreach ($handles as $item) {
            $curl = $item['curl']; $index = $item['index']; $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            // Per-transfer timing comes from cURL, not the end time of the slowest request.
            $timing = ['milliseconds' => (int)round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000),
                'firstByteMilliseconds' => (int)round(curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME) * 1000)];
            if ($item['kind'] === 'question') {
                $ok = false;
                try {
                    $reply = answer($buffers[$index]);
                    $ok = $status === 200 && (bool)$reply['sources'] && (bool)trim(implode('', array_column($reply['segments'], 'text')));
                } catch (Throwable) {}
                $results[] = ['ok' => $ok, 'httpStatus' => $status, 'firstTextMilliseconds' => $firstText[$index] ?? null] + $timing;
            } else {
                $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: '';
                $ok = $status === 206 && $bytes[$index] === $videoBytes && str_starts_with($type, 'video/');
                $mediaResults[] = ['ok' => $ok, 'httpStatus' => $status, 'bytes' => $bytes[$index]] + $timing;
            }
            curl_multi_remove_handle($multi, $curl); curl_close($curl);
        }
        $handles = []; curl_multi_close($multi); $multi = null; unlink($bundle);
    }
    ensure(rows('SELECT count(*) AS n FROM users')[0]['n'] === $accounts, 'Extra VideoQ accounts created');
    $passed = !in_array(false, array_column([...$results, ...$mediaResults], 'ok'), true) && (!$live || $playback['ok']);
    $summary = ['passed' => $passed, 'verifiedAt' => gmdate('c'), 'realMoodleLogins' => count($sessions), 'realAi' => $live,
        'questionsStartedConcurrently' => $live, 'concurrentAuthenticatedVideoRanges' => count($mediaResults),
        'videoBytesPerLearner' => $videoBytes, 'videoReceiveLimitBytesPerSecond' => $videoRate,
        'browserPlaybackObservers' => $live ? 1 : 0, 'playbackDuringLoad' => $playback, 'additionalVideoqAccounts' => 0,
        'questionP50Milliseconds' => percentile(array_column($results, 'milliseconds'), 0.5),
        'questionP95Milliseconds' => percentile(array_column($results, 'milliseconds'), 0.95),
        'videoRangeP95Milliseconds' => percentile(array_column($mediaResults, 'milliseconds'), 0.95),
        'results' => $results, 'videoResults' => $mediaResults];
} finally {
    if ($observer) $observer->close();
    if ($multi) {
        foreach ($handles as $item) { curl_multi_remove_handle($multi, $item['curl']); curl_close($item['curl']); }
        curl_multi_close($multi);
    }
    if (isset($bundle) && is_file($bundle)) unlink($bundle);
    docker(['exec', container(), 'php', '/lab/acceptance-users.php', 'cleanup', (string)$count, '5']);
    // Delete only this uniquely marked run's test questions, never the owner's
    // real course history. Metered usage remains unchanged because calls were real.
    rows("DELETE FROM chat_logs WHERE course_id=$1 AND user_id='lti-lab-owner' AND question=$2", [$courseid, $loadQuestion]);
    rows("DELETE FROM lti_credentials WHERE kind='lti-history'
        AND payload->'turns' @> jsonb_build_array(jsonb_build_object('question',$1::text))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'turns') AS turn WHERE turn->>'question'<>$1::text)", [$loadQuestion]);
    if ($existingLogs) {
        $ids = '{' . implode(',', array_column($existingLogs, 'id')) . '}';
        ensure(rows('SELECT * FROM chat_logs WHERE id=ANY($1::bigint[]) ORDER BY id', [$ids]) === $existingLogs, 'Existing course questions changed');
    }
}
ensure($summary !== null, 'No completed load test');
$summary['testQuestionsRemoved'] = true;
$summary['existingCourseQuestionsPreserved'] = true;
report('php-load-' . $count, $summary);
ensure($summary['passed'], 'Concurrent question or authenticated video delivery failed');
