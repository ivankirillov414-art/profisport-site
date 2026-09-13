"""Read the existing import tree over authenticated FTP; never modify the server.

Only inventory, schema names and public product/photo linkage diagnostics are
printed. Original exports, credentials and arbitrary SQL rows are not published.
"""
import csv
import ftplib
import io
import json
import os
import posixpath
import re
from collections import Counter, defaultdict
from urllib.parse import unquote, urlsplit

ROOT = '/htdocs/import'
IMAGE_EXT = {'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'}
UUID = re.compile(r'[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}', re.I)
MAX_DOWNLOAD = 96 * 1024 * 1024
PARTITION_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_.-'


def emit(label, value):
    print(label + '=' + json.dumps(value, ensure_ascii=False, separators=(',', ':')), flush=True)


def list_pattern(ftp, pattern):
    lines = []
    try:
        ftp.retrlines('LIST ' + pattern, lines.append)
    except ftplib.error_perm as error:
        if str(error).startswith('550'):
            return []
        raise
    found = []
    for line in lines:
        parts = line.split(maxsplit=8)
        if len(parts) != 9 or parts[0][0] not in ('d', '-'):
            continue
        found.append((posixpath.basename(parts[8]), 'dir' if parts[0][0] == 'd' else 'file', int(parts[4])))
    return found


def complete_listing(ftp, directory, prefix='', depth=0):
    if depth > 4:
        raise ValueError('listing_partition_limit')
    result = {}
    for char in PARTITION_ALPHABET:
        pattern = posixpath.join(directory, prefix + char + '*')
        part = list_pattern(ftp, pattern)
        if len(part) >= 4900:
            part = complete_listing(ftp, directory, prefix + char, depth + 1)
        for entry in part:
            if entry[0].startswith(prefix + char):
                result[entry[0]] = entry
    remainder = list_pattern(ftp, posixpath.join(directory, prefix + '[!' + PARTITION_ALPHABET + ']*'))
    if len(remainder) >= 4900:
        raise ValueError('unsupported_large_filename_partition')
    for entry in remainder:
        result[entry[0]] = entry
    emit('SOURCE_LISTING_PARTITION', {'directory': directory, 'prefix': prefix, 'entries': len(result)})
    return list(result.values())


def entries(ftp, directory):
    try:
        listing = list(ftp.mlsd(directory))
        found = [(name, facts.get('type'), int(facts.get('size', '0'))) for name, facts in listing]
    except ftplib.error_perm as error:
        if not str(error).startswith(('500', '501', '502', '504')):
            raise
        found = list_pattern(ftp, directory)
    if len(found) >= 4900:
        initial = {entry[0]: entry for entry in found}
        expanded = {entry[0]: entry for entry in complete_listing(ftp, directory)}
        if not set(initial).difference({'.', '..'}).issubset(expanded):
            raise ValueError('incomplete_partitioned_listing')
        return list(expanded.values())
    return found


def inventory(ftp):
    pending, seen, files = [ROOT], set(), []
    while pending:
        directory = pending.pop()
        if directory in seen:
            continue
        seen.add(directory)
        if len(seen) > 1000:
            raise ValueError('directory_limit')
        for name, kind, size in entries(ftp, directory):
            if name in ('.', '..') or '/' in name or '\\' in name:
                continue
            full = posixpath.join(directory, name)
            if kind == 'dir':
                pending.append(full)
            elif kind == 'file':
                files.append({'path': full[len(ROOT) + 1:], 'size': size})
                if len(files) > 250000:
                    raise ValueError('file_limit')
    return files


def read_export(ftp, entry):
    if entry['size'] > MAX_DOWNLOAD:
        raise ValueError('export_too_large')
    output = io.BytesIO()
    def receive(block):
        if output.tell() + len(block) > MAX_DOWNLOAD:
            raise ValueError('export_too_large')
        output.write(block)
    ftp.retrbinary('RETR ' + posixpath.join(ROOT, entry['path']), receive)
    raw = output.getvalue()
    if raw.startswith((b'\xff\xfe', b'\xfe\xff')):
        return raw.decode('utf-16')
    try:
        return raw.decode('utf-8-sig')
    except UnicodeDecodeError:
        return raw.decode('cp1251')


def image_indexes(files):
    by_path, by_base, by_uuid = {}, defaultdict(list), defaultdict(list)
    for entry in files:
        path = entry['path']
        if path.rsplit('.', 1)[-1].lower() not in IMAGE_EXT:
            continue
        by_path[path.casefold()] = path
        by_base[posixpath.basename(path).casefold()].append(path)
        match = UUID.search(posixpath.basename(path))
        if match:
            by_uuid[match.group().casefold()].append(path)
    return by_path, by_base, by_uuid


def references(cell):
    return list(dict.fromkeys(x.strip(' \t\"\'') for x in re.split(r'[|,\r\n]+', cell) if x.strip(' \t\"\'')))


def resolve(reference, indexes):
    by_path, by_base, by_uuid = indexes
    path = unquote(urlsplit(reference.replace('\\', '/')).path).lstrip('/')
    if path.startswith('import/'):
        path = path[7:]
    if '..' in path.split('/'):
        return 'invalid', []
    if '/' in path and path.casefold() in by_path:
        return 'exact_path', [by_path[path.casefold()]]
    matches = by_base.get(posixpath.basename(path).casefold(), [])
    if matches:
        return ('exact_basename' if len(matches) == 1 else 'ambiguous_basename'), matches
    identifier = UUID.search(posixpath.basename(path))
    matches = by_uuid.get(identifier.group().casefold(), []) if identifier else []
    if matches:
        return ('uuid_unique' if len(matches) == 1 else 'ambiguous_uuid'), matches
    return 'unresolved', []


