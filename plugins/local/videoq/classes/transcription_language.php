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

/**
 * Stores the upload-time transcription language for source activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Upload language settings, independent of whether VideoQ is enabled yet.
 */
final class transcription_language {
    /** @var string Core activity form field. */
    public const FIELD = 'local_videoq_transcriptionlanguage';
    /** @var array Supported VideoQ transcription language names. */
    private const LANGUAGES = [
        'ja' => '日本語', 'en' => 'English', 'zh' => '中文', 'ko' => '한국어',
        'es' => 'Español', 'fr' => 'Français', 'de' => 'Deutsch', 'pt' => 'Português',
        'it' => 'Italiano', 'ru' => 'Русский', 'hi' => 'हिन्दी', 'ar' => 'العربية',
    ];

    /**
     * Lists the automatic option and supported upload languages.
     *
     * @return array
     */
    public static function options(): array {
        return ['' => get_string('transcriptionlanguageauto', 'local_videoq')] + self::LANGUAGES;
    }

    /**
     * Validates a portable language code, with empty meaning automatic detection.
     *
     * @param string $language Language code.
     * @return bool
     */
    public static function valid(string $language): bool {
        return $language === '' || isset(self::LANGUAGES[$language]);
    }

    /**
     * Reads the activity's upload language without requiring a remote binding.
     *
     * @param int $cmid Course-module identifier, or zero for a new activity.
     * @return string
     */
    public static function get(int $cmid): string {
        global $DB;
        return $cmid > 0 ? (string)($DB->get_field('local_videoq_languages', 'language', ['cmid' => $cmid]) ?: '') : '';
    }

    /**
     * Stores portable activity metadata; entry points enforce edit/restore permissions.
     *
     * @param int $cmid Destination course-module identifier.
     * @param string $language Validated upload language.
     * @return void
     */
    public static function save(int $cmid, string $language): void {
        global $DB;
        if ($cmid <= 0 || !self::valid($language)) {
            throw new \moodle_exception('invalidparameter');
        }
        if ($language === '') {
            $DB->delete_records('local_videoq_languages', ['cmid' => $cmid]);
            return;
        }
        $row = $DB->get_record('local_videoq_languages', ['cmid' => $cmid]);
        if ($row) {
            $DB->set_field('local_videoq_languages', 'language', $language, ['id' => $row->id]);
        } else {
            $DB->insert_record('local_videoq_languages', (object)['cmid' => $cmid, 'language' => $language]);
        }
    }

    /**
     * Adds the language selector to Moodle's File upload form.
     *
     * @param \moodleform_mod $form Activity form.
     * @param \MoodleQuickForm $mform QuickForm fields.
     * @return void
     */
    public static function form(\moodleform_mod $form, \MoodleQuickForm $mform): void {
        if (!$form instanceof \mod_resource_mod_form || !has_capability('moodle/course:manageactivities', $form->get_context())) {
            return;
        }
        $cm = $form->get_coursemodule();
        $mform->addElement('header', 'local_videoq_upload', get_string('managesync', 'local_videoq'));
        $mform->setExpanded('local_videoq_upload', true);
        $mform->addElement('select', self::FIELD, get_string('transcriptionlanguage', 'local_videoq'), self::options());
        $mform->setType(self::FIELD, PARAM_ALPHA);
        $mform->setDefault(self::FIELD, self::get((int)($cm->id ?? 0)));
    }

    /**
     * Allows language selection for new uploads, without reprocessing existing media.
     *
     * @param \moodleform_mod $form Activity form.
     * @param array $data Submitted fields.
     * @return array Field validation errors.
     */
    public static function validate(\moodleform_mod $form, array $data): array {
        global $DB, $USER;
        if (!$form instanceof \mod_resource_mod_form || !array_key_exists(self::FIELD, $data)) {
            return [];
        }
        $language = (string)$data[self::FIELD];
        if (!self::valid($language)) {
            return [self::FIELD => get_string('invalidtranscriptionlanguage', 'local_videoq')];
        }
        $cm = $form->get_coursemodule();
        if (!$cm || $language === self::get((int)$cm->id) || !$DB->record_exists('local_videoq_sync', ['cmid' => $cm->id])) {
            return [];
        }
        $current = synchronizer::video_file((int)$cm->id);
        $drafts = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            (int)($data['files'] ?? 0),
            'sortorder DESC, id ASC',
            false
        );
        $draft = reset($drafts);
        if (!$current || ($draft && $draft->get_contenthash() !== $current->get_contenthash())) {
            return [];
        }
        return [self::FIELD => get_string('transcriptionlanguageuploadonly', 'local_videoq')];
    }

    /**
     * Saves language only for a File activity the current actor may edit.
     *
     * @param \stdClass $moduleinfo Saved module fields.
     * @return void
     */
    public static function saved(\stdClass $moduleinfo): void {
        if (($moduleinfo->modulename ?? '') !== 'resource' || !property_exists($moduleinfo, self::FIELD)) {
            return;
        }
        $cmid = (int)$moduleinfo->coursemodule;
        require_capability('moodle/course:manageactivities', \context_module::instance($cmid));
        self::save($cmid, (string)$moduleinfo->{self::FIELD});
    }
}
