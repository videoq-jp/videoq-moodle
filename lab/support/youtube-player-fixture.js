// Owned Moodle AJAX substitute; no real media or provider request is needed.
window.playerFixture = {calls: [], pending: [], delayed: false, errors: []};
const fixture = window.playerFixture;
window.addEventListener('error', event => fixture.errors.push(event.message));
const sources = [{videoId: 1, lesson: '1', title: 'Owned VideoQ file'}, {videoId: 2, lesson: '2', title: 'Owned YouTube'},
    {videoId: 3, lesson: '3', title: 'Another VideoQ file'}];
document.body.innerHTML = '<div class="local-videoq-workspace" data-videoq-native="1"><section class="local-videoq-main">'
    + '<div data-videoq-file><video id="videoq-player"></video></div><div class="local-videoq-youtube" data-videoq-youtube hidden></div>'
    + '<p data-videoq-player-status role="status"></p><h3 data-videoq-video-title></h3><p data-videoq-description></p></section>'
    + '<div data-videoq-playlist></div></div>';
const root = fixture.root = document.querySelector('.local-videoq-workspace');
root.dataset.videoqConfig = JSON.stringify({courseid: 42, copy: {connecting: 'Loading', connectionError: 'Unavailable'}});
root.dataset.videoqLessons = JSON.stringify(sources);
const media = root.querySelector('video');
let position = 0;
Object.defineProperties(media, {duration: {get: () => 120}, readyState: {get: () => 2},
    currentTime: {get: () => position, set: value => {position = value;}}});
media.pause = () => {}; media.load = () => {};
window.require = (dependencies, callback) => callback({call: requests => requests.map(request => {
    fixture.calls.push(request);
    const id = JSON.parse(request.args.payload).videoId;
    const data = id === 2 ? {kind: 'youtube', url: 'https://www.youtube-nocookie.com/embed/abcDEF123_-'}
        : {kind: 'file', url: 'https://media.example/file.mp4'};
    const result = {status: 200, contenttype: 'application/json', body: JSON.stringify({...data, title: 'Video ' + id, description: 'Owned description'})};
    return fixture.delayed ? new Promise(resolve => fixture.pending.push(() => resolve(result))) : Promise.resolve(result);
})});
fixture.send = videos => root.dispatchEvent(new CustomEvent('videoq:materials', {detail: {videos}}));
fixture.read = () => {const detail = {}; root.dispatchEvent(new CustomEvent('videoq:read-playback', {detail})); return detail.playback;};
fixture.select = id => root.querySelector(`[data-video-id="${id}"]`).click();
