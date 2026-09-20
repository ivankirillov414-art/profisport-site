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
assert call('logout',{})[0]==200
assert call('state')[0]==401
print('CMS HTTP contracts passed: auth, CSRF, conflict, private draft, publish, logout')
