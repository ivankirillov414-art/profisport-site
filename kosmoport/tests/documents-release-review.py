"""Offline review of ftp-release.py. All FTP operations use an in-memory fake.
No FTP connection, CMS execution, deployment or real business data is used.
"""
from __future__ import annotations

import contextlib
from datetime import datetime, timezone
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import secrets
import sys
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("documents_ftp_review_subject", ROOT / "deploy/ftp-release.py")
DEPLOY = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = DEPLOY
SPEC.loader.exec_module(DEPLOY)
FINDINGS = []


class MemoryFTP:
    def __init__(self):
        self.directories = {"/", "/htdocs"}
        self.files = {}
        self.writes = []
        self.reads = []

    def mlsd(self, path, facts=None):
        if path not in self.directories:
            raise DEPLOY.ftplib.error_perm("550 fixture path missing")
        prefix = path.rstrip("/") + "/"
        rows = {}
        for directory in self.directories:
            if directory.startswith(prefix) and directory != path:
                tail = directory[len(prefix):]
                if tail and "/" not in tail:
                    rows[tail] = {"type": "dir"}
        for filename, contents in self.files.items():
            if filename.startswith(prefix):
                tail = filename[len(prefix):]
                if tail and "/" not in tail:
                    rows[tail] = {"type": "file", "size": str(len(contents))}
        return list(rows.items())

    def check_write(self, path):
        if path != DEPLOY.REMOTE_ROOT and not path.startswith(DEPLOY.REMOTE_ROOT + "/"):
            raise AssertionError("Write attempted outside the new Kosmoport root")

    def mkd(self, path):
        self.check_write(path)
        self.writes.append(("mkdir", path))
        self.directories.add(path)

    def storbinary(self, command, stream):
        assert command.startswith("STOR ")
        path = command[5:]
        self.check_write(path)
        self.writes.append(("store", path))
        self.files[path] = stream.read()

    def retrbinary(self, command, callback):
        assert command.startswith("RETR ")
        path = command[5:]
        self.reads.append(path)
        callback(self.files[path])

    def rename(self, source, target):
        self.check_write(source)
        self.check_write(target)
        self.writes.append(("rename", target))
        self.files[target] = self.files.pop(source)


def source_package(body=b"<?php echo 'generated fixture';", extra=None):
    files = {name: b"Require all denied\n" for name in DEPLOY.GUARDS}
    files[".htaccess"] = b"RewriteEngine On\n"
    files["public/index.php"] = body
    files["config/infinityfree.example.php"] = (ROOT / "config/infinityfree.example.php").read_bytes()
    files["SOURCE-PACKAGE.json"] = json.dumps({"format": "kosmoport-source-v1", "php_minimum": "8.2", "contains_business_data": False, "contains_live_configuration": False}).encode() + b"\n"
    files.update(extra or {})
    checksums = "".join(DEPLOY.digest(contents) + "  " + name + "\n" for name, contents in sorted(files.items())).encode()
    files["CHECKSUMS.sha256"] = checksums
    identity = hashlib.sha256(body + checksums).hexdigest()
    return DEPLOY.Package(files, identity)


def deploy(ftp, package=None):
    return DEPLOY.ReleaseDeployer(ftp, package or source_package(), config_template=(ROOT / "config/infinityfree.example.php").read_bytes(), setup_hash="1" * 64).deploy()


