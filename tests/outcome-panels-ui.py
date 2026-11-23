"""Disclosure persistence and outcome filters under repeated live renders."""
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
    page=browser.new_page()
    page.set_content('<section id="history"><div id="alerts"></div></section><div id="detailsContent"></div>')
    page.add_script_tag(path=str(root/'public/panel-state.js'))
    page.add_script_tag(content="const esc=s=>String(s??'').replaceAll('<','&lt;');const time=n=>String(n);const exchangeUI={matches:()=>true,label:x=>x};")
    page.add_script_tag(path=str(root/'public/outcomes.js'))
    page.evaluate("""() => {
      window.rows=['WIN','LOSS',null].map((result,i)=>({alert_id:i+1,strategy_id:'s',exchange:'wallex',cohort:'confirmed',started:100,last_check:200,snapshot:{name:'Fixture',entry_price:100},state:{tp1_result:result,status:result?'STOPPED':'OPEN',targets:[]}}));
      outcomeUI.render(rows);
    }""")
    assert page.locator('#history + #outcomes').count()==1
    page.locator('[data-outcome="1"] > summary').click()
    for _ in range(3):page.evaluate('outcomeUI.render(rows)')
    assert page.locator('[data-outcome="1"]').evaluate('(d)=>d.open')
    page.locator('#outcomePanel > summary').click()
    page.evaluate('outcomeUI.render(rows)')
    assert not page.locator('#outcomePanel').evaluate('(d)=>d.open')
    page.locator('#outcomePanel > summary').click()
    page.select_option('#outcomeResult','LOSS')
    assert page.locator('#outcomeRows > details').count()==1
    assert page.locator('#outcomeRows > details').get_attribute('data-outcome')=='2'
    page.evaluate("document.getElementById('detailsContent').innerHTML=outcomeUI.strategy('s')")
    page.locator('#detailsContent [data-panel] > summary').click()
    page.evaluate("document.getElementById('detailsContent').innerHTML=outcomeUI.strategy('s')")
    assert not page.locator('#detailsContent [data-panel]').evaluate('(d)=>d.open')
    assert '50' in page.locator('#detailsContent .outcome-rate').text_content().translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹','0123456789'))
    browser.close()
print('PASS outcome placement, filters, strategy rates, and disclosure persistence')
