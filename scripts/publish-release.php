<?php
/** Resume release publication after ambiguous API failures; never replace published assets. */
declare(strict_types=1);
final class ReleasePublisher {
    public function __construct(private Closure $request, private Closure $pause) {}
    private static function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    private function api(string $method, string $path, ?array $body = null, ?string $file = null): array {
        $response = ($this->request)($method, $path, $body, $file);
        if ($response['status'] >= 500 || in_array($response['status'], [0, 408, 429], true)) throw new UnexpectedValueException('Transient release API failure');
        return $response;
    }
    private function find(string $base, string $tag): ?array {
        $response = $this->api('GET', $base . '/releases/tags/' . rawurlencode($tag));
        if ($response['status'] === 200) return $response['body'];
        self::check($response['status'] === 404, 'Cannot inspect release (HTTP ' . $response['status'] . ')');
        // A create request may have committed a draft before its response failed.
        $response = $this->api('GET', $base . '/releases?per_page=100');
        self::check($response['status'] === 200, 'Cannot inspect draft releases');
        foreach ($response['body'] as $release) if ($release['tag_name'] === $tag) return $release;
        return null;
    }
    public function publish(string $repository, string $tag, string $directory): array {
        self::check((bool)preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository), 'Invalid repository');
        self::check((bool)preg_match('/^v\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $tag), 'Invalid release tag');
        $names = ['local_videoq.zip', 'SHA256SUMS']; $hashes = [];
        foreach ($names as $name) { self::check(is_file($directory . '/' . $name), 'Missing release asset'); $hashes[$name] = hash_file('sha256', $directory . '/' . $name); }
        $lines = file($directory . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::check(count($lines) === 1, 'Invalid checksum manifest'); $listed = [];
        foreach ($lines as $line) {
            self::check((bool)preg_match('/^([a-f0-9]{64})  (local_videoq\.zip)$/D', $line, $match), 'Invalid checksum entry');
            self::check(!isset($listed[$match[2]]) && hash_equals($hashes[$match[2]], $match[1]), 'Installer checksum mismatch'); $listed[$match[2]] = true;
        }
        $base = 'https://api.github.com/repos/' . $repository;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $release = $this->find($base, $tag);
                if (!$release) {
                    $response = $this->api('POST', $base . '/releases', ['tag_name'=>$tag, 'name'=>'VideoQ for Moodle ' . $tag, 'draft'=>true, 'prerelease'=>true,
                        'body'=>"A single Moodle plugin with PHP-only packaging and verification. A compatible separately deployed VideoQ service is required.\n\nInstall local_videoq.zip only. The retired ltisource_videoq helper is not required. Verify downloads with SHA256SUMS."]);
                    // Another attempt/job may already have created this release.
                    if ($response['status'] === 422) throw new UnexpectedValueException('Reconcile concurrent release creation');
                    self::check($response['status'] === 201, 'Cannot create draft release'); $release = $response['body'];
                }
                self::check($release['tag_name'] === $tag, 'Release tag mismatch'); $assets = [];
                foreach ($release['assets'] as $asset) $assets[$asset['name']] = $asset;
                foreach ($names as $name) {
                    if (isset($assets[$name])) {
                        self::check(($assets[$name]['state'] ?? '') === 'uploaded' && ($assets[$name]['digest'] ?? '') === 'sha256:' . $hashes[$name], 'Existing release asset differs: ' . $name);
                        continue;
                    }
                    self::check($release['draft'], 'Published release has a missing asset');
                    $response = $this->api('POST', 'https://uploads.github.com/repos/' . $repository . '/releases/' . $release['id'] . '/assets?name=' . rawurlencode($name), null, $directory . '/' . $name);
                    if ($response['status'] === 422) throw new UnexpectedValueException('Reconcile concurrent asset upload');
                    self::check($response['status'] === 201 && ($response['body']['digest'] ?? '') === 'sha256:' . $hashes[$name], 'Release asset upload failed');
                }
                if ($release['draft']) {
                    $response = $this->api('PATCH', $base . '/releases/' . $release['id'], ['draft'=>false, 'prerelease'=>true]);
                    self::check($response['status'] === 200 && !$response['body']['draft'], 'Cannot publish release');
                }
                return ['tag'=>$tag, 'assetsVerified'=>count($names), 'published'=>true];
            } catch (UnexpectedValueException $error) {
                if ($attempt === 4) throw new RuntimeException('Release API remained unavailable after reconciliation retries');
                // Re-read server state before retrying any mutation. Never delete a
                // draft to recover, and never overwrite an existing release asset.
                ($this->pause)(min(8, 1 << $attempt));
            }
        }
        throw new RuntimeException('Release publication incomplete');
    }
}
function github_release_request(#[SensitiveParameter] string $token): Closure {
    return static function(string $method, string $url, ?array $body, ?string $file) use ($token): array {
        $curl = curl_init($url); $stream = null;
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: videoq-moodle-release'];
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>60, CURLOPT_FOLLOWLOCATION=>false]);
            if ($file) { $stream = fopen($file, 'rb'); $headers[] = 'Content-Type: ' . (str_ends_with($file, '.zip') ? 'application/zip' : 'text/plain'); curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>filesize($file)]); }
            elseif ($body !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers]);
            $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            return ['status'=>$raw === false ? 0 : $status, 'body'=>$raw === false ? [] : (json_decode($raw,true) ?? [])];
        } finally { curl_close($curl); if (is_resource($stream)) fclose($stream); }
    };
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $token = getenv('GH_TOKEN') ?: ''; if (!$token) throw new RuntimeException('GH_TOKEN is required');
    $publisher = new ReleasePublisher(github_release_request($token), static fn(int $seconds)=>sleep($seconds));
    echo json_encode($publisher->publish(getenv('GH_REPO') ?: '', $argv[1] ?? '', $argv[2] ?? 'dist'), JSON_THROW_ON_ERROR) . "\n";
}
