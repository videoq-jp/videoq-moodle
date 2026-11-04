# VideoQ for Moodle

Moodle plugins that let learners watch course videos and ask the VideoQ AI assistant
questions across the course. This repository independently maintains the Moodle
implementation, distribution packages, documentation, and integration lab.

## Installation

1. Download [local_videoq.zip](https://github.com/yukiharada1228/videoq-moodle/releases/download/v0.8.0/local_videoq.zip)
   and [ltisource_videoq.zip](https://github.com/yukiharada1228/videoq-moodle/releases/download/v0.8.0/ltisource_videoq.zip)
   from the development-preview release. Install them in that order through
   Moodle's plugin installer. **No Node.js, npm or JavaScript build is required.**
2. Sign in to the school's VideoQ account. Open **Settings → Connect Moodle**,
   enter the Moodle URL, and generate a connection code.
3. In Moodle, open **Site administration → Plugins → Local plugins → Connect to
   VideoQ** and paste the code.
4. Add a video as a **File** activity and enable **VideoQ questions** in its settings.

Tested with Moodle 5.1.8+ and the Boost theme. This integration is not LTI certified.
See the [setup guide](docs/setup.md) for detailed instructions.

## Compatible VideoQ server

The server must provide `/api/lti/*`, `/api/lms/*`, connection-code generation,
and connection revocation. At extraction, the corresponding APIs were in the
VideoQ development working tree. A build of the [source base commit](docs/extraction.md)
alone does not provide them. Connect to a deployment that includes those APIs.

## LTI materials-service setup

The [setup and migration guide](docs/materials-service.md) describes the new mode:
one LTI registration, automatic service authentication and Moodle-side background
exports directly to VideoQ storage. No synchronization API key is configured.
The compatible VideoQ implementation is required; the local trial has been
migrated and verified with real provider calls and the native Moodle chat.

The [architecture decision](docs/design/decision-lti-materials-handoff.md) explains
the division of responsibilities. The earlier pure media-pull proposal is retained
as historical analysis. Existing legacy connections remain compatible.

## Build installers with PHP

PHP CLI with `ext-zip` is sufficient. Neither npm dependencies nor a VideoQ
checkout is needed:

```sh
php scripts/package-plugins.php dist
php tests/packages.php
```

This creates `dist/local_videoq.zip`, `dist/ltisource_videoq.zip` and
`dist/SHA256SUMS`. Each archive has Moodle's required `videoq/` root. CI runs the
same PHP-only build and publishes ready-to-install ZIPs on version tags.

Browser JavaScript in `plugins/` is shipped directly as static assets. It connects
the native Moodle player/chat to VideoQ and needs no bundler or Node runtime.

## Development and verification

This repository has no Node.js runtime, package manager, build tool or CI action
requirement. PHP builds the installers; Python runs optional verification;
Selenium uses native Chrome/ChromeDriver. Browser JavaScript stays in `plugins/`
and is served directly by Moodle.

```sh
python3 -m venv .venv
.venv/bin/pip install -r lab/requirements.txt
.venv/bin/python -m unittest discover -s tests -p 'test_*.py' -v
.venv/bin/python lab/check_browser.py
```

The [Moodle lab](lab/README.md) connects to an independently deployed VideoQ
service. This repository does not build or start that server. Its implementation
and runtime dependencies belong to the VideoQ project.

See [verification coverage](docs/verification.md) for offline checks, browser
acceptance, background synchronization and the migration from earlier lab tools.
