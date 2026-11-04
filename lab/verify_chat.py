"""Native Moodle chat: real LTI launch, streaming, feedback and timestamp seek.

The default uses a held browser-only stream, with no paid inference. --live sends
one real question to the separately deployed VideoQ provider.
"""
import argparse
import re
from videoq_lab.browser import browser, login, open_course, fill, click, wait_js, in_tool, stream_response, element
from videoq_lab.runtime import DATA, guard_lab, query, save_report
from videoq_lab.sse import read_answer

FIXTURE = r'''
if(location.origin===VIDEOQ_ORIGIN){
 const original=window.fetch.bind(window);
 window.feedbackValues=[];window.failFeedback=false;
 window.fetch=async(input,init)=>{
  const path=new URL(String(input),location.href).pathname;
  if(path.endsWith('/api/lti/feedback')){
   const body=JSON.parse(init.body);window.feedbackValues.push(body.feedback);
   if(window.failFeedback){window.failFeedback=false;return new Response('{}',{status:500});}
   return new Response(JSON.stringify({feedback:body.feedback}),{headers:{'Content-Type':'application/json'}});
  }
  if(!path.endsWith('/api/lti/messages/stream')) return original(input,init);
  return new Response(new ReadableStream({start(controller){
   const encoder=new TextEncoder();const emit=event=>controller.enqueue(encoder.encode('data: '+JSON.stringify(event)+'\r\n\r\n'));
   const first=encoder.encode('data: '+JSON.stringify({type:'source',source:{id:1,video_id:VIDEO_ID,title:'動画教材',start_time:'00:00:08',end_time:'00:00:16'}})+'\r\n\r\n');
   const split=first.findIndex(byte=>byte>=0xe0)+1;
   controller.enqueue(first.slice(0,split));controller.enqueue(first.slice(split,-1));controller.enqueue(first.slice(-1));
   emit({type:'text_delta',segmentIndex:0,text:'最初に見える説明です。<img src=x onerror="alert(1)">'});
   window.finishStream=()=>{emit({type:'text_delta',segmentIndex:0,text:'\n\n**続きの説明です。**\n\n- 動画を見る\n- 質問する'});emit({type:'citation',segmentIndex:0,sourceId:1});emit({type:'done',chat_log_id:1234,feedbackId:'b'.repeat(64),feedback:null});controller.close();};
   window.interruptStream=()=>controller.close();
  }}),{headers:{'Content-Type':'text/event-stream'}});
 };
}
'''

def ready(driver):
    wait_js(driver, "return document.querySelector('#videoq-native-controls')?.disabled===false", 150)

