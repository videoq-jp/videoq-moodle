# VideoQ for Moodle

Watch Moodle course videos and ask VideoQ questions across the course.

Requires Moodle 5.1 and a compatible VideoQ deployment. Development preview,
tested with the Boost theme.

## Installation

1. Download `local_videoq.zip` and `ltisource_videoq.zip` from
   [Releases](https://github.com/videoq-jp/videoq-moodle/releases).
   Install them through Moodle's plugin installer, in that order.
2. In Moodle, open **Site administration → Plugins → Local plugins → Connect to
   VideoQ** and enter your cloud or on-premises VideoQ HTTPS URL.
3. Sign in to VideoQ and allow the connection to return to Moodle automatically
   (requires the compatible backend described in the setup guide).
4. Add videos as **File** activities and enable **VideoQ questions**.

See the [setup guide](docs/setup.md) for details and the
[development guide](lab/README.md) for building and testing.
