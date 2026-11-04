# VideoQ for Moodle

Moodle plugins that let learners watch course videos and ask the VideoQ AI assistant
questions across the course. This repository independently maintains the Moodle
implementation, distribution packages, documentation, and integration lab.

## Installation

1. Download [local_videoq.zip](https://github.com/yukiharada1228/videoq-moodle/releases/download/v0.9.0/local_videoq.zip)
   and [ltisource_videoq.zip](https://github.com/yukiharada1228/videoq-moodle/releases/download/v0.9.0/ltisource_videoq.zip)
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

## PHP-only development and verification

The plugins, installer build, optional mock AI, verification tools and CI use PHP.
No Node.js, Python or Composer package installation is needed. Browser automation
uses PHP cURL directly with native Chrome/ChromeDriver; the static browser
JavaScript is served by Moodle unchanged.

```sh
php tests/packages.php
php tests/tool-keys.php
php tests/lab.php
php tests/dependencies.php
php scripts/package-plugins.php dist
```

See the [lab](lab/README.md) for optional native-browser and live integration
checks, and [verification coverage](docs/verification.md) for their boundaries.
VideoQ is a separately deployed service whose runtime remains owned by its project.
