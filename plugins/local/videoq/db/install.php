<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();
function xmldb_local_videoq_install() {
    set_config('accesssigningkey', bin2hex(random_bytes(32)), 'local_videoq');
}