class FTPReleaseReview(unittest.TestCase):
    def test_fixed_root_and_unsafe_paths(self):
        self.assertEqual(DEPLOY.REMOTE_ROOT, "/htdocs/kosmoport")
        for name in ["../cms/config.php", "/htdocs/other", "a/../../b", "a\\b", "a//b", "a/./b", "a:b", "a\nfile"]:
            with self.subTest(path=name), self.assertRaises(DEPLOY.DeploymentError):
                DEPLOY.safe_name(name)
        ftp = MemoryFTP()
        runner = DEPLOY.ReleaseDeployer(ftp, source_package())
        with self.assertRaises(DEPLOY.DeploymentError):
            runner.ensure_dir("/htdocs/someone-else")
        self.assertEqual(ftp.writes, [])

    def test_foreign_occupied_root_refused_before_writes(self):
        ftp = MemoryFTP()
        ftp.directories.add(DEPLOY.REMOTE_ROOT)
        foreign = DEPLOY.REMOTE_ROOT + "/foreign.txt"
        ftp.files[foreign] = b"Do not touch"
        with self.assertRaises(DEPLOY.DeploymentError):
            deploy(ftp)
        self.assertEqual(ftp.writes, [])
        self.assertEqual(ftp.files[foreign], b"Do not touch")

    def test_foreign_marker_refused_before_writes(self):
        ftp = MemoryFTP()
        ftp.directories.add(DEPLOY.REMOTE_ROOT)
        ftp.files[DEPLOY.REMOTE_ROOT + "/" + DEPLOY.MARKER_NAME] = json.dumps({"application": "foreign-app", "root": DEPLOY.REMOTE_ROOT}).encode()
        with self.assertRaises(DEPLOY.DeploymentError):
            deploy(ftp)
        self.assertEqual(ftp.writes, [])

    def test_update_preserves_live_private_config_keys_and_documents(self):
        ftp = MemoryFTP()
        self.assertTrue(deploy(ftp)["ok"])
        live_config = DEPLOY.REMOTE_ROOT + "/config/config.php"
        live_key = DEPLOY.REMOTE_ROOT + "/config/app-key.php"
        live_document = DEPLOY.REMOTE_ROOT + "/storage/documents/generated.blob"
        ftp.files[live_config] = b"PRIVATE CONFIG MODIFIED ON SERVER"
        ftp.files[live_key] = b"PRIVATE APP KEY PLACEHOLDER"
        ftp.files[live_document] = b"Generated immutable file"
        ftp.reads.clear()
        result = deploy(ftp, source_package(b"<?php echo 'fixture second revision';"))
        self.assertTrue(result["config_preserved"])
        self.assertTrue(result["storage_preserved"])
        self.assertEqual(ftp.files[live_config], b"PRIVATE CONFIG MODIFIED ON SERVER")
        self.assertEqual(ftp.files[live_key], b"PRIVATE APP KEY PLACEHOLDER")
        self.assertEqual(ftp.files[live_document], b"Generated immutable file")
        self.assertNotIn(live_config, ftp.reads)
        self.assertNotIn(live_key, ftp.reads)
        self.assertNotIn(live_document, ftp.reads)
        self.assertTrue(all(path == DEPLOY.REMOTE_ROOT or path.startswith(DEPLOY.REMOTE_ROOT + "/") for _, path in ftp.writes))

    def test_unmanaged_collision_refused_before_writes(self):
        ftp = MemoryFTP()
        deploy(ftp)
        ftp.files[DEPLOY.REMOTE_ROOT + "/public/foreign.php"] = b"Unmanaged content"
        ftp.writes.clear()
        with self.assertRaises(DEPLOY.DeploymentError):
            deploy(ftp, source_package(extra={"public/foreign.php": b"Replacement refused"}))
        self.assertEqual(ftp.writes, [])
        self.assertEqual(ftp.files[DEPLOY.REMOTE_ROOT + "/public/foreign.php"], b"Unmanaged content")

    def test_offline_plan_never_connects_or_prints_ftp_password(self):
        package = source_package()
        with tempfile.TemporaryDirectory(prefix="kcrm-documents-release-review-") as temporary:
            folder = Path(temporary)
            archive = folder / "source.zip"
            with zipfile.ZipFile(archive, "w") as stream:
                for name, contents in package.files.items():
                    stream.writestr(name, contents)
            digest = DEPLOY.digest(archive.read_bytes())
            install = folder / "install-public.json"
            install.write_text(json.dumps({"setup_key_hash": "1" * 64}), encoding="utf-8")
            sentinel = "NOT_A_REAL_SECRET_" + secrets.token_hex(20)
            output = io.StringIO()
            with mock.patch.dict(os.environ, {"FTP_PASS": sentinel}), mock.patch.object(DEPLOY, "connect_ftps", side_effect=AssertionError("Network call in an offline plan")), contextlib.redirect_stdout(output):
                result = DEPLOY.main(["--package", str(archive), "--expected-sha256", digest, "--install-public", str(install)])
            self.assertEqual(result, 0)
            self.assertNotIn(sentinel, output.getvalue())
            self.assertFalse(json.loads(output.getvalue())["network_used"])

    def test_hosting_config_wires_the_core_storage_policy(self):
        template = (ROOT / "config/infinityfree.example.php").read_bytes()
        generated = DEPLOY.build_initial_config(template, "1" * 64, "2" * 32).decode()
        core = (ROOT / "app/core/config.php").read_text(encoding="utf-8")
        missing = [key for key in ["http_denial_verified", "'storage'"] if key not in generated]
        if "storage.http_denial_verified" in core and missing:
            FINDINGS.append({"id": "DEPLOY-CORE-STORAGE-POLICY", "severity": "deployment-blocker-fail-closed", "paths": ["deploy/ftp-release.py", "config/infinityfree.example.php", "app/core/config.php"], "summary": "Generated hosting config only wires documents.allow_webroot_storage; core requires storage.allow_webroot_storage and storage.http_denial_verified before even creating a session in webroot storage.", "missing_generated_keys": missing})
        self.assertEqual(missing, [], "The shared-host generated config does not satisfy the core verified-storage contract")

    def test_pre_enabled_policy_rejected_with_php_boolean_case(self):
        template = b"<?php return ['app_key'=>'GENERATED_ON_FIRST_DEPLOYMENT','setup_key_hash'=>'SET_TO_INDEPENDENT_SETUP_TOKEN_SHA256','storage'=>['allow_webroot_storage'=>TRUE,'http_denial_verified'=>TRUE],'documents'=>['allow_webroot_storage'=>false]];"
        try:
            DEPLOY.build_initial_config(template, "1" * 64, "2" * 32)
        except DEPLOY.DeploymentError:
            return
        FINDINGS.append({"id": "DEPLOY-PRIVACY-BOOLEAN-CASE", "severity": "low-defence-in-depth", "paths": ["deploy/ftp-release.py"], "summary": "The guard against a pre-enabled custom PHP storage policy checks lowercase true only; PHP TRUE is also true. The default reviewed template stays false, but an accidental/custom uppercase pre-enabled policy is accepted by the compiler. No FTP operation or generated PHP execution was performed."})
        self.fail("Compiler accepted an uppercase TRUE pre-enabled custom policy")


if __name__ == "__main__":
    source = ROOT / "deploy/ftp-release.py"
    before = hashlib.sha256(source.read_bytes()).hexdigest()
    template_path = ROOT / "config/infinityfree.example.php"
    template_before = hashlib.sha256(template_path.read_bytes()).hexdigest()
    program = unittest.main(verbosity=2, exit=False)
    result = program.result
    receipt = {"checked_at": datetime.now(timezone.utc).isoformat(), "scope": "offline import and in-memory FTP fake only; no network or remote writes", "tests_run": result.testsRun, "success": result.wasSuccessful(), "failures": [case.id() for case, _ in result.failures], "errors": [case.id() for case, _ in result.errors], "findings": FINDINGS, "source_sha256": hashlib.sha256(source.read_bytes()).hexdigest(), "template_sha256": hashlib.sha256(template_path.read_bytes()).hexdigest(), "source_stable": before == hashlib.sha256(source.read_bytes()).hexdigest() and template_before == hashlib.sha256(template_path.read_bytes()).hexdigest(), "network_used": False, "deployed": False}
    (ROOT / "tests/documents-release-review-results.json").write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    sys.exit(0 if result.wasSuccessful() else 1)
