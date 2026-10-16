"""Raw HTML tests: no JavaScript renderer or external search accounts needed."""
import urllib.request, urllib.error, json, re
base='http://127.0.0.1:8080/'
def get(path):
    try:
        with urllib.request.urlopen(base+path) as r:return r.status, r.read().decode(),r.headers
    except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
status,body,_=get('catalog.php')
assert status==200 and 'product.html?id=1' in body and 'product.html?id=2' not in body
status,body,_=get('server/seo-static.php')
assert status==404
status,body,_=get('server/seo-product.php?id=1')
assert status==200 and 'Test ball' in body and '<h1>Test ball</h1>' in body
schema=json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>',body).group(1))
assert schema['name']=='Test ball' and 'offers' not in schema # Domain deliberately not configured.
assert 'noindex, follow' in body
status,body,headers=get('server/seo-product.php?id=999999999')
assert status==404 and headers.get('X-Robots-Tag')=='noindex'
status,body,_=get('server/seo-product.php?id=1%20OR%201=1')
assert status==404
status,body,_=get('catalog.php?page=999999999')
assert status==404
status,body,_=get('robots.php')
assert status==200 and 'Disallow: /\n' in body and 'Sitemap:' not in body
status,body,_=get('sitemap.php')
assert status==503
print('Raw HTML product/catalog, errors and prelaunch robots checks passed.')

# Activate only a reserved test origin in the disposable CI database environment.
from pathlib import Path
import xml.etree.ElementTree as ET
config=Path('server/config.php')
original=config.read_text()
try:
    configured,n=re.subn(r'(return\s*\[)',r"\1'seo_public_url'=>'https://example.test','seo_indexing'=>true,",original,count=1)
    assert n==1
    config.write_text(configured)
    status,body,_=get('server/seo-product.php?id=1&utm_source=test')
    assert status==200 and 'index, follow, max-image-preview:large' in body, (status, body[:500])
    assert 'href="https://example.test/product.html?id=1"' in body
    schema=json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>',body).group(1))
    _,api,_=get('api/catalog.php?id=1')
    actual=json.loads(api)['items'][0]
    assert schema['offers']['price']==str(actual['price_rub']) and schema['offers']['priceCurrency']=='RUB'
    status,body,_=get('catalog.php?category=Sport%20%2F%20Balls&utm_source=test')
    assert status==200 and 'https://example.test/catalog.php?category=Sport%20%2F%20Balls' in body
    status,body,_=get('sitemap.php')
    assert status==200
    urls=[e.text for e in ET.fromstring(body).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
    assert 'https://example.test/product.html?id=1' in urls
    assert 'https://example.test/product.html?id=2' not in urls
    assert all(u.startswith('https://example.test/') and 'profile.html' not in u and 'checkout.html' not in u for u in urls)
    status,body,_=get('robots.php')
    assert status==200 and 'Sitemap: https://example.test/sitemap.xml' in body and 'Disallow: /\n' not in body
finally:
    config.write_text(original)
print('Production-origin canonical, public price parity, sitemap XML and robots checks passed.')
