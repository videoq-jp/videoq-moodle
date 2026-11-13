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

namespace local_videoq\output;

/**
 * Exports public Moodle player and learning-panel presentation data.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workspace implements \renderable, \templatable {
    /** @var int Current Moodle course. */
    private int $courseid;
    /** @var array Accessible media descriptors. */
    private array $catalog;
    /** @var string Selected lesson. */
    private string $lesson;
    /** @var array Current Moodle source actions. */
    private array $managedsources;
    /** @var bool Whether the course has a current learning anchor. */
    private bool $connected;
    /** @var string Current linked VideoQ course title. */
    private string $title;
    /** @var bool Whether the current teacher can manage the connection. */
    private bool $canconnect;
    /**
     * Stores server-checked presentation data only.
     *
     * @param int $courseid Current course.
     * @param array $catalog Accessible source descriptors without credentials.
     * @param string $lesson Selected lesson identifier.
     * @param array $managedsources Moodle source action links.
     * @param bool $connected Whether a current learning anchor exists.
     * @param string $title Current course title.
     * @param bool $canconnect Whether connection settings are available.
     */
    public function __construct(
        int $courseid,
        array $catalog,
        string $lesson,
        array $managedsources,
        bool $connected,
        string $title = '',
        bool $canconnect = false
    ) {
        $this->courseid = $courseid;
        $this->catalog = $catalog;
        $this->lesson = $lesson;
        $this->managedsources = $managedsources;
        $this->connected = $connected;
        $this->title = $title;
        $this->canconnect = $canconnect;
    }

    /**
     * Supplies the Moodle templates and native JavaScript bootstrap.
     *
     * @param \renderer_base $output Active theme renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $selected = null;
        $options = [];
        foreach ($this->catalog as $item) {
            $isselected = $item['lesson'] === $this->lesson;
            $options[] = ['lesson' => $item['lesson'], 'title' => $item['title'], 'selected' => $isselected];
            if ($isselected) {
                $selected = $item;
            }
        }
        $message = ['id' => '', 'text' => '', 'formattedtime' => userdate(time(), get_string('strftimetime', 'langconfig'))];
        return [
            'coursetitle' => $this->title ?: get_string('courseassistant', 'local_videoq'),
            'backurl' => (new \moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
            'connectionurl' => (new \moodle_url('/local/videoq/teacherconnect.php', ['course' => $this->courseid]))->out(false),
            'canconnect' => $this->canconnect,
            'connected' => $this->connected,
            'hasplayer' => $selected !== null,
            'youtube' => ($selected['kind'] ?? '') === 'youtube',
            'videos' => $options,
            'lesson' => $this->lesson,
            'catalog' => json_encode($this->catalog, JSON_THROW_ON_ERROR),
            'config' => json_encode(['courseid' => $this->courseid, 'lesson' => $this->lesson,
                'streamUrl' => (new \moodle_url('/local/videoq/stream.php'))->out(false),
                'copy' => \local_videoq\ui_strings::data()], JSON_THROW_ON_ERROR),
            'videourl' => $selected['url'] ?? '',
            'videomime' => $selected['mime'] ?? 'video/mp4',
            'activityurl' => $selected['activityUrl'] ?? '',
            'videoclass' => get_config('media_videojs', 'videocssclass') ?: 'video-js',
            'videosetup' => json_encode(['fluid' => true, 'playbackRates' => [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2],
                'userActions' => ['hotkeys' => true], 'language' => current_language()], JSON_THROW_ON_ERROR),
            'managedsources' => $this->managedsources,
            'hasmanagedsources' => (bool)$this->managedsources,
            'heading' => get_string('coursequestions', 'local_videoq'),
            'connectionerror' => get_string('chatconnectionerror', 'local_videoq'),
            'seeksuffix' => get_string('chatseek', 'local_videoq'),
            'feedbackgood' => get_string('chatfeedbackgood', 'local_videoq'),
            'feedbackbad' => get_string('chatfeedbackbad', 'local_videoq'),
            'empty' => get_string('chatempty', 'local_videoq'),
            'connecting' => get_string('chatconnecting', 'local_videoq'),
            'hint' => get_string('chathint', 'local_videoq'),
            'messagemaxlength' => 4000,
            'showemojipicker' => false,
            'questionmessage' => $message + ['fromloggedinuser' => true],
            'answermessage' => $message + ['fromloggedinuser' => false, 'userfrom' => [
                'fullname' => get_string('assistant', 'local_videoq'),
                'profileimageurl' => $output->image_url('u/f2', 'core')->out(false),
            ]],
        ];
    }
}
