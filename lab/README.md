# PHP-only Moodle interoperability lab

The plugin, packaging, optional mock AI service, verification tools and CI all use
PHP. No Node.js, Python, Composer package or Selenium binding is required. Browser
checks use PHP cURL to speak W3C WebDriver directly to native Chrome/ChromeDriver.
The browser still runs the static JavaScript required for chat and video controls.

The lab owns Moodle, its database, TLS proxy and scoped PHP synchronization runner.
Deploy VideoQ separately with its storage, queue, processing worker and materials
scheduler. This repository does not import, build or start that application.

## Offline checks

Use PHP 8.3+ with `curl`, `openssl`, `zip`, JSON and hash support. Live database
checks also use `pgsql`. These are PHP extensions, not another language runtime.

```sh
php tests/packages.php
php tests/tool-keys.php
php tests/lab.php
php tests/dependencies.php
php scripts/package-plugins.php dist
```

The package contracts run without network access. The mock HTTP contracts start
a loopback PHP development server, exercise real HTTP/SSE requests and stop it.

## Native browser checks

Install a matching native Chrome and ChromeDriver, then set `CHROME_BINARY` and
`CHROMEDRIVER`. Alternatively this PHP helper downloads a matching stable Linux
pair from Chrome for Testing:

```sh
php scripts/native-browser.php /private/native-browser
# Set the two environment variables printed by the helper.
php lab/check-browser.php
```

PHP starts and stops ChromeDriver for each browser session; it binds only loopback.
An already-managed WebDriver endpoint can be selected with `WEBDRIVER_URL` instead.
No Selenium server, Java service, package manager or JavaScript interpreter is used
by the automation harness. Never expose the WebDriver endpoint publicly.

## Start Moodle

1. Obtain Moodle 5.1 source with its `public/` directory and prepare a private
   persistent lab-data directory. Keep checkouts, operator configuration and
   fixtures outside `/tmp` if the lab must survive host cleanup/restarts.
2. Copy `lab/compose.env.example` outside source control and configure its paths.
3. Place `lesson.mp4` in `LTI_LAB_DIR`. Optional `php lab/generate-video.php DIR`
   uses native FFmpeg and a Japanese font to create an original silent lesson
   and two explicitly artificial test-tone videos. `--spoken` also uses native
   eSpeak to generate a short original spoken fixture. These media-generation
   binaries are optional; normal Moodle uploads need none of them.
4. Deploy a compatible external VideoQ service. The proxy's default upstreams
   `api:8787`, `web:80`, `garage:3900` must be reachable from the lab network.
   Configure alternatives in the private Compose env file. External Docker
   projects can attach to `videoq-lti-lab_default`.

```sh
docker compose --env-file /private/compose.env -p videoq-lti-lab -f lab/compose.yaml config --quiet
docker compose --env-file /private/compose.env -p videoq-lti-lab -f lab/compose.yaml up -d
```

Install a fresh Moodle database through its supported installer and use the normal
[connection-code setup](../docs/setup.md). Existing databases and media volumes
are preserved; never run `down --volumes` for data you want to retain. Source data
is uploaded through Moodle File activities and processed by the normal external
VideoQ worker. No fake vectors or internal application adapters are inserted.

The proxy serves `https://moodle.localhost:18443`, `https://videoq.localhost:18443`
and `https://storage.videoq.localhost:18443`. Forward port 18443 over SSH for a
remote host. Trust the lab's public CA in your browser. PHP peer/hostname checks
remain enabled; the scoped runner refreshes Moodle's readable public CA bundle:

```sh
docker exec videoq-lti-lab-moodle-1 php /lab/trust-lab-ca.php
docker exec -u www-data videoq-lti-lab-moodle-1 php /lab/check-materials-tls.php
```

## Automatic synchronization acceptance test

The migrated trial uses course 5, source 82 and installation `moodle-sync-lab`.
Mutation commands reject other base URLs; do not run them against production.\nLive checks share mutable fixtures and must run sequentially; a PHP file lock\nrejects overlapping runs instead of producing misleading preservation failures.
Reports go to `$LTI_LAB_DIR/acceptance`, defaulting to
`$HOME/.local/share/videoq-moodle-lab/acceptance`. They exclude private tokens and
keys. Consistent database/media snapshots remain private files outside this repo.

```sh
php lab/verify-chat.php                    # held browser stream, no paid answer
php lab/verify-chat.php --live             # one real provider question
php lab/check-materials.php                # disposable PHP auth/export contracts
php lab/verify-connection.php              # disposable pairing/disconnect UI
php lab/verify-background.php /private/spoken-lesson.mp4
php lab/verify-load.php --count 2 --live    # two real learners/concurrent questions
php lab/verify-backup.php
php lab/verify-media-backup.php
# Deliberate bounded outage; run only after other integration checks finish.
php lab/verify-readiness.php
```

Real AI commands may incur provider charges. `verify-load.php` supports 1–200
learners and measures question concurrency, not simultaneous video decoding.
The background check preserves existing videos, creates one uniquely tagged File,
waits for normal cron/export/processing, verifies two-video citation switching
without resetting the conversation, replaces its own File and verifies a new\nindexed video in the same course, deletes only its own source, and waits for
physical S3 cleanup. PHP signs its own lab S3 requests; it does not execute code
inside the external processing worker or require that worker's SDK.

The optional `--profile mock` service runs PHP's development server at `ai:8080`.
An external VideoQ deployment can explicitly use `http://ai:8080/v1`; starting
this profile alone never changes its real provider settings. Fixture responses
verify protocol/UI behavior, not retrieval quality or real transcription.

See [coverage](../docs/verification.md). Prior evidence remains historical;
only new PHP-run reports establish current results.
