# Marketplace submission candidate

Current candidate: **local_videoq 0.25.4 / 2026111305**, Moodle 5.1, maturity alpha. Not submitted or approved. One component and one installer: `local_videoq.zip`. The retired `ltisource_videoq` helper must not be submitted or installed for this version.

Use the root-layout `moodle-local_videoq` source export from `scripts/export-plugin-sources.php`. The canonical source repository is `https://github.com/videoq-jp/videoq-moodle`. Do not advertise a second installable component or a nonexistent source branch.

Run the PHP-only package, official Moodle coding, Privacy API, native browser, PostgreSQL/MySQL update/uninstall/reinstall and single-plugin checks before preparing a submission. Marketplace installers use English strings; Japanese translations are prepared separately through AMOS. The ordinary GitHub installer retains Japanese.

Install one plugin, authorize school communication as the administrator, then let each teacher connect an owned VideoQ course. Learners use the Moodle course assistant. The service is separate and must implement teacher-owned connections; no Moodle-side video import or installation-wide ownership is offered.

The package has no non-core plugin dependencies. Existing helper installations may be retired through Moodle's plugin overview after the local plugin is updated. See [setup](setup.md) for the exact steps and [privacy](privacy.md) for data handling. Do not claim Marketplace approval or legal certification from test results.
