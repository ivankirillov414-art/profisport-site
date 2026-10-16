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
