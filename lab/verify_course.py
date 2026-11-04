"""Two-video citation switching in the real course UI, no paid answer request."""
import json
from selenium.webdriver.support.ui import Select
from videoq_lab.browser import browser, login, open_course, element, in_tool, origin
from videoq_lab.runtime import VIDEOQ, save_report
from verify_chat import FIXTURE, ask, ready, wait_js

def check_course(video_id, source_cmid):
    fixture = FIXTURE.replace('VIDEOQ_ORIGIN',json.dumps(origin(VIDEOQ))).replace('VIDEO_ID',str(video_id))
    with browser(fixture) as driver:
        login(driver)
        open_course(driver)
        catalog = driver.execute_script("return JSON.parse(document.querySelector('.local-videoq-workspace').dataset.videoqLessons)")
        target = next(item for item in catalog if item['lesson'] == 'sync_'+str(source_cmid))
        original = next(item for item in catalog if item['lesson'] != target['lesson'])
        assert len(catalog) >= 2
        Select(element(driver,'#videoq-lesson')).select_by_value(original['lesson'])
        wait_js(driver,"return document.querySelector('video').currentSrc===arguments[0]",30,original['url'])
        before = in_tool(driver,lambda current:current.execute_script('return performance.timeOrigin'))
        driver.execute_script("document.querySelectorAll('.answer').forEach(element=>element.dataset.existing='true')")
        ask(driver,'別の動画の場面を確認します')
        wait_js(driver,"return document.querySelector('.answer:not([data-existing])')")
        in_tool(driver,lambda current:current.execute_script('window.finishStream()'))
        ready(driver)
        element(driver,'.answer:not([data-existing]) button.citation').click()
        wait_js(driver,"return document.querySelector('video').currentSrc===arguments[0]&&Math.abs(document.querySelector('video').currentTime-8)<2",30,target['url'])
        assert Select(element(driver,'#videoq-lesson')).first_selected_option.get_attribute('value') == target['lesson']
        Select(element(driver,'#videoq-lesson')).select_by_value(original['lesson'])
        wait_js(driver,"return document.querySelector('video').currentSrc===arguments[0]",30,original['url'])
        assert in_tool(driver,lambda current:current.execute_script('return performance.timeOrigin')) == before
        assert len(driver.find_elements('css selector','.answer:not([data-existing])')) == 1
        driver.execute_script("document.querySelector('video').pause()")
        save_report('course-switch',dict(passed=True,nativeMoodlePlayer=True,videoCount=len(catalog),
                    citationChangesVideoAndPosition=True,selectorChangesBack=True,conversationPreserved=True,toolFrameNotReloaded=True))
