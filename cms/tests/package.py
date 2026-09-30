"""Portable packaging invariants; no database or third-party dependencies."""
import importlib.util
import json
from pathlib import Path
import tempfile
import zipfile
import hashlib

spec = importlib.util.spec_from_file_location('cms_package', Path(__file__).resolve().parents[1] / 'tools/package.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix='id-studio-package-') as temporary:
    for demo in (False, True):
        destination = Path(temporary) / ('demo' if demo else 'portable')
        archive = module.build(destination, demo)
        with zipfile.ZipFile(archive) as packed:
            files = set(packed.namelist())
            manifest = json.loads(packed.read('release-manifest.json'))['files']
            assert set(manifest) == files - {'release-manifest.json'}
            assert all(hashlib.sha256(packed.read(name)).hexdigest() == digest for name, digest in manifest.items())
            assert 'index.html' in files and 'access.js' in files and 'components-ui.js' in files
            assert 'private/config.php' not in files
            assert not any('/node_modules/' in file or file.startswith(('tools/', 'tests/')) for file in files)
            if demo:
                assert not any(file.endswith('.php') for file in files)
                assert 'demo-state.json' in files
            else:
                assert json.loads(packed.read('private/bindings.json')) == {'site': 'generic', 'pages': {}}
                assert 'private/templates.json' not in files and 'demo-state.json' not in files
                assert not any(f.startswith(('sites/', 'private/connectors/')) for f in files)
                assert {f for f in files if f.startswith('media/')} == {'media/.htaccess'}
                assert {'seo-ui.js', 'collection-lists-ui.js', 'sitemap.php', 'install.php', 'vendor/LICENSE'} <= files
        try:
            module.build(destination, demo)
            raise AssertionError('Existing output was overwritten')
        except ValueError:
            pass
print('Portable/demo package checks passed: dependencies, generic bindings, no config/uploads and no overwrites')