def ask(driver, question):
    fill(driver, '#videoq-native-question', question)
    click(driver, '#videoq-native-send')

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--live', action='store_true', help='One real provider request (may incur charges)')
    parser.add_argument('--course', type=int, default=5)
    args = parser.parse_args()
    guard_lab()
    from videoq_lab.browser import origin, VIDEOQ
    import json
    video_ids = [row['video_id'] for row in query("SELECT video_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND video_id IS NOT NULL")]
    assert video_ids, 'An indexed course video is required'
    fixture = FIXTURE.replace('VIDEOQ_ORIGIN', json.dumps(origin(VIDEOQ))).replace('VIDEO_ID', str(video_ids[0]))
    with browser('' if args.live else fixture) as driver:
        login(driver)
        open_course(driver, args.course)
        driver.execute_script("document.querySelectorAll('.answer:not([data-existing])').forEach(element=>element.dataset.existing='true')")
        assert element(driver, '#videoq-native-question').get_attribute('rows') == '3'
        assert driver.execute_script("return document.querySelector('#videoq-questions').getAttribute('aria-hidden')") == 'true'
        assert driver.execute_script("return document.querySelectorAll('#playback-controls,#current-question,#use-position,[data-question],.local-videoq-chat-link').length") == 0
        if args.live:
            ask(driver, 'この講座で説明している画像の標本化と量子化の違いを簡潔に説明してください。')
            response = stream_response(driver)
            assert response['status'] == 200 and 'text/event-stream' in response['contentType']
            answer = read_answer(response['text'].encode())
            assert any(len(part['text'].strip()) > 20 for part in answer['segments'])
            assert answer['sources'] and all(source['video_id'] in video_ids for source in answer['sources'])
            ready(driver)
            assert driver.execute_script('return window.vqUpdates') > 1
        else:
            fill(driver, '#videoq-native-question', 'IME入力')
            driver.execute_script("document.querySelector('#videoq-native-question').dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',isComposing:true,bubbles:true,cancelable:true}))")
            assert driver.execute_script("return document.querySelectorAll('.answer:not([data-existing])').length") == 0
            ask(driver, 'ストリーミングを確認します')
            wait_js(driver, "return document.querySelector('.answer:not([data-existing]) [data-region=\"text-container\"]')?.textContent.includes('最初に見える説明です。')")
            assert not element(driver, '#videoq-native-question').is_enabled()
            assert not driver.find_elements('css selector', '.answer:not([data-existing]) [data-feedback]')
            in_tool(driver, lambda current: current.execute_script('window.finishStream()'))
            ready(driver)
            assert element(driver, '.answer:not([data-existing]) strong').text == '続きの説明です。'
            assert len(driver.find_elements('css selector', '.answer:not([data-existing]) ul li')) == 2
        assert element(driver, '.answer:not([data-existing]) h6').text == 'AIアシスタント'
        assert not driver.find_elements('css selector', '.answer:not([data-existing]) [data-region="text-container"] img')
        for value, pressed in [('good', True), ('bad', True), ('bad', False)]:
            click(driver, '[data-feedback="' + value + '"]')
            wait_js(driver, "return document.querySelector('[data-feedback=\"'+arguments[0]+'\"]').getAttribute('aria-pressed')===String(arguments[1])&&!document.querySelector('[data-feedback=\"'+arguments[0]+'\"]').disabled", 30, value, pressed)
        if not args.live:
            in_tool(driver, lambda current: current.execute_script('window.failFeedback=true'))
            click(driver, '[data-feedback="good"]')
            wait_js(driver, "return document.querySelector('[data-region=\"answer-feedback\"] [role=status]')?.textContent.includes('評価を保存できませんでした')")
            assert element(driver, '[data-feedback="good"]').get_attribute('aria-pressed') == 'false'
            click(driver, '[data-feedback="good"]')
            wait_js(driver, "return document.querySelector('[data-feedback=good]').getAttribute('aria-pressed')==='true'")
            # Wrong origin, wrong source window, and wrong channel cannot forge a chat event.
            driver.execute_script('''const bridge=window.vqBridge,child=document.querySelector('#videoq-questions').contentWindow;
              const data={type:'videoq:chat',version:1,channel:bridge.channel,videoId:bridge.videoId,event:'question',requestId:'f'.repeat(32),text:'FORGED'};
              for(const event of [{source:child,origin:'https://attacker.example',data},{source:window,origin:bridge.origin,data},{source:child,origin:bridge.origin,data:{...data,channel:'f'.repeat(32)}}])window.dispatchEvent(new MessageEvent('message',event));''')
            assert 'FORGED' not in element(driver, '#videoq-native-chat').text
            original = driver.execute_script("return getComputedStyle(document.querySelector('.message.send')).backgroundColor")
            driver.execute_script("window.testStyle=document.createElement('style');testStyle.textContent='.message-app .message.send {background-color:rgb(96,54,151)!important}';document.head.append(testStyle)")
            assert driver.execute_script("return getComputedStyle(document.querySelector('.message.send')).backgroundColor") == 'rgb(96, 54, 151)'
            driver.execute_script('testStyle.remove()')
            assert driver.execute_script("return getComputedStyle(document.querySelector('.message.send')).backgroundColor") == original
        citation = element(driver, '.answer:not([data-existing]) button.citation')
        seconds = 0
        for part in re.search(r'\d+:\d+(?::\d+)?', citation.text)[0].split(':'):
            seconds = seconds * 60 + int(part)
        citation.click()
        wait_js(driver, "return Math.abs(document.querySelector('video').currentTime-arguments[0])<2", 30, seconds)
        driver.execute_script("document.querySelector('video').pause()")
        for width in [390, 768, 1280, 1440]:
            driver.set_window_size(width, 1100)
            wait_js(driver, 'return document.documentElement.scrollWidth<=innerWidth')
        if not args.live:
            ask(driver, '途中切断の確認')
            wait_js(driver, "return document.querySelectorAll('.answer:not([data-existing])').length===2")
            in_tool(driver, lambda current: current.execute_script('window.interruptStream()'))
            ready(driver)
            wait_js(driver, "return document.querySelector('#videoq-native-status').classList.contains('text-danger')")
            assert element(driver, '#videoq-native-question').get_attribute('value') == '途中切断の確認'
            assert not driver.find_elements('css selector', '.answer:not([data-existing]):last-child [data-feedback]')
        (DATA / 'acceptance').mkdir(parents=True, exist_ok=True)
        driver.save_screenshot(str(DATA / 'acceptance' / ('chat-live.png' if args.live else 'chat-browser.png')))
        save_report('chat-live' if args.live else 'chat-browser', dict(passed=True, realProvider=args.live,
                    nativeMoodleChat=True, streaming=True, feedbackGoodBadReset=True, timestampSeek=True,
                    responsiveWidths=[390,768,1280,1440], sourceVideoIds=video_ids))

if __name__ == '__main__':
    main()
