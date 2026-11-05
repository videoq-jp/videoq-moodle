# VideoQ LTI integration for Moodle

Connect Moodle course activities to the VideoQ AI video assistant using LTI 1.3.
This companion plugin requires `local_videoq`; install both plugins before
connecting to VideoQ from Moodle's administration page.

The plugin is GPL-3.0-or-later. A compatible cloud or on-premises VideoQ server,
an owning VideoQ account and processing allowance are required. Hosting and AI
service usage are separate from this free Moodle plugin.

Moodle owns enrollment, source videos and playback. VideoQ receives LTI user,
course and resource identifiers to authorize questions and citations. See the
[setup and access guide](https://github.com/videoq-jp/videoq-moodle/blob/main/docs/setup.md)
and each plugin's Privacy API metadata before enabling the integration.

Supported test environment: Moodle 5.1 with Boost. Other versions and themes
have not been verified. This is a development preview, not a certified integration.

Source and issues: https://github.com/videoq-jp/videoq-moodle
