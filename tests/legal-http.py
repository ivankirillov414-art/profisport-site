import json, urllib.request, urllib.error
BASE='http://127.0.0.1:8080/'
def call(path,data=None,cookie=None,csrf=None):
    headers={'Content-Type':'application/json'}
    if cookie: headers['Cookie']=cookie
    if csrf: headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(BASE+path,data=json.dumps(data).encode() if data is not None else None,headers=headers)
    try: response=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e: response=e
    return response.status,json.loads(response.read()),response.headers
assert call('api/legal-admin.php')[0]==401
assert call('api/legal-public.php')[1]['documents']==[]
status,auth,headers=call('server/api.php?action=login',{'username':'Иван Кириллов 414','password':'test-only-password'})
assert status==200
cookie=next(c.split(';')[0] for c in reversed(headers.get_all('Set-Cookie')) if c.startswith('PROFISPORT_ADMIN='))
csrf=auth['csrf']
status,data,_=call('api/legal-admin.php',cookie=cookie);assert status==200
state=data['state'];payload={'action':'save','revision':state['revision'],'slug':'reviews','title':'Проверка отзывов','body':'Текст <script>alert(1)</script> остаётся текстом и не выполняется в браузере.'}
assert call('api/legal-admin.php',payload,cookie)[0]==403
assert call('api/legal-admin.php',payload,cookie,csrf)[0]==200
assert call('api/legal-public.php')[1]['documents']==[]
assert call('api/legal-admin.php',payload,cookie,csrf)[0]==409
payload.update(action='publish',revision=1)
assert call('api/legal-admin.php',payload,cookie,csrf)[0]==422
assert call('server/api.php?action=settings_save',{'settings':{'legal_documents_v1':'{}'}},cookie,csrf)[0]==403
seller={k:'Уточнено' for k in data['fields']};seller.update(seller_type='ООО',inn='1234567890',ogrn='1234567890123',email='legal@example.test')
assert call('api/legal-admin.php',{'action':'seller','revision':1,'seller':seller},cookie,csrf)[0]==200
payload['revision']=2
assert call('api/legal-admin.php',payload,cookie,csrf)[0]==200
public=call('api/legal-public.php')[1]['documents'];assert len(public)==1 and 'by' not in public[0]
html=urllib.request.urlopen(BASE+'legal.php?document=reviews').read().decode()
assert '&lt;script&gt;' in html and '<script>alert(1)</script>' not in html
assert call('api/legal-admin.php',{'action':'unpublish','revision':3,'slug':'reviews'},cookie,csrf)[0]==200
try: urllib.request.urlopen(BASE+'legal.php?document=reviews');raise AssertionError('draft publicly accessible')
except urllib.error.HTTPError as e: assert e.code==404
assert call('api/legal-public.php')[1]['documents']==[]
print('PASS: legal API authentication, CSRF, validation, stale writes, generic settings protection, public filtering and HTML escaping')
