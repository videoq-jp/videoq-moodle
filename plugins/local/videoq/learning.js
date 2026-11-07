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
 * Runs Moodle-owned learning panels through session-authenticated Moodle endpoints.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function () {
    'use strict';
    function init() {
        const workspace = document.querySelector('.local-videoq-workspace[data-videoq-native]');
        if (!workspace)
            return;
        window.require(['core/ajax'], function (Ajax) {
            const config = JSON.parse(workspace.dataset.videoqConfig);
            const uiCopy = config.copy;
            const chat = workspace.querySelector('#videoq-native-chat');
            const status = chat.querySelector('#videoq-native-status');
            const question = chat.querySelector('[data-region="send-message-txt"]');
            const send = chat.querySelector('[data-action="send-message"]');
            const form = chat.querySelector('#videoq-native-form');
            const messagesView = chat.querySelector('#videoq-native-messages');
            const empty = chat.querySelector('#videoq-native-empty');
            const controls = chat.querySelector('#videoq-native-controls');
            controls.disabled = false;
            question.disabled = send.disabled = true;
            question.id = 'videoq-native-question';
            question.maxLength = 4000;
            question.placeholder = uiCopy.placeholder;
            send.type = 'button';
            send.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); form.requestSubmit(); });
            let messages = [], history = [], features = {}, nativeRequestId = null;
            let sessionReady = false, learningEnabled = false, learningVideoIds = new Set(), learningVideos = [];
            let chatInFlight = false, activeVideoId = null, streamController = null;
            async function learningRequest(operation, payload = {}) {
                try {
                    const result = await Ajax.call([{
                            methodname: 'local_videoq_request_learning',
                            args: { courseid: config.courseid, lesson: config.lesson || '', operation, payload: JSON.stringify(payload) }
                        }])[0];
                    if (!Number.isInteger(result.status) || result.status < 200 || result.status > 599 ||
                        !['application/json', 'text/csv'].includes(result.contenttype) || typeof result.body !== 'string') {
                        throw new Error(uiCopy.connectionError);
                    }
                    return new Response(result.body, { status: result.status, headers: { 'Content-Type': result.contenttype } });
                }
                catch (error) {
                    const code = ['requireloginerror', 'invalidsesskey', 'notloggedin'].includes(error?.errorcode) ? 401 :
                        error?.errorcode === 'nopermissions' ? 403 : 503;
                    return new Response(JSON.stringify({ error: { code: 'MOODLE_REQUEST_FAILED' } }), { status: code, headers: { 'Content-Type': 'application/json' } });
                }
            }
            function syncComposer() {
                send.disabled = question.disabled = !sessionReady || !learningEnabled || chatInFlight || personalBusy;
                if (sessionReady && !learningEnabled && !chatInFlight) {
                    status.dataset.kind = 'waiting';
                    status.textContent = uiCopy.materialPreparing;
                }
                else if (status.dataset.kind === 'waiting') {
                    status.dataset.kind = '';
                    status.textContent = '';
                }
                managementDraw();
            }
            function learningRefresh(videos) {
                learningVideos = videos.filter(video => Number.isSafeInteger(video.videoId) && video.videoId > 0 && typeof video.lesson === 'string');
                learningVideoIds = new Set(learningVideos.map(video => video.videoId));
                learningEnabled = learningVideos.length > 0;
                workspace.dispatchEvent(new CustomEvent('videoq:materials', { detail: { videos: learningVideos } }));
                syncComposer();
            }
            workspace.addEventListener('videoq:selection', event => {
                activeVideoId = learningVideoIds.has(event.detail?.videoId) ? event.detail.videoId : null;
            });
            function freshPlayback() {
                const detail = { playback: null };
                workspace.dispatchEvent(new CustomEvent('videoq:read-playback', { detail }));
                const value = detail.playback;
                return value && learningVideoIds.has(value.videoId) && Number.isFinite(value.seconds) && value.seconds >= 0 &&
                    Number.isFinite(value.duration) && value.duration > 0 && value.duration <= 604800 && value.seconds <= value.duration ? value : null;
            }
            function scrollLatest() { requestAnimationFrame(() => { messagesView.scrollTop = messagesView.scrollHeight; }); }
            function resizeInput() { question.style.height = 'auto'; question.style.height = Math.min(128, Math.max(44, question.scrollHeight)) + 'px'; }
            question.addEventListener('input', resizeInput);
            question.addEventListener('keydown', event => {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
                    event.preventDefault();
                    if (!send.disabled && question.value.trim())
                        form.requestSubmit();
                }
            });
            function inlineText(parent, text) {
                for (const part of text.split(/(\*\*[^*\n]+\*\*|`[^`\n]+`)/g)) {
                    if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
                        const strong = document.createElement('strong');
                        strong.textContent = part.slice(2, -2);
                        parent.append(strong);
                    }
                    else if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
                        const code = document.createElement('code');
                        code.textContent = part.slice(1, -1);
                        parent.append(code);
                    }
                    else
                        parent.append(document.createTextNode(part));
                }
            }
            function answerText(parent, text) {
                for (const block of text.split(/\n{2,}/)) {
                    const lines = block.split('\n');
                    if (lines.every(line => /^\s*[-*]\s+/.test(line))) {
                        const list = document.createElement('ul');
                        for (const line of lines) {
                            const item = document.createElement('li');
                            inlineText(item, line.replace(/^\s*[-*]\s+/, ''));
                            list.append(item);
                        }
                        parent.append(list);
                    }
                    else {
                        const heading = /^#{1,3} /.test(block) && lines.length === 1;
                        const paragraph = document.createElement(heading ? 'h5' : 'p');
                        inlineText(paragraph, heading ? block.replace(/^#{1,3} /, '') : block);
                        parent.append(paragraph);
                    }
                }
            }
            function show(text, kind) {
                empty.hidden = true;
                const template = chat.querySelector('#videoq-native-' + kind + '-template');
                const element = template.content.firstElementChild.cloneNode(true);
                element.classList.remove('clickable');
                element.classList.add('message', 'flex-shrink-0', kind);
                element.removeAttribute('role');
                element.removeAttribute('aria-checked');
                element.removeAttribute('tabindex');
                element.dataset.messageId = 'videoq-' + crypto.randomUUID();
                const time = element.querySelector('[data-region="time-created"]');
                if (time)
                    time.textContent = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date());
                const body = element.querySelector('[data-region="text-container"]');
                body.classList.add('message-body');
                if (kind === 'answer')
                    answerText(body, text);
                else
                    body.textContent = text;
                messagesView.append(element);
                scrollLatest();
                return element;
            }
            function sourceSeconds(value) {
                if (typeof value !== 'string' || !/^\d{2,3}:[0-5]\d:[0-5]\d(?:[.,]\d+)?$/.test(value))
                    return null;
                const seconds = value.replace(',', '.').split(':').reduce((total, part) => total * 60 + Number(part), 0);
                return Number.isFinite(seconds) && seconds <= 604800 ? seconds : null;
            }
            function seekSource(source) {
                const seconds = sourceSeconds(source.start_time), target = learningVideos.find(video => video.videoId === source.video_id);
                if (seconds === null || !target)
                    return;
                workspace.dispatchEvent(new CustomEvent('videoq:seek-citation', { detail: { videoId: target.videoId, lesson: target.lesson, seconds } }));
            }
            function citation(source) {
                const seconds = sourceSeconds(source.start_time), allowed = seconds !== null && learningVideoIds.has(source.video_id);
                const element = document.createElement(allowed ? 'button' : 'span');
                const label = source.title + (source.start_time ? ' ' + source.start_time : '');
                element.title = label;
                element.textContent = seconds !== null ? '(' + source.start_time.split(/[.,]/)[0].replace(/^00:/, '').replace(/^0(\d:)/, '$1') + ')' : source.title;
                if (allowed) {
                    element.type = 'button';
                    element.className = 'btn btn-link p-0 align-baseline citation';
                    element.setAttribute('aria-label', label + ' ' + chat.dataset.seekSuffix);
                    element.dataset.videoId = String(source.video_id);
                    element.addEventListener('click', () => seekSource(source));
                }
                else
                    element.className = 'text-muted';
                return element;
            }
            function showAnswer(answer, existing = null) {
                const element = existing || show('', 'answer'), body = element.querySelector('.message-body');
                body.replaceChildren();
                const sources = new Map(answer.sources.map(source => [source.id, source]));
                for (const segment of answer.segments) {
                    answerText(body, segment.text);
                    const target = body.lastElementChild || body;
                    for (const id of new Set(segment.sourceIds)) {
                        const source = sources.get(id);
                        if (source)
                            target.append(document.createTextNode(' '), citation(source));
                    }
                }
                return element;
            }
            // materials operations.
            const materialsPanel = document.getElementById("videoq-course-materials"), materialSelect = document.getElementById("videoq-material-video"), materialStatus = document.getElementById("videoq-material-status"), materialCues = document.getElementById("videoq-material-cues"), materialMore = document.getElementById("videoq-material-more"), materialRefresh = document.getElementById("videoq-material-refresh");
            let materialCursor = null, materialLoading = false, materialListLoaded = false, materialListing = null;
            async function materialFetch(action, payload) {
                const response = await learningRequest(action === "list" ? "materials" : "transcript", payload);
                if (!response.ok) {
                    if (action === "list" && [401, 403].includes(response.status)) {
                        learningRefresh([]);
                        qaAvailability(false);
                        personalAvailability(false);
                        managementAvailability(false);
                        reportsAvailability(false);
                        tagsAvailability(false);
                    }
                    throw new Error(uiCopy.materialError);
                }
                const result = await response.json();
                if (action === "list") {
                    if (result.nativeProtocol !== 1 || !Array.isArray(result.videos) || !result.permissions)
                        throw new Error(uiCopy.connectionError);
                    features = result.features || {};
                    learningRefresh(result.videos);
                    materialsPanel.hidden = !features.transcripts || result.permissions.viewTranscript !== true;
                    qaAvailability(result.permissions.viewQa === true);
                    personalAvailability(result.permissions.manageOwnHistory === true);
                    managementCatalog(result);
                    tagsCanManage = result.permissions.manageContent === true;
                    tagsAvailability(features.tags === true);
                    reportsAvailability(result.permissions.viewReports === true);
                }
                return result;
            }
            // Coalesce only concurrent, identical metadata reads. Later operations always
            // request fresh authority; transcripts and writes are never cached here.
            async function materialRequest(action, payload = {}) {
                if (action !== 'list' || Object.keys(payload).length)
                    return materialFetch(action, payload);
                if (materialListing)
                    return materialListing;
                const request = materialFetch(action, payload);
                materialListing = request;
                try {
                    return await request;
                }
                finally {
                    if (materialListing === request)
                        materialListing = null;
                }
            }
            function resetMaterial() { materialCues.replaceChildren(); materialCursor = null; materialMore.hidden = true; materialStatus.textContent = ''; }
            function materialBusy(value) { materialLoading = value; materialSelect.disabled = value; materialMore.disabled = value; materialRefresh.disabled = value; materialsPanel.setAttribute('aria-busy', String(value)); }
            async function loadMaterialList() {
                if (materialLoading)
                    return;
                materialBusy(true);
                resetMaterial();
                try {
                    const result = await materialRequest('list');
                    materialSelect.replaceChildren();
                    if (!result.permissions?.viewTranscript) {
                        materialsPanel.hidden = true;
                        return;
                    }
                    for (const video of result.videos) {
                        const option = document.createElement('option');
                        option.value = video.videoId;
                        option.textContent = video.title;
                        materialSelect.append(option);
                    }
                    materialListLoaded = true;
                }
                catch {
                    materialListLoaded = false;
                    materialSelect.replaceChildren();
                    materialStatus.textContent = uiCopy.materialError;
                }
                finally {
                    materialBusy(false);
                }
            }
            async function loadMaterialTranscript(append = false) {
                if (materialLoading || !materialSelect.value)
                    return;
                if (!append)
                    resetMaterial();
                materialBusy(true);
                try {
                    const result = await materialRequest('transcript', { videoId: Number(materialSelect.value), cursor: append ? materialCursor : 0 });
                    for (const cue of result.cues) {
                        const row = document.createElement('li'), button = document.createElement('button'), text = document.createElement('span');
                        button.type = 'button';
                        button.textContent = cue.startTime;
                        button.addEventListener('click', () => seekSource({ video_id: result.videoId, start_time: cue.startTime }));
                        text.textContent = ' ' + cue.text;
                        row.append(button, text);
                        materialCues.append(row);
                    }
                    materialCursor = result.nextCursor;
                    materialMore.hidden = materialCursor === null;
                    materialStatus.textContent = materialCues.children.length ? '' : uiCopy.emptyTranscript;
                }
                catch {
                    resetMaterial();
                    materialListLoaded = false;
                    materialStatus.textContent = uiCopy.materialError;
                }
                finally {
                    materialBusy(false);
                }
            }
            materialsPanel.addEventListener('toggle', async () => {
                if (!materialsPanel.open)
                    return;
                if (!materialListLoaded)
                    await loadMaterialList();
                if (materialSelect.value && !materialCues.children.length)
                    await loadMaterialTranscript();
            });
            materialSelect.addEventListener('change', () => void loadMaterialTranscript());
            materialMore.addEventListener('click', () => void loadMaterialTranscript(true));
            materialRefresh.addEventListener('click', async () => {
                await loadMaterialList();
                if (materialSelect.value)
                    await loadMaterialTranscript();
            });
            // qa operations.
            const qaPanel = document.getElementById("videoq-course-qa"), qaContent = document.getElementById("videoq-qa-content"), qaStatus = document.getElementById("videoq-qa-status"), qaCopy = uiCopy.qa;
            let qaData = null, qaContext = { videos: [], permissions: {} }, qaSelected = null, qaPage = 0, qaReplyPage = 0, qaEditor = null, qaDraft = null, qaBusyState = false, qaAllowed = null, qaError = '', qaPendingDialog = null;
            const qaFilter = { search: '', videoId: null, unanswered: false, sort: 'recent' };
            function qaNode(tag, text = "", className = "") {
                const node = document.createElement(tag);
                if (text)
                    node.textContent = text;
                node.className = className;
                if (["input", "textarea"].includes(tag))
                    node.classList.add("form-control");
                if (tag === "select")
                    node.classList.add("form-select");
                if (tag === "table")
                    node.classList.add("table");
                if (tag === "h2")
                    node.classList.add("h5");
                if (tag === "h3")
                    node.classList.add("h6");
                return node;
            }
            function qaButton(text, action, { disabled = false, primary = false, pressed = null } = {}) {
                const button = qaNode("button", text, "btn btn-sm " + (primary ? "btn-primary" : "btn-outline-secondary"));
                button.type = "button";
                button.disabled = disabled;
                if (pressed !== null)
                    button.setAttribute("aria-pressed", String(pressed));
                button.addEventListener("click", () => void action());
                return button;
            }
            function qaSetBusy(value) {
                qaBusyState = value;
                qaPanel.setAttribute('aria-busy', String(value));
                for (const control of qaContent.querySelectorAll('button,input,select,textarea')) {
                    if (value) {
                        control.dataset.qaDisabled = String(control.disabled);
                        control.disabled = true;
                    }
                    else if (control.dataset.qaDisabled !== undefined) {
                        control.disabled = control.dataset.qaDisabled === 'true';
                        delete control.dataset.qaDisabled;
                    }
                }
                qaStatus.dataset.error = String(!value && !!qaError);
                qaStatus.textContent = value ? qaCopy.loading : qaError;
            }
            function qaAvailability(allowed) {
                qaAllowed = allowed === true;
                if (!qaAllowed && qaPendingDialog)
                    qaPendingDialog();
                qaPanel.hidden = !features.qa || (!qaAllowed && !qaPanel.open && !qaDraft);
            }
            async function qaCall(action, payload = {}) {
                if (qaBusyState)
                    return null;
                qaSetBusy(true);
                try {
                    const response = await learningRequest("qa", { action, ...payload });
                    if (!response.ok) {
                        if (response.status === 403 && ['list', 'get'].includes(action)) {
                            qaContent.replaceChildren();
                            qaAvailability(false);
                        }
                        throw new Error('unavailable');
                    }
                    const result = await response.json();
                    if (!result?.data || !result.permissions || !Array.isArray(result.videos))
                        throw new Error('invalid response');
                    qaContext = result;
                    qaAvailability(result.permissions.viewQa === true);
                    return result.data;
                }
                catch {
                    qaError = qaCopy.error;
                    return null;
                }
                finally {
                    qaSetBusy(false);
                }
            }
            async function qaLoad(preserveError = false) {
                const previous = qaError;
                if (!preserveError)
                    qaError = '';
                const data = await qaCall(qaSelected ? 'get' : 'list', qaSelected ? { questionId: qaSelected, offset: qaReplyPage * 20, limit: 20 } : { ...qaFilter, ...(qaFilter.videoId === null ? { videoId: undefined } : {}), offset: qaPage * 20, limit: 20 });
                if (data) {
                    qaData = data;
                    if (preserveError)
                        qaError = previous;
                }
                else
                    qaData = null;
                qaDraw();
            }
            function qaConfirm(message, affirmative) {
                if (qaPendingDialog)
                    return Promise.resolve(false);
                return new Promise(resolve => {
                    const dialog = qaNode('dialog', '', "local-videoq-confirm");
                    dialog.setAttribute('aria-label', qaCopy.confirmTitle);
                    dialog.append(qaNode('p', message));
                    const row = qaNode('div', '', 'qa-row');
                    let settled = false;
                    const finish = value => {
                        if (settled)
                            return;
                        settled = true;
                        qaPendingDialog = null;
                        dialog.close();
                        dialog.remove();
                        resolve(value);
                    };
                    qaPendingDialog = () => finish(false);
                    const cancel = qaButton(qaCopy.cancel, () => finish(false));
                    row.append(cancel, qaButton(affirmative, () => finish(true), { primary: true }));
                    dialog.append(row);
                    dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
                    workspace.append(dialog);
                    try {
                        dialog.showModal();
                        cancel.focus();
                    }
                    catch {
                        finish(false);
                    }
                });
            }
            async function qaDiscard() {
                if (qaEditor && qaDraft && (qaDraft.title !== qaDraft.originalTitle || qaDraft.body !== qaDraft.originalBody || qaDraft.videoId !== qaDraft.originalVideoId) && !await qaConfirm(qaCopy.discard, qaCopy.discardAction))
                    return false;
                qaEditor = null;
                qaDraft = null;
                return true;
            }
            async function qaOpen(id) {
                if (!await qaDiscard())
                    return;
                qaSelected = id;
                qaReplyPage = 0;
                void qaLoad();
            }
            async function qaStartEditor(kind, item = null) {
                if (!await qaDiscard())
                    return;
                let videoId = qaSelected ? qaData.question.videoId : (freshPlayback()?.videoId ?? activeVideoId ?? null);
                if (!qaSelected) {
                    const candidate = qaContext.videos.find(video => video.videoId === videoId);
                    videoId = candidate?.videoId ?? null;
                }
                qaEditor = { kind, id: item?.id ?? null };
                qaDraft = { title: item?.title ?? '', body: item?.body ?? '', videoId, originalTitle: item?.title ?? '', originalBody: item?.body ?? '', originalVideoId: videoId };
                qaDraw();
            }
            async function qaChange(action, payload, success) {
                qaError = '';
                const data = await qaCall(action, payload);
                if (data) {
                    if (success)
                        success(data);
                    if (['ask', 'reply', 'updateQuestion', 'updateReply', 'deleteQuestion'].includes(action) || (action === 'deleteReply' && qaEditor?.kind === 'replyEdit' && qaEditor.id === payload.replyId)) {
                        qaEditor = null;
                        qaDraft = null;
                    }
                    await qaLoad();
                }
                else
                    await qaLoad(true);
            }
            function qaAuthor(author, date) {
                const row = qaNode('p', '', 'qa-meta');
                row.append(qaNode('span', author.source === 'moodle' ? qaCopy.participant + ' ' + (author.name || '') : author.name || qaCopy.deletedUser));
                if (author.instructor)
                    row.append(document.createTextNode(' · '), qaNode('span', qaCopy.instructor, 'qa-badge'));
                const parsed = new Date(date);
                if (Number.isFinite(parsed.getTime())) {
                    const time = qaNode('time', ' · ' + parsed.toLocaleString(document.documentElement.lang));
                    time.dateTime = date;
                    row.append(time);
                }
                return row;
            }
            function qaSource(question) {
                if (!question.videoId || !question.videoTitle)
                    return null;
                const target = learningVideos?.find(video => video.videoId === question.videoId);
                if ((question.videoId === activeVideoId || target)) {
                    const button = qaNode('button', question.videoTitle, 'qa-link');
                    button.type = 'button';
                    button.addEventListener('click', () => seekSource({ video_id: question.videoId, start_time: '00:00:00' }));
                    return button;
                }
                return qaNode('span', question.videoTitle, 'qa-meta');
            }
            function qaPages(parent, page, total, change) { const row = qaNode('div', '', 'qa-row'); row.append(qaButton(qaCopy.previous, () => change(page - 1), { disabled: page === 0 }), qaNode('span', String(page + 1) + ' / ' + String(Math.max(1, Math.ceil(total / 20)))), qaButton(qaCopy.next, () => change(page + 1), { disabled: (page + 1) * 20 >= total })); parent.append(row); }
            function qaQuestionActions(question, parent) {
                const row = qaNode('div', '', 'qa-row');
                row.append(qaButton((question.voted ? qaCopy.removeVote : qaCopy.vote) + ' (' + question.votes + ')', () => qaChange('vote', { questionId: question.id, voted: !question.voted }), { disabled: !(qaData.canVote ?? qaData.canPost), pressed: question.voted }));
                if (qaData.canModerate)
                    row.append(qaButton(question.featured ? qaCopy.unfeature : qaCopy.feature, () => qaChange('feature', { questionId: question.id, featured: !question.featured })));
                if (question.canEdit)
                    row.append(qaButton(qaCopy.edit, () => qaStartEditor('question', question)));
                if (question.canDelete)
                    row.append(qaButton(qaCopy.delete, async () => {
                        if (await qaConfirm(qaCopy.deleteQuestion, qaCopy.delete))
                            return qaChange('deleteQuestion', { questionId: question.id }, () => { qaSelected = null; qaPage = 0; });
                    }));
                parent.append(row);
            }
            function qaField(form, label, tag, value, { name, maxLength = 10000, readOnly = false } = {}) { const field = qaNode('div', '', 'qa-field'), id = "videoq-qa-input-" + name, caption = qaNode('label', label), control = document.createElement(tag); caption.htmlFor = id; control.id = id; control.name = name; control.value = value; control.maxLength = maxLength; control.required = true; control.readOnly = readOnly; field.append(caption, control); form.append(field); return control; }
            function qaEditorForm(parent) {
                const form = qaNode('form'), kind = qaEditor.kind, readOnly = !qaData.canPost;
                let title = null, lesson = null;
                if (kind === 'ask' || kind === 'question') {
                    title = qaField(form, qaCopy.questionTitle, 'input', qaDraft.title, { name: 'title', maxLength: 200, readOnly });
                    title.addEventListener('input', () => { qaDraft.title = title.value; });
                }
                const body = qaField(form, kind === 'reply' || kind === 'replyEdit' ? qaCopy.replyBody : qaCopy.questionBody, 'textarea', qaDraft.body, { name: 'body', readOnly });
                body.addEventListener('input', () => { qaDraft.body = body.value; });
                if (kind === 'ask') {
                    const field = qaNode('div', '', 'qa-field'), label = qaNode('label', qaCopy.lesson);
                    label.htmlFor = 'qa-input-lesson';
                    lesson = document.createElement('select');
                    lesson.id = 'qa-input-lesson';
                    lesson.disabled = readOnly;
                    const course = qaNode('option', qaCopy.wholeCourse);
                    course.value = '';
                    lesson.append(course);
                    for (const video of qaContext.videos) {
                        const option = qaNode('option', video.title);
                        option.value = String(video.videoId);
                        lesson.append(option);
                    }
                    if (qaDraft.videoId !== null && !qaContext.videos.some(video => video.videoId === qaDraft.videoId)) {
                        const option = qaNode('option', qaCopy.unavailableLesson);
                        option.value = 'unavailable';
                        option.disabled = true;
                        lesson.append(option);
                        lesson.value = 'unavailable';
                    }
                    else
                        lesson.value = qaDraft.videoId === null ? '' : String(qaDraft.videoId);
                    lesson.addEventListener('change', () => { qaDraft.videoId = lesson.value === '' ? null : Number(lesson.value); });
                    field.append(label, lesson);
                    form.append(field);
                }
                const row = qaNode('div', '', 'qa-row'), submit = qaNode('button', kind === 'ask' ? qaCopy.publish : (kind === 'reply' ? qaCopy.postReply : qaCopy.save), 'qa-button primary');
                submit.type = 'submit';
                submit.disabled = readOnly;
                row.append(submit, qaButton(qaCopy.cancel, async () => {
                    if (await qaDiscard())
                        qaDraw();
                }));
                form.append(row);
                if (readOnly)
                    form.append(qaNode('p', qaCopy.readOnly, 'qa-hint'));
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    if (qaBusyState || readOnly)
                        return;
                    const text = body.value.trim(), heading = title?.value.trim();
                    if (!text || ((kind === 'ask' || kind === 'question') && !heading))
                        return;
                    if (kind === 'ask' && lesson.value === 'unavailable') {
                        qaError = qaCopy.unavailableLesson;
                        qaSetBusy(false);
                        return;
                    }
                    const payload = { body: text };
                    if (kind === 'ask')
                        void qaChange('ask', { ...payload, title: heading, videoId: qaDraft.videoId }, value => { qaSelected = value.id; qaReplyPage = 0; });
                    else if (kind === 'question')
                        void qaChange('updateQuestion', { ...payload, title: heading, questionId: qaSelected });
                    else if (kind === 'reply')
                        void qaChange('reply', { ...payload, questionId: qaSelected });
                    else
                        void qaChange('updateReply', { ...payload, questionId: qaSelected, replyId: qaEditor.id });
                });
                parent.append(form);
            }
            function qaDraw() {
                qaContent.replaceChildren();
                qaSetBusy(false);
                if (!qaData) {
                    qaContent.append(qaButton(qaCopy.refresh, () => qaLoad()));
                    return;
                }
                if (qaSelected) {
                    qaContent.append(qaButton(qaCopy.back, () => qaOpen(null)));
                    const question = qaData.question;
                    if (!question)
                        return;
                    qaContent.append(qaNode('h2', question.title), qaAuthor(question.author, question.updatedAt));
                    const source = qaSource(question);
                    if (source)
                        qaContent.append(source);
                    qaContent.append(qaNode('p', question.body, 'qa-body'));
                    qaQuestionActions(question, qaContent);
                    if (qaEditor?.kind === 'question') {
                        qaEditorForm(qaContent);
                        return;
                    }
                    const replies = qaNode('section');
                    replies.setAttribute('aria-label', qaCopy.replies);
                    replies.append(qaNode('h3', qaCopy.replies + ' (' + qaData.replyTotal + ')'));
                    for (const reply of qaData.replies) {
                        const article = qaNode('article', '', 'qa-card');
                        article.append(qaAuthor(reply.author, reply.updatedAt), qaNode('p', reply.body, 'qa-body'));
                        const controls = qaNode('div', '', 'qa-row');
                        if (reply.canEdit)
                            controls.append(qaButton(qaCopy.edit, () => qaStartEditor('replyEdit', reply)));
                        if (reply.canDelete)
                            controls.append(qaButton(qaCopy.delete, async () => {
                                if (await qaConfirm(qaCopy.deleteReply, qaCopy.delete))
                                    return qaChange('deleteReply', { questionId: qaSelected, replyId: reply.id });
                            }));
                        article.append(controls);
                        if (qaEditor?.kind === 'replyEdit' && qaEditor.id === reply.id)
                            qaEditorForm(article);
                        replies.append(article);
                    }
                    if (!qaData.replies.length)
                        replies.append(qaNode('p', qaCopy.noReplies, 'qa-hint'));
                    qaPages(replies, qaReplyPage, qaData.replyTotal, async (page) => {
                        if (await qaDiscard()) {
                            qaReplyPage = page;
                            void qaLoad();
                        }
                    });
                    qaContent.append(replies);
                    if (qaEditor?.kind === 'reply')
                        qaEditorForm(qaContent);
                    else if (!qaEditor && qaData.canPost)
                        qaContent.append(qaButton(qaCopy.reply, () => qaStartEditor('reply'), { primary: true }));
                    return;
                }
                if (qaEditor?.kind === 'ask') {
                    qaContent.append(qaNode('h2', qaCopy.ask));
                    qaEditorForm(qaContent);
                    return;
                }
                const filters = qaNode('form'), search = qaField(filters, qaCopy.search, 'input', qaFilter.search, { name: 'search', maxLength: 200 });
                search.required = false;
                const row = qaNode('div', '', 'qa-row'), lesson = document.createElement('select'), sort = document.createElement('select'), unanswered = document.createElement('input'), unansweredLabel = qaNode('label', qaCopy.unanswered);
                lesson.setAttribute('aria-label', qaCopy.lesson);
                const all = qaNode('option', qaCopy.allLessons);
                all.value = '';
                lesson.append(all);
                for (const video of qaContext.videos) {
                    const option = qaNode('option', video.title);
                    option.value = String(video.videoId);
                    lesson.append(option);
                }
                lesson.value = qaFilter.videoId === null ? '' : String(qaFilter.videoId);
                sort.setAttribute('aria-label', qaCopy.sort);
                for (const [value, label] of [['recent', qaCopy.recent], ['votes', qaCopy.votes]]) {
                    const option = qaNode('option', label);
                    option.value = value;
                    sort.append(option);
                }
                sort.value = qaFilter.sort;
                unanswered.type = 'checkbox';
                unanswered.id = 'qa-unanswered';
                unanswered.checked = qaFilter.unanswered;
                unansweredLabel.htmlFor = unanswered.id;
                row.append(lesson, sort, unanswered, unansweredLabel);
                const apply = qaNode('button', qaCopy.apply, 'qa-button');
                apply.type = 'submit';
                row.append(apply, qaButton(qaCopy.refresh, () => qaLoad()));
                filters.append(row);
                filters.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!await qaDiscard())
                        return;
                    qaFilter.search = search.value.trim();
                    qaFilter.videoId = lesson.value === '' ? null : Number(lesson.value);
                    qaFilter.sort = sort.value;
                    qaFilter.unanswered = unanswered.checked;
                    qaPage = 0;
                    void qaLoad();
                });
                qaContent.append(filters);
                if (qaData.canPost)
                    qaContent.append(qaButton(qaCopy.ask, () => qaStartEditor('ask'), { primary: true }));
                for (const question of qaData.items) {
                    const article = qaNode('article', '', 'qa-card'), open = qaNode('button', question.title, 'qa-link');
                    open.type = 'button';
                    open.addEventListener('click', () => qaOpen(question.id));
                    if (question.featured)
                        article.append(qaNode('span', qaCopy.featured + ' · ', 'qa-badge'));
                    article.append(open, qaAuthor(question.author, question.updatedAt), qaNode('p', qaCopy.replies + ': ' + question.replyCount + ' · ' + qaCopy.votes + ': ' + question.votes, 'qa-meta'));
                    qaContent.append(article);
                }
                if (!qaData.items.length)
                    qaContent.append(qaNode('p', qaCopy.empty, 'qa-hint'));
                qaPages(qaContent, qaPage, qaData.total, async (page) => {
                    if (await qaDiscard()) {
                        qaPage = page;
                        void qaLoad();
                    }
                });
            }
            qaPanel.addEventListener('toggle', () => {
                if (qaPanel.open) {
                    document.getElementById("videoq-course-materials").open = false;
                    if (!qaBusyState) {
                        qaData = null;
                        qaContent.replaceChildren();
                        void qaLoad();
                    }
                }
            });
            // personal operations.
            const personalCopy = uiCopy.personal, personalPanel = document.getElementById("videoq-personal-history"), personalContent = document.getElementById("videoq-personal-history-content"), personalStatus = document.getElementById("videoq-personal-history-status");
            let personalAllowed = null, personalBusy = false, personalError = '', personalItems = [], personalHasMore = false, personalPage = 0, personalDetail = null, personalOlder = false, personalLegacy = false;
            let activeConversationId = null, resumePersonalConversation = false, personalInitialized = false;
            function personalAvailability(value) {
                personalAllowed = value === true;
                if (!personalAllowed) {
                    personalDetail = null;
                    personalItems = [];
                    personalContent.replaceChildren();
                    if (activeConversationId) {
                        activeConversationId = null;
                        resumePersonalConversation = false;
                        replaceChatHistory([]);
                    }
                }
                personalPanel.hidden = !features.conversations || personalAllowed === false;
            }
            function personalReturnToChat() {
                personalPanel.open = false;
                if (!question.disabled)
                    question.focus({ preventScroll: true });
            }
            async function personalRequest(payload) {
                const response = await learningRequest("conversations", payload);
                if (!response.ok) {
                    if (response.status === 403 || response.status === 401)
                        personalAvailability(false);
                    if (response.status === 404) {
                        personalDetail = null;
                        personalItems = [];
                        if (payload.conversationId === activeConversationId) {
                            activeConversationId = crypto.randomUUID();
                            resumePersonalConversation = false;
                            replaceChatHistory([]);
                        }
                    }
                    const data = await response.json().catch(() => null);
                    throw new Error(data?.error?.code === 'CONVERSATION_BUSY' ? personalCopy.busy : personalCopy.error);
                }
                const data = await response.json();
                personalAvailability(true);
                return data;
            }
            async function personalRun(operation, initial = false) {
                if (personalBusy || (!initial && (!sessionReady || chatInFlight)))
                    return;
                personalBusy = true;
                personalError = '';
                syncComposer();
                personalDraw();
                try {
                    await operation();
                }
                catch (error) {
                    personalError = error.message || personalCopy.error;
                }
                finally {
                    personalBusy = false;
                    syncComposer();
                    personalDraw();
                }
            }
            async function personalList() { const data = await personalRequest({ action: 'list', limit: 20, offset: personalPage * 20 }); personalItems = data.items; personalHasMore = data.hasMore; personalDetail = null; }
            async function personalLoad(id, beforeTurnId) { personalDetail = await personalRequest({ action: 'get', conversationId: id, limit: 50, ...(beforeTurnId ? { beforeTurnId } : {}) }); personalOlder = !!beforeTurnId; }
            async function personalContinue(id) {
                if (!learningEnabled)
                    return;
                const detail = await personalRequest({ action: 'get', conversationId: id, limit: 50 });
                activeConversationId = id;
                resumePersonalConversation = true;
                replaceChatHistory(detail.turns);
                personalReturnToChat();
            }
            function personalNew() {
                if (personalBusy || send.disabled || personalAllowed !== true)
                    return;
                activeConversationId = crypto.randomUUID();
                resumePersonalConversation = false;
                replaceChatHistory([]);
                personalDetail = null;
                personalReturnToChat();
                personalDraw();
            }
            function personalMessageInput() { return activeConversationId ? { conversationId: activeConversationId, resumeConversation: resumePersonalConversation } : {}; }
            function personalCommitted() {
                if (activeConversationId)
                    resumePersonalConversation = true;
                personalDraw();
            }
            async function personalInit() {
                if (!features.conversations || personalInitialized)
                    return;
                personalInitialized = true;
                personalLegacy = history.length > 0;
                await personalRun(async () => {
                    await personalList();
                    if (personalItems.length && learningEnabled)
                        await personalContinue(personalItems[0].id);
                    else {
                        activeConversationId = crypto.randomUUID();
                        resumePersonalConversation = false;
                        replaceChatHistory([]);
                    }
                }, true);
            }
            async function personalRecent() {
                const response = await learningRequest("history", {});
                if (!response.ok)
                    throw new Error(personalCopy.error);
                const data = await response.json();
                activeConversationId = null;
                resumePersonalConversation = false;
                replaceChatHistory(data.turns || []);
                personalReturnToChat();
            }
            async function personalDelete(id) {
                if (!await qaConfirm(personalCopy.confirm, personalCopy.delete))
                    return;
                await personalRequest({ action: 'delete', conversationId: id });
                if (activeConversationId === id) {
                    activeConversationId = crypto.randomUUID();
                    resumePersonalConversation = false;
                    replaceChatHistory([]);
                }
                await personalList();
            }
            async function personalExport(id) {
                const pages = [];
                let cursor, metadata;
                while (true) {
                    const page = await personalRequest({ action: 'export', conversationId: id, limit: 100, ...(cursor ? { beforeTurnId: cursor } : {}) });
                    metadata ??= { format: 'videoq-personal-conversation-v1', id: page.id, title: page.title, createdAt: page.createdAt, updatedAt: page.updatedAt };
                    pages.unshift(page.turns);
                    if (!page.hasMore)
                        break;
                    const next = page.turns[0]?.id;
                    if (!next || next === cursor)
                        throw new Error(personalCopy.error);
                    cursor = next;
                }
                const parts = [JSON.stringify({ type: 'conversation', ...metadata }) + '\n'];
                for (const page of pages)
                    for (const turn of page)
                        parts.push(JSON.stringify({ type: 'turn', ...turn }) + '\n');
                const blob = new Blob(parts, { type: 'application/x-ndjson' }), url = URL.createObjectURL(blob), link = document.createElement('a');
                link.href = url;
                link.download = 'videoq-conversation-' + id + '.ndjson';
                document.body.append(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 30000);
            }
            function personalButton(label, operation, requiresLearning = false) { return qaButton(label, () => personalRun(operation), { disabled: personalBusy || !sessionReady || chatInFlight || (requiresLearning && !learningEnabled) }); }
            function personalDraw() {
                personalStatus.dataset.error = String(!!personalError);
                personalStatus.textContent = personalBusy ? personalCopy.loading : personalError;
                personalPanel.setAttribute('aria-busy', String(personalBusy));
                personalContent.replaceChildren();
                if (personalAllowed !== true) {
                    if (!personalBusy && personalAllowed !== false)
                        personalContent.append(personalButton(personalCopy.refresh, personalList));
                    return;
                }
                const actions = qaNode('div', '', 'qa-row');
                actions.append(qaButton(personalCopy.new, personalNew, { disabled: personalBusy || send.disabled }), personalButton(personalCopy.refresh, personalList));
                if (personalLegacy)
                    actions.append(personalButton(personalCopy.recent, personalRecent));
                personalContent.append(actions);
                if (personalDetail) {
                    const detail = personalDetail, row = qaNode('div', '', 'qa-row');
                    personalContent.append(qaNode('h2', detail.title));
                    row.append(personalButton(personalCopy.back, personalList), personalButton(personalCopy.continue, () => personalContinue(detail.id), true), personalButton(personalCopy.export, () => personalExport(detail.id)), personalButton(personalCopy.delete, () => personalDelete(detail.id)));
                    personalContent.append(row);
                    for (const turn of detail.turns) {
                        const card = qaNode('article', '', 'qa-card');
                        card.append(qaNode('h3', personalCopy.question), qaNode('p', turn.question, 'personal-turn'), qaNode('h3', personalCopy.answer), qaNode('p', turn.answer.segments.map(segment => segment.text).join(''), 'personal-turn'));
                        for (const source of turn.answer.sources)
                            card.append(citation(source));
                        personalContent.append(card);
                    }
                    const paging = qaNode('div', '', 'qa-row');
                    if (detail.hasMore && detail.turns.length)
                        paging.append(personalButton(personalCopy.older, () => personalLoad(detail.id, detail.turns[0].id)));
                    if (personalOlder)
                        paging.append(personalButton(personalCopy.latest, () => personalLoad(detail.id)));
                    personalContent.append(paging);
                    return;
                }
                for (const item of personalItems) {
                    const card = qaNode('article', '', 'qa-card'), row = qaNode('div', '', 'qa-row');
                    card.append(qaNode('h2', item.title));
                    const time = qaNode('time', new Date(item.updatedAt).toLocaleString(document.documentElement.lang), 'qa-meta');
                    time.dateTime = item.updatedAt;
                    card.append(time);
                    row.append(personalButton(personalCopy.open, () => personalLoad(item.id)), personalButton(personalCopy.continue, () => personalContinue(item.id), true), personalButton(personalCopy.export, () => personalExport(item.id)), personalButton(personalCopy.delete, () => personalDelete(item.id)));
                    card.append(row);
                    personalContent.append(card);
                }
                if (!personalItems.length)
                    personalContent.append(qaNode('p', personalCopy.empty));
                const paging = qaNode('div', '', 'qa-row');
                paging.append(qaButton(personalCopy.previous, () => personalRun(async () => { personalPage--; await personalList(); }), { disabled: personalBusy || !sessionReady || chatInFlight || personalPage === 0 }), qaNode('span', String(personalPage + 1)), qaButton(personalCopy.next, () => personalRun(async () => { personalPage++; await personalList(); }), { disabled: personalBusy || !sessionReady || chatInFlight || !personalHasMore }));
                personalContent.append(paging);
            }
            personalPanel.addEventListener('toggle', () => {
                if (personalPanel.open)
                    void personalRun(personalList);
            });
            // management operations.
            const managementCopy = uiCopy.management, managementPanel = document.getElementById("videoq-material-management"), managementSelect = document.getElementById("videoq-management-video"), managementStatus = document.getElementById("videoq-management-status"), managementProgress = document.getElementById("videoq-management-progress"), managementDescription = document.getElementById("videoq-management-description"), managementTranscript = document.getElementById("videoq-management-transcript"), managementSave = document.getElementById("videoq-management-save"), managementRefresh = document.getElementById("videoq-management-refresh"), managementReindex = document.getElementById("videoq-management-reindex"), managementRetry = document.getElementById("videoq-management-retry"), managementClose = document.getElementById("videoq-management-close");
            let managementAllowed = null, managementBusy = false, managementDetail = null, managementObserved = null, managementVideos = [], managementError = '', managementNotice = '', managementDraftChanged = false, managementUncertain = null, managementPolls = 0, managementTimer = null;
            function managementDirty() { return !!managementDetail && (managementDescription.value !== managementDetail.description || managementTranscript.value !== managementDetail.transcript); }
            function managementAwaitingLearning(detail) { return detail?.status === 'completed' && learningVideoIds && !learningVideoIds.has(detail.videoId); }
            function managementAvailability(value) {
                managementAllowed = value === true;
                managementPanel.hidden = !features.management || (!managementAllowed && !managementDirty());
                if (!managementAllowed && !managementDirty())
                    managementPanel.open = false;
                managementDraw();
            }
            function managementCatalog(result) {
                managementVideos = Array.isArray(result.managementVideos) ? result.managementVideos : [];
                managementAvailability(features.management === true && result.permissions?.manageContent === true && managementVideos.length > 0);
                const selected = Number(managementSelect.value) || managementDetail?.videoId || activeVideoId;
                managementSelect.replaceChildren();
                for (const video of managementVideos) {
                    const option = qaNode('option', video.title);
                    option.value = String(video.videoId);
                    managementSelect.append(option);
                }
                if (managementVideos.some(video => video.videoId === selected))
                    managementSelect.value = String(selected);
                if (managementDetail && !managementVideos.some(video => video.videoId === managementDetail.videoId)) {
                    if (managementDirty()) {
                        managementAllowed = false;
                        managementError = managementCopy.unavailable;
                        managementAvailability(false);
                    }
                    else {
                        managementDetail = null;
                        managementObserved = null;
                        managementDescription.value = '';
                        managementTranscript.value = '';
                        managementDraw();
                    }
                }
            }
            function managementDraw() {
                const detail = managementObserved || managementDetail, dirty = managementDirty(), locked = managementBusy || chatInFlight || managementAllowed !== true;
                managementPanel.setAttribute('aria-busy', String(managementBusy));
                managementStatus.dataset.error = String(!!managementError);
                managementStatus.textContent = managementBusy ? managementCopy.loading : managementError || managementNotice || (managementDraftChanged ? managementCopy.changed : dirty ? managementCopy.unsaved : '');
                const labels = { completed: managementCopy.completed, indexing: managementCopy.indexing, error: managementCopy.errorState, pending: managementCopy.pendingState, processing: managementCopy.processing, uploading: managementCopy.uploading, failed: managementCopy.failed, queued: managementCopy.queuedState, running: managementCopy.running };
                managementProgress.textContent = detail ? managementCopy.status + ': ' + (labels[detail.status] || managementCopy.processing) + ' · ' + managementCopy.job + ': ' + (labels[detail.jobState] || managementCopy.processing) + (!detail.canEdit ? ' · ' + managementCopy.pending : detail.status === 'error' && !detail.canRetry ? ' · ' + managementCopy.olderJob : managementAwaitingLearning(detail) ? ' · ' + managementCopy.syncWaiting : '') : '';
                managementDescription.readOnly = managementTranscript.readOnly = locked || !detail?.canEdit;
                managementSave.disabled = locked || !detail?.canEdit || !dirty;
                managementReindex.disabled = locked || !detail?.canReindex || dirty;
                managementRetry.disabled = locked || !detail?.canRetry || dirty;
                managementSelect.disabled = managementBusy || chatInFlight || managementAllowed !== true;
                managementRefresh.disabled = managementBusy || chatInFlight;
                managementClose.hidden = !managementPanel.open;
            }
            async function managementRequest(payload) {
                const response = await learningRequest("management", payload);
                if (!response.ok) {
                    if (response.status === 401 || response.status === 403) {
                        managementError = managementCopy.unavailable;
                        managementAvailability(false);
                    }
                    throw new Error(response.status === 409 ? managementCopy.conflict : [400, 413].includes(response.status) ? managementCopy.validation : managementCopy.error);
                }
                return response.json();
            }
            async function managementRun(operation) {
                if (managementBusy || chatInFlight)
                    return;
                managementBusy = true;
                managementError = '';
                managementNotice = '';
                managementDraw();
                try {
                    await operation();
                }
                catch (error) {
                    managementError = managementAllowed === false ? managementCopy.unavailable : error.message || managementCopy.error;
                }
                finally {
                    managementBusy = false;
                    managementDraw();
                    managementSchedule();
                }
            }
            function managementApply(detail) {
                managementObserved = detail;
                if (managementDirty()) {
                    if (detail.revision !== managementDetail.revision)
                        managementDraftChanged = true;
                    return;
                }
                managementDetail = detail;
                managementDraftChanged = false;
                managementDescription.value = detail.description;
                managementTranscript.value = detail.transcript;
                managementUncertain = null;
            }
            async function managementDiscard() {
                if (!managementDirty())
                    return true;
                if (!await qaConfirm(managementCopy.discard, managementCopy.discardAction))
                    return false;
                managementDetail = null;
                managementObserved = null;
                managementDescription.value = '';
                managementTranscript.value = '';
                managementDraftChanged = false;
                managementUncertain = null;
                return true;
            }
            async function managementLoad(refreshCatalog = true) {
                if (refreshCatalog)
                    await materialRequest('list');
                if (managementAllowed !== true || !managementSelect.value)
                    return;
                const id = Number(managementSelect.value);
                if (managementDetail && managementDetail.videoId !== id) {
                    managementDetail = null;
                    managementObserved = null;
                    managementDescription.value = '';
                    managementTranscript.value = '';
                }
                const result = await managementRequest({ action: 'get', videoId: id });
                managementApply(result.material);
            }
            async function managementMutation(action) {
                if (!managementDetail || managementAllowed !== true || managementBusy || chatInFlight)
                    return;
                const detail = managementDetail, fields = {};
                if (action === 'update') {
                    if (managementDescription.value !== detail.description)
                        fields.description = managementDescription.value;
                    if (managementTranscript.value !== detail.transcript)
                        fields.transcript = managementTranscript.value;
                    if (!Object.keys(fields).length)
                        return;
                    if (Object.hasOwn(fields, 'transcript') && !fields.transcript.trim() && !await qaConfirm(managementCopy.clear, managementCopy.confirm))
                        return;
                }
                else if (!await qaConfirm(action === 'reindex' ? managementCopy.reindexConfirm : managementCopy.retryConfirm, managementCopy.confirm))
                    return;
                const payload = { action, videoId: detail.videoId, revision: detail.revision, ...(action === 'update' ? { fields } : {}) }, signature = JSON.stringify(payload);
                // A response may be lost after commit. Reuse the operation identity while its
                // exact request is unchanged; never silently replace a local draft on error.
                if (managementUncertain?.signature !== signature)
                    managementUncertain = { signature, operationId: crypto.randomUUID() };
                const operationId = managementUncertain.operationId;
                await managementRun(async () => { const result = await managementRequest({ ...payload, operationId }); managementDetail = null; managementApply(result.material); managementPolls = 0; managementNotice = result.material.status === 'completed' ? managementCopy.saved : managementCopy.queued; await materialRequest('list'); });
            }
            function managementSchedule() {
                clearTimeout(managementTimer);
                const observed = managementObserved || managementDetail, waiting = managementAwaitingLearning(observed);
                if (!sessionReady || managementAllowed !== true || (!managementPanel.open && learningEnabled && !waiting) || !observed || (observed.status === 'completed' && !waiting) || observed.status === 'error' || managementPolls >= 60)
                    return;
                managementTimer = setTimeout(() => {
                    if (document.hidden || managementBusy || chatInFlight) {
                        managementSchedule();
                        return;
                    }
                    managementPolls++;
                    void managementRun(() => managementLoad(true));
                }, 5000);
            }
            async function managementInit() {
                if (features.management) {
                    managementPanel.hidden = false;
                    await managementRun(async () => {
                        await materialRequest('list');
                        if (managementPanel.open)
                            await managementLoad(false);
                    });
                }
            }
            managementPanel.addEventListener('toggle', () => {
                if (managementPanel.open)
                    void managementRun(() => managementLoad());
                managementDraw();
                managementSchedule();
            });
            managementRefresh.addEventListener('click', async () => {
                if (await managementDiscard()) {
                    managementPolls = 0;
                    await managementRun(() => managementLoad());
                }
            });
            managementSelect.addEventListener('change', async () => {
                const id = Number(managementSelect.value);
                if (!await managementDiscard()) {
                    if (managementDetail)
                        managementSelect.value = String(managementDetail.videoId);
                    return;
                }
                managementSelect.value = String(id);
                managementPolls = 0;
                await managementRun(() => managementLoad(false));
            });
            for (const field of [managementDescription, managementTranscript])
                field.addEventListener('input', () => { managementError = ''; managementNotice = ''; managementDraw(); });
            document.getElementById("videoq-management-form").addEventListener('submit', event => { event.preventDefault(); void managementMutation('update'); });
            managementReindex.addEventListener('click', () => void managementMutation('reindex'));
            managementRetry.addEventListener('click', () => void managementMutation('retry'));
            managementClose.addEventListener('click', () => { managementPanel.open = false; });
            window.addEventListener('beforeunload', event => {
                if (managementDirty()) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            window.addEventListener('pagehide', () => clearTimeout(managementTimer), { once: true });
            // tags operations.
            const tagsCopy = uiCopy.tags, tagColors = ["gray", "blue", "light-blue", "cyan", "green", "lime", "yellow", "orange", "red", "magenta", "purple"], tagsPanel = document.getElementById("videoq-course-tags"), tagsStatus = document.getElementById("videoq-tags-status"), tagsCatalog = document.getElementById("videoq-tags-catalog"), tagsFilter = document.getElementById("videoq-tags-filter"), tagsSearch = document.getElementById("videoq-tags-search"), tagsForm = document.getElementById("videoq-tags-editor"), tagsFields = document.getElementById("videoq-tags-fields");
            let tagsTranscriptId = null, tagsTranscriptCursor = null;
            let tagsData = null, tagsTitles = new Map(), tagsOrder = new Map(), tagsLearning = new Set(), tagsTranscript = false, tagsAllowed = null, tagsCanManage = false, tagsBusy = false, tagsEditor = null, tagsUncertain = null, tagsNotice = '';
            function tagsValues() {
                if (!tagsEditor)
                    return null;
                return tagsEditor.kind === 'assign' ? { tagIds: [...tagsFields.querySelectorAll('input:checked')].map(el => el.value).sort() } : { name: tagsFields.querySelector('input').value, color: tagsFields.querySelector('select').value };
            }
            function tagsDirty() { return !!tagsEditor && JSON.stringify(tagsValues()) !== JSON.stringify(tagsEditor.original); }
            function tagsAvailability(value) { tagsAllowed = value === true; tagsPanel.hidden = !features.tags || (!tagsAllowed && !tagsDirty()); tagsLock(); }
            function tagsLock() {
                const readlocked = tagsBusy || tagsAllowed !== true, locked = readlocked || !tagsCanManage;
                tagsFields.disabled = tagsBusy;
                for (const control of tagsFields.querySelectorAll('input,select')) {
                    if (control.tagName === 'INPUT' && control.type !== 'checkbox')
                        control.readOnly = locked;
                    else
                        control.disabled = locked;
                }
                document.getElementById("videoq-tags-save").disabled = locked || !tagsDirty();
                document.getElementById("videoq-tags-delete").disabled = locked;
                document.getElementById("videoq-tags-refresh").disabled = tagsBusy;
                document.getElementById("videoq-tags-create").disabled = locked;
                document.getElementById("videoq-tags-create").hidden = !tagsCanManage || tagsData?.canManage !== true;
                for (const button of tagsCatalog.querySelectorAll('button'))
                    button.disabled = readlocked || (button.dataset.videoqTagWrite === 'true' && !tagsCanManage);
                tagsCatalog.hidden = tagsAllowed !== true;
                tagsPanel.setAttribute('aria-busy', String(tagsBusy));
            }
            function tagsWriteButton(text, action, options = {}) {
                const button = qaButton(text, action, {...options, disabled: tagsBusy || !tagsCanManage});
                button.dataset.videoqTagWrite = 'true';
                return button;
            }
            function tagsSetOpen(open) {
                tagsPanel.open = open === true;
                tagsAvailability(tagsAllowed);
                if (tagsPanel.open && !tagsDirty())
                    void tagsRun(tagsLoad);
            }
            async function tagsRequest(input) {
                const response = await learningRequest("tags", input);
                if (!response.ok) {
                    if ([401, 403].includes(response.status))
                        tagsAvailability(false);
                    const error = new Error(response.status === 409 ? tagsCopy.conflict : tagsCopy.error);
                    error.status = response.status;
                    throw error;
                }
                const result = await response.json();
                if (!Array.isArray(result.tags) || result.tags.length > 200 || !Array.isArray(result.videos) || result.videos.length > 1000 || typeof result.canManage !== 'boolean')
                    throw new Error(tagsCopy.error);
                return result;
            }
            async function tagsRun(action) {
                if (tagsBusy)
                    return;
                tagsBusy = true;
                tagsLock();
                tagsStatus.textContent = tagsCopy.loading;
                try {
                    await action();
                    tagsStatus.textContent = tagsNotice || '';
                }
                catch (error) {
                    tagsStatus.textContent = error.status === 409 ? tagsCopy.conflict : tagsCopy.error;
                }
                finally {
                    tagsBusy = false;
                    tagsLock();
                }
            }
            function tagChip(tag) { const chip = qaNode('span', tag.name, 'course-tag-chip'); chip.dataset.color = tagColors.includes(tag.color) ? tag.color : 'gray'; return chip; }
            function tagsRender() {
                const old = tagsFilter.value;
                tagsFilter.replaceChildren();
                for (const [value, label] of [['', tagsCopy.all], ['untagged', tagsCopy.untagged], ...tagsData.tags.map(tag => [tag.id, tag.name])]) {
                    const option = qaNode('option', label);
                    option.value = value;
                    tagsFilter.append(option);
                }
                if ([...tagsFilter.options].some(option => option.value === old))
                    tagsFilter.value = old;
                document.getElementById("videoq-tags-create").hidden = !tagsData.canManage;
                const vocabulary = document.getElementById("videoq-tags-vocabulary");
                vocabulary.replaceChildren();
                for (const tag of tagsData.tags) {
                    const row = qaNode('li');
                    row.append(tagChip(tag), document.createTextNode(' (' + tag.videoIds.length + ') '));
                    if (tag.editable)
                        row.append(tagsWriteButton(tagsCopy.edit, () => tagsStart('update', tag)));
                    vocabulary.append(row);
                }
                tagsRenderVideos();
                tagsLock();
            }
            function tagsRenderVideos() {
                if (!tagsData)
                    return;
                const list = document.getElementById("videoq-tags-materials");
                list.replaceChildren();
                const needle = tagsSearch.value.toLocaleLowerCase();
                for (const video of [...tagsData.videos].sort((a, b) => (tagsOrder.get(a.videoId) ?? Number.MAX_SAFE_INTEGER) - (tagsOrder.get(b.videoId) ?? Number.MAX_SAFE_INTEGER))) {
                    const title = tagsTitles.get(video.videoId);
                    if (!title || !title.toLocaleLowerCase().includes(needle) || (tagsFilter.value === 'untagged' ? video.tagIds.length > 0 : tagsFilter.value && !video.tagIds.includes(tagsFilter.value)))
                        continue;
                    const row = qaNode('li'), heading = qaNode('p', title), labels = qaNode('p');
                    for (const tag of tagsData.tags.filter(tag => video.tagIds.includes(tag.id)))
                        labels.append(tagChip(tag), document.createTextNode(' '));
                    row.append(heading, labels);
                    if (tagsLearning.has(video.videoId)) {
                        row.append(qaButton(tagsCopy.open, () => seekSource({ video_id: video.videoId, start_time: '00:00:00' })));
                        if (tagsTranscript)
                            row.append(qaButton(tagsCopy.transcript, () => { void tagsRun(() => tagsReadTranscript(video.videoId, false)); }));
                    }
                    if (video.editable)
                        row.append(tagsWriteButton(tagsCopy.assign, () => tagsStart('assign', video)));
                    list.append(row);
                }
                if (!list.children.length)
                    list.append(qaNode('li', tagsCopy.empty));
            }
            async function tagsReadTranscript(videoId, append) {
                const panel = document.getElementById("videoq-tags-transcript"), list = panel.querySelector('ol');
                if (!append) {
                    list.replaceChildren();
                    panel.hidden = true;
                }
                try {
                    const result = await materialRequest('transcript', { videoId, cursor: append ? tagsTranscriptCursor : 0 });
                    for (const cue of result.cues) {
                        const row = qaNode('li'), seek = qaButton(cue.startTime, () => seekSource({ video_id: result.videoId, start_time: cue.startTime }));
                        row.append(seek, document.createTextNode(' ' + cue.text));
                        list.append(row);
                    }
                    tagsTranscriptId = videoId;
                    tagsTranscriptCursor = result.nextCursor;
                    panel.querySelector('h3').textContent = tagsTitles.get(videoId);
                    document.getElementById("videoq-tags-more").hidden = result.nextCursor === null;
                    panel.hidden = false;
                }
                catch (error) {
                    list.replaceChildren();
                    panel.hidden = true;
                    throw error;
                }
            }
            document.getElementById("videoq-tags-more").addEventListener('click', () => void tagsRun(() => tagsReadTranscript(tagsTranscriptId, true)));
            async function tagsLoad() { document.getElementById("videoq-tags-transcript").hidden = true; const metadata = await materialRequest('list'); const result = await tagsRequest({ action: 'list' }); tagsTitles = new Map([...metadata.videos, ...(metadata.managementVideos || [])].map(video => [video.videoId, video.title])); tagsOrder = new Map((Array.isArray(metadata.order) ? metadata.order : [...tagsTitles.keys()]).map((videoId, index) => [videoId, index])); tagsLearning = new Set(metadata.videos.map(video => video.videoId)); tagsTranscript = metadata.permissions?.viewTranscript === true; tagsData = result; tagsNotice = ''; tagsAvailability(true); tagsRender(); }
            async function tagsDiscard() {
                if (tagsDirty() && !await qaConfirm(tagsCopy.discard, tagsCopy.discardAction))
                    return false;
                tagsEditor = null;
                tagsUncertain = null;
                tagsForm.hidden = true;
                tagsAvailability(tagsAllowed);
                return true;
            }
            async function tagsStart(kind, item = null) {
                if (tagsBusy || tagsAllowed !== true || !tagsCanManage || !await tagsDiscard())
                    return;
                tagsEditor = { kind, item, original: kind === 'assign' ? { tagIds: [...item.tagIds].sort() } : { name: item?.name || '', color: item?.color || 'gray' } };
                tagsFields.replaceChildren();
                document.getElementById("videoq-tags-editor-title").textContent = kind === 'assign' ? tagsCopy.assign + ' · ' + tagsTitles.get(item.videoId) : kind === 'create' ? tagsCopy.create : tagsCopy.edit;
                if (kind === 'assign') {
                    for (const tag of tagsData.tags) {
                        const label = qaNode('label'), input = document.createElement('input');
                        input.type = 'checkbox';
                        input.value = tag.id;
                        input.checked = item.tagIds.includes(tag.id);
                        label.append(input, document.createTextNode(' ' + tag.name));
                        tagsFields.append(label);
                    }
                }
                else {
                    const nameLabel = qaNode('label', tagsCopy.name), input = document.createElement('input');
                    input.id = 'videoq-tags-name';
                    input.maxLength = 50;
                    input.required = true;
                    input.value = tagsEditor.original.name;
                    nameLabel.htmlFor = input.id;
                    const colorLabel = qaNode('label', tagsCopy.color), select = document.createElement('select');
                    select.id = 'videoq-tags-color';
                    colorLabel.htmlFor = select.id;
                    for (const color of tagColors) {
                        const option = qaNode('option', color);
                        option.value = color;
                        select.append(option);
                    }
                    select.value = tagsEditor.original.color;
                    tagsFields.append(nameLabel, input, colorLabel, select);
                }
                document.getElementById("videoq-tags-delete").hidden = kind !== 'update';
                tagsForm.hidden = false;
                tagsFields.querySelector('input')?.focus();
                tagsLock();
            }
            async function tagsMutate(remove = false) {
                if (!tagsEditor || tagsAllowed !== true || !tagsCanManage)
                    return;
                const editor = tagsEditor;
                if (remove && !await qaConfirm(tagsCopy.confirmDelete, tagsCopy.remove))
                    return;
                const values = tagsValues();
                const payload = remove ? { action: 'delete', id: editor.item.id, revision: editor.item.revision } : editor.kind === 'assign' ? { action: 'assign', videoId: editor.item.videoId, revision: editor.item.revision, ...values } : editor.kind === 'update' ? { action: 'update', id: editor.item.id, revision: editor.item.revision, ...values } : { action: 'create', ...values };
                const signature = JSON.stringify(payload);
                const input = tagsUncertain?.signature === signature ? tagsUncertain.input : { ...payload, operationId: crypto.randomUUID() };
                tagsUncertain = { signature, input };
                try {
                    tagsData = await tagsRequest(input);
                    tagsEditor = null;
                    tagsUncertain = null;
                    tagsForm.hidden = true;
                    tagsNotice = tagsCopy.saved;
                    tagsAvailability(true);
                    tagsRender();
                }
                catch (error) {
                    if (error.status && error.status < 500)
                        tagsUncertain = null;
                    throw error;
                }
            }
            tagsFields.addEventListener('input', () => { tagsStatus.textContent = tagsDirty() ? tagsCopy.unsaved : ''; tagsAvailability(tagsAllowed); });
            tagsFields.addEventListener('change', () => tagsAvailability(tagsAllowed));
            tagsForm.addEventListener('submit', event => { event.preventDefault(); void tagsRun(() => tagsMutate()); });
            document.getElementById("videoq-tags-delete").addEventListener('click', () => void tagsRun(() => tagsMutate(true)));
            document.getElementById("videoq-tags-cancel").addEventListener('click', () => void tagsDiscard());
            document.getElementById("videoq-tags-create").addEventListener('click', () => void tagsStart('create'));
            tagsFilter.addEventListener('change', tagsRenderVideos);
            tagsSearch.addEventListener('input', tagsRenderVideos);
            document.getElementById("videoq-tags-refresh").addEventListener('click', async () => {
                if (await tagsDiscard())
                    void tagsRun(tagsLoad);
            });
            document.getElementById("videoq-tags-close").addEventListener('click', () => { tagsSetOpen(false); });
            tagsPanel.addEventListener('toggle', () => {
                if (tagsPanel.open && !tagsData && !tagsBusy)
                    void tagsRun(tagsLoad);
            });
            async function tagsInit() {
                if (!features.tags)
                    return;
                await tagsRun(tagsLoad);
            }
            // reports operations.
            const reportsCopy = uiCopy.reports, reportsPanel = document.getElementById("videoq-course-reports"), reportsContent = document.getElementById("videoq-reports-content"), reportsStatus = document.getElementById("videoq-reports-status"), reportsForm = document.getElementById("videoq-reports-form"), reportsFrom = document.getElementById("videoq-reports-from"), reportsTo = document.getElementById("videoq-reports-to");
            let reportsAllowed = null, reportsOpen = false, reportsBusy = false, reportsData = null, reportsEpoch = 0;
            const reportsToday = new Date();
            reportsTo.value = reportsToday.toISOString().slice(0, 10);
            reportsToday.setUTCDate(reportsToday.getUTCDate() - 29);
            reportsFrom.value = reportsToday.toISOString().slice(0, 10);
            function reportsLock() {
                for (const el of reportsForm.elements)
                    el.disabled = reportsBusy || reportsAllowed !== true;
                document.getElementById("videoq-reports-export").disabled = reportsBusy || !reportsData || reportsAllowed !== true;
                document.getElementById("videoq-reports-close").disabled = false;
                reportsPanel.setAttribute('aria-busy', String(reportsBusy));
            }
            function reportsAvailability(value) {
                reportsAllowed = features.reports === true && value === true;
                if (!reportsAllowed) {
                    reportsEpoch++;
                    reportsData = null;
                    reportsContent.replaceChildren();
                    reportsStatus.textContent = "";
                    reportsOpen = false;
                    reportsPanel.open = false;
                }
                reportsPanel.hidden = !reportsAllowed;
                reportsLock();
            }
            function reportsSetOpen(open) {
                reportsOpen = open === true && reportsAllowed === true;
                reportsPanel.open = reportsOpen;
                document.getElementById("videoq-reports-close").hidden = !reportsOpen;
                if (reportsOpen)
                    void reportsLoad();
            }
            function reportsRender() {
                reportsContent.replaceChildren();
                if (!reportsData || reportsAllowed !== true)
                    return;
                const totals = reportsData.totals;
                const summary = qaNode('dl');
                for (const key of ['questions', 'good', 'bad', 'none'])
                    summary.append(qaNode('dt', reportsCopy[key]), qaNode('dd', String(totals[key])));
                reportsContent.append(summary);
                if (!reportsData.days.length) {
                    reportsContent.append(qaNode('p', reportsCopy.empty));
                    return;
                }
                const table = qaNode('table'), head = qaNode('thead'), row = qaNode('tr');
                for (const key of ['date', 'questions', 'good', 'bad', 'none']) {
                    const th = qaNode('th', reportsCopy[key]);
                    th.scope = 'col';
                    row.append(th);
                }
                head.append(row);
                table.append(head);
                const body = qaNode('tbody');
                for (const day of reportsData.days) {
                    const tr = qaNode('tr');
                    for (const key of ['date', 'questions', 'good', 'bad', 'none'])
                        tr.append(qaNode('td', String(day[key])));
                    body.append(tr);
                }
                table.append(body);
                reportsContent.append(table);
            }
            async function reportsLoad() {
                if (reportsBusy || !sessionReady || chatInFlight || reportsAllowed !== true)
                    return false;
                reportsBusy = true;
                reportsData = null;
                reportsRender();
                reportsStatus.textContent = reportsCopy.loading;
                reportsLock();
                try {
                    const epoch = reportsEpoch, payload = { action: 'summary', from: reportsFrom.value, to: reportsTo.value };
                    // The report endpoint itself reauthorizes every read, including CSV reloads.
                    const response = await learningRequest("reports", payload);
                    if (!response.ok) {
                        if ([401, 403].includes(response.status))
                            reportsAvailability(false);
                        throw new Error(reportsCopy.error);
                    }
                    const data = await response.json();
                    if (epoch !== reportsEpoch || reportsAllowed !== true)
                        return false;
                    const count = n => Number.isSafeInteger(n) && n >= 0;
                    if (data.period?.from !== payload.from || data.period?.to !== payload.to || data.period?.timezone !== 'UTC' || data.coverage !== 'verified-moodle-resource-scope' || !Array.isArray(data.days) || data.days.length > 366 || !['questions', 'good', 'bad', 'none'].every(key => count(data.totals?.[key])) || data.days.some(row => !/^\d{4}-\d{2}-\d{2}$/.test(row.date) || !['questions', 'good', 'bad', 'none'].every(key => count(row[key]))))
                        throw new Error(reportsCopy.error);
                    reportsData = data;
                    reportsStatus.textContent = '';
                    reportsRender();
                    return true;
                }
                catch {
                    reportsData = null;
                    reportsRender();
                    reportsStatus.textContent = reportsAllowed === false ? reportsCopy.unavailable : reportsCopy.error;
                    return false;
                }
                finally {
                    reportsBusy = false;
                    reportsLock();
                }
            }
            reportsForm.addEventListener('submit', event => { event.preventDefault(); void reportsLoad(); });
            document.getElementById("videoq-reports-export").addEventListener('click', async () => {
                if (!await reportsLoad() || !reportsData)
                    return;
                const lines = ['date,questions,good,bad,unrated', ...reportsData.days.map(row => [row.date, row.questions, row.good, row.bad, row.none].join(','))];
                const url = URL.createObjectURL(new Blob([lines.join('\n') + '\n'], { type: 'text/csv;charset=utf-8' })), link = qaNode('a');
                link.href = url;
                link.download = 'videoq-usage-' + reportsData.period.from + '_' + reportsData.period.to + '.csv';
                document.body.append(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 30000);
            });
            document.getElementById("videoq-reports-close").addEventListener('click', () => { reportsSetOpen(false); });
            reportsPanel.addEventListener('toggle', () => { reportsSetOpen(reportsPanel.open); });
            async function reportsInit() {
                if (features.reports && reportsAllowed === null) {
                    try {
                        await materialRequest('list');
                    }
                    catch {
                        reportsAvailability(false);
                    }
                }
            }
            // Incremental answer and feedback.
            const feedbackRefs = new Map();
            function setScope(videos) {
                if (Array.isArray(videos))
                    learningRefresh(videos);
            }
            function addFeedback(element, responseId) {
 const ref = feedbackRefs.get(responseId); if (!ref) return;
 const row = chat.querySelector('#videoq-native-feedback-template').content.firstElementChild.cloneNode(true);
 row.classList.add('feedback-controls');
 for (const button of row.querySelectorAll('[data-feedback]')) {
  const value = button.dataset.feedback;
  button.setAttribute('aria-pressed', String(ref.value === value));
  button.addEventListener('click', () => rateAnswer(responseId, ref.value === value ? null : value));
 }
 row.querySelector('[role="status"]').classList.add('feedback-error');
 element.append(row); ref.controls = row;
}
            async function rateAnswer(responseId, value) {
                const ref = feedbackRefs.get(responseId);
                if (!ref || ref.busy || !(value === null || value === 'good' || value === 'bad'))
                    return;
                ref.busy = true;
                for (const button of ref.controls?.querySelectorAll('button') || [])
                    button.disabled = true;
                try {
                    const res = await learningRequest("feedback", { feedbackId: ref.id, feedback: value });
                    if (!res.ok)
                        throw new Error(uiCopy.feedbackError);
                    const data = await res.json();
                    if (!(data.feedback === null || data.feedback === 'good' || data.feedback === 'bad'))
                        throw new Error(uiCopy.feedbackError);
                    ref.value = data.feedback;
                    if (ref.controls) {
                        for (const button of ref.controls.querySelectorAll('button'))
                            button.setAttribute('aria-pressed', String(button.dataset.feedback === ref.value));
                        ref.controls.querySelector('.feedback-error').textContent = '';
                    }
                }
                catch {
                    if (ref.controls)
                        ref.controls.querySelector('.feedback-error').textContent = uiCopy.feedbackError;
                }
                finally {
                    ref.busy = false;
                    for (const button of ref.controls?.querySelectorAll('button') || [])
                        button.disabled = false;
                }
            }
            async function readAnswer(res, responseId) {
                if (!res.headers.get('Content-Type')?.includes('text/event-stream')) {
                    const data = await res.json();
                    setScope(data.videos);
                    if (typeof data.feedbackId === 'string' && /^[a-f0-9]{64}$/.test(data.feedbackId))
                        feedbackRefs.set(responseId, { id: data.feedbackId, value: data.feedback ?? null, busy: false });
                    return { answer: data.answer };
                }
                const reader = res.body?.getReader();
                if (!reader)
                    throw new Error(uiCopy.interrupted);
                const decoder = new TextDecoder(), segments = new Map(), sources = new Map();
                let buffer = '', done = false, scheduled = null, element = null, dirty = false;
                const current = () => ({ segments: [...segments.entries()].sort((a, b) => a[0] - b[0]).map(([, value]) => ({ text: value.text, sourceIds: [...value.sourceIds] })), sources: [...sources.values()] });
                const segment = index => {
                    if (!segments.has(index))
                        segments.set(index, { text: '', sourceIds: [] });
                    return segments.get(index);
                };
                function render() {
                    scheduled = null;
                    dirty = false;
                    const answer = current();
                    if (!answer.segments.some(part => part.text))
                        return;
                    element = showAnswer(answer, element);
                    scrollLatest();
                }
                function change() {
                    if (scheduled === null)
                        scheduled = requestAnimationFrame(render);
                }
                function event(data) {
                    if (done)
                        return;
                    if (data.type === 'scope') {
                        setScope(data.videos);
                        return;
                    }
                    if (data.type === 'error')
                        throw new Error(data.code === 'AI_ANSWERS_LIMIT_EXCEEDED' ? data.message : uiCopy.answerError);
                    if (data.type === 'source' && data.source && Number.isSafeInteger(data.source.id)) {
                        sources.set(data.source.id, data.source);
                        change();
                    }
                    else if ((data.type === 'text_delta' || data.type === 'citation') && Number.isSafeInteger(data.segmentIndex) && data.segmentIndex >= 0 && data.segmentIndex < 10000) {
                        const value = segment(data.segmentIndex);
                        if (data.type === 'text_delta' && typeof data.text === 'string')
                            value.text += data.text;
                        else if (data.type === 'citation' && Number.isSafeInteger(data.sourceId) && !value.sourceIds.includes(data.sourceId))
                            value.sourceIds.push(data.sourceId);
                        change();
                    }
                    else if (data.type === 'done') {
                        done = true;
                        if (typeof data.feedbackId === 'string' && /^[a-f0-9]{64}$/.test(data.feedbackId))
                            feedbackRefs.set(responseId, { id: data.feedbackId, value: data.feedback ?? null, busy: false });
                    }
                }
                function frames() {
                    let end;
                    while ((end = buffer.indexOf('\n\n')) !== -1) {
                        const block = buffer.slice(0, end);
                        buffer = buffer.slice(end + 2);
                        const json = block.split('\n').filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n');
                        if (json)
                            event(JSON.parse(json));
                    }
                }
                try {
                    for (;;) {
                        const result = await reader.read();
                        buffer += decoder.decode(result.value, { stream: !result.done });
                        buffer = buffer.replace(/\r\n/g, '\n');
                        if (buffer.length > 1000000)
                            throw new Error(uiCopy.interrupted);
                        frames();
                        if (result.done)
                            break;
                    }
                    if (!done)
                        throw new Error(uiCopy.interrupted);
                    const answer = current();
                    if (!answer.segments.some(part => part.text.trim()))
                        throw new Error(uiCopy.answerError);
                    return { answer, element };
                }
                finally {
                    if (scheduled !== null) {
                        cancelAnimationFrame(scheduled);
                        render();
                    }
                    else if (dirty)
                        render();
                    reader.releaseLock();
                }
            }
            function replaceChatHistory(turns) {
                feedbackRefs.clear();
                messagesView.querySelectorAll('.message').forEach(element => element.remove());
                empty.hidden = false;
                history = turns.filter(turn => typeof turn.question === 'string' && turn.answer).map(turn => ({ ...turn,
                    id: typeof turn.id === 'string' ? turn.id : crypto.randomUUID() }));
                for (const turn of history) {
                    show(turn.question, 'question');
                    const element = showAnswer(turn.answer);
                    if (typeof turn.feedbackId === 'string') {
                        feedbackRefs.set(turn.id, { id: turn.feedbackId, value: turn.feedback });
                        addFeedback(element, turn.id);
                    }
                }
                messages = history.flatMap(turn => [{ role: 'user', content: turn.question },
                    { role: 'assistant', content: turn.answer.segments.map(segment => segment.text).join('') }]).slice(-8);
            }
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const text = question.value.trim();
                if (!text || send.disabled)
                    return;
                const position = freshPlayback();
                nativeRequestId = crypto.randomUUID();
                chatInFlight = true;
                syncComposer();
                personalDraw();
                const next = [...messages, { role: 'user', content: text }];
                streamController = new AbortController();
                try {
                show(text, 'question');
                status.dataset.kind = 'progress';
                status.textContent = uiCopy.answering;
                messagesView.setAttribute('aria-busy', 'true');
                    const endpoint = new URL(config.streamUrl, location.href);
                    if (endpoint.origin !== location.origin || !endpoint.pathname.endsWith('/local/videoq/stream.php'))
                        throw new Error(uiCopy.connectionError);
                    const payload = { messages: activeConversationId ? [{ role: 'user', content: text }] : next, ...personalMessageInput(),
                        ...(position ? { playback: { video_id: position.videoId, position_seconds: position.seconds } } : {}) };
                    const res = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', signal: streamController.signal,
                        body: new URLSearchParams({ courseid: String(config.courseid), lesson: config.lesson || '', sesskey: M.cfg.sesskey, payload: JSON.stringify(payload) }) });
                    if (!res.ok)
                        throw new Error(res.status === 429 ? uiCopy.rateLimit : uiCopy.answerError);
                    const data = await readAnswer(res, nativeRequestId);
                    const element = showAnswer(data.answer, data.element);
                    addFeedback(element, nativeRequestId);
                    messages = [...next, { role: 'assistant', content: data.answer.segments.map(segment => segment.text).join('') }].slice(-8);
                    question.value = '';
                    resizeInput();
                    status.textContent = '';
                    scrollLatest();
                    personalCommitted();
                }
                catch (error) {
                    status.dataset.kind = 'error';
                    status.textContent = error.message || uiCopy.answerError;
                    // Refresh current authority without retrying the question or changing its draft.
                    void materialRequest('list').catch(() => { });
                }
                finally {
                    streamController?.abort();
                    streamController = null;
                    chatInFlight = false;
                    messagesView.setAttribute('aria-busy', 'false');
                    syncComposer();
                    personalDraw();
                }
            });
            window.addEventListener('pagehide', () => { streamController?.abort(); if (qaPendingDialog)
                qaPendingDialog(); });
            window.addEventListener('pageshow', event => { if (event.persisted)
                void materialRequest('list').catch(() => { }); });
            async function start() {
                try {
                    await materialRequest('list');
                    if (learningEnabled) {
                        const response = await learningRequest('history');
                        if (!response.ok)
                            throw new Error(uiCopy.connectionError);
                        const saved = await response.json();
                        replaceChatHistory(Array.isArray(saved.turns) ? saved.turns : []);
                    }
                    sessionReady = true;
                    status.textContent = '';
                    syncComposer();
                    void Promise.all([personalInit(), managementInit(), tagsInit(), reportsInit()]).catch(() => { });
                }
                catch (error) {
                    status.dataset.kind = 'error';
                    status.textContent = uiCopy.connectionError;
                }
            }
            void start();
        });
    }
    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', init, { once: true });
    else
        init();
})();
