"""Native Moodle logins and optional concurrent real SSE questions (1–200).

This measures API/provider question concurrency, not simultaneous video decoders.
Tokens stay in process memory. No per-learner VideoQ accounts are created.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import time
import urllib.error
import urllib.request
from videoq_lab.browser import browser, login, open_course, in_tool
from videoq_lab.runtime import MOODLE_CONTAINER, VIDEOQ, docker, guard_lab, query, save_report, tls_context
from videoq_lab.sse import read_answer

CAPTURE_TOKEN = '''
const original=window.fetch.bind(window);
window.fetch=(input,init)=>{
 if(new URL(String(input),location.href).pathname.endsWith('/api/lti/messages/stream')){
  window.vqLoadToken=new Headers(init.headers).get('Authorization');
  return Promise.resolve(new Response('data: {"type":"text_delta","segmentIndex":0,"text":"Authentication capture only"}\\n\\ndata: {"type":"done"}\\n\\n',{headers:{'Content-Type':'text/event-stream'}}));
 }return original(input,init);
};
'''

def authenticated(user):
    with browser() as driver:
        login(driver,user['username'])
        open_course(driver)
        print('Signed learner launch ready.', flush=True)
        def capture(current):
            current.execute_script(CAPTURE_TOKEN)
            current.execute_script("document.querySelector('#question').value='Authentication capture';document.querySelector('#form').requestSubmit()")
            token = current.execute_script('return window.vqLoadToken')
            assert token and token.startswith('Bearer '), 'Signed LTI session missing'
            return token
        return in_tool(driver,capture)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--count',type=int,default=5)
    parser.add_argument('--live',action='store_true',help='Send one real AI question per learner (may incur charges)')
    args = parser.parse_args()
    guard_lab()
    assert 1 <= args.count <= 200
    if args.live:
        quota = query("SELECT ai_answers_limit,used_ai_answers FROM users WHERE id='lti-lab-owner'")[0]
        assert quota['ai_answers_limit'] - quota['used_ai_answers'] >= args.count, 'Reserve sufficient answer quota'
    before = query('SELECT count(*) AS n FROM users')[0]['n']
    users = json.loads(docker('exec',MOODLE_CONTAINER,'php','/lab/acceptance-users.php','create',str(args.count),'5'))
    try:
        with ThreadPoolExecutor(max_workers=2) as pool:
            tokens = list(pool.map(authenticated,users))
        context = tls_context()
        began = time.monotonic()
        def ask(token):
            request = urllib.request.Request(VIDEOQ+'/api/lti/messages/stream',
                data=json.dumps({'messages':[{'role':'user','content':'この講座で説明する画像の標本化と量子化の違いを短く説明してください。'}]}).encode(),
                headers={'Authorization':token,'Content-Type':'application/json','Origin':VIDEOQ})
            try:
                with urllib.request.urlopen(request,context=context,timeout=300) as response:
                    answer = read_answer(response.read())
                    ok = response.status == 200 and bool(answer['sources']) and any(part['text'].strip() for part in answer['segments'])
                    return dict(ok=bool(ok),httpStatus=response.status,milliseconds=round((time.monotonic()-began)*1000))
            except Exception as error:
                return dict(ok=False,error=type(error).__name__,milliseconds=round((time.monotonic()-began)*1000))
        results = []
        if args.live:
            with ThreadPoolExecutor(max_workers=args.count) as pool:
                results = list(pool.map(ask,tokens))
            assert all(row['ok'] for row in results), 'One or more real questions failed'
        assert query('SELECT count(*) AS n FROM users')[0]['n'] == before
        save_report('load-'+str(args.count),dict(passed=True,realMoodleLogins=len(tokens),realAi=args.live,
                    questionsStartedConcurrently=args.live,simultaneousVideoPlaybackTested=False,
                    additionalVideoqAccounts=0,results=results))
    finally:
        docker('exec',MOODLE_CONTAINER,'php','/lab/acceptance-users.php','cleanup',str(args.count),'5')

if __name__ == '__main__':
    main()
