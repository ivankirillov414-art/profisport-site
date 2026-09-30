"""Collection document persistence through the real disposable PHP API."""
import copy
import json
import os
import time
import urllib.error
import urllib.request

assert os.environ.get('CMS_TEST') == '1', 'Only disposable test environments'
base = 'http://localhost:8123/cms/api.php?action='
cookie = ''
csrf = ''

def call(action, body=None, auth=True):
    global cookie
    headers = {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}
    if auth and cookie:
        headers['Cookie'] = cookie
    request = urllib.request.Request(base + action, data=None if body is None else json.dumps(body).encode(), headers=headers)
    try:
        response = urllib.request.urlopen(request, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    if auth and response.headers.get('Set-Cookie'):
        cookie = response.headers['Set-Cookie'].split(';')[0]
    return response.status, json.load(response)

csrf = call('session')[1]['csrf']
code, login = call('login', {'username': 'test-owner', 'password': 'test-only-password-1234'})
assert code == 200, login
csrf = login['csrf']
site = 'collections-' + str(time.time_ns())
code, result = call('create-site', {'key': site, 'name': 'Collections integration', 'url': 'https://example.test/'})
assert code == 200, result
suffix = '&site=' + site
state = call('state' + suffix)[1]
draft = state['draft']
draft['collections'] = [{
    'id': 'news', 'name': 'Private collection name', 'titleField': 'title',
    'fields': [{'key': 'title', 'label': 'Название', 'type': 'string', 'required': True}],
    'entries': [{'id': 'first', 'slug': 'first', 'status': 'draft', 'values': {'title': 'Private record'}}]
}]
code, saved = call('save' + suffix, {'version': state['version'], 'draft': draft})
assert code == 200, saved
assert call('state' + suffix)[1]['draft']['collections'] == draft['collections']
history_id = call('history' + suffix)[1]['items'][0]['id']
assert call('save' + suffix, {'version': state['version'], 'draft': draft})[0] == 409
bad = copy.deepcopy(draft)
bad['collections'][0]['entries'][0]['status'] = 'published'
bad['collections'][0]['entries'][0]['values']['title'] = ''
assert call('save' + suffix, {'version': saved['version'], 'draft': bad})[0] == 422
assert call('state' + suffix)[1]['version'] == saved['version']
code, published = call('publish' + suffix, {'version': saved['version']})
assert code == 200, published
public_before = call('public' + suffix, auth=False)[1]
assert 'Private record' not in json.dumps(public_before)
assert 'Private collection name' not in json.dumps(public_before)
draft['collections'][0]['entries'][0]['values']['title'] = 'New private record'
code, changed = call('save' + suffix, {'version': published['version'], 'draft': draft})
assert code == 200, changed
code, restored = call('restore' + suffix, {'version': changed['version'], 'id': history_id})
assert code == 200, restored
assert restored['draft']['collections'][0]['entries'][0]['values']['title'] == 'Private record'
assert call('public' + suffix, auth=False)[1] == public_before
assert 'Private record' not in json.dumps(call('state')[1]['draft'])
print('Collection HTTP checks passed: persistence, revisions, rejected writes, private records, atomic history and site isolation')
