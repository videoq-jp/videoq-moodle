// Controlled Moodle-session/API substitute. Production templates and browser assets are real.
(function() {
    const copy = nativeFixtureConfig.copy;
    const fixture = window.nativeLearningFixture = {copy, calls: [], streams: [], errors: [], seeks: [], downloads: [], writes: 0,
        ownerHistory: true, failWrite: false, conflictWrite: false, denyManage: false, denyCoreReports: false, failStream: false, questions: [], conversations: [], tags: []};
    window.addEventListener('error', event => fixture.errors.push(event.message));
    window.addEventListener('unhandledrejection', event => fixture.errors.push(String(event.reason)));
    window.M = {cfg: {sesskey: 'owned-moodle-sesskey'}};
    const root = document.querySelector('.local-videoq-workspace');
    root.addEventListener('videoq:seek-citation', event => fixture.seeks.push(event.detail));
    const media = root.querySelector('video');
    let seconds = 0;
    if (media) {
        Object.defineProperties(media, {duration: {get: () => 120}, readyState: {get: () => 2},
            currentSrc: {get: () => location.origin + '/fixture.mp4'}, currentTime: {get: () => seconds, set: value => {seconds = value;}}});
        media.play = () => Promise.resolve(); media.pause = () => {}; media.load = () => {};
    }
    const videos = nativeFixtureConfig.empty ? [] : [{videoId: 1, lesson: '1', title: 'Owned Moodle File'}];
    const permissions = {viewTranscript: true, viewQa: true, postQa: true, voteQa: true, deleteOwnQa: true,
        moderateQa: true, instructorQa: true, manageOwnHistory: true, manageContent: true, viewReports: true};
    let material = {videoId: 1, title: 'Owned Moodle File', description: 'Original description', transcript: '1\n00:00:00,000 --> 00:00:03,000\nOwned text\n',
        revision: 'initial', status: 'completed', jobState: 'completed', canEdit: true, canReindex: true, canRetry: false};
    let tagRevision = 'initial';
    const committed = new Map();
    const answer = {segments: [{text: 'Owned answer <img src=x onerror="window.nativeUnsafe=true">', sourceIds: [1]}],
        sources: [{id: 1, video_id: 1, title: 'Owned Moodle File', start_time: '00:00:12'}]};
    const author = {source: 'moodle', name: 'Owned teacher', instructor: true};
    function metadata() { return {courseHistoryAvailable: fixture.ownerHistory, nativeProtocol: 1, features: {transcripts: true, qa: true, conversations: true, management: false, tags: false, reports: false},
        permissions: {...permissions, manageContent: false, viewReports: false}, videos, managementVideos: [], order: videos.map(v => v.videoId)}; }
    function forumData(input) {
        if (input.action === 'ask') { const question = {id: crypto.randomUUID(), title: input.title, body: input.body, videoId: input.videoId,
            videoTitle: videos[0]?.title, author, updatedAt: new Date().toISOString(), votes: 0, voted: false, featured: false, canEdit: true, canDelete: true, replyCount: 0}; fixture.questions.push(question); return {id: question.id}; }
        const item = fixture.questions.find(q => q.id === input.questionId);
        if (input.action === 'vote') { item.voted = input.voted; item.votes = input.voted ? 1 : 0; }
        if (input.action === 'feature') item.featured = input.featured;
        if (input.action === 'updateQuestion') {item.title = input.title; item.body = input.body;}
        if (input.action === 'deleteQuestion') fixture.questions = fixture.questions.filter(q => q.id !== input.questionId);
        if (input.action === 'reply') { item.replies ||= []; item.replies.push({id: crypto.randomUUID(), body: input.body, author, createdAt: new Date().toISOString(), canEdit: true, canDelete: true}); item.replyCount++; }
        if (input.action === 'get') return {question: item, replies: item.replies || [], replyTotal: item.replies?.length || 0, canPost: true, canVote: true, canModerate: true};
        return {items: fixture.questions, total: fixture.questions.length, canPost: true, canVote: true, canModerate: true};
    }
    function handle(operation, input) {
        if (operation === 'playback') return {kind: 'file', url: 'https://media.example/fixture.mp4', title: 'Owned VideoQ video', description: 'Linked course description'};
        if (operation === 'materials') return metadata();
        if (operation === 'course-history') return {count: 1, results: [{id:1, created_at:new Date().toISOString(), question:'Owned course question',
            asked_by:{username:'Learner',email:'learner@example.com'}, answer: {
                segments: [{text: 'History answer', sourceIds: [1, 1]}],
                sources: [{id: 1, video_id: 1, title: 'Owned Moodle File', start_time: '00:00:12,345'},
                    {id: 2, video_id: 1, title: 'Unreferenced source', start_time: '01:02:03,450'}]
            }, feedback:'good'}]};
        if (operation === 'history') return {turns: []};
        if (operation === 'transcript') return {videoId: 1, cues: [{startTime: '00:00:12', text: 'Owned transcript'}], nextCursor: null};
        if (operation === 'feedback') return {feedback: input.feedback};
        if (operation === 'qa') return {data: forumData(input), permissions, videos};
        if (operation === 'conversations') {
            const item = fixture.conversations.find(c => c.id === input.conversationId);
            if (input.action === 'list') return {items: fixture.conversations.map(c => ({...c, turns: undefined})), hasMore: false};
            if (input.action === 'delete') { fixture.conversations = fixture.conversations.filter(c => c.id !== input.conversationId); return {ok: true}; }
            return {...item, turns: item?.turns || [], hasMore: false};
        }
        if (operation === 'management') {
            if (fixture.denyManage) throw {status: 403};
            if (input.action !== 'get') {
                if (fixture.conflictWrite) throw {status: 409};
                if (!committed.has(input.operationId)) { fixture.writes++; material = {...material, ...input.fields, revision: crypto.randomUUID()}; committed.set(input.operationId, {material}); }
                if (fixture.failWrite) {fixture.failWrite = false; throw {status: 503};}
                return committed.get(input.operationId);
            }
            return {material};
        }
        if (operation === 'tags') {
            if (input.action === 'create') { fixture.tags.push({id: crypto.randomUUID(), name: input.name, color: input.color, videoIds: [], revision: crypto.randomUUID(), editable: true}); }
            if (input.action === 'assign') {for (const tag of fixture.tags) tag.videoIds = input.tagIds.includes(tag.id) ? [1] : []; tagRevision = crypto.randomUUID();}
            if (input.action === 'delete') fixture.tags = fixture.tags.filter(tag => tag.id !== input.id);
            return {tags: fixture.tags, videos: videos.map(v => ({videoId: v.videoId, tagIds: fixture.tags.filter(t => t.videoIds.includes(v.videoId)).map(t => t.id), revision: tagRevision, editable: true})), canManage: true};
        }
        if (operation === 'reports') return {period: {from: input.from, to: input.to, timezone: 'UTC'}, coverage: 'verified-moodle-resource-scope',
            totals: {questions: 1, good: 1, bad: 0, none: 0}, days: [{date: input.to, questions: 1, good: 1, bad: 0, none: 0}]};
        throw new Error('Unexpected operation ' + operation);
    }
    window.require = function(names, callback) {
        if (names[0] === 'media_videojs/video-lazy') {callback({getPlayer: () => null}); return;}
        if (names[0] !== 'core/ajax') throw new Error('Unexpected Moodle module');
        callback({call: requests => requests.map(async request => {
            const input = JSON.parse(request.args.payload); fixture.calls.push({method: request.methodname, args: request.args, input});
            if (request.methodname !== 'local_videoq_request_learning' || request.args.courseid !== 42) throw new Error('Unexpected Moodle authority input');
            if (request.args.operation === 'reports' && fixture.denyCoreReports) throw {errorcode: 'nativeaccessdenied'};
            if (request.args.operation === 'course-history' && input.action === 'csv') return {status:200,contenttype:'text/csv',body:'created_at,asked_by_email,question,answer\r\n2026-11-13,learner@example.com,Question,Answer\r\n'};
            try {return {status: 200, body: JSON.stringify(handle(request.args.operation, input)), contenttype: 'application/json'};}
            catch (error) { if (error.status) return {status: error.status, body: '{"error":{}}', contenttype: 'application/json'}; throw error; }
        })});
    };
    const originalFetch = window.fetch;
    window.fetch = async function(url, options) {
        if (new URL(url, location.href).pathname === '/local/videoq/stream.php') {
            const values = Object.fromEntries(options.body), input = JSON.parse(values.payload);
            const record = {url: String(url), values, input, aborted: false, options: {credentials: options.credentials, headers: options.headers}};
            fixture.streams.push(record);
            options.signal.addEventListener('abort', () => {record.aborted = true;});
            const turn = {id: crypto.randomUUID(), question: input.messages.at(-1).content, answer, feedbackId: 'a'.repeat(64), feedback: null};
            const c = fixture.conversations.find(c => c.id === input.conversationId);
            if (c) c.turns.push(turn); else fixture.conversations.push({id: input.conversationId, title: turn.question, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(), turns: [turn]});
            const events = fixture.failStream ? [{type: 'error', code: 'STREAM_FAILED'}] : [{type: 'scope', videos}, {type: 'source', source: answer.sources[0]},
                {type: 'text_delta', segmentIndex: 0, text: answer.segments[0].text}, {type: 'citation', segmentIndex: 0, sourceId: 1}, {type: 'done', feedbackId: 'a'.repeat(64), feedback: null}];
            const bytes = new TextEncoder().encode(events.map(event => 'data: ' + JSON.stringify(event) + '\n\n').join(''));
            return new Response(new ReadableStream({start(controller) {controller.enqueue(bytes.slice(0, 17)); setTimeout(() => {controller.enqueue(bytes.slice(17)); controller.close();}, 20);}}), {headers: {'Content-Type': 'text/event-stream'}});
        }
        return originalFetch(url, options);
    };
    document.addEventListener('click', event => {const link = event.target.closest('a[download]'); if (link) {event.preventDefault(); fixture.downloads.push({name: link.download, url: link.href});}}, true);
})();
