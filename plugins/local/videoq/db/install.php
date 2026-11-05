<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
// SPDX-License-Identifier: GPL-3.0-or-later.

/**
 * Initializes the local access-proof signing key and optional manual settings.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initializes learner access proofs and empty defaults for optional manual setup.
 *
 * @return void
 */
function xmldb_local_videoq_install() {
    set_config('accesssigningkey', bin2hex(random_bytes(32)), 'local_videoq');
    // Manual connection is optional; do not prompt for it during initial installation.
    foreach (['apiurl' => '', 'synctoken' => '', 'typeid' => '', 'lessons' => '[]'] as $name => $value) {
        set_config($name, $value, 'local_videoq');
    }
}
