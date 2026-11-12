"""Exercise the admin link UI with API fixtures; no production catalog writes."""
import json
import re
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
HTML = re.sub(r'<\?php.*?\?>', '', (ROOT / 'admin/product-links.php').read_text(), flags=re.S)
ITEM = dict(id=42, name='Велосипед <Test> & 29', price_rub=15000, stock_qty=2,
            is_active=1, stock_status='in_stock', availability='in_stock')

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=shutil.which('chromium'))
    context = browser.new_context(permissions=['clipboard-read', 'clipboard-write'])
    page = context.new_page()
    calls = []
    state = {'mode': 'normal'}
    def route(r):
        url = r.request.url
        if '/api/product-admin.php?' in url:
            calls.append(url)
            if state['mode'] == 'error':
                r.fulfill(status=500, body='failed')
            elif state['mode'] == 'empty':
                r.fulfill(json={'ok': True, 'items': [], 'pages': 1, 'total': 0})
            else:
                r.fulfill(json={'ok': True, 'items': [ITEM, dict(ITEM, id=43, name='Скрытый товар', is_active=0)], 'pages': 2, 'total': 31})
        elif url.endswith('/admin/product-links.php'):
            r.fulfill(content_type='text/html', body=HTML)
        elif '/admin/product-links.' in url:
            name = url.split('/admin/')[1].split('?')[0]
            r.fulfill(path=str(ROOT / 'admin' / name))
        else:
            r.fulfill(body='')
    page.route('http://localhost:8765/**', route)
    page.goto('http://localhost:8765/admin/product-links.php')
    page.locator('.productLinkCard').first.wait_for()
    assert page.locator('h2').first.inner_text() == ITEM['name']
    assert page.locator('.productLinkWarning').count() == 1
    direct = page.locator('#product-link-42-direct')
    utm = page.locator('#product-link-42-utm')
    assert direct.input_value() == 'http://localhost:8765/product.html?id=42'
    assert utm.input_value() == 'http://localhost:8765/product.html?id=42&utm_source=2gis&utm_medium=referral&utm_campaign=products&utm_content=product_42'
    page.locator('.productLinkRow button').nth(1).click()
    assert page.evaluate('navigator.clipboard.readText()') == utm.input_value()
    assert page.locator('#copyStatus').inner_text() == 'Ссылка скопирована.'
    page.locator('#linkNext').click()
    page.wait_for_function("document.querySelector('#linkPageInfo').textContent.includes('2 из 2')")
    assert 'page=2' in calls[-1]
    page.locator('#linkQuery').fill('STELS 123')
    page.wait_for_function("document.querySelector('#linkPageInfo').textContent.includes('1 из 2')")
    assert 'q=STELS+123' in calls[-1] and 'page=1' in calls[-1]
    # Clipboard denied: the selection-based fallback must still copy.
    page.evaluate("navigator.clipboard.writeText = async () => { throw Error('denied'); }; document.execCommand = command => command === 'copy';")
    page.locator('.productLinkRow button').first.click()
    assert page.locator('#copyStatus').inner_text() == 'Ссылка скопирована.'
    # Total failure keeps the full URL selected for manual copying.
    page.evaluate("document.execCommand = () => false")
    page.locator('.productLinkRow button').first.click()
    assert 'Ссылка выделена' in page.locator('#copyStatus').inner_text()
    assert direct.evaluate('(el) => el.selectionEnd - el.selectionStart') == len(direct.input_value())
    for width in (390, 1280):
        page.set_viewport_size({'width': width, 'height': 900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    state['mode'] = 'empty'
    page.locator('#linkQuery').fill('Несуществующий')
    page.wait_for_function("document.querySelector('#linkStatus').textContent.includes('Товаров не найдено')")
    assert page.locator('.productLinkCard').count() == 0
    state['mode'] = 'error'
    page.locator('#linkSearch button').click()
    page.wait_for_function("document.querySelector('#linkStatus').textContent.includes('Не удалось загрузить')")
    assert page.locator('.productLinkCard').count() == 0
    state['mode'] = 'normal'
    page.locator('#linkSearch button').click()
    page.locator('.productLinkCard').first.wait_for()
    page.set_viewport_size({'width': 390, 'height': 900})
    page.screenshot(path='/tmp/profisport-product-links-mobile.png', full_page=True)
    browser.close()
print('PASS: links, clipboard and fallback, search, pagination, visibility, errors, mobile layout')
