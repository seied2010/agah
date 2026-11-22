"""Real Chromium + isolated WordPress; no production host or real customer data."""
from pathlib import Path
import json, sys, uuid
from urllib.parse import urlparse, parse_qs
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'mobile-backend/tests'))
from integration import cli
from playwright.sync_api import sync_playwright, expect

fixture = json.loads(cli('ux-workspace-fixture.php'))
base = 'http://127.0.0.1:18085/accounting/'
cid = fixture['company']
tag = uuid.uuid4().hex[:8]
out = Path('/workspace/output/agah-ux-workspace'); out.mkdir(parents=True, exist_ok=True)
passed = []
def check(name, ok=True):
    assert ok, name
    passed.append(name); print('PASS', name, flush=True)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    context = browser.new_context(viewport={'width':1366,'height':768})
    context.add_init_script("window.__returnMetaRaw = sessionStorage.getItem('agah.workspace-return.v1');")
    page = context.new_page(); errors=[]; page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(base);page.fill('#user_login','mobiletest');page.fill('#user_pass','LocalIntegration-2026-Only');page.click('#wp-submit');page.wait_for_load_state('networkidle')
    url=base+f'?c={cid}&p=units&q=UXFIX&pg=2&sort=code&dir=desc'
    def open_units():
        page.goto(url,wait_until='networkidle')
        return page.locator('form').filter(has=page.locator('[name="act"][value="module_catalog_save"]'))
    form=open_units()
    check('year selector beside company controls',page.locator('.erp-top-actions .erp-year-compact select[name=jy]').count()==1 and page.locator('.erp-workspace-content .erp-year-switch').count()==0)
    form.locator('[name=code]').fill('UXFIX'+tag);form.locator('[name=erp_name]').fill('آزمون حفظ موقعیت '+tag)
    form.locator('[name=erp_name]').focus();page.evaluate('window.scrollTo(0,document.documentElement.scrollHeight)')
    before=page.evaluate('scrollY');check('save starts below top',before>150)
    with page.expect_navigation(wait_until='networkidle'):
        form.get_by_role('button',name='ذخیره',exact=True).click()
    page.wait_for_function('scrollY>100')
    check('successful save retains window position',abs(page.evaluate('scrollY')-before)<45)
    query=parse_qs(urlparse(page.url).query)
    check('search sort pagination retained',all(query.get(k)==[v] for k,v in {'q':'UXFIX','pg':'2','sort':'code','dir':'desc'}.items()))
    check('keyboard focus restored without scrolling',page.evaluate('document.activeElement.name')=='erp_name')
    check('success announced',page.locator('[data-work-status]').get_attribute('aria-label')=='عملیات با موفقیت انجام شد.' and page.locator('[data-work-status]').evaluate('(e)=>{const r=e.getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight}'))
    check('return metadata consumed once',page.evaluate("sessionStorage.getItem('agah.workspace-return.v1')===null"))
    stored=page.evaluate('window.__returnMetaRaw')
    check('no form values or nonce in stored metadata',stored and tag not in stored and '_idem' not in stored and '_n"' not in stored and 'آزمون حفظ' not in stored)
    check('new idempotency token after reload',page.locator('[name=_idem]').count()>0)

    # Server-side validation and inline, associated field errors.
    form=open_units();form.locator('[name=code]').fill('UXFIX000');form.locator('[name=erp_name]').fill('نام حفظ‌شده '+tag)
    form.get_by_role('button',name='ذخیره',exact=True).click()
    expect(page.locator('#agah-form-errors')).to_be_visible()
    check('server validation retains entered data',form.locator('[name=erp_name]').input_value()=='نام حفظ‌شده '+tag)
    field=form.locator('[name=code]');expect(field).to_have_attribute('aria-invalid','true')
    check('inline error associated with field',bool(field.get_attribute('aria-describedby')) and form.locator('[data-work-error]').count()==1)
    page.get_by_role('button',name='اصلاح اطلاعات',exact=True).click();expect(field).to_be_focused()
    check('validation dismissal focuses invalid field')
    check('failed save unlocks form',not form.evaluate('(e)=>e.inert') and not form.get_by_role('button',name='ذخیره',exact=True).is_disabled())

    # A real leave attempt must warn, and Cancel must retain data and the page.
    dialogs=[]
    def cancel_leave(dialog): dialogs.append(dialog.type);dialog.dismiss()
    page.on('dialog',cancel_leave)
    page.get_by_role('link',name='تغییر شرکت',exact=True).click()
    check('unsaved changes prevent accidental exit',dialogs==['beforeunload'] and page.locator('[name=code]').input_value()=='UXFIX000')
    page.remove_listener('dialog',cancel_leave)
    def accept(dialog): dialog.accept()
    page.on('dialog',accept);page.reload(wait_until='networkidle');page.remove_listener('dialog',accept)

    # Respect the existing cancellation of a destructive action: no HTTP write.
    posts=[]
    def watch(request):
        if request.method=='POST' and request.url.startswith(base): posts.append(request)
    page.on('request',watch);page.on('dialog',cancel_leave)
    page.get_by_role('button',name='حذف بدون سابقه').first.click()
    page.wait_for_timeout(150)
    check('cancelled deletion sends no request',not posts)
    page.remove_listener('dialog',cancel_leave);page.remove_listener('request',watch)

    # A failed connection is not a success and never clears the draft.
    form=page.locator('form').filter(has=page.locator('[name=act][value=module_catalog_save]'))
    form.locator('[name=code]').fill('UXFAIL'+tag);form.locator('[name=erp_name]').fill('داده در خطای شبکه '+tag)
    def abort_post(route):
        if route.request.method=='POST':route.abort('connectionfailed')
        else:route.continue_()
    page.route('**/accounting/**',abort_post)
    form.get_by_role('button',name='ذخیره',exact=True).click();expect(page.locator('#agah-form-errors')).to_be_visible()
    check('network error retains data and enables retry',form.locator('[name=erp_name]').input_value()=='داده در خطای شبکه '+tag and not form.evaluate('(e)=>e.inert'))
    check('uncertain outcome warning is explicit','پیش از ارسال دوباره' in page.locator('#agah-form-errors').inner_text())
    page.unroute('**/accounting/**',abort_post);page.get_by_role('button',name='اصلاح اطلاعات',exact=True).click()
    page.on('dialog',accept);page.reload(wait_until='networkidle');page.remove_listener('dialog',accept)

    # A forged/old workspace position must not spill into another user/company/year.
    page.evaluate("sessionStorage.setItem('agah.workspace-return.v1',JSON.stringify({scope:'999:1:1',created:Date.now(),target:JSON.stringify([location.pathname,'1','units','','','']),state:{x:0,y:99999,areas:[]}}))")
    page.goto(base+f'?c={cid}&p=units',wait_until='networkidle')
    check('different user scope is ignored',page.evaluate('scrollY')<100 and page.locator('[data-work-status]').count()==0)

    # Storage denial must not prevent saving; data refresh still happens.
    form=open_units();page.evaluate("()=>{Storage.prototype.setItem=function(){throw new DOMException('blocked','SecurityError')}}")
    form.locator('[name=code]').fill('UXFIXS'+tag);form.locator('[name=erp_name]').fill('مرورگر بدون ذخیره موقعیت '+tag)
    with page.expect_navigation(wait_until='networkidle'):form.get_by_role('button',name='ذخیره',exact=True).click()
    check('saving works with browser storage blocked','pg=2' in page.url)

    routes=['dashboard','units','items','parties','accounts','vouchers','invoice_new&kind=sale','treasury_new&kind=receipt','treasury_new&kind=payment','financial_ratios','moadian_items','members','roles','invoice_delete_access','settings']
    for width in [320,390,768,1024,1366,1920]:
        page.set_viewport_size({'width':width,'height':900})
        for work in routes:
            page.goto(base+f'?c={cid}&p='+work,wait_until='networkidle')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(width,work,'document overflow')
            assert page.locator('.erp-brand').evaluate('(el)=>el.scrollWidth<=el.clientWidth+1'),(width,work,'header overflow')
            assert page.locator('.erp').count()==1,(width,work,'missing workspace')
            assert 'خطای داخلی رخ داد' not in page.locator('.erp').inner_text(),(width,work,'render exception')
            clipped=page.locator('.erp-workspace-content').evaluate('''root=>[...root.querySelectorAll('input:not([type=hidden]),select,textarea,button,.erp-scroll')].filter(el=>{
                const r=el.getBoundingClientRect();if(!r.width||!r.height||el.closest('[hidden]'))return false;
                if(el.closest('.erp-scroll')&&!el.matches('.erp-scroll'))return false;
                return r.left < -1 || r.right > innerWidth+1;
            }).map(el=>({tag:el.tagName,name:el.name,cls:el.className}))''')
            assert not clipped,(width,work,'clipped controls',clipped)
        check(f'workspace routes fit viewport {width}px')
    # Horizontal table position is part of the work context too (RTL uses negative scrollLeft).
    page.set_viewport_size({'width':390,'height':844});form=open_units()
    area=page.locator('.erp-scroll').first;area.evaluate('(el)=>el.scrollLeft=-100');left=area.evaluate('(el)=>el.scrollLeft')
    form.locator('[name=code]').fill('UXFIXH'+tag);form.locator('[name=erp_name]').fill('حفظ ستون جدول '+tag)
    with page.expect_navigation(wait_until='networkidle'):form.get_by_role('button',name='ذخیره',exact=True).click()
    page.wait_for_function('Math.abs(document.querySelector(".erp-scroll").scrollLeft)>50')
    check('RTL table horizontal position retained',abs(page.locator('.erp-scroll').first.evaluate('(el)=>el.scrollLeft')-left)<2)

    page.goto(base+f'?c={cid}&p=treasury_new&kind=receipt',wait_until='networkidle');nav=page.locator('.erp-navigation');button=page.locator('.erp-mobile-nav')
    check('closed mobile drawer cannot take keyboard focus',nav.evaluate('(el)=>el.inert'))
    button.click();expect(button).to_have_attribute('aria-expanded','true');check('opening drawer moves focus into menu',nav.evaluate('(el)=>el.contains(document.activeElement)') and page.locator('.erp-workspace-content').evaluate('(el)=>el.inert'))
    button.focus();page.keyboard.press('Shift+Tab');check('mobile drawer traps reverse Tab',nav.evaluate('(el)=>el.contains(document.activeElement)'))
    page.keyboard.press('Escape');expect(button).to_be_focused();check('Escape closes drawer and restores focus',not page.locator('.erp-workspace-content').evaluate('(el)=>el.inert') and nav.evaluate('(el)=>el.inert'))
    button.click();page.set_viewport_size({'width':1366,'height':900});expect(nav).to_have_attribute('aria-hidden','false');check('desktop resize removes drawer modality',not nav.evaluate('(el)=>el.inert') and not page.locator('.erp-workspace-content').evaluate('(el)=>el.inert'))
    calc=page.locator('.erp-calc-open');check('calculator defaults to toolbar instead of covering fields',page.locator('.erp-brand-actions .erp-calc-open').count()==1 and calc.evaluate('(el)=>getComputedStyle(el).position')=='static')
    calc.focus();page.keyboard.press('ArrowDown');check('calculator remains movable with keyboard',calc.evaluate('(el)=>getComputedStyle(el).position')=='fixed')
    calc.click();page.locator('[data-calc-expr]').fill('2+2');page.locator('[data-calc-expr]').press('Enter');expect(page.locator('[data-calc-out]')).to_have_text('= 4')
    page.get_by_role('button',name='بازگرداندن جایگاه پیش‌فرض').click();check('calculator reset restores toolbar position',calc.evaluate('(el)=>getComputedStyle(el).position')=='static')
    page.keyboard.press('Escape');expect(calc).to_be_focused();check('calculator closes with Escape and restores focus',page.locator('.erp-calc').is_hidden())
    page.set_viewport_size({'width':390,'height':844});page.goto(base+f'?c={cid}&p=treasury_new&kind=receipt',wait_until='networkidle');page.screenshot(path=str(out/'receipt-390.png'),full_page=True)
    page.set_viewport_size({'width':1366,'height':900});page.goto(base+f'?c={cid}&p=invoice_delete_access',wait_until='networkidle');page.screenshot(path=str(out/'permissions-1366.png'),full_page=True)

    # Native POST fallback (no JS) must preserve the same filters too.
    native=browser.new_context(java_script_enabled=False,storage_state=context.storage_state(),viewport={'width':1366,'height':768});np=native.new_page();np.goto(url,wait_until='networkidle')
    nf=np.locator('form').filter(has=np.locator('[name=act][value=module_catalog_save]'));nf.locator('[name=code]').fill('UXFIXN'+tag);nf.locator('[name=erp_name]').fill('آزمون بدون جاوااسکریپت '+tag)
    with np.expect_navigation(wait_until='networkidle'):nf.get_by_role('button',name='ذخیره',exact=True).click()
    query=parse_qs(urlparse(np.url).query);check('native POST fallback retains filters',query.get('q')==['UXFIX'] and query.get('pg')==['2'])
    native.close();check('no JavaScript runtime errors',not errors);browser.close()

(out/'results.json').write_text(json.dumps({'passed':passed,'count':len(passed)},ensure_ascii=False,indent=2)+'\n')
