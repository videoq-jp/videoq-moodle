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
 * Bridges authorized citation seeks to the Moodle player.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Restricted player bridge. Native chat has its own authenticated channel; API tokens stay in the tool frame.
(function() {
    'use strict';
    function init() {
        const root = document.querySelector('.local-videoq-workspace[data-videoq-origin]');
        if (!root) return;
        const getVideo = () => root.querySelector('video');
        let videojs = null;
        if (typeof window.require === 'function') window.require(['media_videojs/video-lazy'], module => { videojs = module.default || module; });
        const getPlayer = () => videojs?.getPlayer(root.querySelector('.video-js')) || root.querySelector('.video-js')?.player;
        const sameUrl = (a, b) => Boolean(a && b && new URL(a, location.href).href === new URL(b, location.href).href);
        const frame = root.querySelector('#videoq-questions');
        const origin = root.dataset.videoqOrigin;
        if (!frame) return;
        const lessons = JSON.parse(root.dataset.videoqLessons || '[]');
        const selector = root.querySelector('#videoq-lesson');
        let activeLesson = root.dataset.videoqLesson;
        let videos = [];
        let pendingSeek = null;
        const videoPanel = root.querySelector('.local-videoq-video');
        const chatPanel = root.querySelector('.local-videoq-questions');
        function resizeChat() {
            if (window.matchMedia('(max-width: 900px)').matches) {
                chatPanel.style.removeProperty('height');
            } else {
                chatPanel.style.height = videoPanel.getBoundingClientRect().height + 'px';
            }
        }
        window.addEventListener('resize', resizeChat, { passive: true });
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(resizeChat).observe(videoPanel);
        }
        requestAnimationFrame(resizeChat);
        let channel = null;
        let videoId = null;
        function sendTheme() {
            const sample = document.createElement('button');
            sample.className = 'btn btn-primary';
            sample.tabIndex = -1;
            sample.setAttribute('aria-hidden', 'true');
            sample.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
            root.append(sample);
            const buttonStyle = getComputedStyle(sample);
            const bodyStyle = getComputedStyle(root);
            const panelStyle = getComputedStyle(root.querySelector('.local-videoq-panel'));
            const muted = root.querySelector('.text-muted');
            const theme = {
                primary: buttonStyle.backgroundColor,
                'on-primary': buttonStyle.color,
                text: bodyStyle.color,
                surface: panelStyle.backgroundColor,
                border: panelStyle.borderTopColor,
                muted: muted ? getComputedStyle(muted).color : bodyStyle.color,
                font: bodyStyle.fontFamily,
            };
            sample.remove();
            frame.contentWindow.postMessage({ type: 'videoq:theme', version: 1, channel, videoId, theme }, origin);
        }
        function selectVideo(target, seconds, play) {
            const item = lessons.find(lesson => lesson.lesson === target.lesson);
            const video = getVideo();
            const player = getPlayer();
            if (!item || !video) return;
            const changed = !sameUrl(video.currentSrc, item.url) || (player && !sameUrl(player.currentSource()?.src, item.url));
            if (!changed && Number.isFinite(video.duration) && seconds > video.duration) return;
            pendingSeek = { seconds, play, item, target, waitForMetadata: changed };
            if (selector) { selector.value = activeLesson; selector.setAttribute('aria-busy', 'true'); }
            if (changed) {
                // Keep the native controls/source handlers in sync through Moodle's Video.js instance.
                if (player && !player.isDisposed()) player.src({ src: item.url, type: item.mime || 'video/mp4' });
                else { video.src = item.url; video.load(); }
                // Start the play request during the actual user click (important for Safari).
                if (play) void (player ? player.play() : video.play())?.catch(() => {});
            } else applySeek();
        }
        function applySeek(event) {
            const video = getVideo();
            const player = getPlayer();
            if (!pendingSeek || !video || !Number.isFinite(video.duration)) return;
            if (pendingSeek.waitForMetadata) {
                if (event?.type !== 'loadedmetadata') return;
                pendingSeek.waitForMetadata = false;
            }
            if (!sameUrl(video.currentSrc, pendingSeek.item.url)) return;
            // Some browsers accept currentTime at HAVE_METADATA but reset it while
            // decoding the first frame. Wait for media data before applying the cue.
            if (video.readyState < 2) return;
            const target = pendingSeek;
            pendingSeek = null;
            // Commit the selected lesson only once its own media is loaded.
            activeLesson = target.item.lesson;
            videoId = target.target.videoId;
            if (selector) { selector.value = activeLesson; selector.removeAttribute('aria-busy'); }
            if (target.seconds > video.duration) return;
            if (player) player.currentTime(target.seconds); else video.currentTime = target.seconds;
            if (target.play) void (player ? player.play() : video.play())?.catch(() => {});
            const panel = root.querySelector('.local-videoq-video');
            const bounds = panel.getBoundingClientRect();
            if (bounds.top < 0 || bounds.bottom > window.innerHeight) panel.scrollIntoView({ block: 'start', behavior: 'instant' });
            send();
        }
        root.addEventListener('videoq:seek-citation', event => {
            const detail = event.detail;
            if (!detail || !Number.isFinite(detail.seconds) || detail.seconds < 0 || detail.seconds > 604800) return;
            const target = videos.find(item => item.videoId === detail.videoId && item.lesson === detail.lesson);
            if (target) selectVideo(target, detail.seconds, true);
        });
        ['loadedmetadata', 'loadeddata', 'canplay', 'progress'].forEach(event => root.addEventListener(event, applySeek, true));
        selector?.addEventListener('change', () => {
            const target = videos.find(item => item.lesson === selector.value);
            if (target) selectVideo(target, 0, false);
        });
        function readPlayback() {
            const video = getVideo();
            if (!video || pendingSeek || !channel || !Number.isFinite(video.duration) || !Number.isFinite(video.currentTime)) return null;
            return { videoId, seconds: video.currentTime, duration: video.duration };
        }
        root.addEventListener('videoq:read-playback', event => {
            if (event.detail && typeof event.detail === 'object') event.detail.playback = readPlayback();
        });
        function send() {
            const state = readPlayback();
            if (!state) return;
            frame.contentWindow.postMessage({
                type: 'videoq:state', version: 1, channel, ...state,
            }, origin);
        }
        window.addEventListener('message', function(event) {
            if (event.source !== frame.contentWindow || event.origin !== origin) return;
            const data = event.data;
            if (!data || data.version !== 1 || typeof data.channel !== 'string'
                    || !/^[a-f0-9]{32}$/.test(data.channel)) return;
            if (data.type === 'videoq:ready') {
                if (!Number.isSafeInteger(data.videoId) || data.videoId < 1) return;
                channel = data.channel;
                videos = Array.isArray(data.videos) ? data.videos.filter(item => Number.isSafeInteger(item.videoId)
                    && item.videoId > 0 && lessons.some(lesson => lesson.lesson === item.lesson)) : [];
                const current = videos.find(item => item.lesson === activeLesson);
                videoId = current ? current.videoId : data.videoId;
                if (selector && videos.length) {
                    // Keep only processed, authorized videos returned by the LTI session.
                    selector.replaceChildren(...videos.map(item => {
                        const option = document.createElement('option');
                        option.value = item.lesson;
                        option.textContent = lessons.find(lesson => lesson.lesson === item.lesson).title;
                        return option;
                    }));
                    selector.value = activeLesson;
                }
                sendTheme();
                send();
            } else if (data.type === 'videoq:seek' && data.channel === channel) {
                const video = getVideo();
                if (!video) return;
                if (typeof data.seconds !== 'number' || !Number.isFinite(data.seconds)
                        || data.seconds < 0 || data.seconds > 604800) return;
                const target = videos.find(item => item.videoId === data.videoId && item.lesson === data.lesson);
                if (target) selectVideo(target, data.seconds, true);
                else if (!videos.length && data.videoId === videoId && data.seconds <= video.duration) {
                    const item = lessons.find(lesson => lesson.lesson === activeLesson);
                    pendingSeek = { seconds: data.seconds, play: true, item, target: { videoId }, waitForMetadata: false };
                    applySeek();
                }
            }
        });
        ['loadedmetadata', 'timeupdate', 'seeked', 'play', 'pause'].forEach(event => root.addEventListener(event, send, true));
        const timer = window.setInterval(send, 1000);
        window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
