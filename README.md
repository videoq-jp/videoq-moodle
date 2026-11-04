# VideoQ for Moodle

Moodle plugins that let learners watch course videos and ask the VideoQ AI assistant
questions across the course. This repository independently maintains the Moodle
implementation, distribution packages, documentation, and integration lab.

## Installation

1. Download [local_videoq.zip](https://github.com/yukiharada1228/videoq-moodle/releases/download/v0.7.1/local_videoq.zip)
   and [ltisource_videoq.zip](https://github.com/yukiharada1228/videoq-moodle/releases/download/v0.7.1/ltisource_videoq.zip)
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

## Optional development lab

Node.js is confined to optional tools under `lab/`, including browser automation
and the separate VideoQ trial environment. Plugin installation and PHP packaging
do not use these tools:

```sh
npm ci --prefix lab
npm run check --prefix lab
npm test --prefix lab
```

Use the Node version in `lab/.nvmrc`. See [lab instructions](lab/README.md) for
browser and integration tests. VideoQ itself has its own deployment requirements;
this repository does not change the VideoQ server's runtime.

## Repository responsibilities

| This repository | VideoQ application |
|---|---|
| `plugins/local/videoq`: synchronization, video/chat interface, connection setup | LTI authentication, sessions, connection registration and revocation APIs |
| `plugins/ltisource/videoq`: access proofs supplied at LTI launch | Video management, transcription, retrieval, AI answers |
| `scripts/`: package generation and source checks | School accounts, quotas, storage, workers |
| `lab/`: Moodle integration environment and acceptance checks | API and database migrations |

The plugins connect through HTTPS LTI and synchronization APIs. Connection-code
issuance and disconnection controls belong to VideoQ, which manages the account.
The plugins do not import VideoQ application source at runtime.

## Integration testing

Follow the [lab instructions](lab/README.md). Integration tests additionally
require a built checkout of the [VideoQ application](https://github.com/yukiharada1228/videoq).

Use `lab/compose.env.example` as a template. Set `VIDEOQ_ROOT` to the application
checkout and `MOODLE_INTEGRATION_ROOT` to this checkout, using absolute paths.
Compose mounts the plugins from this repository and the API and worker from VideoQ.

The isolated lab contains public fixture credentials; never use them in production.
Real API keys, uploaded media, acceptance artifacts, and backups stay outside this
repository.

## Licensing and provenance

The Moodle plugins retain their existing **GPL-3.0-or-later** license; see each
plugin's `LICENSE` file. Lab scripts and documentation retain the source project's
[PolyForm Noncommercial 1.0.0 license](LICENSE). The VideoQ application license is
unchanged.

The [extraction record](docs/extraction.md) documents the source and repository boundary.
