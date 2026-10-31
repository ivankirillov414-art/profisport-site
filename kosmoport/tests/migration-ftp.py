"""Offline FTPS release safety tests; no real FTP, HTTP or GitHub operations.

Planned product checks (bound to deploy/ftp-release.py after its API is ready):
* explicit TLS and fixed host/login/root; no mutation of a foreign/nonempty root;
* deployment-marker update guard, no deletion/sync, config/storage preservation;
* deny rules before source/config; temp+readback+rename within the owned root;
* exact PHP/JSON/font bytes; measured WebP dimensions and RGB RMS <= 5;
* ZIP traversal/symlink/private-config/runtime/control-file rejection;
* first-deploy-only trusted config adapter and valid setup SHA256 metadata;
* opt-in reversible legacy-root/index.html redirect, preserving directives.

The fixture implements an in-memory subset of ftplib.FTP_TLS. Socket creation is
blocked when importing/executing the release module. Synthetic data only.
"""
from __future__ import annotations

from datetime import datetime, timezone
from contextlib import redirect_stdout, redirect_stderr
from copy import deepcopy
import builtins
import ftplib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import posixpath
import secrets
import shutil
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile
from PIL import Image

sys.dont_write_bytecode = True


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "deploy" / "ftp-release.py"
TARGET = "/htdocs/kosmoport"
LEGACY = "/htdocs/cms/sites/kosmosfera"
HOST = "ftpupload.net"
USERNAME = "if0_42771076"
FIXTURE_PASSWORD = "generated-fixture-password-not-an-account"


class FakeFTPS:
    """FTPS model with durable byte-level state and a complete operation journal."""

    def __init__(self, host="", user="", passwd="", acct="", *, context=None,
                 timeout=None, encoding="utf-8", files=None, directories=None,
                 transform=None, **kwargs):
        self.files = dict(files or {})
        self.directories = {"/", "/htdocs", "/htdocs/cms", "/htdocs/cms/sites", LEGACY}
        self.directories.update(directories or ())
        for name in self.files:
            parent = posixpath.dirname(name)
            while parent and parent != "/":
                self.directories.add(parent)
                parent = posixpath.dirname(parent)
        self.events = []
        self.current = "/"
        self.context = context
        self.control_tls = False
        self.data_tls = False
        self.logged_in = False
        self.closed = False
        self.transform = transform
        self.encoding = encoding
        if host:
            self.connect(host, timeout=timeout)
            if user:
                self.login(user, passwd, acct)

    def _path(self, path):
        if path in (None, ""):
            return self.current
        return posixpath.normpath(path if path.startswith("/") else posixpath.join(self.current, path))

    def _write_allowed(self, path):
        allowed = path == TARGET or path.startswith(TARGET + "/")
        # Only the explicitly authorized legacy htaccess and its reversible
        # temporary/backup variants may be touched outside the new application.
        allowed = allowed or (posixpath.dirname(path) == LEGACY
                              and posixpath.basename(path).startswith(".htaccess"))
        if not allowed:
            raise AssertionError("Attempted mutation outside the authorized release paths: " + path)

    def _protected(self):
        if not (self.control_tls and self.data_tls and self.logged_in):
            raise AssertionError("Binary transfer attempted without explicit control/data TLS and login")

    def connect(self, host, port=21, timeout=None, source_address=None):
        self.events.append(("connect", host, port))
        if host != HOST or port != 21:
            raise AssertionError("Only the fixed explicit-TLS fixture host/port may be selected")
        return "220 in-memory FTPS"

    def auth(self):
        self.events.append(("auth_tls",))
        self.control_tls = True
        return "234 TLS established"

    def login(self, user="anonymous", passwd="", acct="", secure=True):
        # FTP_TLS.login normally negotiates AUTH TLS itself.
        if secure and not self.control_tls:
            self.auth()
        self.events.append(("login", user))  # Deliberately never record passwords.
        if user != USERNAME:
            raise AssertionError("Unexpected deployment account")
        self.logged_in = True
        return "230 logged in"

    def prot_p(self):
        if not self.control_tls:
            raise AssertionError("PROT P before control TLS")
        self.events.append(("prot_p",))
        self.data_tls = True
        return "200 private data connection"

    def set_pasv(self, value):
        self.events.append(("set_pasv", bool(value)))

    def pwd(self):
        return self.current

    def cwd(self, dirname):
        path = self._path(dirname)
        self.events.append(("cwd", path))
        if path not in self.directories:
            raise ftplib.error_perm("550 directory does not exist")
        self.current = path
        return "250 directory selected"

    def mkd(self, dirname):
        path = self._path(dirname)
        self._write_allowed(path)
        if posixpath.dirname(path) not in self.directories:
            raise ftplib.error_perm("550 parent directory does not exist")
        if path in self.directories or path in self.files:
            raise ftplib.error_perm("550 already exists")
        self.events.append(("mkdir", path))
        self.directories.add(path)
        return path

    def _children(self, path):
        if path not in self.directories:
            if path in self.files:
                return [path]
            raise ftplib.error_perm("550 path does not exist")
        return sorted(name for name in self.directories | self.files.keys()
                      if name != path and posixpath.dirname(name) == path)

    def mlsd(self, path="", facts=None):
        path = self._path(path)
        self.events.append(("mlsd", path))
        for name in self._children(path):
            directory = name in self.directories
            yield posixpath.basename(name), {"type": "dir" if directory else "file",
                                            "size": str(0 if directory else len(self.files[name])),
                                            "modify": "20261031000000"}

    def nlst(self, *args):
        path = self._path(args[-1] if args else "")
        self.events.append(("nlst", path))
        return self._children(path)

    def size(self, filename):
        path = self._path(filename)
        if path not in self.files:
            raise ftplib.error_perm("550 file does not exist")
        return len(self.files[path])

    def storbinary(self, cmd, fp, blocksize=8192, callback=None, rest=None):
        self._protected()
        if not cmd.startswith("STOR ") or rest is not None:
            raise AssertionError("Unexpected write command")
        path = self._path(cmd[5:])
        self._write_allowed(path)
        if posixpath.dirname(path) not in self.directories:
            raise ftplib.error_perm("550 missing upload directory")
        data = fp.read()
        if self.transform is not None:
            data = self.transform(path, data)
        self.events.append(("store", path, len(data)))
        self.files[path] = data
        if callback:
            for start in range(0, len(data), blocksize):
                callback(data[start:start + blocksize])
        return "226 upload complete"

    def retrbinary(self, cmd, callback, blocksize=8192, rest=None):
        self._protected()
        if not cmd.startswith("RETR ") or rest is not None:
            raise AssertionError("Unexpected read command")
        path = self._path(cmd[5:])
        if path.startswith("/htdocs/cms/private/"):
            raise AssertionError("The deployer must not fetch existing CMS database secrets")
        self.events.append(("read", path))
        if path not in self.files:
            raise ftplib.error_perm("550 file does not exist")
        data = self.files[path]
        for start in range(0, len(data), blocksize):
            callback(data[start:start + blocksize])
        return "226 download complete"

    def rename(self, fromname, toname):
        source, target = self._path(fromname), self._path(toname)
        self._write_allowed(source)
        self._write_allowed(target)
        if source not in self.files:
            raise ftplib.error_perm("550 rename source does not exist")
        if posixpath.dirname(target) not in self.directories:
            raise ftplib.error_perm("550 rename target directory does not exist")
        self.events.append(("rename", source, target))
        self.files[target] = self.files.pop(source)
        return "250 renamed"

    def delete(self, filename):
        self.events.append(("delete", self._path(filename)))
        raise AssertionError("Deletion/synchronization is forbidden for this release workflow")

    def rmd(self, dirname):
        self.events.append(("rmdir", self._path(dirname)))
        raise AssertionError("Directory deletion is forbidden")

    def voidcmd(self, cmd):
        self.events.append(("voidcmd", cmd))
        return "200 accepted"

    def quit(self):
        self.events.append(("quit",))
        self.closed = True
        return "221 closed"

    def close(self):
        self.closed = True

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.quit()


