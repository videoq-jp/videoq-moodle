// Owned browser contract fixture. YouTube SDK is substituted; no provider data is fetched.
window.playerFixture = { records: [], pending: [], delayed: false, loaded: new Set(), messages: [], errors: [] };
const fixture = window.playerFixture;
window.addEventListener('error', event => fixture.errors.push(event.message));
const frameOrigin = location.protocol + '//' + (location.hostname === '127.0.0.1' ? 'localhost' : '127.0.0.1') + ':' + location.port;
const sources = [{ lesson: 'file', title: 'Owned File', kind: 'file', url: location.origin + '/fixture.mp4' },
    { lesson: 'youtube', title: 'Owned YouTube', kind: 'youtube', youtubeVideoId: 'abcDEF123_-', sourceGeneration: 7 }];
document.body.innerHTML = '<main class="local-videoq-workspace" data-videoq-lesson="youtube"><section class="local-videoq-video local-videoq-panel">'
    + '<select id="videoq-lesson"><option value="file">Owned File</option><option value="youtube" selected>Owned YouTube</option></select>'
    + '<div data-videoq-file><video preload="none"></video></div><div class="local-videoq-youtube" data-videoq-youtube hidden></div>'
    + '<p data-videoq-player-status role="status"></p></section><section class="local-videoq-questions local-videoq-panel">'
    + '<iframe id="videoq-questions" title="Owned tool"></iframe></section></main><iframe id="rogue" title="Owned foreign frame" hidden></iframe>';
const root = document.querySelector('main');
root.dataset.videoqOrigin = frameOrigin;
root.dataset.videoqLessons = JSON.stringify(sources);
root.dataset.videoqYoutubeError = 'Owned provider failed';
root.dataset.videoqYoutubeAutoplay = 'Owned press Play';
root.dataset.videoqSourceUpdated = 'Owned source changed: reload';
for (const frame of document.querySelectorAll('iframe')) frame.src = frameOrigin + '/frame?parent=' + encodeURIComponent(location.origin);
window.addEventListener('message', event => {
    if (event.origin !== frameOrigin) return;
    const id = [...document.querySelectorAll('iframe')].find(frame => frame.contentWindow === event.source)?.id;
    if (event.data?.fixtureLoaded) fixture.loaded.add(id);
    else fixture.messages.push({ id, data: event.data });
});
const media = root.querySelector('video');
let fileUrl = sources[0].url, position = 0;
Object.defineProperties(media, {
    duration: { get: () => 120 }, readyState: { get: () => 2 },
    currentSrc: { get: () => fileUrl }, currentTime: { get: () => position, set: value => { position = value; } },
});
media.pause = () => {};
media.play = () => Promise.resolve();
media.load = () => {
    fileUrl = media.src;
    setTimeout(() => { media.dispatchEvent(new Event('loadedmetadata')); media.dispatchEvent(new Event('canplay')); }, 0);
};
window.YT = { Player: class {
    constructor(host, options) {
        this.options = options; this.position = 0; this.plays = 0; this.destroyed = false;
        this.requestedSource = host.src; this.requestedReferrerPolicy = host.referrerPolicy;
        this.frame = host; this.frame.src = 'about:blank'; this.frame.title = 'Owned provider substitute';
        this.frame.style.background = '#dedede';
        fixture.records.push(this);
        const ready = () => options.events.onReady({ target: this });
        if (fixture.delayed) fixture.pending.push(ready); else setTimeout(ready, 0);
    }
    getDuration() { return 120; }
    getCurrentTime() { return this.position; }
    seekTo(seconds) { this.position = seconds; }
    playVideo() { this.plays++; }
    destroy() { this.destroyed = true; this.frame.remove(); }
} };
fixture.root = root;
fixture.send = (payload, id = 'videoq-questions') => document.getElementById(id).contentWindow.postMessage({ fixture: payload }, frameOrigin);
fixture.read = () => { const detail = {}; root.dispatchEvent(new CustomEvent('videoq:read-playback', { detail })); return detail.playback; };
fixture.select = lesson => { const select = root.querySelector('select'); select.value = lesson; select.dispatchEvent(new Event('change')); };
