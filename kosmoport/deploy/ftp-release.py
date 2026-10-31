#!/usr/bin/env python3
"""Local/CI release tool. Dry planning never connects; remote writes need --apply.

Only /htdocs/kosmoport is managed. The optional, explicitly requested redirect
touches only /htdocs/cms/sites/kosmosfera/.htaccess. No delete/sync operation.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import ftplib
import hashlib
import io
import json
import math
import os
import re
import secrets
import ssl
import stat
import sys
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

FTP_HOST = "ftpupload.net"
FTP_USER = "if0_42771076"
EXPECTED_ORIGIN = "https://ferryffggg.infinityfreeapp.com"
REMOTE_ROOT = "/htdocs/kosmoport"
REPOSITORY = "ivankirillov414-art/profisport-site"
MARKER_NAME = ".kosmoport-deploy.json"
PRIVATE_DIRS = ("app", "config", "migrations", "bin", "tools", "docs", "deploy", "integrations", "storage")
GUARDS = (".htaccess",) + tuple(f"{name}/.htaccess" for name in PRIVATE_DIRS)
REDIRECT_PATH = "/htdocs/cms/sites/kosmosfera/.htaccess"
REDIRECT_BEGIN = "# BEGIN KOSMOPORT ROOT REDIRECT v1"
REDIRECT_END = "# END KOSMOPORT ROOT REDIRECT v1"
REDIRECT_BLOCK = (
    REDIRECT_BEGIN + "\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
    "RewriteRule ^(?:index\\.html)?$ /kosmoport/ [R=302,L]\n"
    "</IfModule>\n" + REDIRECT_END + "\n"
)
MAX_FILE = 32 * 1024 * 1024
MAX_TOTAL = 64 * 1024 * 1024


class DeploymentError(RuntimeError):
    pass


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")


def safe_name(name: str) -> str:
    if (not isinstance(name, str) or not name or len(name) > 1024
            or "\\" in name or re.search(r"[\x00-\x1f\x7f:<>|?*]", name)
            or name.startswith("/")):
        raise DeploymentError("Unsafe package/FTP relative path.")
    for part in name.split("/"):
        if (part in ("", ".", "..") or part.strip() != part or part.endswith(".")
                or re.match(r"^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)", part, re.I)):
            raise DeploymentError("Unsafe package/FTP path segment.")
    return name


def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise DeploymentError("Duplicate JSON key.")
        result[key] = value
    return result


def read_json(data: bytes) -> dict:
    try:
        value = json.loads(data.decode("utf-8-sig"), object_pairs_hook=reject_duplicates)
    except (ValueError, UnicodeError) as exc:
        raise DeploymentError("Invalid JSON document.") from exc
    if not isinstance(value, dict):
        raise DeploymentError("JSON object required.")
    return value


def parse_install_manifest(data: bytes) -> str:
    value = read_json(data)
    if set(value) == {"setup_key_hash"}:
        checksum = value["setup_key_hash"]
    elif (set(value) == {"format", "setup_key_sha256", "base_path", "contains_secret"}
          and value["format"] == "kosmoport-install-public-v1"
          and value["base_path"] == "/kosmoport" and value["contains_secret"] is False):
        checksum = value["setup_key_sha256"]
    else:
        raise DeploymentError("Install manifest must contain only the reviewed public setup hash/metadata.")
    if not isinstance(checksum, str) or not re.fullmatch(r"[a-f0-9]{64}", checksum):
        raise DeploymentError("Invalid independent setup SHA256.")
    return checksum


@dataclass
class Package:
    files: dict[str, bytes]
    sha256: str


def load_package(path: str | Path, expected_sha256: str) -> Package:
    if not re.fullmatch(r"[0-9a-fA-F]{64}", expected_sha256):
        raise DeploymentError("An explicit SHA256 of the reviewed source ZIP is required.")
    archive_path = Path(path)
    if archive_path.is_symlink() or not archive_path.is_file() or archive_path.stat().st_size > MAX_TOTAL:
        raise DeploymentError("Invalid or oversized source ZIP.")
    archive_bytes = archive_path.read_bytes()
    if digest(archive_bytes) != expected_sha256.lower():
        raise DeploymentError("Source ZIP SHA256 mismatch.")
    files: dict[str, bytes] = {}
    names = set()
    total = 0
    try:
        with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
            if len(archive.infolist()) > 12000:
                raise DeploymentError("Too many ZIP entries.")
            for info in archive.infolist():
                name = safe_name(info.filename.rstrip("/") if info.is_dir() else info.filename)
                if name.lower() in names:
                    raise DeploymentError("Duplicate or case-colliding ZIP path.")
                names.add(name.lower())
                mode = info.external_attr >> 16
                if stat.S_ISLNK(mode) or info.flag_bits & 1:
                    raise DeploymentError("ZIP links and encrypted entries are forbidden.")
                if info.is_dir():
                    continue
                total += info.file_size
                if info.file_size > MAX_FILE or total > MAX_TOTAL:
                    raise DeploymentError("Expanded source ZIP limit exceeded.")
                parts = name.split("/")
                if parts[0] not in set(PRIVATE_DIRS) | {"public", ".htaccess", "CHECKSUMS.sha256", "SOURCE-PACKAGE.json"}:
                    raise DeploymentError("Unknown source package root.")
                if (any(re.match(r"^(?:\.env(?:\.|$)|node_modules$|\.git$|tests?$|fixtures?(?:\.|$)|backups?$|artifacts$|runtime$)", p, re.I) for p in parts)
                        or re.search(r"\.(?:sqlite3?|db|zip|bak|log|private\.json)$", name, re.I)
                        or (parts[0] == "config" and name not in {"config/.htaccess", "config/config.example.php", "config/infinityfree.example.php"})
                        or (parts[0] == "storage" and name != "storage/.htaccess")
                        or (name.endswith("/config.php") and name != "app/core/config.php")):
                    raise DeploymentError("Private/business/runtime material in source ZIP.")
                if (any(p.lower() in {".user.ini", "php.ini", ".htpasswd"} for p in parts)
                        or (name.endswith("/.htaccess") and name not in GUARDS and name != "public/.htaccess")):
                    raise DeploymentError("Unreviewed server-control file in package.")
                data = archive.read(info)
                if len(data) != info.file_size:
                    raise DeploymentError("ZIP entry size mismatch.")
                files[name] = data
    except (zipfile.BadZipFile, RuntimeError) as exc:
        if isinstance(exc, DeploymentError):
            raise
        raise DeploymentError("Corrupted source ZIP.") from exc
    checksum_data = files.get("CHECKSUMS.sha256")
    if checksum_data is None or "SOURCE-PACKAGE.json" not in files:
        raise DeploymentError("Source package checksum manifest missing.")
    checksums = {}
    for line in checksum_data.decode("utf-8").splitlines():
        match = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        if not match:
            raise DeploymentError("Invalid source member checksum.")
        name = safe_name(match[2])
        if name in checksums:
            raise DeploymentError("Duplicate source member checksum.")
        checksums[name] = match[1]
    if set(checksums) != set(files) - {"CHECKSUMS.sha256"}:
        raise DeploymentError("ZIP and checksum inventory differ.")
    if any(digest(files[name]) != checksum for name, checksum in checksums.items()):
        raise DeploymentError("Source member checksum mismatch.")
    metadata = read_json(files["SOURCE-PACKAGE.json"])
    if (metadata.get("format") != "kosmoport-source-v1" or metadata.get("php_minimum") != "8.2"
            or metadata.get("contains_business_data") is not False or metadata.get("contains_live_configuration") is not False):
        raise DeploymentError("Invalid source-only package metadata.")
    if "deploy/install-public.json" in files:
        parse_install_manifest(files["deploy/install-public.json"])
    # Fail on missing image support or invalid/animated source images OFFLINE,
    # before even opening an FTP connection or claiming a remote directory.
    for name, data in files.items():
        if name.lower().endswith(".webp"):
            verify_readback(name, data, data)
    package = Package(files, expected_sha256.lower())
    check_layout(package)
    return package


def check_layout(package: Package) -> None:
    for path in GUARDS:
        if path not in package.files:
            raise DeploymentError("Missing required root/private HTTP guard: " + path)
        if path != ".htaccess" and not re.search(rb"Require\s+all\s+denied|Deny\s+from\s+all", package.files[path], re.I):
            raise DeploymentError("Private guard is not a deny rule: " + path)
    for path in package.files:
        safe_name(path)
    if not re.fullmatch(r"[a-f0-9]{64}", package.sha256):
        raise DeploymentError("Invalid package identity.")


def verify_readback(path: str, expected: bytes, actual: bytes) -> dict:
    result = {"source_sha256": digest(expected), "remote_sha256": digest(actual), "bytes": len(actual)}
    is_webp = path.lower().endswith(".webp")
    if actual == expected and not is_webp:
        return {**result, "mode": "exact"}
    if not path.lower().endswith(".webp"):
        raise DeploymentError("Byte-exact FTP readback mismatch: " + path)
    try:
        from PIL import Image, ImageChops, ImageStat
        with Image.open(io.BytesIO(expected)) as left, Image.open(io.BytesIO(actual)) as right:
            if left.format != "WEBP" or right.format != "WEBP":
                raise DeploymentError("WebP readback is not a WebP image.")
            if left.size != right.size or getattr(left, "n_frames", 1) != 1 or getattr(right, "n_frames", 1) != 1:
                raise DeploymentError("WebP size/frame count changed.")
            left.load()
            right.load()
            rms = ImageStat.Stat(ImageChops.difference(left.convert("RGBA"), right.convert("RGBA"))).rms
            if not all(math.isfinite(value) and value <= 5 for value in rms):
                raise DeploymentError("WebP readback exceeds channel RMS 5.")
            return {**result, "mode": "exact" if actual == expected else "webp-recompressed", "dimensions": list(left.size), "rgba_rms": rms}
    except ImportError as exc:
        raise DeploymentError("Pillow is required to verify changed WebP bytes.") from exc
    except (OSError, ValueError) as exc:
        raise DeploymentError("Cannot decode WebP readback.") from exc


def build_initial_config(template: bytes, setup_hash: str, instance_id: str) -> bytes:
    if not re.fullmatch(r"[a-f0-9]{64}", setup_hash) or not re.fullmatch(r"[a-f0-9]{32}", instance_id):
        raise DeploymentError("Invalid independent setup hash or deployment instance.")
    text = template.decode("utf-8-sig")
    if (text.count("GENERATED_ON_FIRST_DEPLOYMENT") != 1
            or text.count("SET_TO_INDEPENDENT_SETUP_TOKEN_SHA256") != 1
            or re.search(r"ps_cms_users|cms_users|password_hash\s*\(|password_verify\s*\(|\bSELECT\b", text, re.I)):
        raise DeploymentError("Hosting config must be the reviewed DB-only template.")
    prelude = r'''
// App key is created on this server; no legacy CMS account or secret is copied.
$kcrmHostingKey=(static function(): string {
    $keyPath=__DIR__.'/app-key.php';
    $lockPath=__DIR__.'/.app-key.lock';
    if (is_link($keyPath) || is_link($lockPath)) throw new RuntimeException('Unsafe private key path.');
    $lock=fopen($lockPath,'c');
    if ($lock===false || !flock($lock,LOCK_EX)) throw new RuntimeException('Private key unavailable.');
    try {
        if (is_file($keyPath)) {
            $value=require $keyPath;
            if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value)) throw new RuntimeException('Private key invalid.');
            return $value;
        }
        $value=bin2hex(random_bytes(32));
        $temporary=__DIR__.'/.app-key-'.bin2hex(random_bytes(12)).'.php';
        $handle=fopen($temporary,'xb');
        if ($handle===false) throw new RuntimeException('Private key creation failed.');
        try {
            $bytes="<?php\nreturn '".$value."';\n";
            if (fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle)) throw new RuntimeException('Private key write failed.');
        } finally { fclose($handle); }
        chmod($temporary,0600);
        if (!rename($temporary,$keyPath)) { @unlink($temporary); throw new RuntimeException('Private key publish failed.'); }
        return $value;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
})();
$kcrmWebrootAllowed=false;
$kcrmPrivacyPath=__DIR__.'/documents-privacy.php';
if (is_file($kcrmPrivacyPath) && !is_link($kcrmPrivacyPath)) {
    $kcrmProof=require $kcrmPrivacyPath;
    $kcrmReleasePath=dirname(__DIR__).'/.kosmoport-deploy.json';
    $kcrmRelease=is_file($kcrmReleasePath) && !is_link($kcrmReleasePath)
        ? json_decode((string)file_get_contents($kcrmReleasePath),true) : null;
    if (is_array($kcrmProof) && ($kcrmProof['allowed']??false)===true
        && is_array($kcrmRelease) && ($kcrmRelease['status']??'')==='verified'
        && ($kcrmProof['deployment_nonce']??'')===($kcrmRelease['deployment_nonce']??null)
        && ($kcrmProof['package_sha256']??'')===($kcrmRelease['package_sha256']??null)
        && ($kcrmProof['instance_id']??'')==='INSTANCE_ID_PLACEHOLDER'
        && ($kcrmProof['origin']??'')==='EXPECTED_ORIGIN_PLACEHOLDER'
        && (PHP_SAPI==='cli' || (strtolower((string)($_SERVER['HTTP_HOST']??''))==='EXPECTED_HOST_PLACEHOLDER'
            && str_starts_with((string)($_SERVER['SCRIPT_NAME']??''),'/kosmoport/')))
        && is_array($kcrmProof['guard_sha256']??null) && count($kcrmProof['guard_sha256'])===10) {
        $kcrmWebrootAllowed=true;
        foreach ($kcrmProof['guard_sha256'] as $relative=>$expected) {
            if (!in_array($relative,['.htaccess','app/.htaccess','config/.htaccess','migrations/.htaccess','bin/.htaccess','tools/.htaccess','docs/.htaccess','deploy/.htaccess','integrations/.htaccess','storage/.htaccess'],true)
                || !is_string($expected) || !hash_equals($expected,(string)hash_file('sha256',dirname(__DIR__).'/'.$relative))) $kcrmWebrootAllowed=false;
        }
    }
}
'''
    prelude = prelude.replace("INSTANCE_ID_PLACEHOLDER", instance_id)
    prelude = prelude.replace("EXPECTED_ORIGIN_PLACEHOLDER", EXPECTED_ORIGIN)
    prelude = prelude.replace("EXPECTED_HOST_PLACEHOLDER", urlsplit(EXPECTED_ORIGIN).netloc)
    if text.count("return [") != 1:
        raise DeploymentError("Unexpected hosting config layout.")
    text = text.replace("return [", prelude + "\nreturn [", 1)
    text = text.replace("'GENERATED_ON_FIRST_DEPLOYMENT'", "$kcrmHostingKey")
    text = text.replace("'SET_TO_INDEPENDENT_SETUP_TOKEN_SHA256'", "'" + setup_hash + "'")
    if re.search(r"'(?:allow_webroot_storage|http_denial_verified)'\s*=>\s*true\b", text):
        raise DeploymentError("Hosting config must start with document storage disabled.")
    text, count = re.subn(r"('allow_webroot_storage'\s*=>\s*)false\b", r"\1$kcrmWebrootAllowed", text)
    text, verified_count = re.subn(r"('http_denial_verified'\s*=>\s*)false\b", r"\1$kcrmWebrootAllowed", text)
    if count not in (1, 2) or (count == 2 and verified_count != 1):
        raise DeploymentError("Default document privacy opt-in missing.")
    header = re.match(r"\A<\?php\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*", text)
    if header is None:
        raise DeploymentError("Hosting config requires its reviewed strict PHP header.")
    # require inherits caller-local variables. Isolate the whole adapter so the
    # template's foreach($key) cannot replace crm_config($key) on its first call.
    body = text[header.end():]
    return ("<?php\ndeclare(strict_types=1);\nreturn (static function(): array {\n"
            + body + "\n})();\n").encode("utf-8")


def connect_ftps(password: str, ftp_factory=ftplib.FTP_TLS):
    if not password:
        raise DeploymentError("FTP_PASS secret is required only for --apply.")
    for key, expected in (("FTP_HOST", FTP_HOST), ("FTP_USER", FTP_USER)):
        if os.environ.get(key, expected) != expected:
            raise DeploymentError("FTP destination override is forbidden.")
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    ftp = ftp_factory(context=context, timeout=45)
    ftp.connect(FTP_HOST, 21)
    ftp.login(FTP_USER, password)
    ftp.prot_p()
    ftp.set_pasv(True)
    return ftp


def listing(ftp, path: str) -> dict[str, dict]:
    try:
        rows = list(ftp.mlsd(path, facts=["type", "size"]))
    except ftplib.all_errors as exc:
        raise DeploymentError("Cannot obtain safe MLSD inventory; no mutation attempted.") from exc
    entries = {}
    for name, facts in rows:
        if name in (".", "..") or facts.get("type") in ("cdir", "pdir"):
            continue
        safe_name(name)
        if "/" in name or name in entries or facts.get("type") not in ("file", "dir"):
            raise DeploymentError("Unknown, linked or ambiguous FTP directory entry.")
        entries[name] = facts
    return entries


def read_remote(ftp, path: str, limit: int = MAX_FILE) -> bytes:
    chunks = bytearray()
    def collect(data: bytes) -> None:
        if len(chunks) + len(data) > limit:
            raise DeploymentError("Remote readback exceeds size limit.")
        chunks.extend(data)
    ftp.retrbinary("RETR " + path, collect)
    return bytes(chunks)


def marker_valid(marker: dict) -> None:
    if (marker.get("application") != "kosmoport-crm" or marker.get("version") != 1
            or marker.get("root") != REMOTE_ROOT or marker.get("repository") != REPOSITORY
            or marker.get("origin") != EXPECTED_ORIGIN
            or marker.get("status") not in ("claimed", "verified")
            or not re.fullmatch(r"[a-f0-9]{32}", str(marker.get("deployment_nonce", "")))
            or not re.fullmatch(r"[a-f0-9]{64}", str(marker.get("package_sha256", "")))
            or not re.fullmatch(r"[a-f0-9]{32}", str(marker.get("instance_id", "")))
            or not isinstance(marker.get("managed_files"), dict) or not isinstance(marker.get("planned_files"), dict)):
        raise DeploymentError("Foreign or invalid deployment marker.")
    for inventory in (marker["managed_files"], marker["planned_files"]):
        for path, info in inventory.items():
            safe_name(path)
            if (not isinstance(info, dict) or not re.fullmatch(r"[a-f0-9]{64}", str(info.get("sha256", "")))
                    or not isinstance(info.get("bytes"), int) or not 0 <= info["bytes"] <= MAX_FILE):
                raise DeploymentError("Invalid marker file inventory.")
    for field, folder in (("privacy_probe", "tmp"), ("document_probe", "documents")):
        probe = marker.get(field)
        if (not isinstance(probe, dict) or not re.fullmatch(r"storage/" + folder + r"/privacy-probe-[a-f0-9]{32}\.txt", str(probe.get("path", "")))
                or not re.fullmatch(r"[a-f0-9]{64}", str(probe.get("sha256", "")))
                or not re.fullmatch(r"[a-f0-9]{64}", str(probe.get("token", "")))
                or digest((probe["token"] + "\n").encode("ascii")) != probe["sha256"]):
            raise DeploymentError("Invalid deployment browser canary.")
    control = marker.get("public_control")
    if (not isinstance(control, dict) or control.get("path") != "public/hosting-control-" + marker["instance_id"] + ".json"
            or not re.fullmatch(r"[a-f0-9]{64}", str(control.get("sha256", "")))):
        raise DeploymentError("Invalid public browser control.")
    if marker["status"] == "verified":
        hashes = marker.get("guard_sha256")
        if not isinstance(hashes, dict) or set(hashes) != set(GUARDS) or any(not re.fullmatch(r"[a-f0-9]{64}", str(v)) for v in hashes.values()):
            raise DeploymentError("Invalid verified denial policy.")


class ReleaseDeployer:
    def __init__(self, ftp, package: Package, *, config_template: bytes | None = None,
                 setup_hash: str | None = None, source_ref: str = "local"):
        check_layout(package)
        self.ftp, self.package = ftp, package
        self.config_template, self.setup_hash = config_template, setup_hash
        self.source_ref = source_ref[:200]
        self.directories: dict[str, dict] = {}
        self.marker: dict = {}
        self.verified = False
        self.expected_remote: dict[str, str | None] = {}

    def path(self, relative: str) -> str:
        return REMOTE_ROOT + "/" + safe_name(relative)

    def entries(self, directory: str) -> dict:
        if directory not in self.directories:
            self.directories[directory] = listing(self.ftp, directory)
        return self.directories[directory]

    def ensure_dir(self, path: str) -> None:
        if path == REMOTE_ROOT:
            return
        if not path.startswith(REMOTE_ROOT + "/"):
            raise DeploymentError("Write path escapes the isolated root.")
        parent, name = path.rsplit("/", 1)
        self.ensure_dir(parent)
        entries = self.entries(parent)
        if name in entries:
            if entries[name]["type"] != "dir":
                raise DeploymentError("Expected an ordinary FTP directory.")
        else:
            self.ftp.mkd(path)
            entries[name] = {"type": "dir"}
            self.directories[path] = {}

    def existing_file(self, relative: str) -> bool:
        current = REMOTE_ROOT
        parts = safe_name(relative).split("/")
        for name in parts[:-1]:
            entry = self.entries(current).get(name)
            if entry is None:
                return False
            if entry["type"] != "dir":
                raise DeploymentError("Linked/non-directory FTP ancestor.")
            current += "/" + name
        entry = self.entries(current).get(parts[-1])
        if entry and entry["type"] != "file":
            raise DeploymentError("Upload destination is not an ordinary file.")
        return bool(entry)

    def put(self, relative: str, data: bytes) -> dict:
        path = self.path(relative)
        parent, name = path.rsplit("/", 1)
        self.ensure_dir(parent)
        # Fresh state around promotion catches edits after an earlier preflight.
        before_entries = listing(self.ftp, parent)
        before = read_remote(self.ftp, path) if name in before_entries else None
        if name in before_entries and before_entries[name]["type"] != "file":
            raise DeploymentError("Destination changed to a non-file.")
        actual_before = digest(before) if before is not None else None
        expected_before = self.expected_remote.get(relative)
        if actual_before != expected_before:
            raise DeploymentError("Concurrent/unmanaged FTP destination change: " + relative)
        temporary = parent + "/.kosmoport-upload-" + secrets.token_hex(16)
        self.ftp.storbinary("STOR " + temporary, io.BytesIO(data))
        verify_readback(relative, data, read_remote(self.ftp, temporary))
        now_entries = listing(self.ftp, parent)
        now = read_remote(self.ftp, path) if name in now_entries else None
        if (name in now_entries and now_entries[name]["type"] != "file") or (digest(now) if now is not None else None) != actual_before:
            raise DeploymentError("FTP destination changed before promotion: " + relative)
        # No delete fallback when a server refuses overwrite by rename.
        self.ftp.rename(temporary, path)
        result = verify_readback(relative, data, read_remote(self.ftp, path))
        self.entries(parent)[name] = {"type": "file", "size": str(result["bytes"])}
        self.expected_remote[relative] = result["remote_sha256"]
        return result

    def deploy(self) -> dict:
        root_parent = listing(self.ftp, "/htdocs")
        if "kosmoport" not in root_parent:
            self.ftp.mkd(REMOTE_ROOT)
            self.directories[REMOTE_ROOT] = {}
        elif root_parent["kosmoport"]["type"] != "dir":
            raise DeploymentError("Isolated target is not a directory.")
        entries = self.entries(REMOTE_ROOT)
        previous: dict = {}
        if entries:
            if MARKER_NAME not in entries or entries[MARKER_NAME]["type"] != "file":
                raise DeploymentError("Occupied directory has no Kosmoport deployment marker.")
            raw_marker = read_remote(self.ftp, self.path(MARKER_NAME), MAX_FILE)
            previous = read_json(raw_marker)
            self.expected_remote[MARKER_NAME] = digest(raw_marker)
            marker_valid(previous)
            if previous["status"] == "claimed" and previous.get("package_sha256") != self.package.sha256:
                raise DeploymentError("Resume the unfinished reviewed package before changing releases.")
        instance_id = previous.get("instance_id", secrets.token_hex(16))
        deployment_nonce = previous["deployment_nonce"] if previous.get("status") == "claimed" else secrets.token_hex(16)
        first = not previous
        resume_initial = bool(previous and previous["status"] == "claimed"
                              and "config/config.php" in previous["planned_files"]
                              and not self.existing_file("config/config.php"))
        target_files = {name: data for name, data in self.package.files.items()
                        if not name.startswith(("config/", "storage/")) or name in GUARDS}
        if first or resume_initial:
            if self.config_template is None or self.setup_hash is None:
                raise DeploymentError("First deployment requires DB-only config template and independent setup hash.")
            for name, data in self.package.files.items():
                if name.startswith("config/"):
                    target_files[name] = data
            target_files["config/config.php"] = build_initial_config(self.config_template, self.setup_hash, instance_id)
        control_path = "public/hosting-control-" + instance_id + ".json"
        control_bytes = canonical_json({"application": "kosmoport-crm", "instance_id": instance_id,
                                        "package_sha256": self.package.sha256, "deployment_nonce": deployment_nonce})
        target_files[control_path] = control_bytes
        # Check every overwrite against previously verified ownership before writes.
        owned = {**previous.get("managed_files", {}), **previous.get("planned_files", {})}
        for name, data in target_files.items():
            if self.existing_file(name):
                if name not in owned:
                    raise DeploymentError("Unmanaged upload destination; refusing overwrite: " + name)
                remote = read_remote(self.ftp, self.path(name))
                self.expected_remote[name] = digest(remote)
                old = previous.get("managed_files", {}).get(name)
                if not (old and digest(remote) == old["sha256"]):
                    verify_readback(name, data, remote)  # Same pending package/resume is safe.
        managed = dict(previous.get("managed_files", {}))
        probe = previous.get("privacy_probe")
        new_probe_bytes = new_document_bytes = None
        if not isinstance(probe, dict):
            token = secrets.token_hex(32)
            probe_bytes = (token + "\n").encode("ascii")
            probe = {"path": "storage/tmp/privacy-probe-" + secrets.token_hex(16) + ".txt", "sha256": digest(probe_bytes), "token": token}
            new_probe_bytes = probe_bytes
        elif not self.existing_file(probe["path"]):
            new_probe_bytes = (probe["token"] + "\n").encode("ascii")
        document_probe = previous.get("document_probe")
        if not isinstance(document_probe, dict):
            token = secrets.token_hex(32)
            probe_bytes = (token + "\n").encode("ascii")
            document_probe = {"path": "storage/documents/privacy-probe-" + secrets.token_hex(16) + ".txt", "sha256": digest(probe_bytes), "token": token}
            new_document_bytes = probe_bytes
        elif not self.existing_file(document_probe["path"]):
            new_document_bytes = (document_probe["token"] + "\n").encode("ascii")
        self.marker = {
            "application": "kosmoport-crm", "version": 1, "repository": REPOSITORY,
            "root": REMOTE_ROOT, "instance_id": instance_id, "status": "claimed",
            "origin": EXPECTED_ORIGIN, "deployment_nonce": deployment_nonce,
            "package_sha256": self.package.sha256, "source_ref": self.source_ref,
            "managed_files": managed,
            "planned_files": {name: {"sha256": digest(data), "bytes": len(data)} for name, data in target_files.items()},
            "privacy_probe": probe, "document_probe": document_probe,
            "public_control": {"path": control_path, "sha256": digest(control_bytes)},
            "generated_config_sha256": digest(target_files["config/config.php"]) if (first or resume_initial) else previous.get("generated_config_sha256"),
            "created_at": previous.get("created_at", dt.datetime.now(dt.timezone.utc).isoformat()),
        }
        # Reserve ownership with nonsensitive metadata before content. All deny
        # rules are then verified before any application/config/probe upload.
        self.put(MARKER_NAME, canonical_json(self.marker))
        for name in GUARDS:
            result = self.put(name, self.package.files[name])
            managed[name] = {"sha256": result["remote_sha256"], "bytes": result["bytes"]}
        if new_probe_bytes is not None:
            self.put(probe["path"], new_probe_bytes)
        if new_document_bytes is not None:
            self.put(document_probe["path"], new_document_bytes)
        readbacks = {}
        for name, data in sorted(target_files.items()):
            if name in GUARDS:
                continue
            # Private key/config/storage written by PHP are never read or replaced on updates.
            result = self.put(name, data)
            readbacks[name] = result
            managed[name] = {"sha256": result["remote_sha256"], "bytes": result["bytes"]}
        self.marker["managed_files"] = managed
        self.marker["status"] = "verified"
        self.marker["verified_at"] = dt.datetime.now(dt.timezone.utc).isoformat()
        self.marker["guard_sha256"] = {name: digest(self.package.files[name]) for name in GUARDS}
        self.put(MARKER_NAME, canonical_json(self.marker))
        self.verified = True
        return {"ok": True, "root": REMOTE_ROOT, "instance_id": instance_id,
                "deployment_nonce": deployment_nonce, "origin": EXPECTED_ORIGIN,
                "verified_at": self.marker["verified_at"],
                "package_sha256": self.package.sha256, "files_verified": len(target_files),
                "readbacks": readbacks, "config_preserved": not first, "storage_preserved": True,
                "browser_privacy_verified": False, "privacy_probe": probe, "document_probe": document_probe,
                "public_control": self.marker["public_control"], "guard_sha256": self.marker["guard_sha256"]}


def apply_redirect(ftp, enable: bool = True, *, expected_package_sha256: str | None = None) -> dict:
    if enable:
        try:
            marker = read_json(read_remote(ftp, REMOTE_ROOT + "/" + MARKER_NAME, MAX_FILE))
        except ftplib.all_errors as exc:
            raise DeploymentError("Kosmoport deployment ownership was not established.") from exc
        marker_valid(marker)
        if marker["status"] != "verified":
            raise DeploymentError("Redirect requires an existing verified deployment.")
        if expected_package_sha256 is not None and marker["package_sha256"] != expected_package_sha256:
            raise DeploymentError("Redirect source package differs from the verified live release.")
    parent = "/htdocs"
    for component in ("cms", "sites", "kosmosfera"):
        entry = listing(ftp, parent).get(component)
        if not entry or entry["type"] != "dir":
            raise DeploymentError("Kosmosfera redirect ancestor is not an ordinary directory.")
        parent += "/" + component
    entries = listing(ftp, parent)
    if ".htaccess" in entries:
        if entries[".htaccess"]["type"] != "file":
            raise DeploymentError("Kosmosfera access rules are not a regular file.")
        old = read_remote(ftp, REDIRECT_PATH, 1024 * 1024)
    else:
        old = b""
    try:
        text = old.decode("utf-8-sig")
    except UnicodeError as exc:
        raise DeploymentError("Cannot safely edit non-UTF8 Kosmosfera access rules.") from exc
    block = re.compile(re.escape(REDIRECT_BEGIN) + r"\r?\n.*?" + re.escape(REDIRECT_END) + r"(?:\r?\n)?", re.S)
    if (text.count(REDIRECT_BEGIN) != text.count(REDIRECT_END) or text.count(REDIRECT_BEGIN) > 1):
        raise DeploymentError("Ambiguous managed redirect block.")
    existing = block.search(text)
    if existing and existing.group(0).replace("\r\n", "\n").rstrip("\n") != REDIRECT_BLOCK.rstrip("\n"):
        raise DeploymentError("Managed redirect content changed; manual review required.")
    clean = block.sub("", text)
    updated = (b"\xef\xbb\xbf" if old.startswith(b"\xef\xbb\xbf") else b"") + ((REDIRECT_BLOCK + clean) if enable else clean).encode("utf-8")
    if updated == old:
        return {"ok": True, "changed": False, "enabled": enable, "path": REDIRECT_PATH}
    if (read_remote(ftp, REDIRECT_PATH, 1024 * 1024) if ".htaccess" in entries else b"") != old:
        raise DeploymentError("Kosmosfera access rules changed concurrently.")
    temporary = REDIRECT_PATH + ".kosmoport-upload-" + secrets.token_hex(16)
    ftp.storbinary("STOR " + temporary, io.BytesIO(updated))
    verify_readback("redirect.htaccess", updated, read_remote(ftp, temporary, 1024 * 1024))
    if (read_remote(ftp, REDIRECT_PATH, 1024 * 1024) if ".htaccess" in entries else b"") != old:
        raise DeploymentError("Kosmosfera access rules changed before atomic promotion.")
    ftp.rename(temporary, REDIRECT_PATH)
    verify_readback("redirect.htaccess", updated, read_remote(ftp, REDIRECT_PATH, 1024 * 1024))
    return {"ok": True, "changed": True, "enabled": enable, "path": REDIRECT_PATH,
            "previous_sha256": digest(old), "remote_sha256": digest(updated)}


def enable_document_storage(ftp, proof: dict, *, operator_confirmed: bool, expected_package_sha256: str | None = None) -> dict:
    """Explicit operator attestation of actual browser evidence, never FTP inference."""
    marker = read_json(read_remote(ftp, REMOTE_ROOT + "/" + MARKER_NAME, MAX_FILE))
    marker_valid(marker)
    if operator_confirmed is not True or marker["status"] != "verified":
        raise DeploymentError("Explicit operator browser-check confirmation required.")
    if expected_package_sha256 is not None and expected_package_sha256 != marker["package_sha256"]:
        raise DeploymentError("Selected source package differs from the observed live release.")
    if (proof.get("kind") != "interactive-browser-observation" or proof.get("instance_id") != marker["instance_id"]
            or proof.get("deployment_nonce") != marker["deployment_nonce"] or proof.get("origin") != marker["origin"]
            or proof.get("package_sha256") != marker["package_sha256"] or proof.get("guard_sha256") != marker.get("guard_sha256")):
        raise DeploymentError("Browser receipt is stale or belongs to another release/policy.")
    origin = urlsplit(str(proof.get("origin", "")))
    if (origin.scheme != "https" or not origin.hostname or not re.fullmatch(r"[a-zA-Z0-9.-]+", origin.hostname)
            or origin.username or origin.password or origin.path not in ("", "/") or origin.query or origin.fragment):
        raise DeploymentError("Receipt requires an exact HTTPS origin.")
    try:
        checked = dt.datetime.fromisoformat(str(proof["checked_at"]).replace("Z", "+00:00"))
        age = (dt.datetime.now(dt.timezone.utc) - checked).total_seconds()
    except (ValueError, KeyError, TypeError) as exc:
        raise DeploymentError("Invalid browser receipt time.") from exc
    if not 0 <= age <= 3600:
        raise DeploymentError("Browser receipt must be less than one hour old.")
    if checked < dt.datetime.fromisoformat(marker["verified_at"]):
        raise DeploymentError("Browser receipt predates the current deployment.")
    expected_paths = {
        "/kosmoport/" + marker["privacy_probe"]["path"],
        "/kosmoport/" + marker["document_probe"]["path"],
        "/kosmoport/config/config.php", "/kosmoport/app/bootstrap.php",
    }
    observations = proof.get("private_gets")
    if not isinstance(observations, list) or {x.get("path") for x in observations if isinstance(x, dict)} != expected_paths:
        raise DeploymentError("Incomplete real browser private-path checks.")
    if len(observations) != len(expected_paths):
        raise DeploymentError("Duplicate browser observations are not accepted.")
    for observation in observations:
        expected_url = origin.geturl().rstrip("/") + observation["path"]
        if (observation.get("status") not in (403, 404) or observation.get("method") != "GET"
                or observation.get("url") != expected_url or observation.get("final_url") != expected_url
                or observation.get("challenge") is not False or observation.get("network_error") is not False
                or not isinstance(observation.get("evidence"), str) or not observation["evidence"]):
            raise DeploymentError("Private-path status is not an observed same-origin browser denial.")
    control = proof.get("public_control", {})
    control_url = origin.geturl().rstrip("/") + "/kosmoport/" + marker["public_control"]["path"].removeprefix("public/")
    if (control.get("status") != 200 or control.get("method") != "GET" or control.get("network_error") is not False or control.get("url") != control_url
            or control.get("final_url") != control_url or control.get("body_sha256") != marker["public_control"]["sha256"]
            or control.get("challenge") is not False or not control.get("evidence")):
        raise DeploymentError("Actual browser fresh public-canary positive control required.")
    # Check existing probes/policy over FTP; this does NOT replace browser evidence.
    if digest(read_remote(ftp, REMOTE_ROOT + "/" + marker["privacy_probe"]["path"])) != marker["privacy_probe"]["sha256"]:
        raise DeploymentError("Private browser canary missing or changed.")
    if digest(read_remote(ftp, REMOTE_ROOT + "/" + marker["document_probe"]["path"])) != marker["document_probe"]["sha256"]:
        raise DeploymentError("Document browser canary missing or changed.")
    for path, checksum in marker["guard_sha256"].items():
        if digest(read_remote(ftp, REMOTE_ROOT + "/" + safe_name(path))) != checksum:
            raise DeploymentError("Denial policy changed after browser observations.")
    if digest(read_remote(ftp, REMOTE_ROOT + "/config/config.php")) != marker.get("generated_config_sha256"):
        raise DeploymentError("Live config changed; automatic opt-in is not safe.")
    payload = {"allowed": True, "instance_id": marker["instance_id"], "guard_sha256": marker["guard_sha256"],
               "origin": origin.geturl().rstrip("/"), "checked_at": proof["checked_at"],
               "deployment_nonce": marker["deployment_nonce"],
               "package_sha256": marker["package_sha256"],
               "evidence_sha256": digest(canonical_json(proof))}
    # JSON-decoded static data avoids interpolating PHP code from the receipt.
    encoded = base64.b64encode(canonical_json(payload)).decode("ascii")
    php = ("<?php\nreturn json_decode(base64_decode('" + encoded + "'), true, 16, JSON_THROW_ON_ERROR);\n").encode("utf-8")
    path = REMOTE_ROOT + "/config/documents-privacy.php"
    temporary = REMOTE_ROOT + "/config/.kosmoport-upload-" + secrets.token_hex(16)
    ftp.storbinary("STOR " + temporary, io.BytesIO(php))
    verify_readback("privacy.php", php, read_remote(ftp, temporary))
    ftp.rename(temporary, path)
    verify_readback("privacy.php", php, read_remote(ftp, path))
    return {"ok": True, "operator_attestation": True, "independent_browser_proof": False,
            "document_storage_opt_in_written": True, "evidence_sha256": payload["evidence_sha256"]}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", required=True)
    parser.add_argument("--expected-sha256", required=True)
    parser.add_argument("--config-template", type=Path)
    parser.add_argument("--install-public", type=Path)
    parser.add_argument("--apply", action="store_true", help="Explicitly allow FTP mutations. Omit for an offline plan.")
    parser.add_argument("--redirect-kosmosfera", action="store_true")
    parser.add_argument("--remove-kosmosfera-redirect", action="store_true")
    parser.add_argument("--privacy-proof", type=Path)
    parser.add_argument("--confirm-browser-checks", action="store_true")
    parser.add_argument("--report", type=Path)
    args = parser.parse_args(argv)
    ftp = None
    try:
        package = load_package(args.package, args.expected_sha256)
        root = Path(__file__).resolve().parent.parent
        template_path = args.config_template or root / "config/infinityfree.example.php"
        install_path = args.install_public or root / "deploy/install-public.json"
        template = template_path.read_bytes() if template_path.is_file() else package.files.get("config/infinityfree.example.php")
        packaged_template = package.files.get("config/infinityfree.example.php")
        if packaged_template is not None and template != packaged_template:
            raise DeploymentError("Local config template differs from the reviewed source package.")
        install_bytes = install_path.read_bytes() if install_path.is_file() else package.files.get("deploy/install-public.json")
        setup_hash = parse_install_manifest(install_bytes) if install_bytes is not None else None
        if args.redirect_kosmosfera and args.remove_kosmosfera_redirect:
            raise DeploymentError("Choose at most one redirect operation.")
        if args.privacy_proof and (args.redirect_kosmosfera or args.remove_kosmosfera_redirect):
            raise DeploymentError("Browser opt-in and redirect are separate operations.")
        if args.confirm_browser_checks and not args.privacy_proof:
            raise DeploymentError("--confirm-browser-checks requires actual browser evidence.")
        if args.report and args.report.exists():
            raise DeploymentError("Report already exists; choose a fresh local path.")
        report = {"ok": True, "mode": "offline-plan", "host": FTP_HOST, "root": REMOTE_ROOT,
                  "package_sha256": package.sha256, "source_files": len(package.files),
                  "first_deploy_config_ready": template is not None and setup_hash is not None,
                  "network_used": False, "browser_privacy_verified": False}
        if args.apply:
            if not args.privacy_proof and not args.remove_kosmosfera_redirect and not args.redirect_kosmosfera:
                if template is None or setup_hash is None:
                    raise DeploymentError("Config template and independent public setup hash required before FTP.")
                build_initial_config(template, setup_hash, "0" * 32)  # Offline semantic-shape preflight.
            ftp = connect_ftps(os.environ.get("FTP_PASS", ""))
            if args.privacy_proof:
                report = enable_document_storage(ftp, read_json(args.privacy_proof.read_bytes()), operator_confirmed=args.confirm_browser_checks, expected_package_sha256=package.sha256)
            elif args.remove_kosmosfera_redirect:
                report = apply_redirect(ftp, False)
            elif args.redirect_kosmosfera:
                # A separate activation after real browser privacy/setup checks;
                # do not redeploy or invalidate the accepted deployment nonce.
                report = apply_redirect(ftp, True, expected_package_sha256=package.sha256)
            else:
                runner = ReleaseDeployer(ftp, package, config_template=template,
                                         setup_hash=setup_hash,
                                         source_ref=os.environ.get("GITHUB_SHA", "local"))
                report = runner.deploy()
            report["network_used"] = True
        if args.report:
            if args.report.exists():
                raise DeploymentError("Report already exists; choose a fresh local path.")
            args.report.parent.mkdir(parents=True, exist_ok=True)
            with args.report.open("xb") as stream:
                stream.write(canonical_json(report))
        print(canonical_json(report).decode("utf-8"), end="")
        return 0
    except Exception as exc:
        # Never emit FTP replies, credentials, remote config or a traceback.
        message = str(exc) if isinstance(exc, DeploymentError) else "Release stopped; inspect destination and local validation without exposing secrets."
        print(json.dumps({"ok": False, "error": message}), file=sys.stderr)
        return 1
    finally:
        if ftp is not None:
            try:
                ftp.quit()
            except ftplib.all_errors:
                ftp.close()


if __name__ == "__main__":
    raise SystemExit(main())
