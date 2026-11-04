"""Native WebDriver/Chrome automation. No JavaScript server runtime or bundled driver."""
from contextlib import contextmanager
import json
import os
import threading
from urllib.parse import urlsplit
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.common.exceptions import StaleElementReferenceException
from .runtime import MOODLE, VIDEOQ

_DRIVER_START_LOCK = threading.Lock()

CAPTURE = r'''
if (location.origin === VIDEOQ_ORIGIN) {
 const original = window.fetch.bind(window); window.vqEvidence=[];
 window.fetch=async function(input, init) {
  const response=await original(input,init), path=new URL(String(input),location.href).pathname;
  if(path.startsWith('/api/lti/')) response.clone().text().then(text=>{
   if(path.endsWith('/session')&&response.ok) window.vqSession=JSON.parse(text);
   else window.vqEvidence.push({path,status:response.status,contentType:response.headers.get('content-type'),text});
  }); return response;
 };
}
if (location.origin === MOODLE_ORIGIN) {
 window.vqBridge=null;window.vqUpdates=0;
 window.addEventListener('message',event=>{
  if(event.origin===VIDEOQ_ORIGIN&&event.data?.type==='videoq:ready') window.vqBridge={...event.data,origin:event.origin};
  if(event.origin===VIDEOQ_ORIGIN&&event.data?.event==='answer-update') window.vqUpdates++;
 });
}
'''
def origin(url):
    parsed = urlsplit(url)
    return parsed.scheme + '://' + parsed.netloc

CAPTURE = CAPTURE.replace('VIDEOQ_ORIGIN', json.dumps(origin(VIDEOQ))).replace('MOODLE_ORIGIN', json.dumps(origin(MOODLE)))

@contextmanager
def browser(init_script=''):
    options = Options()
    options.page_load_strategy = 'eager'
    for flag in ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--no-proxy-server', '--window-size=1440,1100']:
        options.add_argument(flag)
    options.set_capability('acceptInsecureCerts', all(urlsplit(url).hostname.endswith('.localhost') for url in (MOODLE, VIDEOQ)))
    if os.environ.get('CHROME_BINARY'):
        options.binary_location = os.environ['CHROME_BINARY']
    service = Service(executable_path=os.environ['CHROMEDRIVER']) if os.environ.get('CHROMEDRIVER') else Service()
    # Selenium Manager updates one shared metadata file. Serialize startup while
    # allowing the resulting browser sessions and requests to run concurrently.
    with _DRIVER_START_LOCK:
        driver = webdriver.Chrome(options=options, service=service)
    driver.set_script_timeout(150)
    driver.set_page_load_timeout(45)
    driver.execute_cdp_cmd('Page.addScriptToEvaluateOnNewDocument', {'source': CAPTURE})
    driver.vq_init_script = init_script
    try:
        yield driver
    finally:
        driver.quit()

def wait_js(driver, script, seconds=30, *args):
    return WebDriverWait(driver, seconds).until(lambda current: current.execute_script(script, *args))

def element(driver, selector):
    return WebDriverWait(driver, 30).until(lambda current: next(iter(current.find_elements(By.CSS_SELECTOR, selector)), False))

def fill(driver, selector, value):
    def enter(current):
        try:
            fields = current.find_elements(By.CSS_SELECTOR, selector)
            if not fields or not fields[0].is_displayed() or not fields[0].is_enabled():
                return False
            fields[0].clear()
            fields[0].send_keys(value)
            return True
        except StaleElementReferenceException:
            return False
    WebDriverWait(driver, 30).until(enter)

def click(driver, selector):
    element(driver, selector).click()

def login(driver, username='admin', target=MOODLE):
    if target == MOODLE:
        driver.get(target + '/login/index.php')
        fill(driver, '#username', username)
        element(driver, '.toggle-sensitive-wrapper #password')
        fill(driver, '#password', os.environ.get('LAB_PASSWORD', 'Lti-lab-only-123!'))
        click(driver, '#loginbtn')
    else:
        driver.get(target + '/login')
        fill(driver, '#username', username)
        fill(driver, '#password', os.environ.get('LAB_PASSWORD', 'Lti-lab-only-123!'))
        click(driver, 'form button[type="submit"]')
    WebDriverWait(driver, 30).until(lambda current: '/login' not in current.current_url)

def open_course(driver, course=5):
    driver.get(MOODLE + '/local/videoq/view.php?course=' + str(course) + '&lang=ja')
    wait_js(driver, "return document.querySelector('#videoq-native-controls')?.disabled===false")
    # Install response observers after the real signed launch. Top-level CDP
    # preloads do not reliably cover Chrome's cross-process tool frame.
    in_tool(driver, lambda current: current.execute_script(CAPTURE + driver.vq_init_script))

def in_tool(driver, callback):
    driver.switch_to.frame(element(driver, '#videoq-questions'))
    try:
        return callback(driver)
    finally:
        driver.switch_to.default_content()

def fetch(driver, url, body=None, headers=None):
    init = {'method': 'POST' if body is not None else 'GET', 'headers': headers or {}}
    if body is not None:
        init['headers'] = {'Content-Type': 'application/json', **init['headers']}
        init['body'] = json.dumps(body)
    return driver.execute_async_script('''
      const done=arguments[arguments.length-1];
      fetch(arguments[0],arguments[1]).then(async response=>done({status:response.status,text:await response.text()})).catch(()=>done({status:0}));
    ''', url, init)

def stream_response(driver):
    return in_tool(driver, lambda current: wait_js(current, "return window.vqEvidence.findLast(event=>event.path.endsWith('/messages/stream'))", 150))
