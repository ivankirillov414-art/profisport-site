"""Read-only comparison of owned deployable core sources against a source ZIP."""
import argparse, hashlib, json, pathlib, zipfile

root = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('archive')
args = parser.parse_args()
files = [root/'app/bootstrap.php', *root.glob('app/core/*.php'), root/'public/api/index.php', root/'public/setup.php', root/'config/config.example.php', *root.glob('migrations/001*.sql'), *root.glob('migrations/010*.sql'), *root.glob('bin/core-*.php')]
with zipfile.ZipFile(args.archive) as archive:
    names = set(archive.namelist())
    missing, changed, hashes = [], [], {}
    for path in files:
        name = path.relative_to(root).as_posix()
        hashes[name] = hashlib.sha256(path.read_bytes()).hexdigest()
        candidates = [member for member in names if member == name or member.endswith('/'+name)]
        if len(candidates) != 1: missing.append(name)
        elif hashlib.sha256(archive.read(candidates[0])).hexdigest() != hashes[name]: changed.append(name)
print(json.dumps({'ok':not missing and not changed,'owned_sources':len(files),'missing':missing,'changed':changed,'source_sha256':hashes},ensure_ascii=False))
raise SystemExit(1 if missing or changed else 0)
