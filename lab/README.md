# Moodle interoperability lab

This lab owns Moodle, its database, a TLS reverse proxy and the scoped PHP
synchronization runner. An optional Python service supplies deterministic
OpenAI-compatible responses. It does **not** build or start VideoQ. Configure a
compatible VideoQ deployment separately, including its storage, queue, processing
worker and materials reconciliation scheduler.

No Node.js, npm, Playwright or JavaScript server tool is required anywhere in this
repository. The two shipped `.js` files run only inside learners' browsers.
Selenium uses native Chrome/ChromeDriver and a native Rust manager.

## Install verification tools

Use Python 3.12+ and native Chrome/ChromeDriver (Selenium Manager can download
matching native binaries). FFmpeg and a Japanese font are optional for media
fixture generation. PHP CLI with `ext-zip` builds the plugin installers.

```sh
python3 -m venv .venv
.venv/bin/pip install -r lab/requirements.txt
.venv/bin/python -m unittest discover -s tests -p 'test_*.py' -v
.venv/bin/python lab/check_browser.py
php tests/packages.php
php scripts/package-plugins.php dist
```

Set `CHROME_BINARY` and `CHROMEDRIVER` to use existing native executables without
manager downloads. The package build and installer contract run without network
access and do not install Python dependencies.

## Start the Moodle side

1. Put a compatible Moodle 5.1 source tree at a private local path. Its `public/`
   directory must exist. Plugin source is mounted read-only into that tree.
2. Copy `lab/compose.env.example` outside source control and configure paths.
3. Generate the original fixture below, or place your own `lesson.mp4` in
   `LTI_LAB_DIR`; Compose mounts that file as a fixture only.
4. Deploy VideoQ independently and make its HTTPS service reachable from Moodle.
   The sample proxy upstreams `api:8787`, `web:80`, `garage:3900` refer to external
   services on the lab network. Override them in your private Compose env file.
   If using separate Docker projects, attach the external deployment to the
   `videoq-lti-lab_default` network. Never use these public test credentials or
   loopback CA outside a disposable lab.

```sh
.venv/bin/python lab/generate_video.py /tmp/videoq-lti-lab
docker compose --env-file /private/compose.env -p videoq-lti-lab -f lab/compose.yaml config --quiet
docker compose --env-file /private/compose.env -p videoq-lti-lab -f lab/compose.yaml up -d
```

For a fresh database, install Moodle through its supported CLI/web installer;
`lab/moodle-config.php` is the loopback-only configuration. Connect with the
normal [connection-code setup](../docs/setup.md). This lab does not seed VideoQ
by importing application source or writing embeddings directly. Upload normal
Moodle File activities and let the actual synchronization pipeline process them.
Existing lab data volumes are preserved by the consolidated Compose file. Never
run `down --volumes` against a lab containing data you want to retain.

The proxy serves `https://moodle.localhost:18443`, `https://videoq.localhost:18443`
and `https://storage.videoq.localhost:18443`. Use SSH forwarding for port 18443
when accessing a remote host. `localhost` names resolve on your browser's host.
For trusted server-to-server HTTPS, `run-sync.sh` copies only the public Caddy CA
into Moodle's readable trust bundle. Test it as the web-server user:

```sh
docker exec videoq-lti-lab-moodle-1 php /lab/trust-lab-ca.php
docker exec -u www-data videoq-lti-lab-moodle-1 php /lab/check-materials-tls.php
```

## Automatic synchronization acceptance test

The current migrated acceptance fixture is course 5, source activity 82, external
installation `moodle-sync-lab`. Mutation commands deliberately reject other URLs.
Update guarded PHP fixtures explicitly before using a different disposable lab.
Do not point these tests at a production deployment.

```sh
# Real Moodle/LTI handshake; held browser-only answer, no paid model call.
.venv/bin/python lab/verify_chat.py
# One actual provider question, feedback and timestamp seek (may incur charges).
.venv/bin/python lab/verify_chat.py --live
# Real cron, signed export, normal external worker, indexing and cleanup.
.venv/bin/python lab/verify_background.py --media /private/original-spoken-lesson.mp4
# Service authentication/export/permissions contracts (no paid model).
.venv/bin/python lab/check_materials.py
# Disposable connection and disconnect UI; existing connection retained.
.venv/bin/python lab/verify_connection.py
# Small real concurrency smoke (increase only for a deliberate capacity test).
.venv/bin/python lab/verify_load.py --count 2 --live
# Consistent snapshots and scratch-database restores, preserving live DBs.
.venv/bin/python lab/verify_backup.py
.venv/bin/python lab/verify_media_backup.py
```

`verify_background.py` creates only a uniquely tagged temporary File in the
existing course, waits for automatic export/indexing, verifies course membership,
deletes that same source, then checks acknowledgement and physical S3 cleanup.
It preserves all pre-existing videos. The external worker is selected through
`VIDEOQ_TEST_WORKER` (default `videoq-runtime-worker-1`). It must have its normal
S3 Python SDK and lab storage settings; test helpers are sent over stdin without
adding mounts to the external application.

Reports and screenshots go outside the checkout under
`$LTI_LAB_DIR/acceptance` (default `/tmp/videoq-lti-lab/acceptance`). Reports never
include session tokens, provider keys or private JWKs. Database snapshots are
private files; they are not release artifacts.

Optional `--profile mock` starts the stdlib Python fixture on `ai:8080`. A
separately managed VideoQ server can opt into `http://ai:8080/v1`; merely starting
this profile never changes the real deployment's provider configuration. Mock
answers prove UI behavior, not retrieval quality or real transcription.

See [coverage and migration](../docs/verification.md). Historical acceptance
JSON in `docs/evidence/` records earlier runs; it is not a fresh test result.
