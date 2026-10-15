"""Minimal RFC 6238 TOTP (Google Authenticator / Authy / Microsoft Authenticator
compatible: SHA-1, 6 digits, 30s step) with no third-party dependency."""
import base64
import hashlib
import hmac
import struct
import time

STEP = 30
DIGITS = 6


def _code(secret_b32: str, counter: int) -> str:
    key = base64.b32decode(secret_b32.replace(" ", "").upper() + "=" * (-len(secret_b32.replace(" ", "")) % 8))
    mac = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    off = mac[-1] & 0x0F
    num = (struct.unpack(">I", mac[off:off + 4])[0] & 0x7FFFFFFF) % (10 ** DIGITS)
    return str(num).zfill(DIGITS)


def verify(secret_b32: str, code: str, window: int = 1, now=None):
    """Returns the matching time-step counter (so callers can refuse to accept
    the same step twice) or None. Allows +/- `window` steps of clock drift."""
    code = (code or "").strip().replace(" ", "")
    if not (code.isdigit() and len(code) == DIGITS):
        return None
    counter = int((time.time() if now is None else now) // STEP)
    for c in range(counter - window, counter + window + 1):
        if hmac.compare_digest(_code(secret_b32, c), code):
            return c
    return None
