"""Independent real-core setup/auth/CSRF/document security review.

Uses only freshly generated accounts and a newly named disposable MariaDB
database. Never reads or imports any CMS account or hosting credential.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
OPTIONS = None
BASE = "/security-documents"


class Client:
    def __init__(self, suite, port=None):
        self.suite = suite
        self.port = port or suite.port
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def request(self, route=None, *, method="GET", data=None, form=None, filename=None, content=None, headers=None, query=None, csrf=True, setup=False):
        path = BASE + ("/setup.php" if setup else "/api/index.php")
        args = ({} if setup else {"r": route}) | (query or {})
        if args:
            path += "?" + urllib.parse.urlencode(args)
        url = f"http://127.0.0.1:{self.port}" + path
        values = {"X-Documents-Security-Key": self.suite.access_key, "Accept": "application/json"}
        if method != "GET" and csrf and self.csrf:
            values["X-CSRF-Token"] = self.csrf
        body = None
        if filename is not None:
            boundary = "review" + secrets.token_hex(16)
            body = b""
            for key, value in (form or {}).items():
                body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + (content or b"") + f"\r\n--{boundary}--\r\n".encode()
            values["Content-Type"] = "multipart/form-data; boundary=" + boundary
        elif form is not None:
            body = urllib.parse.urlencode(form).encode()
            values["Content-Type"] = "application/x-www-form-urlencoded"
        elif data is not None:
            body = json.dumps(data, ensure_ascii=False).encode()
            values["Content-Type"] = "application/json"
        values.update(headers or {})
        request = urllib.request.Request(url, data=body, headers=values, method=method)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            result = (response.status, {k.lower(): v for k, v in response.headers.items()}, response.read())
        self.suite.responses.append(result[2])
        return result

    def bootstrap(self):
        result = self.request("auth/me")
        data = json.loads(result[2])["data"]
        self.csrf = data["csrf"]
        return result

    def login(self, email, password):
        self.bootstrap()
        result = self.request("auth/login", method="POST", data={"email": email, "password": password})
        value = json.loads(result[2])
        if value.get("ok"):
            self.csrf = value["data"]["csrf"]
        return result

    def upload(self, marker="file", **options):
        return self.request("documents/upload", method="POST", filename="fixture.txt", content=("Generated document " + marker + "\n").encode(), form={"title": "Тестовый документ " + marker, "category": "Проверка"}, **options)


class IntegratedDocumentSecurity(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="kcrm-documents-security-")
        cls.private = Path(cls.temp.name)
        (cls.private / "public").mkdir()
        cls.access_key = secrets.token_hex(32)
        cls.setup_key = secrets.token_hex(32)
        cls.app_key = secrets.token_hex(32)
        cls.owner_password = secrets.token_urlsafe(32)
        cls.sensitive = [cls.access_key, cls.setup_key, cls.app_key, cls.owner_password]
        cls.database = "kcrm_documents_security_" + secrets.token_hex(6)
        cls.responses = []
        cls.servers = []
        cls.role_users = {}
        cls.findings = []
        cls.start_hashes = cls.source_hashes()
        cls.php = [OPTIONS.php, "-d", "extension_dir=" + OPTIONS.extension_dir]
        modules = subprocess.check_output(cls.php + ["-m"], text=True).lower().split()
        for extension in ["pdo_mysql", "fileinfo", "zip"]:
            if extension not in modules:
                cls.php += ["-d", "extension=" + extension]
        runtime = json.loads(Path(OPTIONS.mysql_runtime).read_text(encoding="utf-8-sig"))
        runtime_path = runtime.get("runtime_path", "").replace("\\", "/")
        if runtime.get("host") != "127.0.0.1" or not any(marker in runtime_path for marker in ["/tests/migration-runtime/", "/tests/documents-security-runtime/"]):
            raise RuntimeError("Only the disposable loopback runtime is permitted")
        cls.engine = cls.admin("create")["server"]
        cls.log = open(cls.private / "server.log", "wb")
        cls.addClassCleanup(cls.cleanup)
        cls.config = {"environment": "test", "app_name": "Security fixture", "base_path": BASE, "storage_path": str(cls.private / "storage"), "app_key": cls.app_key, "setup_key_hash": hashlib.sha256(cls.setup_key.encode()).hexdigest(), "database": {"driver": "mysql", "host": "127.0.0.1", "port": runtime["port"], "name": cls.database, "username": runtime["user"], "password": runtime["password"], "charset": "utf8mb4"}, "session": {"name": "KCRMDOCREVIEW", "secure": "auto", "same_site": "Lax", "idle_seconds": 1800, "absolute_seconds": 43200}, "documents": {"max_upload_bytes": 1024 * 1024}}
        cls.sensitive.append(runtime["password"])
        cls.port = cls.start_server(cls.config, "main")
        cls.anon = Client(cls)
        initial = cls.anon.bootstrap()
        if initial[0] != 200:
            raise RuntimeError("Anonymous CSRF bootstrap failed")
        cls.setup_checks = []
        owner_input = {"setup_key": cls.setup_key, "name": "Новый тестовый владелец", "email": "security-owner@example.invalid", "password": cls.owner_password}
        attempts = [
            (cls.anon.request(method="POST", setup=True, data=owner_input, csrf=False), 403, "setup_without_csrf"),
            (cls.anon.request(method="POST", setup=True, data={**owner_input, "setup_key": "wrong-key"}), 403, "wrong_setup_key"),
            (cls.anon.request(method="POST", setup=True, data={k: v for k, v in owner_input.items() if k != "setup_key"}, query={"setup_key": "query-only-dummy"}), 403, "query_key_ignored"),
            (cls.anon.request(method="POST", setup=True, data={**owner_input, "password": "short"}), 422, "weak_owner_password"),
        ]
        for result, expected, name in attempts:
            if result[0] != expected:
                raise RuntimeError("Initial setup precondition failed: " + name + " (HTTP " + str(result[0]) + ")")
            cls.setup_checks.append(name)
        if cls.admin("stats")["tables"]:
            raise RuntimeError("Rejected setup attempts unexpectedly created database tables")
        created = cls.anon.request(method="POST", setup=True, data=owner_input)
        if created[0] != 201:
            raise RuntimeError("Independent initial setup did not create owner: " + created[2].decode(errors="replace"))
        cls.owner_id = json.loads(created[2])["data"]["user"]["id"]
        repeated = cls.anon.request(method="POST", setup=True, data=owner_input)
        if repeated[0] != 409:
            raise RuntimeError("Repeated initial setup was not refused")
        cls.setup_checks += ["independent_owner_created", "repeat_setup_refused"]
        cls.owner = Client(cls)
        if cls.owner.login(owner_input["email"], cls.owner_password)[0] != 200:
            raise RuntimeError("Fresh owner login failed")

    @classmethod
    def source_hashes(cls):
        files = [*sorted((ROOT / "app/core").glob("*.php")), ROOT / "app/bootstrap.php", ROOT / "public/setup.php", ROOT / "public/api/index.php", *sorted((ROOT / "app/documents").glob("*.php")), ROOT / "app/modules/documents.php", *sorted((ROOT / "migrations").glob("*.sql"))]
        return {str(path.relative_to(ROOT)).replace("\\", "/"): hashlib.sha256(path.read_bytes()).hexdigest() for path in files}

    @classmethod
    def admin(cls, mode):
        completed = subprocess.run(cls.php + [str(ROOT / "tests/documents-security-admin.php"), OPTIONS.mysql_runtime, cls.database, mode], capture_output=True, encoding="utf-8", timeout=25, check=True)
        return json.loads(completed.stdout)

    @classmethod
    def start_server(cls, config, label):
        config_json = cls.private / (label + ".private.json")
        config_json.write_text(json.dumps(config, ensure_ascii=False), encoding="utf-8")
        os.chmod(config_json, 0o600)
        config_php = cls.private / (label + ".private.php")
        config_php.write_text("<?php return json_decode(file_get_contents(__DIR__ . '/" + config_json.name + "'), true, flags: JSON_THROW_ON_ERROR);", encoding="utf-8")
        os.chmod(config_php, 0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        process = subprocess.Popen(cls.php + ["-d", "display_errors=0", "-d", "log_errors=1", "-d", "upload_max_filesize=12M", "-d", "post_max_size=13M", "-S", f"127.0.0.1:{port}", "-t", str(cls.private / "public"), str(ROOT / "tests/documents-security-router.php")], env={**os.environ, "KCRM_CONFIG": str(config_php), "KCRM_DOCUMENTS_SECURITY_KEY": cls.access_key}, stdout=cls.log, stderr=cls.log, creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
        cls.servers.append(process)
        deadline = time.monotonic() + 25
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                    return port
            except OSError:
                if process.poll() is not None:
                    break
                time.sleep(0.1)
        raise RuntimeError("Local review listener failed to start")

    @classmethod
    def cleanup(cls):
        cls.end_hashes = cls.source_hashes()
        for server in cls.servers:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
        cls.log.close()
        log = (cls.private / "server.log").read_text(errors="replace")
        cls.secret_leaks = any(secret and (secret in log or any(secret.encode() in body for body in cls.responses)) for secret in cls.sensitive)
        cls.admin("drop")
        cls.temp.cleanup()

    def ok(self, result, expected=200):
        accepted = expected if isinstance(expected, tuple) else (expected,)
        self.assertIn(result[0], accepted, self.safe_result(result))
        value = json.loads(result[2])
        self.assertTrue(value["ok"], value)
        for forbidden in ["password_hash", "session_version", "storage_key"]:
            self.assertNotIn('"' + forbidden + '"', result[2].decode(errors="replace"))
        return value["data"]

    def reject(self, result, status, code=None):
        self.assertEqual(result[0], status, self.safe_result(result))
        value = json.loads(result[2])
        self.assertFalse(value["ok"])
        if code:
            self.assertEqual(value["error"]["code"], code)
        self.assertNotIn(str(self.private), result[2].decode(errors="replace"))

    @staticmethod
    def safe_result(result):
        # Assertions never dump a successful auth response (which contains a
        # legitimate CSRF token) or arbitrary response bodies to stdout/stderr.
        try:
            value = json.loads(result[2])
            code = value.get('error', {}).get('code', 'success' if value.get('ok') else 'unknown')
        except (ValueError, AttributeError):
            code = 'non-json'
        return f'HTTP {result[0]}; response code {code}'

    def new_user(self, role, *, password=None):
        marker = secrets.token_hex(5)
        password = password or secrets.token_urlsafe(24)
        self.sensitive.append(password)
        user = self.ok(self.owner.request("users", method="POST", data={"name": "Новый сотрудник " + role, "email": marker + "@example.invalid", "role": role, "password": password}), (200, 201))
        client = Client(self)
        self.ok(client.login(user["email"], password))
        return user, password, client

    def test_01_setup_and_shared_schema(self):
        self.assertEqual(len(self.setup_checks), 6)
        stats = self.admin("stats")
        self.assertTrue(stats["owners_hashed"])
        for migration in ["001_core.sql", "010_workspace.sql", "020_documents.sql", "030_tickets.sql", "040_website.sql"]:
            self.assertIn(migration, stats["migrations"])
        self.assertEqual(stats["counts"]["kcrm_setup"], 1)
        self.assertTrue(any(a["action"] == "setup.owner_created" for a in stats["audits"]))
        html = self.anon.request(setup=True, headers={"Accept": "text/html"})
        self.assertEqual(html[0], 200)
        self.assertIn(b'type="password"', html[2])
        self.assertIn(b'method="post"', html[2])
        self.assertIn("no-store", html[1]["cache-control"])
        self.assertEqual(html[1]["x-frame-options"], "DENY")

    def test_02_cookie_csrf_rotation_logout_and_replay(self):
        client = Client(self)
        self.ok(client.bootstrap())
        anonymous_csrf = client.csrf
        anonymous_sid = next(cookie.value for cookie in client.jar)
        self.ok(client.login("security-owner@example.invalid", self.owner_password))
        authenticated_sid = next(cookie.value for cookie in client.jar)
        self.assertNotEqual(anonymous_sid, authenticated_sid)
        self.assertNotEqual(anonymous_csrf, client.csrf)
        self.reject(client.upload("old-csrf", headers={"X-CSRF-Token": anonymous_csrf}), 403, "CSRF_INVALID")
        cookie = next(cookie for cookie in client.jar)
        self.assertEqual(cookie.path, BASE + "/")
        self.assertTrue(cookie.has_nonstandard_attr("HttpOnly"))
        self.assertEqual(cookie.get_nonstandard_attr("SameSite"), "Lax")
        logged_out = self.ok(client.request("auth/logout", method="POST", data={}))
        client.csrf = logged_out["csrf"]
        self.assertNotEqual(authenticated_sid, next(cookie.value for cookie in client.jar))
        self.reject(client.request("documents"), 401, "AUTH_REQUIRED")
        replay = Client(self)
        self.reject(replay.request("documents", headers={"Cookie": "KCRMDOCREVIEW=" + authenticated_sid}), 401, "AUTH_REQUIRED")

    def test_03_real_role_matrix_upload_versions_and_download(self):
        first = None
        for role in ["owner", "admin", "manager", "document_manager", "reader", "cashier", "editor"]:
            with self.subTest(role=role):
                client = self.owner
                if role != "owner":
                    _, _, client = self.new_user(role)
                if role in ["cashier", "editor"]:
                    self.reject(client.request("documents"), 403)
                    self.reject(client.request("documents/versions", query={"id": "a" * 32}), 403)
                    self.reject(client.request("documents/download", query={"id": "a" * 32}), 403)
                else:
                    self.ok(client.request("documents"))
                if role in ["reader", "cashier", "editor"]:
                    before = self.admin("stats")["counts"]["kcrm_documents"]
                    self.reject(client.upload(role), 403)
                    self.assertEqual(self.admin("stats")["counts"]["kcrm_documents"], before)
                else:
                    uploaded = self.ok(client.upload(role), 201)
                    first = first or uploaded["document"]
        anon = Client(self)
        self.ok(anon.bootstrap())
        self.reject(anon.request("documents"), 401)
        self.reject(anon.upload("anonymous"), 401)
        _, _, reader = self.new_user("reader")
        result = reader.request("documents/download", query={"id": first["id"]})
        self.assertEqual(result[0], 200)
        self.assertEqual(hashlib.sha256(result[2]).hexdigest(), first["sha256"])
        self.assertIn("attachment", result[1]["content-disposition"])
        second = self.ok(self.owner.request("documents/upload", method="POST", form={"document_id": first["id"], "expected_version": "1"}, filename="new.txt", content=b"Generated new version\n"), 201)
        self.assertEqual(second["document"]["current_version"], 2)
        versions = self.ok(reader.request("documents/versions", query={"id": first["id"]}))
        self.assertEqual([x["version"] for x in versions["items"]], [2, 1])
        old = reader.request("documents/download", query={"id": first["id"], "version": "1"})
        self.assertEqual(old[2], result[2])
        self.assertTrue(any(a["action"] == "documents.download" and a["actor_id"] for a in self.admin("stats")["audits"]))

    def test_04_csrf_origin_size_and_no_cors(self):
        before = self.admin("stats")["counts"]["kcrm_documents"]
        self.reject(self.owner.upload("no-token", csrf=False), 403, "CSRF_INVALID")
        self.reject(self.owner.upload("bad-token", headers={"X-CSRF-Token": "0" * 64}), 403, "CSRF_INVALID")
        cross = self.owner.upload("foreign-origin", headers={"Origin": "https://attacker.invalid"})
        self.reject(cross, 403, "ORIGIN_FORBIDDEN")
        self.assertNotIn("access-control-allow-origin", cross[1])
        options = self.owner.request("documents/upload", method="OPTIONS", headers={"Origin": "https://attacker.invalid"})
        self.assertEqual(options[0], 405)
        self.assertNotIn("access-control-allow-origin", options[1])
        huge = self.owner.request("documents/upload", method="POST", form={"title": "Oversize"}, filename="large.txt", content=b"x" * (1024 * 1024 + 1))
        self.reject(huge, 413, "file_too_large")
        self.assertEqual(self.admin("stats")["counts"]["kcrm_documents"], before)

    def test_05_role_password_and_active_changes_revoke_sessions(self):
        user, password, client = self.new_user("reader")
        self.ok(client.request("documents"))
        user = self.ok(self.owner.request("users", method="POST", data={"id": user["id"], "expected_revision": user["revision"], "role": "editor"}))
        self.reject(client.request("documents"), 401)
        self.ok(client.login(user["email"], password))
        self.reject(client.request("documents"), 403)
        password2 = secrets.token_urlsafe(24)
        self.sensitive.append(password2)
        user = self.ok(self.owner.request("users", method="POST", data={"id": user["id"], "expected_revision": user["revision"], "role": "reader", "password": password2}))
        self.reject(client.request("documents"), 401)
        self.reject(client.login(user["email"], password), 401, "LOGIN_FAILED")
        self.ok(client.login(user["email"], password2))
        self.ok(client.request("documents"))
        self.ok(self.owner.request("users", method="POST", data={"id": user["id"], "expected_revision": user["revision"], "active": False}))
        self.reject(client.request("documents"), 401)
        self.reject(client.login(user["email"], password2), 401, "LOGIN_FAILED")

    def test_06_owner_boundary_and_user_field_allowlist(self):
        users = self.ok(self.owner.request("users"))["items"]
        owner = next(u for u in users if u["id"] == self.owner_id)
        self.reject(self.owner.request("users", method="POST", data={"id": owner["id"], "expected_revision": owner["revision"], "active": False}), 409, "LAST_OWNER")
        _, _, admin = self.new_user("admin")
        self.reject(admin.request("users", method="POST", data={"name": "Rejected owner", "email": "not-created@example.invalid", "role": "owner", "password": secrets.token_urlsafe(24)}), 403, "FORBIDDEN")
        self.reject(admin.request("users", method="POST", data={"id": owner["id"], "expected_revision": owner["revision"], "role": "reader"}), 403, "FORBIDDEN")
        _, _, reader = self.new_user("reader")
        listing = self.ok(reader.request("users"))["items"]
        self.assertTrue(all(set(item) <= {"id", "name", "role"} for item in listing))
        self.reject(reader.request("audit"), 403)

    def test_07_login_rate_limit_and_generic_error(self):
        self.admin("reset-rates")
        client = Client(self)
        self.ok(client.bootstrap())
        for _ in range(8):
            self.reject(client.request("auth/login", method="POST", data={"email": "does-not-exist@example.invalid", "password": "invalid-test-password"}), 401, "LOGIN_FAILED")
        limited = client.request("auth/login", method="POST", data={"email": "does-not-exist@example.invalid", "password": "invalid-test-password"})
        self.reject(limited, 429, "RATE_LIMITED")
        self.assertEqual(limited[1]["retry-after"], "900")
        self.admin("reset-rates")

    def test_08_password_policy_does_not_accept_extra_suffix(self):
        password72 = secrets.token_hex(36)
        user, _, _ = self.new_user("reader", password=password72)
        client = Client(self)
        result = client.login(user["email"], password72 + "different-suffix")
        if result[0] != 401:
            self.findings.append({"id": "CORE-PASSWORD-LENGTH", "severity": "low", "path": "app/core/auth.php", "actual_http": result[0], "expected_http": 401, "summary": "Login accepted a valid 72-byte bcrypt password plus a suffix; creation caps passwords at 72 bytes but login permits more. This requires knowledge of the full valid 72-byte secret; it is not password-free authentication."})
        self.reject(result, 401, "LOGIN_FAILED")

    def test_09_setup_malformed_csrf_is_rejected_without_500(self):
        client = Client(self)
        self.ok(client.bootstrap())
        result = client.request(method="POST", setup=True, form={"_csrf[x]": "not-a-token", "setup_key": "not-a-valid-key"})
        if result[0] == 500:
            self.findings.append({"id": "CORE-SETUP-CSRF-TYPE", "severity": "low", "path": "public/setup.php", "actual_http": 500, "expected_http": 403, "summary": "Array-shaped form _csrf is passed to a ?string helper, producing a TypeError/500 instead of a controlled 403; no setup bypass observed."})
        self.reject(result, 403, "CSRF_INVALID")

    def test_10_production_requires_https_and_ignores_forwarded_header(self):
        config = {**self.config, "environment": "production"}
        port = self.start_server(config, "production-http")
        client = Client(self, port)
        self.reject(client.request("auth/me", headers={"X-Forwarded-Proto": "https"}), 403, "HTTPS_REQUIRED")
        self.reject(client.request(setup=True, headers={"X-Forwarded-Proto": "https"}), 403, "HTTPS_REQUIRED")

    def test_11_verified_shared_host_policy_accepts_documents(self):
        storage = self.private / "public/private-storage"
        storage.mkdir(parents=True, exist_ok=True)
        (storage / ".htaccess").write_text("Require all denied\n", encoding="ascii")
        # This checks configuration integration only. A local router is NOT
        # evidence that Apache/InfinityFree enforces its .htaccess in production.
        config = {**self.config, "storage_path": str(storage), "storage": {"allow_webroot_storage": True, "http_denial_verified": True}}
        port = self.start_server(config, "shared-host-policy")
        client = Client(self, port)
        self.ok(client.login("security-owner@example.invalid", self.owner_password))
        result = client.upload("shared-host")
        if result[0] == 503:
            self.findings.append({"id": "DOCS-STORAGE-POLICY", "severity": "integration", "path": "app/documents/storage.php", "actual_http": 503, "expected_http": 201, "summary": "Core accepts verified storage.* shared-host policy but documents additionally requires an undocumented documents.allow_webroot_storage opt-in."})
        self.ok(result, 201)

    def test_12_unverified_storage_stays_closed_even_with_document_optin(self):
        storage = self.private / 'public/unverified-storage'
        storage.mkdir(parents=True, exist_ok=True)
        (storage / '.htaccess').write_text('Require all denied\n', encoding='ascii')
        config = {**self.config, 'storage_path': str(storage), 'storage': {'allow_webroot_storage': True, 'http_denial_verified': False}, 'documents': {'allow_webroot_storage': True}}
        port = self.start_server(config, 'unverified-policy')
        client = Client(self, port)
        self.reject(client.request('auth/me'), 503, 'UNSAFE_STORAGE')


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", required=True)
    parser.add_argument("--extension-dir", required=True)
    parser.add_argument("--mysql-runtime", required=True)
    parser.add_argument("--report", default=str(ROOT / "tests/documents-security-results.json"))
    OPTIONS, remaining = parser.parse_known_args()
    program = unittest.main(argv=[sys.argv[0], *remaining], verbosity=2, exit=False)
    result = program.result
    start = getattr(IntegratedDocumentSecurity, "start_hashes", {})
    end = getattr(IntegratedDocumentSecurity, "end_hashes", {})
    receipt = {"checked_at": datetime.now(timezone.utc).isoformat(), "scope": "actual public/setup.php and core auth/session/CSRF/roles plus protected documents; new loopback accounts/database only", "database_engine": getattr(IntegratedDocumentSecurity, "engine", None), "tests_run": result.testsRun, "success": result.wasSuccessful(), "failures": [test.id() for test, _ in result.failures], "errors": [test.id() for test, _ in result.errors], "findings": getattr(IntegratedDocumentSecurity, "findings", []), "source_sha256": end, "source_stable": start == end, "changed_during_review": [path for path in start.keys() | end.keys() if start.get(path) != end.get(path)], "secrets_in_responses_or_server_log": getattr(IntegratedDocumentSecurity, "secret_leaks", None), "cms_accounts_read_or_imported": False, "production_changed": False, "deployed": False}
    Path(OPTIONS.report).write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    sys.exit(0 if result.wasSuccessful() else 1)
