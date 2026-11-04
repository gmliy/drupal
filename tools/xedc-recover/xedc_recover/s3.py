"""Minimal S3/R2 client (SigV4, path-style, multipart, no redirects)."""

from __future__ import annotations

import hashlib
import hmac
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timezone

EMPTY_SHA = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"


class S3Error(RuntimeError):
    exit_code = 1


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise S3Error(f"拒绝跟随对象存储重定向 ({code})")


class S3Client:
    def __init__(self, endpoint: str, bucket: str, region: str, access_key: str, secret_key: str, prefix: str = "", timeout: int = 60, retries: int = 5, path_style: bool = True):
        if not endpoint or not bucket or not access_key or not secret_key:
            raise S3Error("对象存储配置不完整")
        self.endpoint = endpoint.rstrip("/")
        self.bucket = bucket
        self.region = region or "us-east-1"
        self.access_key = access_key
        self.secret_key = secret_key
        self.prefix = prefix.lstrip("/")
        self.timeout = timeout
        self.retries = retries
        self.path_style = path_style
        parsed = urllib.parse.urlparse(self.endpoint)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise S3Error("对象存储 endpoint 无效")
        self._opener = urllib.request.build_opener(_NoRedirect)

    def object_key(self, key: str) -> str:
        key = key.lstrip("/")
        if self.prefix and not key.startswith(self.prefix):
            return f"{self.prefix}{key}"
        return key

    def _canonical_uri(self, path: str) -> str:
        parts = [urllib.parse.quote(part, safe="") for part in path.split("/") if part != ""]
        return "/" + "/".join(parts)

    def _sign(self, method: str, path: str, query: list[tuple[str, str]], headers: dict[str, str], payload_hash: str, amz_date: datetime | None = None) -> dict[str, str]:
        moment = amz_date or datetime.now(timezone.utc)
        amz = moment.strftime("%Y%m%dT%H%M%SZ")
        date = amz[:8]
        parsed = urllib.parse.urlparse(self.endpoint)
        host = parsed.netloc
        signed = {
            "host": host,
            "x-amz-content-sha256": payload_hash,
            "x-amz-date": amz,
        }
        signed.update({k.lower(): str(v).strip() for k, v in headers.items()})
        canonical_headers = "".join(f"{k}:{signed[k]}\n" for k in sorted(signed))
        signed_headers = ";".join(sorted(signed))
        canonical_query = "&".join(
            f"{urllib.parse.quote(k, safe='')}={urllib.parse.quote(v, safe='')}"
            for k, v in sorted(query)
        )
        canonical = "\n".join([
            method,
            self._canonical_uri(path),
            canonical_query,
            canonical_headers,
            signed_headers,
            payload_hash,
        ])
        scope = f"{date}/{self.region}/s3/aws4_request"
        string_to_sign = "\n".join(["AWS4-HMAC-SHA256", amz, scope, hashlib.sha256(canonical.encode()).hexdigest()])
        def _hmac(key: bytes, value: str) -> bytes:
            return hmac.new(key, value.encode(), hashlib.sha256).digest()
        signing = _hmac(_hmac(_hmac(_hmac(f"AWS4{self.secret_key}".encode(), date), self.region), "s3"), "aws4_request")
        signature = hmac.new(signing, string_to_sign.encode(), hashlib.sha256).hexdigest()
        signed["authorization"] = (
            f"AWS4-HMAC-SHA256 Credential={self.access_key}/{scope}, SignedHeaders={signed_headers}, Signature={signature}"
        )
        return signed

    def _request(self, method: str, path: str, query: list[tuple[str, str]] | None = None, body: bytes = b"", headers: dict | None = None, stream_path: str | None = None) -> tuple[int, bytes, dict]:
        query = query or []
        payload_hash = hashlib.sha256(body).hexdigest()
        delay = 1
        last = "对象存储请求失败"
        for _ in range(self.retries):
            signed = self._sign(method, path, query, headers or {}, payload_hash)
            query_text = "&".join(f"{urllib.parse.quote(k, safe='')}={urllib.parse.quote(v, safe='')}" for k, v in query)
            url = f"{self.endpoint}{path}" + (f"?{query_text}" if query_text else "")
            request = urllib.request.Request(url, data=body if method not in ("GET", "HEAD") else None, method=method)
            for key, value in signed.items():
                request.add_header(key, value)
            try:
                with self._opener.open(request, timeout=self.timeout) as response:
                    header_map = {k.lower(): v for k, v in response.headers.items()}
                    if stream_path:
                        import shutil
                        with open(stream_path, "wb") as handle:
                            shutil.copyfileobj(response, handle)
                        return response.status, b"", header_map
                    return response.status, response.read(), header_map
            except urllib.error.HTTPError as exc:
                detail = exc.read()
                if exc.code in (429, 500, 502, 503, 504):
                    last = f"HTTP {exc.code}"
                    time.sleep(delay)
                    delay = min(delay * 2, 16)
                    continue
                raise S3Error(f"对象存储返回 HTTP {exc.code}: {detail[:180]!r}") from exc
            except (urllib.error.URLError, TimeoutError, OSError) as exc:
                last = str(exc)
                time.sleep(delay)
                delay = min(delay * 2, 16)
        raise S3Error(f"对象存储重试后仍失败: {last}")

    def ensure_bucket(self) -> None:
        path = f"/{self.bucket}"
        try:
            self._request("PUT", path, body=b"")
        except S3Error as exc:
            if "BucketAlready" in str(exc) or "HTTP 409" in str(exc):
                return
            raise

    def put_bytes(self, key: str, body: bytes, content_type: str = "application/octet-stream") -> None:
        object_key = self.object_key(key)
        path = f"/{self.bucket}/{object_key}"
        self._request("PUT", path, body=body, headers={"content-type": content_type})

    def put_file(self, key: str, path: str, part_size: int = 5 * 1024 * 1024) -> None:
        size = os_path_size(path)
        if size <= part_size:
            with open(path, "rb") as handle:
                self.put_bytes(key, handle.read())
            return
        object_key = self.object_key(key)
        object_path = f"/{self.bucket}/{object_key}"
        status, body, _headers = self._request("POST", object_path, query=[("uploads", "")], body=b"")
        if status not in (200, 201):
            raise S3Error(f"无法创建分块上传: {status}")
        upload_id = _xml_text(body, "UploadId")
        parts = []
        number = 1
        with open(path, "rb") as handle:
            while True:
                chunk = handle.read(part_size)
                if not chunk:
                    break
                part_status, _part_body, part_headers = self._request(
                    "PUT",
                    object_path,
                    query=[("partNumber", str(number)), ("uploadId", upload_id)],
                    body=chunk,
                )
                if part_status not in (200, 201):
                    raise S3Error(f"分块 {number} 上传失败")
                etag = part_headers.get("etag", "").strip()
                if not etag:
                    etag = '"' + hashlib.md5(chunk).hexdigest() + '"'
                parts.append((number, etag.strip('"')))
                number += 1
        xml = ["<CompleteMultipartUpload>"]
        for part_number, etag in parts:
            xml.append(f"<Part><PartNumber>{part_number}</PartNumber><ETag>\"{etag}\"</ETag></Part>")
        xml.append("</CompleteMultipartUpload>")
        payload = "".join(xml).encode()
        self._request("POST", object_path, query=[("uploadId", upload_id)], body=payload, headers={"content-type": "application/xml"})

    def get_file(self, key: str, destination: str) -> None:
        object_key = self.object_key(key)
        self._request("GET", f"/{self.bucket}/{object_key}", stream_path=destination)

    def head(self, key: str) -> int:
        object_key = self.object_key(key)
        status, _body, _headers = self._request("HEAD", f"/{self.bucket}/{object_key}")
        return status

    def delete_key(self, key: str) -> None:
        object_key = self.object_key(key)
        relative = object_key[len(self.prefix):] if self.prefix and object_key.startswith(self.prefix) else object_key
        if not relative.startswith("packages/xedc-full-"):
            raise S3Error("拒绝删除非备份包对象")
        status, _body, _headers = self._request("DELETE", f"/{self.bucket}/{object_key}")
        if status not in (200, 202, 204):
            raise S3Error(f"删除备份对象失败: {status}")

    def list_keys(self, prefix: str) -> list[str]:
        keys = []
        token = ""
        while True:
            query = [("list-type", "2"), ("prefix", self.object_key(prefix))]
            if token:
                query.append(("continuation-token", token))
            _status, body, _headers = self._request("GET", f"/{self.bucket}", query=query)
            root = ET.fromstring(body)
            ns = ""
            if root.tag.startswith("{"):
                ns = root.tag.split("}")[0] + "}"
            for node in root.findall(f"{ns}Contents"):
                key_node = node.find(f"{ns}Key")
                if key_node is not None and key_node.text:
                    keys.append(key_node.text)
            truncated = root.find(f"{ns}IsTruncated")
            if truncated is None or (truncated.text or "").lower() != "true":
                break
            next_token = root.find(f"{ns}NextContinuationToken")
            token = next_token.text if next_token is not None and next_token.text else ""
            if not token:
                break
        return keys


def os_path_size(path: str) -> int:
    import os
    return os.path.getsize(path)


def _xml_text(body: bytes, tag: str) -> str:
    root = ET.fromstring(body)
    for node in root.iter():
        if node.tag.endswith(tag) and node.text:
            return node.text
    raise S3Error(f"响应中没有 {tag}")
