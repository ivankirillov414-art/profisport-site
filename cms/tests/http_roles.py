"""Authorization over real HTTP, after integration.php and http_contract.py."""
import json, os, urllib.request, urllib.error
assert os.environ.get('CMS_TEST') == '1', 'Disposable test environment required'
BASE='http://localhost:8123/cms/api.php?action='
PASSWORD='role-test-password-1234'

class Client:
    def __init__(self):
        self.cookie=''; self.csrf=''
        self.csrf=self.call('session')[1]['csrf']
    def call(self, action, body=None, csrf=True):
        headers={'Cookie':self.cookie}
        if csrf: headers['X-CSRF-Token']=self.csrf
        if body is not None: headers['Content-Type']='application/json'
        request=urllib.request.Request(BASE+action, data=None if body is None else json.dumps(body).encode(), headers=headers)
        try: response=urllib.request.urlopen(request,timeout=10)
        except urllib.error.HTTPError as error: response=error
        if response.headers.get('Set-Cookie'): self.cookie=response.headers['Set-Cookie'].split(';')[0]
        return response.status,json.load(response)
    def login(self, username, password=PASSWORD):
        code,result=self.call('login',{'username':username,'password':password})
        if code==200: self.csrf=result['csrf']
        return code,result

owner=Client();assert owner.login('test-owner','test-only-password-1234')[0]==200
assert owner.call('state')[1]['role']=='owner'
members={};ids={}
for role in ('viewer','editor','publisher'):
    payload={'username':'test-'+role,'password':PASSWORD,'memberships':[{'site_key':'http-site','role':role}]}
    assert owner.call('create-user',payload,csrf=False)[0]==403
    code,result=owner.call('create-user',payload);assert code==200,result
    ids[role]=result['id'];client=Client();assert client.login('test-'+role)[0]==200
    members[role]=client
    sites=client.call('sites')[1]['items'];assert [s['site_key'] for s in sites]==['http-site']
    state=client.call('state')[1];assert state['site']['key']=='http-site' and state['role']==role
    for action in ('state','history','media'):
        assert client.call(action+'&site=profisport')[0]==403,(role,action)
    for action in ('save','publish','restore','upload'):
        assert client.call(action+'&site=profisport',{})[0]==403,(role,action)
    for action in ('create-user','update-user','create-site'):
        assert client.call(action,{**payload,'id':ids[role],'role':'owner'})[0]==403
    assert client.call('users')[0]==403

viewer=members['viewer'];editor=members['editor'];publisher=members['publisher']
for action in ('save','publish','restore','upload'):
    assert viewer.call(action,{})[0]==403,action
assert editor.call('publish',{})[0]==403
state=editor.call('state')[1];state['draft']['pages']['index.html']['layout']=[{'id':'role-block','type':'text','props':{'title':'Editor draft'}}]
code,saved=editor.call('save',{'version':state['version'],'draft':state['draft']});assert code==200,saved
assert 'Editor draft' not in json.dumps(owner.call('public&site=http-site')[1])
code,published=publisher.call('publish',{'version':saved['version']});assert code==200,published
assert 'Editor draft' in json.dumps(owner.call('public&site=http-site')[1])
foreign=owner.call('history&site=profisport')[1]['items'][0]['id']
assert editor.call('restore',{'version':published['version'],'id':foreign})[0]==422
history=editor.call('history')[1]['items'];assert editor.call('restore',{'version':published['version'],'id':history[-1]['id']})[0]==200

# No privilege escalation through owner management or invalid grants.
assert owner.call('update-user',{'id':1,'active':False,'memberships':[]})[0]==422
for grants in ([{'site_key':'http-site','role':'owner'}],[{'site_key':'missing','role':'viewer'}],[{'site_key':'http-site','role':'viewer'}]*2):
    assert owner.call('create-user',{'username':'bad-role','password':PASSWORD,'memberships':grants})[0]==422
assert owner.call('create-user',{'username':'test-editor','password':PASSWORD,'memberships':[]})[0]==422

# Every access change invalidates existing sessions; role is read from the DB.
assert owner.call('update-user',{'id':ids['publisher'],'active':True,'memberships':[{'site_key':'http-site','role':'viewer'}]})[0]==200
assert publisher.call('state')[0]==401
assert publisher.login('test-publisher')[0]==200
assert publisher.call('publish',{})[0]==403
assert owner.call('update-user',{'id':ids['viewer'],'active':False,'memberships':[]})[0]==200
assert viewer.call('state')[0]==401 and viewer.login('test-viewer')[0]==401

# A user with no sites can still log out and manage their password.
assert owner.call('update-user',{'id':ids['viewer'],'active':True,'memberships':[]})[0]==200
assert viewer.login('test-viewer')[0]==200
assert viewer.call('sites')[1]['items']==[] and viewer.call('state')[0]==403
assert viewer.call('logout',{})[0]==200

second=Client();assert second.login('test-editor')[0]==200
assert editor.call('change-password',{'current_password':'wrong','password':PASSWORD+'-new'})[0]==422
assert editor.call('change-password',{'current_password':PASSWORD,'password':PASSWORD+'-new'})[0]==200
assert editor.call('state')[0]==200 and second.call('state')[0]==401
assert second.login('test-editor')[0]==401 and second.login('test-editor',PASSWORD+'-new')[0]==200
listing=owner.call('users')[1];assert 'password' not in json.dumps(listing)
print('CMS role HTTP checks passed: isolation, deny-by-default, edit/publish split, CSRF, owner protection, revocation and password rotation')
