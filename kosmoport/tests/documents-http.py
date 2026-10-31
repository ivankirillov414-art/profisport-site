"""Real multipart/binary HTTP checks against a disposable PHP contract fixture.

Only generated innocuous files and a temporary SQLite database are used.
This tests the document module, not the separate production auth/session core.
Run: python tests/documents-http.py --php <php.exe> [--extension-dir <ext>]
"""
from __future__ import annotations

import argparse
import concurrent.futures
from datetime import datetime, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import zipfile


OPTIONS = None
ROOT = Path(__file__).resolve().parents[1]


def pdf(text=b"Generated fixture"):
    # Small valid PDF: no business documents, scripts or remote references.
    objects = [b"<< /Type /Catalog /Pages 2 0 R >>", b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
               b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] /Contents 4 0 R >>",
               b"<< /Length " + str(len(text) + 1).encode() + b" >>\nstream\n" + text + b"\nendstream"]
    result = b"%PDF-1.4\n"
    offsets = [0]
    for index, body in enumerate(objects, 1):
        offsets.append(len(result))
        result += str(index).encode() + b" 0 obj\n" + body + b"\nendobj\n"
    start = len(result)
    result += b"xref\n0 5\n0000000000 65535 f \n"
    result += b"".join(f"{x:010d} 00000 n \n".encode() for x in offsets[1:])
    return result + b"trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" + str(start).encode() + b"\n%%EOF\n"


def office(extension="docx", extra=None, content_type=None):
    part, tag, family, kind = {
        "docx": ("word/document.xml", "document", "wordprocessingml", "wordprocessingml.document"),
        "xlsx": ("xl/workbook.xml", "workbook", "spreadsheetml", "spreadsheetml.sheet"),
        "pptx": ("ppt/presentation.xml", "presentation", "presentationml", "presentationml.presentation"),
    }[extension]
    main_type = content_type or f"application/vnd.openxmlformats-officedocument.{kind}.main+xml"
    parts = {
        "[Content_Types].xml": f'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/{part}" ContentType="{main_type}"/></Types>',
        "_rels/.rels": f'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="{part}"/></Relationships>',
        part: f'<a:{tag} xmlns:a="http://schemas.openxmlformats.org/{family}/2006/main"/>',
    }
    parts.update(extra or {})
    target = io.BytesIO()
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, content in parts.items():
            archive.writestr(name, content)
    return target.getvalue()