def analyze_csv(text, entry, indexes):
    # csv.reader preserves multiline quoted fields; line splitting corrupts them.
    rows = list(csv.reader(io.StringIO(text.replace('\x00', ''), newline=''), delimiter=';'))
    rows = [row for row in rows if any(x.strip() for x in row)]
    aliases = {'name': {'name', 'title', 'наименование', 'название', 'товар'},
               'id': {'id', 'guid', 'uuid', 'код', 'кодтовара', 'ид'},
               'image': {'image', 'images', 'photo', 'photos', 'picture', 'pictures', 'картинка', 'изображение', 'фото', 'фотографии', 'файлфото'}}
    norm = lambda value: re.sub(r'[^a-zа-я0-9]', '', value.lower())
    mapping = {}
    if rows:
        for index, value in enumerate(rows[0]):
            for field, names in aliases.items():
                if norm(value) in names:
                    mapping[field] = index
    has_header = 'name' in mapping and 'image' in mapping
    if has_header:
        rows = rows[1:]
    else:
        mapping = {'id': 4, 'name': 7, 'image': 9}
    counts, with_refs, focus, unresolved = Counter(), 0, [], []
    columns_with_image_suffix = Counter()
    for row in rows:
        for index, value in enumerate(row):
            if re.search(r'\.(?:jpg|jpeg|png|webp|gif|avif)(?:[\s|,]|$)', value, re.I):
                columns_with_image_suffix[index] += 1
        cell = row[mapping['image']] if len(row) > mapping['image'] else ''
        refs = references(cell)
        if refs:
            with_refs += 1
        name = row[mapping['name']] if len(row) > mapping['name'] else ''
        details = []
        for ref in refs:
            status, candidates = resolve(ref, indexes)
            counts[status] += 1
            details.append({'reference': ref, 'status': status, 'candidates': candidates[:6]})
            if status == 'unresolved' and len(unresolved) < 20:
                unresolved.append(ref)
        if 'brados' in name.casefold() and 'skate' in name.casefold() and len(focus) < 30:
            focus.append({'source_id': row[mapping['id']] if len(row) > mapping['id'] else '',
                          'name': name, 'photos': details})
    return {'file': entry['path'], 'rows': len(rows), 'row_widths': dict(Counter(map(len, rows))),
            'header_detected': has_header, 'mapping_zero_based': mapping,
            'image_columns_zero_based': dict(columns_with_image_suffix), 'rows_with_refs': with_refs,
            'reference_results': dict(counts), 'unresolved_sample': unresolved, 'focus_products': focus}


def main():
    password = os.environ.get('FTP_PASSWORD')
    if not password:
        raise ValueError('ftp_credential_missing')
    ftp = ftplib.FTP(timeout=90)
    try:
        ftp.connect('ftpupload.net', 21)
        ftp.login('if0_42771076', password)
        emit('SOURCE_FTP', {'authenticated': True, 'root': ROOT, 'mode': 'read_only'})
        files = inventory(ftp)
        indexes = image_indexes(files)
        sources = [entry for entry in files if entry['path'].rsplit('.', 1)[-1].lower() in {'csv', 'sql', 'xml', 'json', 'zip', 'gz'}]
        emit('SOURCE_INVENTORY', {'files': len(files), 'image_files': sum(map(len, indexes[1].values())),
              'unique_image_basenames': len(indexes[1]),
              'duplicate_basename_groups': sum(len(v) > 1 for v in indexes[1].values()),
              'image_directories': dict(Counter(posixpath.dirname(v) for v in indexes[0].values())),
              'source_files': sorted(sources, key=lambda item: item['path'])[:100]})
        products = [entry for entry in sources if 'tovary' in posixpath.basename(entry['path']).casefold() and entry['path'].lower().endswith('.csv')]
        if products:
            selected = max(products, key=lambda item: item['size'])
            emit('SOURCE_CSV_LINKS', analyze_csv(read_export(ftp, selected), selected, indexes))
        sql = [entry for entry in sources if entry['path'].lower().endswith('.sql')]
        for entry in sorted(sql, key=lambda item: item['size'])[:3]:
            if entry['size'] > MAX_DOWNLOAD:
                emit('SOURCE_SQL_SCHEMA', {'file': entry['path'], 'status': 'size_limit', 'bytes': entry['size']})
                continue
            text = read_export(ftp, entry)
            schemas = []
            for match in re.finditer(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([\w]+)`?\s*\((.*?)\)\s*(?:ENGINE|TYPE)\s*=', text, re.I | re.S):
                columns = re.findall(r'^\s*`([^`]+)`\s+[a-z]', match.group(2), re.I | re.M)
                schemas.append({'table': match.group(1), 'columns': columns})
            emit('SOURCE_SQL_SCHEMA', {'file': entry['path'], 'tables': schemas})
        emit('SOURCE_AUDIT_COMPLETE', {'ok': True, 'server_writes': 0, 'mysql_queried': False})
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
