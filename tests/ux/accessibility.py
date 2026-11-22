from pathlib import Path
import json,argparse
from playwright.sync_api import sync_playwright
out=Path('/workspace/output/agah-ux-accessibility');out.mkdir(exist_ok=True)
parser=argparse.ArgumentParser();parser.add_argument('--axe',required=True,type=Path);args=parser.parse_args()
results=[]
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);page=b.new_page(viewport={'width':1366,'height':900});page.goto('http://127.0.0.1:18085/accounting/');page.fill('#user_login','mobiletest');page.fill('#user_pass','LocalIntegration-2026-Only');page.click('#wp-submit');page.wait_for_load_state('networkidle')
 for theme in ['light','dark']:
  for route in ['dashboard','units','invoice_new&kind=sale','treasury_new&kind=receipt','financial_ratios','moadian_items','members','roles','invoice_delete_access','settings']:
   page.goto('http://127.0.0.1:18085/accounting/?c=1&p='+route,wait_until='networkidle')
   if page.locator('.erp').get_attribute('data-theme-active')!=theme:page.locator('.erp-theme-toggle').click()
   page.wait_for_timeout(250)
   page.evaluate(args.axe.read_text())
   result=page.evaluate("async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});return {violations:r.violations.map(v=>({id:v.id,impact:v.impact,count:v.nodes.length,nodes:v.nodes.slice(0,3).map(n=>({target:n.target,summary:n.failureSummary,html:n.html}))})),incomplete:r.incomplete.map(x=>({id:x.id,count:x.nodes.length})),passes:r.passes.length}}")
   results.append({'route':route,'theme':theme,**result});print(theme,route,[(v['id'],v['count']) for v in result['violations']],flush=True)
 b.close()
(out/'axe-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))

assert all(not r['violations'] for r in results),'Automated accessibility violations remain; inspect axe-results.json'
