<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/** Administrator-owned associations; browser parameters cannot choose modules. */
final class lesson {
    /** Resolve the shared course entry afresh; it must survive removal of a video. */
    public static function for_course(int $courseid): ?array {
        global $USER, $DB;
        $info = get_fast_modinfo($courseid, $USER->id);
        $typeid = (int)get_config('local_videoq', 'typeid');
        foreach (course_access::lessons($courseid) as $item) {
            if (!isset($info->cms[$item['videoCmid']], $info->cms[$item['ltiCmid']])) continue;
            $video = $info->cms[$item['videoCmid']];
            $lti = $info->cms[$item['ltiCmid']];
            if (!$video->uservisible || !$lti->uservisible || $video->deletioninprogress || $lti->deletioninprogress
                    || ($typeid && (int)$DB->get_field('lti', 'typeid', ['id' => $lti->instance]) !== $typeid)
                    || !has_capability('mod/resource:view', \context_module::instance($video->id))
                    || !has_capability('mod/lti:view', \context_module::instance($lti->id))) continue;
            return self::find($item['id']);
        }
        return null;
    }
    public static function parse(string $json): array {
        $items = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (!is_array($items) || !array_is_list($items) || count($items) > 500) {
            throw new \InvalidArgumentException('Expected a list of lessons');
        }
        $lessons = [];
        foreach ($items as $item) {
            if (!($item instanceof \stdClass)) {
                throw new \InvalidArgumentException('Expected a lesson object');
            }
            $item = (array)$item;
            if (!is_array($item) || count($item) !== 4
                    || !isset($item['id'], $item['title'], $item['videoCmid'], $item['ltiCmid'])
                    || !is_string($item['id']) || !preg_match('/^[a-z0-9_-]{1,64}$/D', $item['id'])
                    || !is_string($item['title']) || trim($item['title']) === '' || strlen($item['title']) > 500
                    || !is_int($item['videoCmid']) || $item['videoCmid'] < 1
                    || !is_int($item['ltiCmid']) || $item['ltiCmid'] < 1
                    || isset($lessons[$item['id']])) {
                throw new \InvalidArgumentException('Invalid or duplicate lesson');
            }
            $lessons[$item['id']] = $item;
        }
        return $lessons;
    }

    public static function find(string $id): array {
        global $DB;
        if (preg_match('/^sync_([1-9][0-9]*)$/D', $id, $match)) {
            $row = $DB->get_record('local_videoq_sync', ['cmid' => (int)$match[1], 'enabled' => 1]);
            if (!$row) throw new \moodle_exception('unknownlesson', 'local_videoq');
            $resource = $DB->get_record('course_modules', ['id' => $row->cmid, 'deletioninprogress' => 0], '*', MUST_EXIST);
            $title = $DB->get_field('resource', 'name', ['id' => $resource->instance], MUST_EXIST);
            return ['id' => $id, 'title' => $title, 'videoCmid' => (int)$row->cmid, 'ltiCmid' => (int)$row->lticmid, 'syncStatus' => $row->status];
        }
        try {
            $lessons = self::parse(get_config('local_videoq', 'lessons') ?: '[]');
        } catch (\Throwable $error) {
            throw new \moodle_exception('invalidconfiguration', 'local_videoq');
        }
        if (!isset($lessons[$id])) {
            throw new \moodle_exception('unknownlesson', 'local_videoq');
        }
        // Old bookmarks follow the source's current synchronized binding.
        if ($DB->record_exists('local_videoq_sync', ['cmid' => $lessons[$id]['videoCmid']])) {
            return self::find('sync_' . $lessons[$id]['videoCmid']);
        }
        return $lessons[$id];
    }
}
