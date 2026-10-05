import json, urllib.request, urllib.error, subprocess
BASE='http://127.0.0.1:8080/'
def call(path,data=None,cookie=None,csrf=None):
    headers={'Content-Type':'application/json'}
    if cookie: headers['Cookie']=cookie
    if csrf: headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(BASE+path,data=json.dumps(data).encode() if data is not None else None,headers=headers)
    try: response=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e: response=e
    return response.status,json.loads(response.read()),response.headers
assert call('api/bicycle-fit-admin.php')[0]==401
status,auth,headers=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'})
assert status==200
cookie=next(c.split(';')[0] for c in reversed(headers.get_all('Set-Cookie')) if c.startswith('PROFISPORT_ADMIN='))
csrf=auth['csrf']
status,data,_=call('api/bicycle-fit-admin.php',cookie=cookie);assert status==200
p=next(x for x in data['items'] if x['id']==3)
payload={'id':3,'version':p['version'],'min':150,'max':170,'frame':'M','note':'Test measurement'}
assert call('api/bicycle-fit-admin.php',payload,cookie)[0]==403
assert call('api/bicycle-fit-admin.php',{**payload,'min':180},cookie,csrf)[0]==422
assert call('api/bicycle-fit-admin.php',payload,cookie,csrf)[0]==200
assert call('api/bicycle-fit-admin.php',payload,cookie,csrf)[0]==409
status,data,_=call('api/bicycle-fit-admin.php',cookie=cookie);assert all(x['id']!=3 for x in data['items'])
status,data,_=call('api/bicycle-fit-admin.php?mode=all',cookie=cookie)
p=next(x for x in data['items'] if x['id']==3);assert p['range']=='150–170 см' and p['fit']['frame']=='M'
status,data,_=call('api/catalog.php?id=3');assert status==200
specs={x['name']:x['value'] for x in data['items'][0]['specs']}
assert specs['Рекомендуемый рост']=='150–170 см' and specs['Размер рамы']=='M'
# A new 1C spec snapshot cannot overwrite separate, manually verified data.
subprocess.run(['php','-r',"require 'server/bootstrap.php'; if($config['db_name']!=='profisport_test')exit(1); $pdo->exec(\"UPDATE products SET specs='{\\\"Размер рамы\\\":\\\"L\\\"}' WHERE id=3\");"],check=True)
assert call('api/catalog.php?id=3')[1]['items'][0]['specs'][0]['value']=='150–170 см'
# Reusing a product identity must invalidate the override.
subprocess.run(['php','-r',"require 'server/bootstrap.php'; if($config['db_name']!=='profisport_test')exit(1); $pdo->exec(\"UPDATE products SET sku='new-identity' WHERE id=3\");"],check=True)
specs=call('api/catalog.php?id=3')[1]['items'][0]['specs'];assert specs=={'Размер рамы':'L'}
subprocess.run(['php','-r',"require 'server/bootstrap.php'; if($config['db_name']!=='profisport_test')exit(1); $pdo->exec(\"DELETE FROM site_settings WHERE setting_key='bicycle_fit:3'\"); $pdo->exec(\"UPDATE products SET sku=NULL,specs=NULL WHERE id=3\");"],check=True)
print('PASS: fit task queue, authentication, CSRF, bounds, stale writes, catalog overlay, import preservation and identity guard')
