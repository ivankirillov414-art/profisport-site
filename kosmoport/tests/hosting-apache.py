"""Exercise the verified source ZIP under real Apache, without credentials/data."""
import sys
import time
import urllib.error
import urllib.request

base = sys.argv[1]
if base != 'http://127.0.0.1:18080/kosmoport/':
    raise SystemExit('Only the fixed disposable loopback Apache fixture is allowed')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, target):
        return None


client = urllib.request.build_opener(NoRedirect())


def get(relative):
    url = base + relative
    try:
        response = client.open(url, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.geturl(), response.read()


for attempt in range(100):
    try:
        root = get('')
        break
    except urllib.error.URLError:
        time.sleep(0.1)
else:
    raise SystemExit('Disposable Apache did not start')

checks = 0


def check(relative, status, needle=None):
    global checks
    code, final, body = root if relative == '' else get(relative)
    assert code == status, (relative, code, status, body[:200])
    assert final == base + relative, (relative, final)
    if needle is not None:
        assert needle in body, relative
    if status == 403:
        assert b'<?php' not in body and b'CREATE TABLE' not in body
    checks += 1


for relative in ['', 'index.php', 'public/index.php']:
    check(relative, 200, 'КОСМОПОРТ'.encode())
for relative in ['crm/', 'crm/index.php']:
    check(relative, 200, 'Космопорт — CRM'.encode())
check('assets/crm.js', 200, b'login')
check('host-control.json', 200, b'{"synthetic":true}\n')
for relative in ['app/bootstrap.php', 'config/config.example.php', 'storage/.gitkeep',
                 'migrations/001_core.sql', 'tools/package.php', 'docs/QA-RESULTS.md']:
    check(relative, 403, b'Forbidden')
check('site/absent-apache-fixture.txt', 404, b'Not found')
print(f'PASS {checks} actual Apache routes, directory indexes, public control and private denials')
