"""Small S3-compatible HTTP server for isolated tests. It does not touch Cloudflare."""

from __future__ import annotations

import hashlib
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, unquote, urlparse
from xml.sax.saxutils import escape


class LabS3:
    def __init__(self):
        self.objects: dict[str, bytes] = {}
        self.uploads: dict[str, dict] = {}
        self.buckets: set[str] = set()
        self._counter = 0

    def next_id(self) -> str:
        self._counter += 1
        return f"upload-{self._counter}"


STORE = LabS3()


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        return

    def _read(self) -> bytes:
        length = int(self.headers.get("Content-Length", "0") or "0")
        return self.rfile.read(length) if length else b""

    def _send(self, status: int, body: bytes = b"", extra: dict | None = None) -> None:
        self.send_response(status)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Content-Type", "application/xml")
        for key, value in (extra or {}).items():
            self.send_header(key, value)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _parts(self):
        parsed = urlparse(self.path)
        parts = [unquote(part) for part in parsed.path.split("/") if part]
        return parts, parse_qs(parsed.query, keep_blank_values=True)

    def do_HEAD(self):
        parts, _query = self._parts()
        if len(parts) < 2:
            self._send(404)
            return
        key = "/".join(parts[1:])
        if key not in STORE.objects:
            self._send(404)
            return
        self._send(200, extra={"ETag": '"' + hashlib.md5(STORE.objects[key]).hexdigest() + '"'})

    def do_PUT(self):
        body = self._read()
        parts, query = self._parts()
        if len(parts) == 1:
            STORE.buckets.add(parts[0])
            self._send(200)
            return
        bucket, key = parts[0], "/".join(parts[1:])
        STORE.buckets.add(bucket)
        if "uploadId" in query and "partNumber" in query:
            upload = STORE.uploads[query["uploadId"][0]]
            etag = hashlib.md5(body).hexdigest()
            upload["parts"][int(query["partNumber"][0])] = (etag, body)
            self._send(200, extra={"ETag": f'"{etag}"'})
            return
        STORE.objects[key] = body
        self._send(200, extra={"ETag": '"' + hashlib.md5(body).hexdigest() + '"'})

    def do_DELETE(self):
        parts, _query = self._parts()
        if len(parts) < 2:
            self._send(404)
            return
        key = "/".join(parts[1:])
        STORE.objects.pop(key, None)
        self._send(204, b"")

    def do_GET(self):
        parts, query = self._parts()
        if len(parts) == 1 and "list-type" in query:
            prefix = query.get("prefix", [""])[0]
            keys = sorted(key for key in STORE.objects if key.startswith(prefix))
            xml = ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>", "<ListBucketResult>", "<IsTruncated>false</IsTruncated>"]
            for key in keys:
                xml.append(f"<Contents><Key>{escape(key)}</Key><Size>{len(STORE.objects[key])}</Size></Contents>")
            xml.append("</ListBucketResult>")
            self._send(200, "".join(xml).encode())
            return
        if len(parts) < 2:
            self._send(404)
            return
        key = "/".join(parts[1:])
        if key not in STORE.objects:
            self._send(404, b"<Error><Code>NoSuchKey</Code></Error>")
            return
        self._send(200, STORE.objects[key])

    def do_POST(self):
        body = self._read()
        parts, query = self._parts()
        if len(parts) < 2:
            self._send(400)
            return
        key = "/".join(parts[1:])
        if "uploads" in query:
            upload_id = STORE.next_id()
            STORE.uploads[upload_id] = {"key": key, "parts": {}}
            xml = f"<InitiateMultipartUploadResult><UploadId>{upload_id}</UploadId></InitiateMultipartUploadResult>"
            self._send(200, xml.encode())
            return
        upload_id = query.get("uploadId", [""])[0]
        if not upload_id or upload_id not in STORE.uploads:
            self._send(404, b"<Error><Code>NoSuchUpload</Code></Error>")
            return
        upload = STORE.uploads[upload_id]
        blob = b"".join(upload["parts"][number][1] for number in sorted(upload["parts"]))
        STORE.objects[key] = blob
        self._send(200, b"<CompleteMultipartUploadResult></CompleteMultipartUploadResult>")


def serve(port: int) -> ThreadingHTTPServer:
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server
