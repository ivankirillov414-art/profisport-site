"""Build a standalone CMS or static demo using only Python's standard library."""
from pathlib import Path
import argparse
import json
import shutil
import zipfile
import hashlib
from html.parser import HTMLParser

SOURCE = Path(__file__).resolve().parents[1]

class Dependencies(HTMLParser):
    def __init__(self):
        super().__init__()
        self.paths = set()
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        value = attrs.get('src') if tag == 'script' else attrs.get('href') if tag == 'link' and attrs.get('rel') == 'stylesheet' else None
        if value and not value.startswith(('https:', 'http:', '//', 'data:')):
            self.paths.add(value)

def dependencies(html):
    parser = Dependencies()
    parser.feed(html)
    return parser.paths

def build(destination, demo=False):
    destination = Path(destination).resolve()
    archive = destination.with_name(destination.name + '.zip')
    if destination.exists() or archive.exists():
        raise ValueError('Choose a fresh output directory and archive name')
    if destination == SOURCE or SOURCE in destination.parents:
        raise ValueError('Output must be outside the CMS source directory')
    destination.mkdir(parents=True)
    if demo:
        shutil.copy2(SOURCE / 'demo.html', destination / 'index.html')
        files = dependencies((SOURCE / 'demo.html').read_text(encoding='utf-8')) | {'demo-state.json', 'research.html', 'research.css', '.htaccess'}
        for name in sorted(files):
            source = (SOURCE / name).resolve()
            if SOURCE not in source.parents or not source.is_file():
                raise ValueError('Missing or unsafe demo dependency: ' + name)
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
        # Runtime loads fonts/assets from the vendor distribution itself.
        shutil.copytree(SOURCE / 'vendor', destination / 'vendor', dirs_exist_ok=True)
    else:
        for source in sorted(SOURCE.rglob('*')):
            if not source.is_file():
                continue
            relative = source.relative_to(SOURCE)
            if any(part in ('tests', 'tools', 'node_modules', '__pycache__') for part in relative.parts):
                continue
            if relative.parts[0].startswith('demo') or relative.as_posix() in ('private/config.php', 'private/bindings.json', 'private/templates.json'):
                continue
            if relative.parts[0] == 'media' and relative.as_posix() != 'media/.htaccess':
                continue
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
        (destination / 'private/bindings.json').write_text(json.dumps({'site': 'generic', 'pages': {}}), encoding='utf-8')
    for name in dependencies((destination / 'index.html').read_text(encoding='utf-8')):
        if not (destination / name).is_file():
            raise ValueError('Release is missing an editor dependency: ' + name)
    manifest = {path.relative_to(destination).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
                for path in sorted(destination.rglob('*')) if path.is_file()}
    (destination / 'release-manifest.json').write_text(json.dumps({'format': 1, 'files': manifest}, indent=2), encoding='utf-8')
    with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED) as output:
        for path in sorted(destination.rglob('*')):
            if path.is_file():
                output.write(path, path.relative_to(destination).as_posix())
    archive.with_suffix('.zip.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n', encoding='utf-8')
    return archive

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('destination')
    parser.add_argument('--demo', action='store_true')
    args = parser.parse_args()
    print(build(args.destination, args.demo))