class DocumentsHTTP(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="kcrm-documents-")
        cls.directory = Path(cls.temp.name)
        (cls.directory / "public").mkdir()
        cls.key = secrets.token_hex(24)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            cls.port = sock.getsockname()[1]
        cmd = [OPTIONS.php]
        if OPTIONS.extension_dir:
            cmd += ["-d", "extension_dir=" + OPTIONS.extension_dir]
        modules = subprocess.check_output(cmd + ["-m"], text=True).lower()
        for extension in ["fileinfo", "zip", "pdo_sqlite"] + (["pdo_mysql"] if OPTIONS.mysql_runtime else []):
            if extension not in modules.split():
                cmd += ["-d", "extension=" + extension]
        cls.php_command = cmd
        cls.database = "kcrm_documents_test_" + secrets.token_hex(6)
        if OPTIONS.mysql_runtime:
            result = cls.mysql("init")
            cls.mysql_server = result["server"]
            print("Document fixture:", result["server"], "isolated database", result["database"], flush=True)
        cls.log = open(cls.directory / "server.log", "wb")
        cls.servers = []
        cls.server = cls.start_server(cls.port)
        cls.addClassCleanup(cls.cleanup_server)
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            try:
                status, _, body = cls.request("_fixture/ping")
                if status == 200:
                    return
                raise RuntimeError(body.decode(errors="replace"))
            except (urllib.error.URLError, ConnectionError):
                if cls.server.poll() is not None:
                    break
                time.sleep(0.1)
        cls.log.flush()
        raise RuntimeError((cls.directory / "server.log").read_text(errors="replace"))

    @classmethod
    def cleanup_server(cls):
        for server in cls.servers:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
        cls.log.close()
        if OPTIONS.mysql_runtime:
            cls.mysql("drop")
        cls.temp.cleanup()

    @classmethod
    def mysql(cls, mode):
        result = subprocess.run(cls.php_command + [str(ROOT / "tests/documents-mysql.php"), OPTIONS.mysql_runtime, cls.database, mode], capture_output=True, text=True, encoding="utf-8", timeout=20, check=True)
        return json.loads(result.stdout)

    @classmethod
    def start_server(cls, port):
        env = {**os.environ, "KCRM_DOCUMENTS_TEST_ROOT": str(cls.directory), "KCRM_DOCUMENTS_TEST_KEY": cls.key}
        if OPTIONS.mysql_runtime:
            env.update({"KCRM_DOCUMENTS_MYSQL_RUNTIME": OPTIONS.mysql_runtime, "KCRM_DOCUMENTS_MYSQL_DATABASE": cls.database})
        process = subprocess.Popen(cls.php_command + ["-d", "display_errors=0", "-d", "log_errors=1", "-d", "upload_max_filesize=8M", "-d", "post_max_size=9M", "-S", f"127.0.0.1:{port}", "-t", str(cls.directory / "public"), str(ROOT / "tests/documents-router.php")], env=env, stdout=cls.log, stderr=cls.log,
                                   creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
        cls.servers.append(process)
        return process

    @classmethod
    def request(cls, route, method="GET", query=None, fields=None, filename=None, content=None, role="manager", headers=None, server_port=None):
        parameters = {"r": route, **(query or {})}
        address = f"http://127.0.0.1:{server_port or cls.port}/api/index.php?" + urllib.parse.urlencode(parameters)
        values = {"X-Documents-Test-Key": cls.key, "X-Test-Role": role, "X-CSRF-Token": "fixture-csrf", **(headers or {})}
        data = None
        if method == "POST":
            boundary = "kcrm" + secrets.token_hex(16)
            data = b""
            for name, value in (fields or {}).items():
                data += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
            if filename is not None:
                data += f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + (content or b"") + b"\r\n"
            data += f"--{boundary}--\r\n".encode()
            values["Content-Type"] = "multipart/form-data; boundary=" + boundary
        request = urllib.request.Request(address, method=method, data=data, headers=values)
        try:
            response = urllib.request.urlopen(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, {k.lower(): v for k, v in response.headers.items()}, response.read()

    def upload(self, filename="fixture.txt", content=b"Fixture text\n", fields=None, **kwargs):
        return self.request("documents/upload", "POST", fields={"title": "Тест " + secrets.token_hex(4), **(fields or {})}, filename=filename, content=content, **kwargs)

    def data(self, result, status=200):
        self.assertEqual(result[0], status, result[2].decode(errors="replace"))
        value = json.loads(result[2])
        self.assertTrue(value["ok"], value)
        return value["data"]

    def rejected(self, result, status, code=None):
        self.assertEqual(result[0], status, result[2].decode(errors="replace"))
        value = json.loads(result[2])
        self.assertFalse(value["ok"])
        if code:
            self.assertEqual(value["error"]["code"], code, value)
        self.assertNotIn(str(self.directory), result[2].decode(errors="replace"))
        return value

    def audit(self):
        if OPTIONS.mysql_runtime:
            return self.mysql("audit")["items"]
        with sqlite3.connect(self.directory / "fixture.sqlite") as db:
            return list(db.execute("select action,entity_id,details,role from documents_test_audit"))

    def test_auth_every_route_and_role(self):
        for role, expected in [("anonymous", 401), ("editor", 403), ("cashier", 403), ("website_editor", 403), ("ticket_operator", 403)]:
            for route in ["documents", "documents/versions", "documents/download"]:
                self.rejected(self.request(route, role=role, query={"id": "a" * 32}), expected)
            self.rejected(self.upload(role=role), expected)
        self.rejected(self.upload(role="reader"), 403)
        for role in ["owner", "admin", "document_manager", "manager"]:
            self.data(self.upload(content=(role + "\n").encode(), role=role), 201)
        for role in ["owner", "admin", "document_manager", "manager", "reader"]:
            self.data(self.request("documents", role=role))
        self.assertTrue(any(row[0] == "documents.access_denied" for row in self.audit()))

    def test_real_upload_versions_download_and_search(self):
        first_bytes = pdf(b"Version one")
        first = self.data(self.upload("Договор.pdf", first_bytes, {"title": "Договор выставки 100%", "category": "Договоры"}), 201)
        document_id = first["document"]["id"]
        self.assertEqual(first["document"]["current_version"], 1)
        self.assertEqual(first["document"]["sha256"], hashlib.sha256(first_bytes).hexdigest())
        self.assertRegex(document_id, r"^[a-f0-9]{32}$")
        self.assertTrue(first["document"]["updated_at"].endswith("Z"))
        self.assertNotIn("storage", json.dumps(first))
        listing = self.data(self.request("documents", query={"q": "100%", "category": "Договоры"}, role="reader"))
        self.assertEqual([x["id"] for x in listing["items"]], [document_id])
        self.assertTrue(any(x["name"] == "Договоры" for x in listing["categories"]))
        second_bytes = pdf(b"Version two")
        second = self.data(self.request("documents/upload", "POST", fields={"document_id": document_id, "expected_version": "1"}, filename="Договор-2.pdf", content=second_bytes), 201)
        self.assertEqual(second["document"]["current_version"], 2)
        self.assertEqual(second["document"]["title"], first["document"]["title"])
        self.rejected(self.upload("three.pdf", pdf(b"Version three"), {"document_id": document_id, "expected_version": "1"}), 409, "version_conflict")
        self.rejected(self.upload("same.pdf", second_bytes, {"document_id": document_id}), 409, "duplicate_version")
        history = self.data(self.request("documents/versions", query={"id": document_id}, role="reader"))
        self.assertEqual([x["version"] for x in history["items"]], [2, 1])
        for version, body in [(1, first_bytes), (2, second_bytes), (None, second_bytes)]:
            result = self.request("documents/download", query={"id": document_id, **({"version": str(version)} if version else {})}, role="reader")
            self.assertEqual(result[0], 200, result[2])
            self.assertEqual(result[2], body)
            self.assertEqual(result[1]["content-type"], "application/pdf")
            self.assertIn("attachment", result[1]["content-disposition"])
            self.assertIn("filename*=UTF-8''", result[1]["content-disposition"])
            self.assertEqual(result[1]["x-content-type-options"], "nosniff")
            self.assertIn("no-store", result[1]["cache-control"])
            self.assertEqual(int(result[1]["content-length"]), len(body))
        self.assertEqual(len([a for a in self.audit() if a[0] == "documents.download" and a[1] == document_id]), 3)

    def test_allowlisted_content(self):
        for extension in ["docx", "xlsx", "pptx"]:
            with self.subTest(extension=extension):
                self.data(self.upload("generated." + extension, office(extension)), 201)
        self.data(self.upload("plain.txt", "Обычный текст\n".encode()), 201)
        self.data(self.upload("table.csv", b"name,count\nfixture,2\nnegative,-4\n"), 201)

    def test_size_and_basic_content_rejections(self):
        cases = [("bad.exe", b"MZstub", 422), ("page.html", b"<html>test</html>", 422), ("image.svg", b"<svg/>", 422),
                 ("bad.php.pdf", pdf(), 422), ("pretend.pdf", b"not pdf", 422), ("pretend.docx", b"MZstub", 422),
                 ("empty.txt", b"", 422), ("binary.txt", b"MZ\x00\x01stub", 422), ("html.txt", b"<html>hi</html>", 422),
                 ("script.csv", b"name,value\na,=HYPERLINK(1)\n", 422), ("large.txt", b"x" * (2 * 1024 * 1024 + 1), 413)]
        cases += [("semicolon.csv", b"name;value\na;=1+1\n", 422), ("tabs.csv", b"name\tvalue\na\t@SUM(1)\n", 422), ("../escape.txt", b"plain text", 422)]
        cases += [("columns.csv", b"a," * 1001 + b"last\n", 413), ("line.csv", b"a" * 65537, 413)]
        for name, content, status in cases:
            with self.subTest(name=name):
                self.rejected(self.upload(name, content), status)
        self.rejected(self.upload("missing.txt", pdf()[:-10]), 422)
        self.rejected(self.upload("active.pdf", pdf(b"/J#61vaScript (alert(1))")), 422, "unsafe_pdf")
        self.rejected(self.upload("compressed.pdf", pdf(b"/Type /ObjStm")), 422, "unsupported_pdf")

    def test_office_traversal_macros_external_relationships(self):
        rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" TargetMode="External" Target="https://example.invalid/template.dotm"/></Relationships>'
        cases = [office(extra={"../escape.php": "bad"}), office(extra={"word/vbaProject.bin": b"macro"}),
                 office(extra={"word/embeddings/oleObject1.bin": b"OLE"}), office(extra={"word/media/vector.svg": "<svg/>"}),
                 office(extra={"word/_rels/document.xml.rels": rels}), office(extra={"customXml/item1.xml": '<!DOCTYPE x [<!ENTITY xx SYSTEM "file:///etc/passwd">]><x>&xx;</x>'}),
                 office("xlsx"), office(content_type="application/vnd.ms-word.document.macroEnabled.main+xml")]
        for index, content in enumerate(cases):
            with self.subTest(case=index):
                self.rejected(self.upload("blocked.docx", content), 422)
        self.rejected(self.upload("bomb.docx", office(extra={"word/huge.xml": "x" * (9 * 1024 * 1024)})), 413)
        for content in ['<x xmlns="urn:test"><instrText>DDEAUTO cmd.exe</instrText></x>', '<x xmlns="urn:test"><fldSimple instr="INCLUDETEXT http://example.invalid"/></x>']:
            self.rejected(self.upload("field.docx", office(extra={"word/fields.xml": content})), 422, "unsafe_office")
        self.rejected(self.upload("external.xlsx", office("xlsx", {"xl/worksheets/sheet1.xml": '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><f>WEBSERVICE("https://example.invalid")</f></worksheet>'})), 422, "unsafe_office")
        escaped_relationship = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="../../../../escape.xml"/></Relationships>'
        self.rejected(self.upload("escape.docx", office(extra={"word/_rels/document.xml.rels": escaped_relationship})), 422, "unsafe_office")
        self.data(self.upload("text-about-macros.docx", office(extra={"word/document.xml": '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Documentation about ActiveX and macroEnabled</w:t></w:r></w:p></w:body></w:document>'})), 201)
        complex_xml = '<root>' + ''.join(f'<x a="{i}"/>' for i in range(31000)) + '</root>'
        self.rejected(self.upload("complex.docx", office(extra={"word/complex.xml": complex_xml})), 413, "office_too_complex")
        self.rejected(self.upload("hidden-exe.docx", office(extra={"word/media/photo.jpg": b"MZhidden executable"})), 422, "unsafe_office")
        disguised_macro = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r2" Type="http://schemas.microsoft.com/office/2006/relationships/vbaProject" Target="harmless.bin"/></Relationships>'
        self.rejected(self.upload("hidden-macro.docx", office(extra={"word/_rels/document.xml.rels": disguised_macro, "word/harmless.bin": b"hidden macro data"})), 422, "unsafe_office")

    def test_opaque_storage_integrity_and_guarded_fallback(self):
        result = self.data(self.upload(content=b"Integrity original\n"), 201)
        document_id = result["document"]["id"]
        objects = list((self.directory / "storage/documents/objects" / document_id[:2] / document_id).glob("*.blob"))
        self.assertEqual(len(objects), 1)
        objects[0].write_bytes(b"Integrity changed!\n")
        self.rejected(self.request("documents/download", query={"id": document_id}), 409, "file_integrity")
        self.assertTrue(any(a[0] == "documents.integrity_failed" and a[1] == document_id for a in self.audit()))
        self.assertEqual((self.directory / "storage/documents/.htaccess").read_text(), "Require all denied\nOptions -Indexes\n")
        self.rejected(self.upload(headers={"X-Test-Storage": "public"}), 503, "storage_configuration")
        self.rejected(self.request("_fixture/local-file-upload", "POST"), 422, "invalid_upload")

    def test_query_validation_and_missing_versions(self):
        self.rejected(self.request("documents/versions", query={"id": "../../secret"}), 422)
        self.rejected(self.request("documents/download", query={"id": "f" * 32}), 404)
        self.rejected(self.request("documents/download", query={"id": "f" * 32, "version": "../../1"}), 422)
        self.rejected(self.request("documents", query={"limit": "1 OR 1=1"}), 422)
        self.rejected(self.request("documents", query={"offset": "-1"}), 422)
        self.rejected(self.request("documents", query={"q": "x" * 201}), 422)
        self.rejected(self.upload(fields={"title": "x" * 100000}), 422, "invalid_field")
        listing = self.data(self.request("documents", query={"q": "%' OR 1=1 --"}))
        self.assertEqual(listing["total"], 0)

    def test_audit_failure_rolls_back_database_and_file(self):
        before = self.data(self.request("documents"))["total"]
        before_files = set((self.directory / "storage/documents").rglob("*.blob"))
        result = self.upload(headers={"X-Test-Audit-Fail": "1"})
        self.rejected(result, 500, "documents_unavailable")
        self.assertNotIn("Fixture audit failure", result[2].decode())
        self.assertEqual(self.data(self.request("documents"))["total"], before)
        self.assertEqual(set((self.directory / "storage/documents").rglob("*.blob")), before_files)

    def test_csrf_is_dispatcher_precondition(self):
        self.rejected(self.upload(headers={"X-CSRF-Token": "wrong"}), 403, "csrf")

    def test_concurrent_mysql_versions(self):
        if not OPTIONS.mysql_runtime:
            self.skipTest("Real row locking is tested only with the isolated MySQL/MariaDB runtime")
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            second_port = sock.getsockname()[1]
        self.start_server(second_port)
        deadline = time.monotonic() + 15
        while True:
            try:
                self.data(self.request("_fixture/ping", server_port=second_port))
                break
            except (urllib.error.URLError, ConnectionError):
                if time.monotonic() >= deadline:
                    raise
                time.sleep(0.05)
        first = self.data(self.upload(content=b"Concurrent base\n"), 201)
        document_id = first["document"]["id"]
        def update(index, expected=None):
            return self.request("documents/upload", "POST", fields={"document_id": document_id, **({"expected_version": str(expected)} if expected else {})}, filename=f"concurrent-{index}.txt", content=f"Concurrent upload {index}\n".encode(), server_port=self.port if index % 2 else second_port)
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            results = list(pool.map(lambda i: update(i, 1), [1, 2]))
        self.assertEqual(sorted(x[0] for x in results), [201, 409], [x[2] for x in results])
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            results = list(pool.map(update, [3, 4]))
        self.assertEqual([x[0] for x in results], [201, 201], [x[2] for x in results])
        history = self.data(self.request("documents/versions", query={"id": document_id}))
        self.assertEqual([x["version"] for x in history["items"]], [4, 3, 2, 1])
        objects = list((self.directory / "storage/documents/objects" / document_id[:2] / document_id).glob("*.blob"))
        self.assertEqual(len(objects), 4)
        self.assertEqual(len({hashlib.sha256(p.read_bytes()).hexdigest() for p in objects}), 4)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", required=True)
    parser.add_argument("--extension-dir")
    parser.add_argument("--mysql-runtime", help="Private local migration-runtime JSON; an isolated kcrm_documents_test_* database is created and removed")
    parser.add_argument("--report", help="Write a small verification receipt without credentials or file contents")
    OPTIONS, remaining = parser.parse_known_args()
    program = unittest.main(argv=[sys.argv[0], *remaining], verbosity=2, exit=False)
    if OPTIONS.report:
        result = program.result
        receipt = {"checked_at": datetime.now(timezone.utc).isoformat(), "module": "corporate-documents", "scope": "real HTTP multipart/binary module contract fixture, not production core sessions or hosting", "backend": getattr(DocumentsHTTP, "mysql_server", "SQLite fixture"), "migration": "migrations/020_documents.sql", "tests_run": result.testsRun, "success": result.wasSuccessful(), "failures": [case.id() for case, _ in result.failures], "errors": [case.id() for case, _ in result.errors], "skipped": [{"test": case.id(), "reason": reason} for case, reason in result.skipped], "business_data_used": False, "deployment_performed": False}
        receipt["source_sha256"] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in ["app/modules/documents.php", "app/documents/service.php", "app/documents/storage.php", "app/documents/validation.php", "migrations/020_documents.sql", "tests/documents-http.py", "tests/documents-router.php", "tests/documents-mysql.php"]}
        Path(OPTIONS.report).write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    sys.exit(0 if program.result.wasSuccessful() else 1)
