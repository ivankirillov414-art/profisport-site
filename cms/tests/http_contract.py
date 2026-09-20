"""End-to-end API contracts against a disposable local test installation."""
import json, urllib.request, urllib.error, time, http.cookiejar, os
assert os.environ.get('CMS_TEST') == '1', 'Only disposable test environments'
base='http://localhost:8123/cms/api.php?action='
# Local HTTP does not send Secure cookies automatically. A test-only client sends
# the returned cookie explicitly; production retains Secure cookies over HTTPS.
cookie=''; csrf=''
def call(action, body=None, token=True, auth=True):
    global cookie
    headers={}
    if auth and cookie: headers['Cookie']=cookie
    if token: headers['X-CSRF-Token']=csrf
    if body is not None: headers['Content-Type']='application/json'
    req=urllib.request.Request(base+action, data=None if body is None else json.dumps(body).encode(),headers=headers)
    try:
        response=urllib.request.urlopen(req,timeout=10)
    except urllib.error.HTTPError as e: response=e
    if auth and response.headers.get('Set-Cookie'):cookie=response.headers['Set-Cookie'].split(';')[0]
    return response.status,json.load(response)
for i in range(30):
    try: code,session=call('session');break
    except OSError:time.sleep(.1)
csrf=session['csrf']
assert call('state',auth=False)[0]==401
assert call('login',{'username':'test-owner','password':'test-only-password-1234'},token=False)[0]==403
code,login=call('login',{'username':'test-owner','password':'test-only-password-1234'});assert code==200,login
csrf=login['csrf']
code,state=call('state');assert code==200,state
version=state['version'];draft=state['draft'];draft['pages']['index.html']['fields']['f1']='HTTP draft only'
assert call('save',{'version':version,'draft':draft},token=False)[0]==403
assert call('save',{'version':version-1,'draft':draft})[0]==409
code,saved=call('save',{'version':version,'draft':draft});assert code==200,saved
code,public=call('public',auth=False);assert 'HTTP draft only' not in json.dumps(public)
assert call('publish',{'version':saved['version']})[0]==200
code,public=call('public',auth=False);assert 'HTTP draft only' in json.dumps(public)
assert 'csrf' not in public and 'draft' not in public
code,created=call('create-site',{'key':'http-site','name':'HTTP Site','url':'https://http.example/'});assert code==200,created
code,second=call('state&site=http-site');assert code==200,second
second['draft']['pages']['about.html']={'title':'About page','fields':{},'blocks':[],'layout':[{'id':'http-block','type':'text','props':{'title':'Isolated published block'}}]}
code,saved2=call('save&site=http-site',{'version':second['version'],'draft':second['draft']});assert code==200,saved2
assert call('public&site=http-site',auth=False)[1]['published'] is False
assert call('publish&site=http-site',{'version':saved2['version']})[0]==200
assert 'Isolated published block' in json.dumps(call('public&site=http-site',auth=False)[1])
assert 'Isolated published block' not in json.dumps(call('public',auth=False)[1])
with urllib.request.urlopen('http://localhost:8123/cms/site.php?site=http-site&page=about.html') as response:
    html=response.read().decode();assert '<title>About page</title>' in html and 'data-cms-site="http-site"' in html
# Upload, list and select media are authenticated and scoped to a site.
assert call('media',auth=False)[0]==401
import base64
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGWsAAAAASUVORK5CYII=')
boundary='IDTESTBOUNDARY'
payload=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="id-test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode())
request=urllib.request.Request(base+'upload&site=http-site',data=payload,headers={'Cookie':cookie,'X-CSRF-Token':csrf,'Content-Type':'multipart/form-data; boundary='+boundary})
with urllib.request.urlopen(request) as response: uploaded=json.load(response)
assert uploaded['url'] in [x['url'] for x in call('media&site=http-site')[1]['items']]
assert uploaded['url'] not in [x['url'] for x in call('media')[1]['items']]
assert call('logout',{})[0]==200
assert call('state')[0]==401
print('CMS HTTP contracts passed: auth, CSRF, conflict, private draft, publish, logout')
