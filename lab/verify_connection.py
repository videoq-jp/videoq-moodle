"""Connection-code/recovery/disconnect UI on a disposable external installation."""
import base64
import hashlib
import json
import uuid
from videoq_lab.browser import browser, login, fill, click, element, wait_js, fetch
from videoq_lab.runtime import VIDEOQ, guard_lab, http, query, save_report

ISSUER = 'https://disconnect.videoq.example'

def button(driver, label, root='body'):
    driver.execute_script("const button=[...document.querySelector(arguments[0]).querySelectorAll('button')].find(e=>e.textContent.trim()===arguments[1]);if(!button)throw new Error('Expected button missing');button.click()", root, label)

def snapshot():
    return query("""SELECT (SELECT coalesce(jsonb_agg(to_jsonb(v) ORDER BY v.id),'[]'::jsonb) FROM videos v) AS videos,
        (SELECT count(*) FROM scene_embeddings) AS vectors,(SELECT count(*) FROM chat_logs) AS logs,
        (SELECT count(*) FROM video_course_members) AS memberships""")

def main():
    guard_lab()
    before = snapshot()
    connections = query('SELECT id,active FROM lms_installations ORDER BY id')
    installation = secret = None
    assert http(VIDEOQ+'/api/lms/pairing/connections/unknown/disconnect', {})[0] == 401
    try:
        with browser() as driver:
            login(driver, 'videoq_lab', VIDEOQ)
            driver.get(VIDEOQ+'/settings#moodle')
            fill(driver, '#moodle-url', ISSUER)
            click(driver, '#moodle form button[type=submit]')
            code = element(driver, '#moodle-code').get_attribute('value')
            assert code.startswith('VIDEOQ2.'), 'Materials-capable server required'
            secret = json.loads(base64.b64decode(code[8:]))['secret']
            body = dict(secret=secret, issuer=ISSUER, clientId='disconnect-'+uuid.uuid4().hex,
                        deploymentId='1', mode='materials', materialsService=dict(
                            tokenEndpoint=ISSUER+'/local/videoq/token.php', endpoint=ISSUER+'/local/videoq/service.php'))
            wrong = dict(body,issuer='https://wrong.videoq.example',materialsService=dict(
                         tokenEndpoint='https://wrong.videoq.example/token',endpoint='https://wrong.videoq.example/service'))
            assert http(VIDEOQ+'/api/lms/pairing/exchange',wrong)[0] == 401
            status, _, response = http(VIDEOQ+'/api/lms/pairing/exchange',body)
            assert status == 200
            installation = json.loads(response)['installationId']
            # Retrying the same short-lived code recovers a lost exchange response.
            status, _, response = http(VIDEOQ+'/api/lms/pairing/exchange',body)
            assert status == 200 and json.loads(response)['installationId'] == installation
            row = '[data-connection-id="'+installation+'"]'
            driver.refresh()
            element(driver,row)
            # Labels come from the Japanese app locale used by the trial owner.
            button(driver,'接続を解除',row)
            button(driver,'キャンセル',row)
            assert query('SELECT active FROM lms_installations WHERE id=%s',(installation,))[0]['active']
            button(driver,'接続を解除',row)
            button(driver,'解除する',row)
            wait_js(driver,"return document.querySelector(arguments[0])?.textContent.includes('接続解除済み')",30,row)
            assert not query('SELECT active FROM lms_installations WHERE id=%s',(installation,))[0]['active']
            assert http(VIDEOQ+'/api/lms/pairing/exchange',body)[0] == 401
            driver.refresh()
            wait_js(driver,"return document.querySelector(arguments[0])?.textContent.includes('接続解除済み')",30,row)
            assert snapshot() == before
            assert query('SELECT id,active FROM lms_installations WHERE id<>%s ORDER BY id',(installation,)) == connections
            save_report('connection', dict(passed=True,materialsMode=True,wrongIssuerRejected=True,lostResponseRecovery=True,
                        cancelPreservesConnection=True,disconnectedStatePersists=True,learningDataPreserved=True,otherConnectionsPreserved=True))
    finally:
        if installation:
            query('DELETE FROM lms_installations WHERE id=%s AND issuer=%s AND owner_user_id=%s',(installation,ISSUER,'lti-lab-owner'))
        if secret:
            hashes = [hashlib.sha256((prefix+secret).encode()).hexdigest() for prefix in ['lti-materials-pairing:','moodle-pairing:']]
            query("DELETE FROM lti_credentials WHERE id=ANY(%s) AND kind IN('materials-pairing','moodle-pairing-code')",(hashes,))

if __name__ == '__main__':
    main()