def load_release_module():
    """Import the hyphenated backend while forbidding any real network call."""
    if not MODULE_PATH.is_file():
        raise RuntimeError("deploy/ftp-release.py API is not ready")
    spec = importlib.util.spec_from_file_location("kosmoport_ftp_release_fixture", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    source = MODULE_PATH.read_bytes()
    module._fixture_source_sha256 = hashlib.sha256(source).hexdigest()
    with mock.patch.object(socket, "create_connection", side_effect=AssertionError("Real networking is forbidden")):
        exec(compile(source, str(MODULE_PATH), "exec"), module.__dict__)
    return module


PRIVATE = ("app", "config", "storage", "migrations", "bin", "tools", "docs", "deploy", "integrations")
DENY = b"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"


def image_bytes(color=(60, 100, 140, 255), size=(16, 12)):
    output = io.BytesIO()
    Image.new("RGBA", size, color).save(output, format="WEBP", lossless=True)
    return output.getvalue()


def source_files(extra=None):
    files = {".htaccess": (ROOT / "deploy/root.htaccess").read_bytes(),
             "app/bootstrap.php": b"<?php\ndeclare(strict_types=1);\n// Innocuous fixture.\n",
             "public/index.php": b"<?php echo 'fixture';\n",
             "public/api/index.php": b"<?php echo '{}';\n",
             "public/crm/index.php": b"<?php echo 'fixture crm';\n",
             "public/assets/app.js": b"console.log('fixture');\n",
             "public/assets/site-data.json": b'{"synthetic":true}\n',
             "public/assets/font-main.woff2": b"wOF2\0\1\0\0fixture-font-bytes",
             "public/assets/hero.webp": image_bytes(),
             "config/config.example.php": b"<?php return ['base_path'=>'/kosmoport'];\n",
             "migrations/001_core.sql": b"CREATE TABLE kcrm_fixture(id INT PRIMARY KEY);\n"}
    for directory in PRIVATE:
        files[directory + "/.htaccess"] = DENY
    files.update(extra or {})
    return files


def package_zip(path, files, symlink=None, source_manifest=None):
    members = dict(files)
    members["SOURCE-PACKAGE.json"] = json.dumps({
        "format": "kosmoport-source-v1", "created_at": "2026-10-31T00:00:00Z",
        "php_minimum": "8.2", "contains_business_data": False,
        "contains_live_configuration": False, "source_files": len(members),
    }, separators=(",", ":")).encode()
    if source_manifest is not None:
        members["SOURCE-PACKAGE.json"] = source_manifest
    checksums = "".join(hashlib.sha256(data).hexdigest() + "  " + name + "\n"
                        for name, data in sorted(members.items()))
    members["CHECKSUMS.sha256"] = checksums.encode()
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in members.items():
            if name == symlink:
                info = zipfile.ZipInfo(name)
                info.create_system = 3
                info.external_attr = (stat.S_IFLNK | 0o777) << 16
                archive.writestr(info, data)
            else:
                archive.writestr(name, data)
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


class ReleaseSafety(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.backend = load_release_module()

    def setUp(self):
        self.network_guard = mock.patch.object(socket, "create_connection",
                                               side_effect=AssertionError("Real network is forbidden"))
        self.network_guard.start()
        self.addCleanup(self.network_guard.stop)
        runtime_root = ROOT / "tests/migration-ftp-runtime"
        runtime_root.mkdir(exist_ok=True)
        # Python's Windows mkdtemp(mode=0700) strips the sandbox's inherited SID
        # permissions. A unique ordinary mkdir keeps the workspace ACL intact.
        self.directory = runtime_root / ("fixture-" + secrets.token_hex(12))
        self.directory.mkdir()

        def cleanup():
            if self.directory.resolve().parent != runtime_root.resolve():
                raise AssertionError("Refused fixture cleanup outside the owned runtime directory")
            shutil.rmtree(self.directory)

        self.addCleanup(cleanup)
        self.template = (ROOT / "config/infinityfree.example.php").read_bytes()
        self.setup_hash = hashlib.sha256(b"independent synthetic setup token").hexdigest()

    def package(self, extra=None):
        files = source_files(extra)
        digest = hashlib.sha256(b"".join(name.encode() + b"\0" + data
                                        for name, data in sorted(files.items()))).hexdigest()
        return self.backend.Package(files=files, sha256=digest)

    def ftp(self, **kwargs):
        ftp = FakeFTPS(**kwargs)
        ftp.connect(HOST)
        ftp.login(USERNAME, FIXTURE_PASSWORD)
        ftp.prot_p()
        return ftp

    def deploy(self, ftp, package=None, initial=True):
        return self.backend.ReleaseDeployer(
            ftp, package or self.package(), config_template=self.template if initial else None,
            setup_hash=self.setup_hash if initial else None, source_ref="offline-fixture").deploy()

    def test_connect_uses_fixed_host_user_and_explicit_verified_tls(self):
        created = []

        def factory(*args, **kwargs):
            instance = FakeFTPS(*args, **kwargs)
            created.append(instance)
            return instance

        ftp = self.backend.connect_ftps(FIXTURE_PASSWORD, ftp_factory=factory)
        self.assertIs(ftp, created[0])
        self.assertIn(("connect", HOST, 21), ftp.events)
        self.assertIn(("login", USERNAME), ftp.events)
        self.assertIn(("prot_p",), ftp.events)
        self.assertTrue(ftp.control_tls and ftp.data_tls)
        self.assertIsInstance(ftp.context, ssl.SSLContext)
        self.assertTrue(ftp.context.check_hostname)
        self.assertEqual(ftp.context.verify_mode, ssl.CERT_REQUIRED)

    def test_source_zip_checksums_and_inventory_load_without_network(self):
        path = self.directory / "valid.zip"
        expected = package_zip(path, source_files())
        package = self.backend.load_package(path, expected)
        self.assertEqual(package.sha256, expected)
        self.assertEqual(package.files["public/assets/site-data.json"], b'{"synthetic":true}\n')
        with self.assertRaises(self.backend.DeploymentError):
            self.backend.load_package(path, "0" * 64)

    def test_untrusted_package_paths_private_data_and_controls_are_refused(self):
        rejected = ["../escape.php", "/absolute.php", "C:/escape.php", "public\\escape.php",
                    "public/../config/config.php", ".user.ini", ".git/config",
                    "public/.user.ini", "app/.user.ini", "public/php.ini", "public/.htpasswd",
                    "public/assets/.htaccess", "app/nested/.htaccess",
                    "config/config.php", "config/runtime.private.json", "storage/documents/live.pdf",
                    "tests/runtime/private.json", "dumps/database.sql", "backups/business.zip",
                    ".kosmoport-deploy.json"]
        for index, name in enumerate(rejected):
            with self.subTest(path=name):
                path = self.directory / ("unsafe-" + str(index) + ".zip")
                expected = package_zip(path, source_files({name: b"synthetic private/control fixture"}))
                with self.assertRaises(self.backend.DeploymentError):
                    self.backend.load_package(path, expected)
        path = self.directory / "symlink.zip"
        name = "public/assets/link.css"
        expected = package_zip(path, source_files({name: b"../../config/config.php"}), symlink=name)
        with self.assertRaises(self.backend.DeploymentError):
            self.backend.load_package(path, expected)

    def test_source_manifest_must_be_valid_and_explicitly_source_only(self):
        for index, manifest in enumerate([b"not JSON", b'{"format":"foreign"}',
                                         b'{"format":"kosmoport-source-v1","contains_business_data":true,"contains_live_configuration":false}',
                                         b'{"format":"kosmoport-source-v1","contains_business_data":false,"contains_live_configuration":true}']):
            path = self.directory / ("manifest-" + str(index) + ".zip")
            expected = package_zip(path, source_files(), source_manifest=manifest)
            with self.subTest(manifest=index), self.assertRaises(self.backend.DeploymentError):
                self.backend.load_package(path, expected)

    def test_nonempty_foreign_root_and_foreign_marker_refuse_before_upload(self):
        for marker in [None, {"application": "foreign-app", "root": TARGET, "version": 1}]:
            files = {TARGET + "/keep.php": b"existing foreign application"}
            if marker is not None:
                files[TARGET + "/" + self.backend.MARKER_NAME] = json.dumps(marker).encode()
            ftp = self.ftp(files=files)
            original = dict(ftp.files)
            with self.assertRaises(self.backend.DeploymentError):
                self.deploy(ftp)
            self.assertEqual(ftp.files, original)
            self.assertFalse(any(event[0] in ("store", "rename", "delete") for event in ftp.events))

    def test_first_deploy_guards_precede_code_config_and_atomic_publication(self):
        ftp = self.ftp()
        report = self.deploy(ftp)
        published = [(index, event) for index, event in enumerate(ftp.events) if event[0] == "rename"]
        guard_names = {TARGET + "/.htaccess"} | {TARGET + "/" + name + "/.htaccess" for name in PRIVATE}
        guard_indexes = {event[2]: index for index, event in published if event[2] in guard_names}
        self.assertEqual(set(guard_indexes), guard_names)
        code_indexes = [index for index, event in published
                        if event[2].endswith((".php", ".js", ".woff2", ".webp"))]
        self.assertTrue(code_indexes)
        self.assertLess(max(guard_indexes.values()), min(code_indexes))
        final_paths = {TARGET + "/" + name for name in self.package().files}
        final_paths |= {TARGET + "/config/config.php", TARGET + "/" + self.backend.MARKER_NAME}
        for index, event in enumerate(ftp.events):
            if event[0] == "store":
                self.assertNotIn(event[1], final_paths, "Final files must be published by same-directory rename")
            if event[0] == "rename":
                self.assertEqual(posixpath.dirname(event[1]), posixpath.dirname(event[2]))
                self.assertTrue(event[1].startswith(TARGET + "/"))
                self.assertIn(("read", event[1]), ftp.events[:index], "Readback must precede publication")
        self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))
        marker = json.loads(ftp.files[TARGET + "/" + self.backend.MARKER_NAME])
        self.assertEqual(marker["application"], "kosmoport-crm")
        self.assertEqual(marker["root"], TARGET)
        self.assertEqual(marker["repository"], "ivankirillov414-art/profisport-site")
        self.assertEqual(marker["status"], "verified")
        self.assertRegex(marker["instance_id"], r"^[a-f0-9]{32}$")
        self.assertTrue(marker["privacy_probe"]["path"].startswith("storage/tmp/privacy-probe-"))
        fields = ("deployment_nonce", "origin", "verified_at")
        self.assertEqual({name: report[name] for name in fields}, {name: marker[name] for name in fields})
        for name in fields:
            self.assertIsInstance(report[name], str)
            self.assertTrue(report[name])
        self.assertRegex(report["deployment_nonce"], r"^[a-f0-9]{32}$")
        self.assertEqual(report["origin"], self.backend.EXPECTED_ORIGIN)
        self.assertTrue(report["origin"].startswith("https://"))
        verified = datetime.fromisoformat(report["verified_at"].replace("Z", "+00:00"))
        self.assertIsNotNone(verified.tzinfo)
        self.assertEqual(verified.utcoffset().total_seconds(), 0)

    def test_updates_preserve_config_storage_and_unrelated_legacy_directives(self):
        legacy_bytes = b"Options -Indexes\nDirectoryIndex index.html\n# existing application directives\n"
        ftp = self.ftp(files={LEGACY + "/.htaccess": legacy_bytes,
                              "/htdocs/cms/private/config.php": b"PRIVATE-CMS-DO-NOT-READ"})
        self.deploy(ftp)
        private_config = b"<?php // independently maintained private configuration\n"
        document = b"synthetic existing document must survive updates"
        ftp.files[TARGET + "/config/config.php"] = private_config
        ftp.files[TARGET + "/storage/documents/existing.blob"] = document
        before = len(ftp.events)
        self.deploy(ftp, self.package({"public/assets/app.js": b"console.log('fixture v2');\n"}), initial=False)
        self.assertEqual(ftp.files[TARGET + "/config/config.php"], private_config)
        self.assertEqual(ftp.files[TARGET + "/storage/documents/existing.blob"], document)
        self.assertEqual(ftp.files[LEGACY + "/.htaccess"], legacy_bytes)
        self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))
        self.assertFalse(any(event[0] == "rename" and event[2] == TARGET + "/config/config.php"
                             for event in ftp.events[before:]))

    def test_modified_managed_file_and_new_upload_collision_are_refused(self):
        for kind in ("modified", "collision"):
            with self.subTest(kind=kind):
                ftp = self.ftp()
                self.deploy(ftp)
                if kind == "modified":
                    ftp.files[TARGET + "/public/index.php"] = b"<?php // concurrent independent edit"
                    package = self.package()
                else:
                    ftp.files[TARGET + "/public/new.php"] = b"<?php // existing unowned file"
                    package = self.package({"public/new.php": b"<?php // desired replacement"})
                original = dict(ftp.files)
                with self.assertRaises(self.backend.DeploymentError):
                    self.deploy(ftp, package, initial=False)
                self.assertEqual(ftp.files, original)

    def test_owned_marker_requires_all_fixed_identity_and_schema_fields(self):
        seed = self.ftp()
        self.deploy(seed)
        marker_path = TARGET + "/" + self.backend.MARKER_NAME
        marker = json.loads(seed.files[marker_path])
        for field, value in [("application", "foreign-app"), ("root", "/htdocs/foreign"),
                             ("repository", "foreign/repository"), ("version", "1"),
                             ("instance_id", "not-an-instance"), ("status", "unknown"),
                             ("package_sha256", "invalid")]:
            with self.subTest(field=field):
                changed = dict(marker)
                changed[field] = value
                files = dict(seed.files)
                files[marker_path] = json.dumps(changed).encode()
                ftp = self.ftp(files=files)
                original = dict(ftp.files)
                with self.assertRaises(self.backend.DeploymentError):
                    self.deploy(ftp, initial=False)
                self.assertEqual(ftp.files, original)

    def test_readback_is_exact_for_code_json_and_fonts(self):
        for name, expected in [("app/bootstrap.php", b"<?php echo 1;\n"),
                               ("public/data.json", b'{"a":1}\n'),
                               ("public/font.woff2", b"wOF2fixture-font")]:
            with self.subTest(path=name):
                self.assertIsInstance(self.backend.verify_readback(name, expected, expected), dict)
                with self.assertRaises(self.backend.DeploymentError):
                    self.backend.verify_readback(name, expected, expected + b" ")

    def test_webp_requires_dimensions_one_frame_and_max_per_channel_rms(self):
        original = image_bytes()
        tolerated = image_bytes((64, 100, 140, 255))
        result = self.backend.verify_readback("public/hero.webp", original, tolerated)
        self.assertIsInstance(result, dict)
        self.assertNotEqual(original, tolerated)
        # A +8 red-channel error has combined RGBA RMS=4 but maximum RMS=8.
        # It must be refused; an averaged-channel comparison is insufficient.
        for actual in [image_bytes((68, 100, 140, 255)), image_bytes(size=(15, 12)), b"not a WebP"]:
            with self.assertRaises(self.backend.DeploymentError):
                self.backend.verify_readback("public/hero.webp", original, actual)
        animated = io.BytesIO()
        Image.new("RGBA", (16, 12), (60, 100, 140, 255)).save(
            animated, format="WEBP", save_all=True,
            append_images=[Image.new("RGBA", (16, 12), (20, 30, 40, 255))], duration=100, loop=0)
        with self.assertRaises(self.backend.DeploymentError):
            self.backend.verify_readback("public/hero.webp", original, animated.getvalue())

    def test_corrupted_readback_never_publishes_the_php_file(self):
        def corrupt(path, data):
            return data + b"\nCORRUPTED" if data.startswith(b"<?php") else data

        ftp = self.ftp(transform=corrupt)
        with self.assertRaises(self.backend.DeploymentError):
            self.deploy(ftp)
        self.assertNotIn(TARGET + "/app/bootstrap.php", ftp.files)
        self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))

    def test_tolerated_webp_readback_remains_updatable_with_verified_ownership(self):
        def reencode(path, data):
            if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
                return image_bytes((64, 100, 140, 255))
            return data

        ftp = self.ftp(transform=reencode)
        self.deploy(ftp)
        self.deploy(ftp, initial=False)
        self.assertIn(TARGET + "/public/assets/hero.webp", ftp.files)
        self.assertNotEqual(ftp.files[TARGET + "/public/assets/hero.webp"], image_bytes())
        self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))

    def test_pending_deploy_resumes_only_the_same_reviewed_package_without_deletion(self):
        for same_package in (True, False):
            with self.subTest(same_package=same_package):
                damaged = [False]

                def once(path, data):
                    if not damaged[0] and data.startswith(b"<?php"):
                        damaged[0] = True
                        return data + b"\nCORRUPTED"
                    return data

                ftp = self.ftp(transform=once)
                with self.assertRaises(self.backend.DeploymentError):
                    self.deploy(ftp)
                marker_path = TARGET + "/" + self.backend.MARKER_NAME
                self.assertEqual(json.loads(ftp.files[marker_path])["status"], "claimed")
                ftp.transform = None
                if same_package:
                    self.deploy(ftp)
                    self.assertEqual(json.loads(ftp.files[marker_path])["status"], "verified")
                    self.assertIn(TARGET + "/config/config.php", ftp.files)
                    self.assertEqual(ftp.files[TARGET + "/app/bootstrap.php"], self.package().files["app/bootstrap.php"])
                else:
                    original = dict(ftp.files)
                    with self.assertRaises(self.backend.DeploymentError):
                        self.deploy(ftp, self.package({"public/assets/app.js": b"console.log('different release');\n"}))
                    self.assertEqual(ftp.files, original)
                self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))

    def test_manual_destination_edit_during_upload_is_preserved_before_rename(self):
        ftp = self.ftp()
        self.deploy(ftp)
        destination = TARGET + "/public/assets/app.js"
        concurrent_bytes = b"// independent concurrent manual edit\n"

        def concurrent_edit(path, data):
            if data == b"console.log('fixture v2');\n":
                ftp.files[destination] = concurrent_bytes
            return data

        ftp.transform = concurrent_edit
        with self.assertRaises(self.backend.DeploymentError):
            self.deploy(ftp, self.package({"public/assets/app.js": b"console.log('fixture v2');\n"}), initial=False)
        self.assertEqual(ftp.files[destination], concurrent_bytes)

    def test_mlsd_linked_ancestor_is_rejected_before_updates(self):
        class LinkedInventory(FakeFTPS):
            inject = False

            def mlsd(self, path="", facts=None):
                for name, info in super().mlsd(path, facts):
                    if self.inject and self._path(path) == TARGET and name == "public":
                        info = {"type": "OS.unix=slink", "size": "0"}
                    yield name, info

        ftp = LinkedInventory()
        ftp.connect(HOST); ftp.login(USERNAME, FIXTURE_PASSWORD); ftp.prot_p()
        self.deploy(ftp)
        original = dict(ftp.files)
        ftp.inject = True
        with self.assertRaises(self.backend.DeploymentError):
            self.deploy(ftp, initial=False)
        self.assertEqual(ftp.files, original)

    def test_initial_config_is_trusted_loader_with_independent_hash_and_server_randomness(self):
        instance = "12" * 16
        data = self.backend.build_initial_config(self.template, self.setup_hash, instance)
        text = data.decode("utf-8")
        self.assertIn(self.setup_hash, text)
        self.assertIn("random_bytes", text)
        self.assertIn("cms/private/config.php", text)
        self.assertNotIn("GENERATED_ON_FIRST_DEPLOYMENT", text)
        self.assertNotIn("SET_TO_INDEPENDENT_SETUP_TOKEN_SHA256", text)
        self.assertNotIn("independent synthetic setup token", text)
        self.assertNotIn("PRIVATE-CMS-DO-NOT-READ", text)
        self.assertNotRegex(text, r"(?i)SELECT\s+.+(?:ps_cms_|users)|password_hash\s*=>")
        for bad in ("", "x" * 64, "a" * 63, "a" * 65, "a" * 64 + "\n"):
            with self.assertRaises(self.backend.DeploymentError):
                self.backend.build_initial_config(self.template, bad, instance)

    def test_initial_config_execution_is_private_stable_and_has_no_cms_user_import(self):
        php = Path(os.environ.get("KCRM_QA_PHP", r"C:\Users\Izop300520251\Documents\ChatGPT\cfqn ghjabcgjhn\tools\php-runtime\php.exe"))
        self.assertTrue(php.is_file(), "A local PHP CLI is required for the trusted-loader fixture")
        project = self.directory / "host/htdocs/kosmoport"
        config = project / "config/config.php"
        config.parent.mkdir(parents=True)
        (project / "storage").mkdir()
        cms_config = project.parent / "cms/private/config.php"
        cms_config.parent.mkdir(parents=True)
        cms_config.write_text("<?php return ['db_host'=>'127.0.0.1','db_name'=>'cms_fixture_only',"
                              "'db_user'=>'fixture_user','db_pass'=>'synthetic-db-password',"
                              "'users'=>[['email'=>'cms@example.invalid','password_hash'=>'CMS-USER-HASH']],"
                              "'accounts'=>[['password'=>'CMS-USER-PASSWORD']]];", encoding="utf-8")
        config.write_bytes(self.backend.build_initial_config(self.template, self.setup_hash, "12" * 16))
        probe = ("function fixture_config_load($path): array { $key='caller_sentinel'; "
                 "$loaded=require $path; return ['value'=>$loaded,'caller_key'=>$key]; } "
                 "$fixture=fixture_config_load($argv[1]); $v=$fixture['value']; "
                 "echo json_encode(['caller_key'=>$fixture['caller_key'],'key_length'=>strlen($v['app_key']),"
                 "'key_fingerprint'=>hash('sha256',$v['app_key']),'setup'=>$v['setup_key_hash'],"
                 "'privacy'=>$v['documents']['allow_webroot_storage']??null,"
                 "'storage_allowed'=>$v['storage']['allow_webroot_storage']??null,"
                 "'http_verified'=>$v['storage']['http_denial_verified']??null,"
                 "'copied_accounts'=>isset($v['users'])||isset($v['accounts'])||isset($v['password_hash'])]);")
        # Even a regression in generated PHP cannot open a network connection:
        # no DB/socket/cURL extensions, URL wrappers off, network/exec functions disabled.
        disabled = "fsockopen,pfsockopen,stream_socket_client,shell_exec,exec,passthru,system,proc_open,popen"
        command = [str(php), "-n", "-d", "allow_url_fopen=0", "-d", "disable_functions=" + disabled,
                   "-r", probe, str(config)]
        values = []
        for _ in range(2):
            result = subprocess.run(command, capture_output=True, text=True, timeout=20)
            self.assertEqual(result.returncode, 0, "Trusted config loader fixture must execute without an error")
            values.append(json.loads(result.stdout))
        self.assertGreaterEqual(values[0]["key_length"], 32)
        self.assertEqual(values[0]["caller_key"], "caller_sentinel")
        self.assertEqual(values[1]["caller_key"], "caller_sentinel")
        self.assertEqual(values[0]["key_fingerprint"], values[1]["key_fingerprint"])
        self.assertEqual(values[0]["setup"], self.setup_hash)
        self.assertIs(values[0]["privacy"], False)
        self.assertIs(values[0]["storage_allowed"], False)
        self.assertIs(values[0]["http_verified"], False)
        self.assertFalse(values[0]["copied_accounts"])
        guards = {name: source_files()[name] for name in self.backend.GUARDS}
        for name, contents in guards.items():
            path = project / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(contents)
        receipt = {"allowed": True, "instance_id": "12" * 16,
                   "deployment_nonce": "56" * 16, "package_sha256": self.package().sha256,
                   "origin": self.backend.EXPECTED_ORIGIN,
                   "guard_sha256": {name: hashlib.sha256(contents).hexdigest()
                                    for name, contents in guards.items()}}
        local_marker = {"status": "verified", "deployment_nonce": receipt["deployment_nonce"],
                        "package_sha256": receipt["package_sha256"]}
        marker_path = project / ".kosmoport-deploy.json"
        marker_path.write_text(json.dumps(local_marker), encoding="utf-8")
        receipt_path = project / "config/documents-privacy.php"

        def write_receipt(value):
            # Synthetic private fixture only, not evidence of an actual host check.
            encoded = json.dumps(json.dumps(value, separators=(",", ":")))
            receipt_path.write_text("<?php return json_decode(" + encoded + ",true,16,JSON_THROW_ON_ERROR);", encoding="utf-8")

        def run_probe():
            result = subprocess.run(command, capture_output=True, text=True, timeout=20)
            self.assertEqual(result.returncode, 0, "Private receipt fixture must execute")
            value = json.loads(result.stdout)
            self.assertEqual(value["caller_key"], "caller_sentinel")
            return value

        write_receipt(receipt)
        allowed = run_probe()
        self.assertIs(allowed["privacy"], True)
        self.assertIs(allowed["storage_allowed"], True)
        self.assertIs(allowed["http_verified"], True)
        self.assertEqual(allowed["key_fingerprint"], values[0]["key_fingerprint"])
        (project / ".htaccess").write_bytes(guards[".htaccess"] + b"\n# changed after verification\n")
        changed = run_probe()
        self.assertIs(changed["privacy"], False)
        self.assertIs(changed["storage_allowed"], False)
        self.assertIs(changed["http_verified"], False)
        (project / ".htaccess").write_bytes(guards[".htaccess"])
        receipt["instance_id"] = "34" * 16
        write_receipt(receipt)
        stale = run_probe()
        self.assertIs(stale["privacy"], False)
        self.assertIs(stale["storage_allowed"], False)
        self.assertIs(stale["http_verified"], False)
        receipt["instance_id"] = "12" * 16
        write_receipt(receipt)
        local_marker["deployment_nonce"] = "78" * 16
        marker_path.write_text(json.dumps(local_marker), encoding="utf-8")
        redeployed = run_probe()
        self.assertIs(redeployed["privacy"], False)
        self.assertIs(redeployed["storage_allowed"], False)
        self.assertIs(redeployed["http_verified"], False)

    def test_redirect_is_explicit_scoped_reversible_and_preserves_original_rules(self):
        original = b"Options -Indexes\nDirectoryIndex index.html\n# independent existing rule\n"
        ftp = self.ftp(files={LEGACY + "/.htaccess": original})
        self.deploy(ftp)
        self.backend.apply_redirect(ftp, enable=True)
        modified = ftp.files[LEGACY + "/.htaccess"].decode()
        self.assertIn(original.decode(), modified)
        self.assertIn("302", modified)
        patterns = []
        for line in modified.splitlines():
            if line.strip().startswith("RewriteRule "):
                patterns.append(line.strip().split()[1])
        import re
        self.assertTrue(any(re.match(pattern, "") for pattern in patterns))
        self.assertTrue(any(re.match(pattern, "index.html") for pattern in patterns))
        for unrelated in ("crm/", "api/index.php", "assets/site.css", "about.html", "index.html/child", "other/index.html"):
            self.assertFalse(any(re.match(pattern, unrelated) for pattern in patterns), unrelated)
        self.backend.apply_redirect(ftp, enable=False)
        self.assertEqual(ftp.files[LEGACY + "/.htaccess"], original)
        self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))

    def test_redirect_rejects_an_unowned_or_unverified_application(self):
        original = b"Options -Indexes\n"
        ftp = self.ftp(files={LEGACY + "/.htaccess": original})
        with self.assertRaises(self.backend.DeploymentError):
            self.backend.apply_redirect(ftp, enable=True)
        self.assertEqual(ftp.files[LEGACY + "/.htaccess"], original)
        self.deploy(ftp)
        marker_path = TARGET + "/" + self.backend.MARKER_NAME
        marker = json.loads(ftp.files[marker_path])
        marker["status"] = "claimed"
        ftp.files[marker_path] = json.dumps(marker).encode()
        with self.assertRaises(self.backend.DeploymentError):
            self.backend.apply_redirect(ftp, enable=True)
        self.assertEqual(ftp.files[LEGACY + "/.htaccess"], original)

    def test_redirect_recovery_preserves_all_directives_without_new_application_marker(self):
        for marker in (None, b"corrupt marker, not JSON"):
            for original in (b"Options -Indexes\r\nDirectoryIndex index.html\r\n# original rules\r\n",
                             b"\xef\xbb\xbfOptions -Indexes\n# original BOM and directives\n"):
                with self.subTest(marker=marker is not None, bom=original.startswith(b"\xef\xbb\xbf")):
                    prefix = b"\xef\xbb\xbf" if original.startswith(b"\xef\xbb\xbf") else b""
                    body = original[len(prefix):]
                    files = {LEGACY + "/.htaccess": prefix + self.backend.REDIRECT_BLOCK.encode() + body}
                    if marker is not None:
                        files[TARGET + "/" + self.backend.MARKER_NAME] = marker
                    ftp = self.ftp(files=files)
                    target_before = {path: data for path, data in ftp.files.items() if path.startswith(TARGET + "/")}
                    report = self.backend.apply_redirect(ftp, enable=False)
                    self.assertTrue(report["ok"])
                    self.assertEqual(ftp.files[LEGACY + "/.htaccess"], original)
                    self.assertEqual({path: data for path, data in ftp.files.items() if path.startswith(TARGET + "/")}, target_before)
                    for event in ftp.events:
                        if event[0] in ("read", "store"):
                            self.assertFalse(event[1].startswith(TARGET + "/"), "Recovery must not depend on the new application")
                        if event[0] == "rename":
                            self.assertEqual(event[2], LEGACY + "/.htaccess")
                    self.assertFalse(any(event[0] in ("delete", "rmdir") for event in ftp.events))

    def test_redirect_recovery_rejects_modified_managed_blocks_before_writes(self):
        for block in (self.backend.REDIRECT_BLOCK.replace("R=302", "R=301"),
                      self.backend.REDIRECT_BLOCK.replace("/kosmoport/", "/foreign/")):
            original = (block + "Options -Indexes\n# independent rules\n").encode()
            ftp = self.ftp(files={LEGACY + "/.htaccess": original})
            with self.assertRaises(self.backend.DeploymentError):
                self.backend.apply_redirect(ftp, enable=False)
            self.assertEqual(ftp.files[LEGACY + "/.htaccess"], original)
            self.assertFalse(any(event[0] in ("store", "rename", "delete", "rmdir") for event in ftp.events))
    def proof(self, marker):
        origin = marker["origin"]
        paths = ["/kosmoport/" + marker["privacy_probe"]["path"],
                 "/kosmoport/" + marker["document_probe"]["path"],
                 "/kosmoport/config/config.php", "/kosmoport/app/bootstrap.php"]
        public_url = origin + "/kosmoport/" + marker["public_control"]["path"].removeprefix("public/")
        return {"kind": "interactive-browser-observation", "instance_id": marker["instance_id"],
                "package_sha256": marker["package_sha256"], "guard_sha256": marker["guard_sha256"],
                "deployment_nonce": marker["deployment_nonce"],
                "origin": origin, "checked_at": datetime.now(timezone.utc).isoformat(),
                "private_gets": [{"method": "GET", "path": path, "url": origin + path,
                                  "final_url": origin + path, "status": 403,
                                  "challenge": False, "network_error": False,
                                  "evidence": "SYNTHETIC unit-test observation; no live browser"} for path in paths],
                "public_control": {"method": "GET", "url": public_url, "final_url": public_url,
                                   "status": 200, "body_sha256": marker["public_control"]["sha256"],
                                   "challenge": False, "network_error": False,
                                   "evidence": "SYNTHETIC positive control; no live browser"}}

    def test_document_storage_stays_closed_without_valid_current_explicit_browser_attestation(self):
        ftp = self.ftp()
        self.deploy(ftp)
        marker = json.loads(ftp.files[TARGET + "/" + self.backend.MARKER_NAME])
        proof = self.proof(marker)
        self.assertNotIn(TARGET + "/config/documents-privacy.php", ftp.files)
        invalid = []
        for property, value in [("status", 200), ("challenge", True), ("network_error", True),
                                ("method", "HEAD"), ("final_url", "https://foreign.example.invalid/")]:
            candidate = deepcopy(proof)
            candidate["private_gets"][0][property] = value
            invalid.append(candidate)
        candidate = deepcopy(proof); candidate["private_gets"][0]["path"] += "-wrong-nonce"; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["private_gets"].pop(); invalid.append(candidate)
        candidate = deepcopy(proof); candidate["instance_id"] = "00" * 16; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["deployment_nonce"] = "00" * 16; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["origin"] = "https://foreign.example.invalid"; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["package_sha256"] = "00" * 32; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["guard_sha256"] = {}; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["checked_at"] = "2020-01-01T00:00:00Z"; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["public_control"]["body_sha256"] = "00" * 32; invalid.append(candidate)
        candidate = deepcopy(proof); candidate["public_control"]["challenge"] = True; invalid.append(candidate)
        original = dict(ftp.files)
        with self.assertRaises(self.backend.DeploymentError):
            self.backend.enable_document_storage(ftp, proof, operator_confirmed=False)
        for index, candidate in enumerate(invalid):
            with self.subTest(proof=index), self.assertRaises(self.backend.DeploymentError):
                self.backend.enable_document_storage(ftp, candidate, operator_confirmed=True)
            self.assertEqual(ftp.files, original)
        report = self.backend.enable_document_storage(ftp, proof, operator_confirmed=True)
        self.assertTrue(report["operator_attestation"])
        self.assertFalse(report["independent_browser_proof"])
        self.assertTrue(report["document_storage_opt_in_written"])
        self.assertIn(TARGET + "/config/documents-privacy.php", ftp.files)

    def test_cli_dry_plan_never_connects_and_public_install_metadata_cannot_contain_a_token(self):
        path = self.directory / "source.zip"
        expected = package_zip(path, source_files())
        install = self.directory / "install-public.json"
        install.write_text(json.dumps({"setup_key_hash": self.setup_hash}), encoding="utf-8")
        args = ["--package", str(path), "--expected-sha256", expected,
                "--config-template", str(ROOT / "config/infinityfree.example.php"),
                "--install-public", str(install)]
        with mock.patch.object(self.backend, "connect_ftps", side_effect=AssertionError("Dry plan connected")) as connector:
            output = io.StringIO()
            with redirect_stdout(output):
                self.assertEqual(self.backend.main(args), 0)
            report = json.loads(output.getvalue())
            self.assertEqual(report["mode"], "offline-plan")
            self.assertFalse(report["network_used"])
            connector.assert_not_called()
            versioned = {"format": "kosmoport-install-public-v1", "setup_key_sha256": self.setup_hash,
                         "base_path": "/kosmoport", "contains_secret": False}
            install.write_text(json.dumps(versioned), encoding="utf-8")
            with redirect_stdout(io.StringIO()):
                self.assertEqual(self.backend.main(args), 0)
            connector.assert_not_called()
            install.write_text(json.dumps({"setup_key_hash": self.setup_hash, "setup_token": "not-for-source"}), encoding="utf-8")
            with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                self.assertEqual(self.backend.main(args + ["--apply"]), 1)
            connector.assert_not_called()
            install.write_text(json.dumps(versioned), encoding="utf-8")
            missing = self.directory / "not-present.json"
            bad_template = self.directory / "bad-template.php"
            bad_template.write_bytes(b"<?php return [];\n")
            for replacement in (["--install-public", str(missing)],
                                ["--config-template", str(missing)],
                                ["--config-template", str(bad_template)]):
                changed_args = list(args)
                index = changed_args.index(replacement[0])
                changed_args[index + 1] = replacement[1]
                with self.subTest(preflight=replacement[1]), redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                    self.assertEqual(self.backend.main(changed_args + ["--apply"]), 1)
                connector.assert_not_called()

    def test_install_manifest_supports_exact_coordinator_schema_and_rejects_tokens_duplicates(self):
        versioned = {"format": "kosmoport-install-public-v1", "setup_key_sha256": self.setup_hash,
                     "base_path": "/kosmoport", "contains_secret": False}
        self.assertEqual(self.backend.parse_install_manifest(json.dumps(versioned).encode()), self.setup_hash)
        self.assertEqual(self.backend.parse_install_manifest(json.dumps({"setup_key_hash": self.setup_hash}).encode()), self.setup_hash)
        invalid = []
        for property, value in (("format", "foreign-format"), ("base_path", "/other"),
                                ("contains_secret", True), ("contains_secret", 0),
                                ("setup_key_sha256", "x" * 64)):
            candidate = dict(versioned); candidate[property] = value
            invalid.append(json.dumps(candidate).encode())
        candidate = dict(versioned); candidate["setup_token"] = "synthetic-raw-token-not-for-source"
        invalid.append(json.dumps(candidate).encode())
        candidate = dict(versioned); candidate["unknown"] = "not allowed"
        invalid.append(json.dumps(candidate).encode())
        invalid.append(('{"setup_key_hash":"' + self.setup_hash + '","setup_key_hash":"' + self.setup_hash + '"}').encode())
        invalid.append(json.dumps({"setup_key_hash": self.setup_hash, "password": "synthetic"}).encode())
        for index, payload in enumerate(invalid):
            with self.subTest(manifest=index), self.assertRaises(self.backend.DeploymentError):
                self.backend.parse_install_manifest(payload)

    def test_cli_redirect_activates_existing_release_without_redeploy_or_privacy_reset(self):
        archive = self.directory / "reviewed-source.zip"
        expected = package_zip(archive, source_files())
        package = self.backend.load_package(archive, expected)
        legacy = b"Options -Indexes\r\nDirectoryIndex index.html\r\n# preserve old site\r\n"
        ftp = self.ftp(files={LEGACY + "/.htaccess": legacy})
        self.deploy(ftp, package)
        marker_path = TARGET + "/" + self.backend.MARKER_NAME
        marker = json.loads(ftp.files[marker_path])
        self.backend.enable_document_storage(ftp, self.proof(marker), operator_confirmed=True,
                                             expected_package_sha256=expected)
        self.assertIn(TARGET + "/config/documents-privacy.php", ftp.files)
        before = {path: data for path, data in ftp.files.items() if path.startswith(TARGET + "/")}
        event_start = len(ftp.events)
        args = ["--package", str(archive), "--expected-sha256", expected,
                "--apply", "--redirect-kosmosfera",
                "--config-template", str(self.directory / "missing-template.php"),
                "--install-public", str(self.directory / "missing-install.json")]
        with mock.patch.object(self.backend, "connect_ftps", return_value=ftp) as connector, \
             mock.patch.object(self.backend, "ReleaseDeployer", side_effect=AssertionError("Activation redeployed")) as deployer, \
             mock.patch.dict(os.environ, {"FTP_PASS": FIXTURE_PASSWORD}), \
             redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            self.assertEqual(self.backend.main(args), 0)
            connector.assert_called_once_with(FIXTURE_PASSWORD)
            deployer.assert_not_called()
        self.assertEqual({path: data for path, data in ftp.files.items() if path.startswith(TARGET + "/")}, before)
        self.assertEqual(json.loads(ftp.files[marker_path])["deployment_nonce"], marker["deployment_nonce"])
        self.assertIn(legacy, ftp.files[LEGACY + "/.htaccess"])
        for event in ftp.events[event_start:]:
            if event[0] == "store":
                self.assertEqual(posixpath.dirname(event[1]), LEGACY)
                self.assertTrue(posixpath.basename(event[1]).startswith(".htaccess"))
            elif event[0] == "rename":
                self.assertEqual(posixpath.dirname(event[1]), LEGACY)
                self.assertEqual(event[2], LEGACY + "/.htaccess")
            self.assertNotIn(event[0], ("delete", "rmdir"))

    def test_cli_redirect_rejects_another_package_or_missing_marker_without_redeploy(self):
        reviewed_zip = self.directory / "reviewed.zip"
        reviewed_sha = package_zip(reviewed_zip, source_files())
        reviewed_package = self.backend.load_package(reviewed_zip, reviewed_sha)
        other_zip = self.directory / "other-reviewed.zip"
        other_sha = package_zip(other_zip, source_files({"public/assets/app.js": b"console.log('different package');\n"}))
        for mode in ("different-package", "missing-marker"):
            with self.subTest(mode=mode):
                legacy = b"Options -Indexes\n# legacy site remains unchanged\n"
                ftp = self.ftp(files={LEGACY + "/.htaccess": legacy})
                if mode == "different-package":
                    self.deploy(ftp, reviewed_package)
                    selected_zip, selected_sha = other_zip, other_sha
                else:
                    selected_zip, selected_sha = reviewed_zip, reviewed_sha
                before = dict(ftp.files)
                start = len(ftp.events)
                args = ["--package", str(selected_zip), "--expected-sha256", selected_sha,
                        "--apply", "--redirect-kosmosfera",
                        "--config-template", str(self.directory / "missing-template.php"),
                        "--install-public", str(self.directory / "missing-install.json")]
                with mock.patch.object(self.backend, "connect_ftps", return_value=ftp) as connector, \
                     mock.patch.object(self.backend, "ReleaseDeployer", side_effect=AssertionError("Activation redeployed")) as deployer, \
                     mock.patch.dict(os.environ, {"FTP_PASS": FIXTURE_PASSWORD}), \
                     redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                    self.assertEqual(self.backend.main(args), 1)
                    connector.assert_called_once_with(FIXTURE_PASSWORD)
                    deployer.assert_not_called()
                self.assertEqual(ftp.files, before)
                self.assertFalse(any(event[0] in ("store", "rename", "delete", "rmdir") for event in ftp.events[start:]))

    def test_source_webp_validation_and_missing_pillow_fail_before_connect(self):
        animated = io.BytesIO()
        Image.new("RGBA", (16, 12), (60, 100, 140, 255)).save(
            animated, format="WEBP", save_all=True,
            append_images=[Image.new("RGBA", (16, 12), (20, 30, 40, 255))], duration=100, loop=0)
        install = self.directory / "webp-install.json"
        install.write_text(json.dumps({"setup_key_hash": self.setup_hash}), encoding="utf-8")
        for index, data in enumerate((b"invalid image bytes", animated.getvalue(), image_bytes())):
            path = self.directory / ("webp-source-" + str(index) + ".zip")
            expected = package_zip(path, source_files({"public/assets/hero.webp": data}))
            args = ["--package", str(path), "--expected-sha256", expected, "--apply",
                    "--config-template", str(ROOT / "config/infinityfree.example.php"),
                    "--install-public", str(install)]
            original_import = builtins.__import__

            def without_pillow(name, *arguments, **keywords):
                if name == "PIL" or name.startswith("PIL."):
                    raise ImportError("synthetic missing Pillow dependency")
                return original_import(name, *arguments, **keywords)

            with self.subTest(webp=index), \
                 mock.patch.object(self.backend, "connect_ftps", side_effect=AssertionError("Invalid WebP connected")) as connector, \
                 mock.patch.dict(os.environ, {"FTP_PASS": FIXTURE_PASSWORD}), \
                 redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                if index == 2:
                    with mock.patch.object(builtins, "__import__", side_effect=without_pillow):
                        self.assertEqual(self.backend.main(args), 1)
                else:
                    self.assertEqual(self.backend.main(args), 1)
                connector.assert_not_called()


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(ReleaseSafety)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    tested_hash = getattr(getattr(ReleaseSafety, "backend", None), "_fixture_source_sha256", None)
    final_hash = hashlib.sha256(MODULE_PATH.read_bytes()).hexdigest() if MODULE_PATH.is_file() else None
    unchanged = tested_hash is not None and tested_hash == final_hash
    report = {"ok": result.wasSuccessful() and unchanged, "tests": result.testsRun,
              "failures": len(result.failures), "errors": len(result.errors),
              "network_used": False, "ftp_used": "in-memory only",
              "verified_at_utc": datetime.now(timezone.utc).isoformat(),
              "deployer_sha256": tested_hash, "deployer_unchanged_during_test": unchanged,
              "cases": unittest.defaultTestLoader.getTestCaseNames(ReleaseSafety)}
    (ROOT / "tests/migration-ftp-results.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False))
    sys.exit(0 if report["ok"] else 1)
