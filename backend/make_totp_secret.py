"""Prints a fresh TOTP secret for the admin account. Put it in the server's
ADMIN_TOTP_SECRET env var (Render) or 'admin_totp_secret' in config.php
(Hostinger), then add the otpauth URI / secret to an authenticator app."""
import base64
import os

secret = base64.b32encode(os.urandom(20)).decode().rstrip("=")
print("Secret :", secret)
print("URI    : otpauth://totp/KCK%20Dashboard:admin?secret=" + secret + "&issuer=KCK%20Dashboard")
