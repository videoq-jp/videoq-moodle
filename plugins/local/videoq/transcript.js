// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.
// SPDX-License-Identifier: GPL-3.0-or-later.

/**
 * Displays accessible lesson transcripts through the authenticated LTI bridge.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';
    function init() {
        const workspace = document.querySelector('.local-videoq-workspace[data-videoq-origin]');
        const root = document.getElementById('videoq-transcript');
        if (!workspace || !root) return;
        const frame = workspace.querySelector('#videoq-questions');
        const origin = workspace.dataset.videoqOrigin;
        const select = root.querySelector('select');
        const cues = root.querySelector('ol');
        const status = root.querySelector('[role="status"]');
        const more = root.querySelector('#videoq-transcript-more');
        const refresh = root.querySelector('#videoq-transcript-refresh');
        let bridge = null, pending = null, cursor = null, timeout = null;
        let listed = false, connected = false;
        let videos = [];
        function clear() { cues.replaceChildren(); cursor = null; more.hidden = true; status.textContent = ''; }
        function busy(value) {
            select.disabled = value; more.disabled = value; refresh.disabled = value;
            root.setAttribute('aria-busy', String(value));
        }
        function failed() {
            window.clearTimeout(timeout); pending = null; listed = false; clear(); busy(false);
            status.textContent = root.dataset.error;
        }
        function request(action, append = false) {
            if (!bridge || !connected || pending) return;
            if (!append) clear();
            const requestId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
            pending = { requestId, action, append, videoId: Number(select.value) }; busy(true);
            frame.contentWindow.postMessage({ type: 'videoq:materials', version: 1, channel: bridge.channel, videoId: bridge.videoId,
                requestId, action, ...(action === 'transcript' ? { targetVideoId: pending.videoId, cursor: append ? cursor : 0 } : {}) }, origin);
            timeout = window.setTimeout(failed, 12000);
        }
        select.addEventListener('change', () => request('transcript'));
        more.addEventListener('click', () => request('transcript', true));
        refresh.addEventListener('click', () => request('list'));
        root.addEventListener('toggle', () => {
            if (root.open && !pending && !cues.children.length) request(listed ? 'transcript' : 'list');
        });
        window.addEventListener('message', event => {
            if (event.source !== frame.contentWindow || event.origin !== origin) return;
            const data = event.data;
            if (!data || data.version !== 1) return;
            if (data.type === 'videoq:ready' && typeof data.channel === 'string' && /^[a-f0-9]{32}$/.test(data.channel)
                    && Number.isSafeInteger(data.videoId) && data.videoId > 0) {
                if (bridge?.channel === data.channel) return;
                window.clearTimeout(timeout); pending = null; connected = false; listed = false;
                if (data.features?.transcripts !== true) { root.hidden = true; clear(); bridge = null; return; }
                root.hidden = false; clear(); bridge = data; return;
            }
            if (!bridge || data.channel !== bridge.channel || data.videoId !== bridge.videoId) return;
            if (data.type === 'videoq:chat' && data.event === 'status' && !connected) {
                connected = true; request('list'); return;
            }
            if (data.type !== 'videoq:materials-result' || !pending || data.requestId !== pending.requestId || data.action !== pending.action) return;
            window.clearTimeout(timeout);
            const operation = pending; pending = null; busy(false);
            if (!data.ok || !data.payload) { failed(); return; }
            const value = data.payload;
            if (operation.action === 'list') {
                clear(); select.replaceChildren(); videos = [];
                if (!value.permissions?.viewTranscript) { root.hidden = true; listed = false; return; }
                if (!Array.isArray(value.videos)) { failed(); return; }
                for (const video of value.videos.slice(0, 1000)) {
                    if (!Number.isSafeInteger(video.videoId) || video.videoId < 1 || typeof video.lesson !== 'string'
                            || !/^[a-z0-9_-]{1,64}$/.test(video.lesson) || typeof video.title !== 'string') continue;
                    videos.push(video);
                    const option = document.createElement('option'); option.value = String(video.videoId); option.textContent = video.title;
                    select.append(option);
                }
                root.hidden = !videos.length; listed = true;
                if (root.open && videos.length) request('transcript');
                return;
            }
            const video = videos.find(video => video.videoId === operation.videoId);
            if (!video || value.videoId !== video.videoId || !Array.isArray(value.cues)
                    || !(value.nextCursor === null || (Number.isSafeInteger(value.nextCursor) && value.nextCursor >= 0))) { failed(); return; }
            for (const cue of value.cues.slice(0, 100)) {
                if (!cue || typeof cue.text !== 'string' || typeof cue.startTime !== 'string'
                        || !Number.isFinite(cue.startSeconds) || cue.startSeconds < 0) continue;
                const row = document.createElement('li'), button = document.createElement('button'), text = document.createElement('span');
                row.className = 'mb-2 text-break'; button.type = 'button'; button.className = 'btn btn-link p-0 align-baseline';
                button.textContent = cue.startTime; button.setAttribute('aria-label', video.title + ' ' + cue.startTime + ' ' + root.dataset.seek);
                button.addEventListener('click', () => workspace.dispatchEvent(new CustomEvent('videoq:seek-citation', {
                    detail: { videoId: video.videoId, lesson: video.lesson, seconds: cue.startSeconds },
                })));
                text.className = 'local-videoq-transcript-text'; text.textContent = ' ' + cue.text; row.append(button, text); cues.append(row);
            }
            cursor = value.nextCursor; more.hidden = cursor === null;
            status.textContent = cues.children.length ? '' : root.dataset.empty;
        });
        window.addEventListener('pagehide', () => window.clearTimeout(timeout), { once: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
