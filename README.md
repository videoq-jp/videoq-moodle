# VideoQ for Moodle

Watch Moodle course videos and ask VideoQ questions across the course.

Requires Moodle 5.1 and a compatible VideoQ deployment. Development preview,
tested with the Boost theme.

## Installation

1. Download `local_videoq.zip` and `ltisource_videoq.zip` from
   [Releases](https://github.com/yukiharada1228/videoq-moodle/releases).
   Install them through Moodle's plugin installer, in that order.
2. In VideoQ, open **Settings → Connect Moodle**, enter your Moodle URL,
   and generate a connection code.
3. In Moodle, open **Site administration → Plugins → Local plugins → Connect to
   VideoQ** and paste the code.
4. Add videos as **File** activities and enable **VideoQ questions**.

See the [setup guide](docs/setup.md) for details and the
[development guide](lab/README.md) for building and testing.
