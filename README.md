# VideoQ for Moodle

Watch Moodle course videos and ask VideoQ questions across the course.

Requires Moodle 5.1 and a compatible VideoQ deployment. Development preview,
tested with the Boost theme.

## Installation

1. Clone this repository and place `plugins/local/videoq` in Moodle's
   `public/local/videoq` and `plugins/ltisource/videoq` in
   `public/mod/lti/source/videoq` (Moodle 5.1). Run Moodle's database upgrade.
   See the [Git installation commands](docs/setup.md#install-from-git).
2. In Moodle, open **Site administration → Plugins → Local plugins → Connect to
   VideoQ** and enter your cloud or on-premises VideoQ HTTPS URL.
3. Sign in to VideoQ and allow the connection to return to Moodle automatically
   (the current `https://videoq.jp` service supports this flow).
4. Add videos as **File** activities and enable **VideoQ questions**.

See the [setup guide](docs/setup.md) for details and the
[development guide](lab/README.md) for building and testing.
