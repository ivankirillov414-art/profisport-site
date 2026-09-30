"""Extract the release and configure a strictly local disposable install test."""
import os
from pathlib import Path
import sys
import zipfile

assert os.environ.get('CMS_TEST') == '1', 'Disposable test mode required'
archive = Path(sys.argv[1]).resolve()
root = Path(sys.argv[2]).resolve()
allowed = Path(os.environ.get('RUNNER_TEMP') or (Path(os.environ['LOCALAPPDATA']) / 'id-studio-test-runtime')).resolve()
assert root.is_relative_to(allowed) and root != allowed, 'Fixture must stay in the test runtime directory'
assert not root.exists(), 'Use a fresh fixture directory'
port = int(os.environ.get('CMS_TEST_DB_PORT', '33307'))
assert port in (3306, 33307), 'Unexpected test database port'
database = os.environ.get('CMS_TEST_DB_NAME', 'idstudio_portable_release_test')
assert database.startswith('idstudio_portable_') and database.endswith('_test') and all(c.isalnum() or c == '_' for c in database)
root.mkdir(parents=True)
with zipfile.ZipFile(archive) as packed:
    assert all((root / name).resolve().is_relative_to(root) for name in packed.namelist())
    assert 'private/config.php' not in packed.namelist(), 'Release must not contain credentials'
    packed.extractall(root)
(root / 'private/config.php').write_text(
    "<?php return ['db_host'=>'127.0.0.1','db_port'=>" + str(port) + ","
    "'db_name'=>'" + database + "','db_user'=>'root','db_pass'=>'cms-test-password',"
    "'site_key'=>'portable-site','site_name'=>'Portable ID Studio','site_url'=>'https://portable.example/',"
    "'media_url'=>'/media/','install_token'=>'portable-test-install-token-1234567890'];", encoding='utf-8')
print('Disposable portable fixture created:', root)
