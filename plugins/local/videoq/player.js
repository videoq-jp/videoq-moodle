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
        let youtubeApi = null;
        let youtube = null;
        let youtubeItem = null;
        let youtubeReady = false;
        let playerEpoch = 0;
        const filePanel = root.querySelector('[data-videoq-file]');
        const youtubePanel = root.querySelector('[data-videoq-youtube]');
        const playerStatus = root.querySelector('[data-videoq-player-status]');
        const activityLink = root.querySelector('[data-videoq-player-activity]');
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
        function youtubeApiReady() {
            if (window.YT?.Player) return Promise.resolve();
            if (youtubeApi) return youtubeApi;
            youtubeApi = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                const previous = window.onYouTubeIframeAPIReady;
                let timer, probe;
                function finish(error) {
                    clearTimeout(timer);
                    clearInterval(probe);
                    if (window.onYouTubeIframeAPIReady === ready) window.onYouTubeIframeAPIReady = previous;
                    if (error) { script.remove(); reject(error); } else resolve();
                }
                function ready() {
                    finish();
                    if (typeof previous === 'function') previous();
                }
                window.onYouTubeIframeAPIReady = ready;
                probe = setInterval(() => { if (window.YT?.Player) finish(); }, 100);
                timer = setTimeout(() => finish(new Error('YouTube player unavailable')), 15000);
                script.src = 'https://www.youtube.com/iframe_api';
                script.referrerPolicy = 'strict-origin-when-cross-origin';
                script.addEventListener('error', () => finish(new Error('YouTube player unavailable')), { once: true });
                document.head.append(script);
            }).catch(error => { youtubeApi = null; throw error; });
            return youtubeApi;
        }
        function stopYoutube() {
            youtubeReady = false;
            const previous = youtube;
            youtube = null;
            youtubeItem = null;
            if (previous) previous.destroy();
            youtubePanel?.replaceChildren();
        }
        function playerFailure(epoch) {
            if (epoch !== playerEpoch) return;
            pendingSeek = null;
            youtubeReady = false;
            if (youtubeItem) {
                activeLesson = youtubeItem.lesson;
                videoId = null;
                if (selector) selector.value = activeLesson;
            }
            selector?.removeAttribute('aria-busy');
            if (playerStatus) playerStatus.textContent = root.dataset.videoqYoutubeError || 'YouTube player unavailable';
        }
        function applyYoutubeSeek() {
            if (!youtubeReady || !youtube || pendingSeek?.provider !== 'youtube') return;
            const duration = youtube.getDuration();
            if (!Number.isFinite(duration) || duration <= 0) return;
            const seek = pendingSeek;
            pendingSeek = null;
            if (seek.seconds > duration) return;
            youtube.seekTo(seek.seconds, true);
            if (seek.play) youtube.playVideo();
        }
        async function selectYoutube(item, target, seconds, play) {
            const epoch = ++playerEpoch;
            stopYoutube();
            youtubeItem = item;
            getPlayer()?.pause();
            getVideo()?.pause();
            if (filePanel) filePanel.hidden = true;
            if (youtubePanel) youtubePanel.hidden = false;
            if (selector) { selector.value = activeLesson; selector.setAttribute('aria-busy', 'true'); }
            pendingSeek = { provider: 'youtube', seconds, play };
            try {
                if (!youtubePanel || typeof item.youtubeVideoId !== 'string' || item.youtubeVideoId.length !== 11
                        || !/^(?=.*[A-Za-z0-9])[A-Za-z0-9_-]{11}$/.test(item.youtubeVideoId)) {
                    throw new Error('Invalid YouTube source');
                }
                await youtubeApiReady();
                if (epoch !== playerEpoch) return;
                const host = document.createElement('iframe');
                host.title = item.title;
                host.width = '100%';
                host.height = '240';
                host.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
                host.allowFullscreen = true;
                host.referrerPolicy = 'strict-origin-when-cross-origin';
                host.src = 'https://www.youtube.com/embed/' + item.youtubeVideoId
                    + '?enablejsapi=1&playsinline=1&origin=' + encodeURIComponent(location.origin);
                youtubePanel.replaceChildren(host);
                youtube = new window.YT.Player(host, {
                    events: {
                        onReady(event) {
                            if (epoch !== playerEpoch) return;
                            youtube = event.target;
                            youtubeReady = true;
                            activeLesson = item.lesson;
                            videoId = videos.find(video => video.lesson === item.lesson)?.videoId ?? target.videoId;
                            if (selector) { selector.value = activeLesson; selector.removeAttribute('aria-busy'); }
                            applyYoutubeSeek();
                            send();
                        },
                        onStateChange() { if (epoch === playerEpoch) { applyYoutubeSeek(); send(); } },
                        onError() { playerFailure(epoch); },
                        onAutoplayBlocked() {
                            if (epoch === playerEpoch && playerStatus) playerStatus.textContent = root.dataset.videoqYoutubeAutoplay;
                        },
                    },
                });
            } catch { playerFailure(epoch); }
        }
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
            if (!item) return;
            if (playerStatus) playerStatus.textContent = '';
            if (activityLink && item.activityUrl && new URL(item.activityUrl, location.href).origin === location.origin) {
                activityLink.href = item.activityUrl;
            }
            if (item.kind === 'youtube') {
                if (youtubeItem?.lesson === item.lesson && youtubeReady) {
                    pendingSeek = { provider: 'youtube', seconds, play };
                    applyYoutubeSeek();
                } else void selectYoutube(item, target, seconds, play);
                return;
            }
            playerEpoch++;
            stopYoutube();
            if (filePanel) filePanel.hidden = false;
            if (youtubePanel) youtubePanel.hidden = true;
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
            if (!pendingSeek || pendingSeek.provider === 'youtube' || !video || !Number.isFinite(video.duration)) return;
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
            if (!channel || !Number.isSafeInteger(videoId) || videoId < 1 || pendingSeek
                    || (videos.length && !videos.some(item => item.videoId === videoId && item.lesson === activeLesson))) return null;
            if (youtubeItem) {
                if (!youtube || !youtubeReady || !videos.some(item => item.videoId === videoId && item.lesson === activeLesson)) return null;
                const seconds = youtube.getCurrentTime(), duration = youtube.getDuration();
                return Number.isFinite(seconds) && seconds >= 0 && Number.isFinite(duration) && duration > 0
                    ? { videoId, seconds, duration } : null;
            }
            if (lessons.find(item => item.lesson === activeLesson)?.kind === 'youtube') return null;
            const video = getVideo();
            if (!video || pendingSeek || !channel || !Number.isFinite(video.duration) || !Number.isFinite(video.currentTime)) return null;
            return { videoId, seconds: video.currentTime, duration: video.duration };
        }
        root.addEventListener('videoq:read-playback', event => {
            if (event.detail && typeof event.detail === 'object') event.detail.playback = readPlayback();
        });
        function send() {
            applyYoutubeSeek();
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
                let staleSource = false;
                videos = Array.isArray(data.videos) ? data.videos.filter(item => {
                    const lesson = lessons.find(lesson => lesson.lesson === item.lesson);
                    if (!Number.isSafeInteger(item.videoId) || item.videoId < 1 || !lesson) return false;
                    if (lesson.kind === 'youtube' && (!Number.isSafeInteger(item.sourceGeneration)
                            || item.sourceGeneration !== lesson.sourceGeneration)) {
                        staleSource = true;
                        return false;
                    }
                    return true;
                }) : [];
                const current = videos.find(item => item.lesson === activeLesson);
                const urlPlayer = lessons.find(item => item.lesson === activeLesson)?.kind === 'youtube';
                videoId = current ? current.videoId : videos.length || staleSource || urlPlayer ? null : data.videoId;
                if (!current && (videos.length || staleSource || urlPlayer)) {
                    getPlayer()?.pause();
                    getVideo()?.pause();
                    stopYoutube();
                }
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
                if (!current && videos.length) selectVideo(videos[0], 0, false);
                else if (current && urlPlayer && !youtubeItem) selectVideo(current, 0, false);
                if (staleSource && playerStatus) playerStatus.textContent = root.dataset.videoqSourceUpdated;
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
        let timer = window.setInterval(send, 1000);
        let resumeSeconds = 0;
        let resumeVideoId = null;
        const initial = lessons.find(item => item.lesson === activeLesson);
        if (initial?.kind === 'youtube') selectVideo({ lesson: initial.lesson, videoId: null }, 0, false);
        window.addEventListener('pagehide', () => {
            resumeSeconds = readPlayback()?.seconds ?? 0;
            resumeVideoId = videoId;
            videoId = null;
            window.clearInterval(timer);
            playerEpoch++;
            stopYoutube();
        });
        window.addEventListener('pageshow', event => {
            if (!event.persisted) return;
            window.clearInterval(timer);
            timer = window.setInterval(send, 1000);
            const item = lessons.find(lesson => lesson.lesson === activeLesson);
            const target = videos.find(video => video.lesson === activeLesson);
            if (item?.kind === 'youtube' && (target || !channel)) {
                selectVideo(target || { lesson: activeLesson, videoId: null }, resumeSeconds, false);
            } else if (item?.kind !== 'youtube') {
                videoId = target?.videoId ?? (videos.length ? null : resumeVideoId);
                send();
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
