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
 * Validates legacy video activity associations.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/**
 * Validates legacy video activity associations.
 */
final class admin_setting_lessons extends \admin_setting_configtextarea {
    /**
     * Validates lesson mapping settings and returns a localized error if invalid.
     *
     * @param string $data Stored connection or restored binding metadata.
     * @return bool|string
     */
    public function validate($data) {
        try {
            lesson::parse($data);
            return true;
        } catch (\Throwable $error) {
            return get_string('invalidconfiguration', 'local_videoq');
        }
    }
}
