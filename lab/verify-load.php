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
$options = getopt('', ['count:', 'live', 'media-only', 'full-video']); $count = (int)($options['count'] ?? 5); $live = isset($options['live']);
$mediaOnly = isset($options['media-only']); $fullVideo = isset($options['full-video']); $execute = $live || $mediaOnly;
ensure(!($live && $mediaOnly), 'Choose live burst or media-only with three real question probes');
ensure(!$fullVideo || $execute, 'Full video requires live or media-only execution');
$questionCount = $live ? $count : ($mediaOnly ? min(3, $count) : 0);
$videoBytes = 4 * 1024 * 1024; $videoRate = 256 * 1024;
ensure($count >= 1 && $count <= 200, 'Learner count must be 1–200');
if ($execute) {
    $quota = rows("SELECT ai_answers_limit,used_ai_answers FROM users WHERE id='lti-lab-owner'")[0];
    ensure($quota['ai_answers_limit'] - $quota['used_ai_answers'] >= $questionCount, 'Insufficient reserved quota');
}
$accounts = rows('SELECT count(*) AS n FROM users')[0]['n'];
$courseid = rows("SELECT course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='82'")[0]['course_id'];
$loadQuestion = 'この講座で説明する画像の標本化と量子化の違いを短く説明してください。 検証ID: ' . bin2hex(random_bytes(16));
$existingLogs = rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id', [$courseid]);
$users = $sessions = $results = $mediaResults = $handles = $buffers = $bytes = $firstText = $parsers = []; $multi = null;
$hashes = []; $transferResults = [];
$expectedMediaHash = $fullVideo ? rows("SELECT f.contenthash FROM mdl_files f JOIN mdl_context c ON c.id=f.contextid
    WHERE c.contextlevel=70 AND c.instanceid=82 AND f.component='mod_resource' AND f.filearea='content' AND f.filename<>'.'", [], 'moodle')[0]['contenthash'] : null;
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
            if ($fullVideo && !$index) {
                $duration = (float)$b->wait("const v=document.querySelector('video');return Number.isFinite(v.duration)&&v.duration>5?v.duration:false");
                $videoBytes = (int)$media['bytes']; $videoRate = (int)ceil($videoBytes / $duration);
            }
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
    if ($execute) {
        $observer = new Browser();
        $observer->login($users[0]['username']); $observer->course();
        $observer->wait("const v=document.querySelector('video');return Number.isFinite(v.duration)&&v.duration>5&&v.readyState>=1");
        ensure($observer->jsAsync("const done=arguments[arguments.length-1],v=document.querySelector('video');v.loop=true;v.muted=true;v.currentTime=0;v.play().then(()=>done(true)).catch(()=>done(false))"), 'Native browser could not start Moodle playback');
        $startPosition = $observer->js("return document.querySelector('video').currentTime");
        $observer->wait("return document.querySelector('video').currentTime>arguments[0]+2", 30, [$startPosition]);
        $observer->js("const v=document.querySelector('video');window.vqPlayback={waiting:0,errors:0,advanced:0,last:v.currentTime};v.addEventListener('waiting',()=>vqPlayback.waiting++);v.addEventListener('error',()=>vqPlayback.errors++);v.addEventListener('timeupdate',()=>{const p=vqPlayback,d=v.currentTime-p.last;p.advanced+=d>=0?d:(p.last>v.duration-2&&v.currentTime<2?v.duration+d:0);p.last=v.currentTime})");
        $startPosition = $observer->js("return document.querySelector('video').currentTime");
        $bundle = tempnam(sys_get_temp_dir(), 'videoq-public-ca-');
        file_put_contents($bundle, docker(['exec', container(), 'cat', '/var/www/moodledata/moodleorgca.crt']));
        $multi = curl_multi_init(); $began = microtime(true);
        $addQuestion = function(int $index) use ($multi, $bundle, $sessions, $loadQuestion, &$handles, &$buffers, &$firstText, &$parsers): void {
            $questionBegan = microtime(true);
            $curl = curl_init(videoq() . '/api/lti/messages/stream');
            curl_setopt_array($curl, [CURLOPT_TIMEOUT => 300, CURLOPT_CAINFO => $bundle,
                CURLOPT_HTTPHEADER => ['Authorization: ' . $sessions[$index]['token'], 'Content-Type: application/json', 'Origin: ' . videoq()],
                CURLOPT_POSTFIELDS => json_encode(['messages' => [['role' => 'user', 'content' => $loadQuestion]]]),
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$buffers, &$firstText, &$parsers, $index, $questionBegan): int {
                    $buffers[$index] .= $chunk;
                    foreach ($parsers[$index]->feed($chunk) as $raw) {
                        $event = json_decode($raw, true);
                        if (($event['type'] ?? '') === 'text_delta' && !isset($firstText[$index])) $firstText[$index] = (int)((microtime(true) - $questionBegan) * 1000);
                    }
                    return strlen($chunk);
                }]);
            $handles[] = ['curl' => $curl, 'kind' => 'question', 'index' => $index]; curl_multi_add_handle($multi, $curl);
        };
        $scheduled = [];
        foreach ($sessions as $index => $session) {
            $buffers[$index] = ''; $bytes[$index] = 0; $parsers[$index] = new Events();
            if ($fullVideo) $hashes[$index] = hash_init('sha1');
            if ($index < ($mediaOnly && $fullVideo ? 1 : $questionCount)) {
                $addQuestion($index); $scheduled[$index] = true;
            }
            $media = curl_init($session['url']);
            curl_setopt_array($media, [CURLOPT_TIMEOUT => $fullVideo ? (int)ceil($duration) + 180 : 90, CURLOPT_CAINFO => $bundle, CURLOPT_RANGE => '0-' . ($videoBytes - 1),
                // Independent viewer connections avoid HTTP/2 paused-stream
                // buffering in the single-process load generator.
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_MAX_RECV_SPEED_LARGE => $videoRate,
                CURLOPT_COOKIE => $session['cookies'], CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$bytes, $index, $videoBytes, $hashes, $fullVideo): int {
                    $bytes[$index] += strlen($chunk);
                    if ($fullVideo) hash_update($hashes[$index], $chunk);
                    return $bytes[$index] > $videoBytes + 131072 ? 0 : strlen($chunk); // Bound traffic even if a server ignores Range.
                }]);
            $handles[] = ['curl' => $media, 'kind' => 'media', 'index' => $index]; curl_multi_add_handle($multi, $media);
        }
        $nextProgress = $began + 30; $nextResource = $began + 5; $lowMemorySamples = 0; $minimumAvailableKiB = null;
        do {
            if ($fullVideo && microtime(true) >= $nextResource && is_readable('/proc/meminfo')) {
                preg_match('/^MemAvailable:\s+(\d+) kB/m', file_get_contents('/proc/meminfo'), $memory);
                if (isset($memory[1])) {
                    $available = (int)$memory[1]; $minimumAvailableKiB = min($minimumAvailableKiB ?? $available, $available);
                    preg_match('/^VmRSS:\s+(\d+) kB/m', file_get_contents('/proc/self/status'), $processMemory);
                    $peakGeneratorKiB = max($peakGeneratorKiB ?? 0, (int)($processMemory[1] ?? 0));
                    $lowMemorySamples = $available < 192 * 1024 ? $lowMemorySamples + 1 : 0;
                    ensure($lowMemorySamples < 3, 'Host memory pressure: stopped sustained load before exhausting the host');
                }
                $nextResource = microtime(true) + 5;
            }
            if ($mediaOnly && $fullVideo) {
                foreach ([1 => $duration / 2, 2 => max(0, $duration - 60)] as $probe => $at) {
                    if ($probe < $questionCount && !isset($scheduled[$probe]) && microtime(true) - $began >= $at) {
                        $addQuestion($probe); $scheduled[$probe] = true;
                        echo 'Real question probe started: ' . ($probe + 1) . ".\n";
                    }
                }
            }
            $status = curl_multi_exec($multi, $running);
            while ($completed = curl_multi_info_read($multi)) {
                $transferResults[spl_object_id($completed['handle'])] = $completed['result'];
            }
            if ($running) curl_multi_select($multi, 0.5);
            if (microtime(true) >= $nextProgress) {
                echo 'Media load active: ' . (int)(microtime(true) - $began) . ' s, received ' . array_sum($bytes) . " bytes.\n";
                $nextProgress += 30;
            }
        } while ($running && $status === CURLM_OK);
        ensure($status === CURLM_OK, 'Concurrent HTTP driver failed');
        $elapsed = microtime(true) - $began;
        $playback = $observer->js("const v=document.querySelector('video'),q=v.getVideoPlaybackQuality();v.pause();return {advancedSeconds:vqPlayback.advanced,waitingEvents:vqPlayback.waiting,errors:vqPlayback.errors,droppedFrames:q.droppedVideoFrames,totalFrames:q.totalVideoFrames}");
        $playback['advancedSeconds'] = round($playback['advancedSeconds'], 3);
        $playback['observedSeconds'] = round($elapsed, 3);
        $playback['ok'] = $playback['errors'] === 0 && $playback['advancedSeconds'] >= $elapsed * 0.9;
        $observer->close(); $observer = null;
        foreach ($handles as $item) {
            $curl = $item['curl']; $index = $item['index']; $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            // Per-transfer timing comes from cURL, not the end time of the slowest request.
            $timing = ['milliseconds' => (int)round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000),
                'firstByteMilliseconds' => (int)round(curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME) * 1000),
                'curlResult' => $transferResults[spl_object_id($curl)] ?? curl_errno($curl)];
            if ($item['kind'] === 'question') {
                $ok = false;
                try {
                    $reply = answer($buffers[$index]);
                    $ok = $timing['curlResult'] === CURLE_OK && $status === 200 && (bool)$reply['sources'] && (bool)trim(implode('', array_column($reply['segments'], 'text')));
                } catch (Throwable) {}
                $results[] = ['ok' => $ok, 'httpStatus' => $status, 'firstTextMilliseconds' => $firstText[$index] ?? null] + $timing;
            } else {
                $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: '';
                $ok = $timing['curlResult'] === CURLE_OK && $status === 206 && $bytes[$index] === $videoBytes && str_starts_with($type, 'video/');
                if ($fullVideo) $ok = $ok && hash_equals($expectedMediaHash, hash_final($hashes[$index]));
                $mediaResults[] = ['ok' => $ok, 'httpStatus' => $status, 'bytes' => $bytes[$index]] + $timing;
            }
            curl_multi_remove_handle($multi, $curl); curl_close($curl);
        }
        $handles = []; curl_multi_close($multi); $multi = null; unlink($bundle);
    }
    ensure(rows('SELECT count(*) AS n FROM users')[0]['n'] === $accounts, 'Extra VideoQ accounts created');
    ensure(!$execute || (count($results) === $questionCount && count($mediaResults) === $count), 'Incomplete load coverage');
    $passed = !in_array(false, array_column([...$results, ...$mediaResults], 'ok'), true) && (!$execute || $playback['ok']);
    $summary = ['passed' => $passed, 'verifiedAt' => gmdate('c'), 'realMoodleLogins' => count($sessions), 'realAi' => $execute,
        'questionsStartedConcurrently' => $questionCount > 0 && !($mediaOnly && $fullVideo),
        'questionSchedule' => $mediaOnly && $fullVideo ? ['start', 'midpoint', 'end-minus-60-seconds'] : ['simultaneous'],
        'questionCount' => $questionCount, 'fullVideo' => $fullVideo,
        'concurrentAuthenticatedVideoRanges' => count($mediaResults),
        'videoBytesPerLearner' => $videoBytes, 'videoReceiveLimitBytesPerSecond' => $videoRate,
        'browserPlaybackObservers' => $execute ? 1 : 0, 'playbackDuringLoad' => $playback, 'additionalVideoqAccounts' => 0,
        'questionP50Milliseconds' => percentile(array_column($results, 'milliseconds'), 0.5),
        'questionP95Milliseconds' => percentile(array_column($results, 'milliseconds'), 0.95),
        'firstTextP50Milliseconds' => percentile(array_values(array_filter(array_column($results, 'firstTextMilliseconds'), static fn($value) => $value !== null)), 0.5),
        'firstTextP95Milliseconds' => percentile(array_values(array_filter(array_column($results, 'firstTextMilliseconds'), static fn($value) => $value !== null)), 0.95),
        'videoRangeP95Milliseconds' => percentile(array_column($mediaResults, 'milliseconds'), 0.95),
        'minimumAvailableMemoryKiB' => $minimumAvailableKiB ?? null,
        'peakGeneratorResidentKiB' => $peakGeneratorKiB ?? null, 'mediaTransport' => 'HTTP/1.1 independent viewer connections',
        'results' => $results, 'videoResults' => $mediaResults];
} catch (Throwable $error) {
    report('php-load-aborted-' . $count, ['passed' => false, 'aborted' => true, 'fullVideo' => $fullVideo,
        'realMoodleLogins' => count($sessions), 'receivedBytes' => array_sum($bytes),
        'elapsedMediaSeconds' => isset($began) ? (int)(microtime(true) - $began) : 0,
        'reason' => str_contains($error->getMessage(), 'Host memory') ? 'host-memory-pressure' : 'incomplete-run']);
    throw $error;
} finally {
    if ($multi) {
        foreach ($handles as $item) { curl_multi_remove_handle($multi, $item['curl']); curl_close($item['curl']); }
        curl_multi_close($multi);
    }
    if ($observer) $observer->close();
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
report(($fullVideo ? 'php-sustained-load-' : 'php-load-') . $count, $summary);
ensure($summary['passed'], 'Concurrent question or authenticated video delivery failed');
