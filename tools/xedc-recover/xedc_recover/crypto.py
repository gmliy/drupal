"""Streaming AES-256-GCM. The key file stays outside the backup directory."""

from __future__ import annotations

import hashlib
import json
import os
import stat
from pathlib import Path

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from .safety import ProductionGuard, assert_write_allowed

MAGIC = b"XEDCENC1"
TRAILER = b"XEDCEND1"
CHUNK = 8 * 1024 * 1024
NONCE = 12


class CryptoError(RuntimeError):
    exit_code = 4


def new_key() -> bytes:
    return os.urandom(32)


def write_key(path: str | Path, key: bytes | None = None, key_id: str | None = None) -> dict:
    destination = assert_write_allowed(path, purpose="key")
    destination.parent.mkdir(parents=True, exist_ok=True)
    os.chmod(destination.parent, 0o700)
    material = key or new_key()
    if len(material) != 32:
        raise CryptoError("密钥必须是 32 字节")
    payload = {
        "key_id": key_id or hashlib.sha256(material).hexdigest()[:16],
        "key_b64": __import__("base64").b64encode(material).decode("ascii"),
        "alg": "AES-256-GCM",
    }
    destination.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    os.chmod(destination, stat.S_IRUSR | stat.S_IWUSR)
    return {"key_id": payload["key_id"], "path": str(destination)}


def load_key(path: str | Path) -> tuple[str, bytes]:
    import base64
    raw = json.loads(Path(path).read_text(encoding="utf-8"))
    key = base64.b64decode(raw["key_b64"])
    if len(key) != 32:
        raise CryptoError("密钥文件无效")
    return str(raw["key_id"]), key


def _aad(key_id: str, index: int) -> bytes:
    return f"{key_id}:{index}".encode("ascii")


def encrypt_file(src: str | Path, dst: str | Path, key: bytes, key_id: str) -> str:
    destination = assert_write_allowed(dst, purpose="encrypt")
    destination.parent.mkdir(parents=True, exist_ok=True)
    aes = AESGCM(key)
    digest = hashlib.sha256()
    header = {"v": 1, "key_id": key_id, "chunk": CHUNK, "alg": "AES-256-GCM"}
    with open(src, "rb") as source, open(destination, "wb") as output:
        output.write(MAGIC + json.dumps(header, sort_keys=True).encode("utf-8") + b"\n")
        index = 0
        while True:
            block = source.read(CHUNK)
            if not block:
                break
            digest.update(block)
            nonce = os.urandom(NONCE)
            ciphertext = aes.encrypt(nonce, block, _aad(key_id, index))
            output.write(nonce)
            output.write(len(ciphertext).to_bytes(4, "big"))
            output.write(ciphertext)
            index += 1
        output.write(b"\x00" * NONCE)
        output.write((0).to_bytes(4, "big"))
        output.write(TRAILER + digest.digest())
    os.chmod(destination, 0o600)
    return digest.hexdigest()


def decrypt_file(src: str | Path, dst: str | Path, key: bytes, key_id: str) -> str:
    destination = assert_write_allowed(dst, purpose="decrypt")
    destination.parent.mkdir(parents=True, exist_ok=True)
    aes = AESGCM(key)
    digest = hashlib.sha256()
    with open(src, "rb") as source, open(destination, "wb") as output:
        magic = source.read(len(MAGIC))
        if magic != MAGIC:
            raise CryptoError("备份加密头不匹配")
        header_line = source.readline()
        header = json.loads(header_line.decode("utf-8"))
        if header.get("key_id") != key_id:
            raise CryptoError("密钥编号与备份不匹配")
        index = 0
        while True:
            nonce = source.read(NONCE)
            length_raw = source.read(4)
            if len(nonce) != NONCE or len(length_raw) != 4:
                raise CryptoError("加密文件被截断")
            length = int.from_bytes(length_raw, "big")
            if length == 0:
                if nonce != b"\x00" * NONCE:
                    raise CryptoError("加密结束标记无效")
                break
            ciphertext = source.read(length)
            if len(ciphertext) != length:
                raise CryptoError("加密分块被截断")
            try:
                plain = aes.decrypt(nonce, ciphertext, _aad(key_id, index))
            except Exception as exc:
                raise CryptoError("解密失败，密钥错误或文件已损坏") from exc
            digest.update(plain)
            output.write(plain)
            index += 1
        trailer = source.read(len(TRAILER))
        expected = source.read(32)
        if trailer != TRAILER or len(expected) != 32 or expected != digest.digest():
            raise CryptoError("明文校验失败")
        extra = source.read(1)
        if extra:
            raise CryptoError("加密文件尾部存在多余数据")
    os.chmod(destination, 0o600)
    return digest.hexdigest()
