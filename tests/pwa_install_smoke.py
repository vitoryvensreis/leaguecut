#!/usr/bin/env python3
"""PWA HTTP installability regression, run against the isolated LeagueCut CI server."""
import json
import re
import struct
import urllib.request

BASE = "http://127.0.0.1:8080"

def get(path):
    with urllib.request.urlopen(BASE + path, timeout=25) as response:
        body = response.read()
        return response.status, response.headers, body

status, headers, body = get("/pwa/manifest?v=4")
assert status == 200
assert "application/manifest+json" in headers.get("Content-Type", "")
manifest = json.loads(body)
assert manifest["display"] == "standalone"
assert manifest["scope"] == "/"
assert manifest["start_url"] == "/"
assert {i["sizes"] for i in manifest["icons"]} == {"192x192", "512x512"}
assert all(i["src"].startswith("/pwa/icon/") for i in manifest["icons"])
assert {"any", "maskable"}.issubset({i["purpose"] for i in manifest["icons"]})

status, headers, worker = get("/pwa/worker")
assert status == 200
assert "javascript" in headers.get("Content-Type", "")
assert headers.get("Service-Worker-Allowed") == "/"
assert headers.get("X-Content-Type-Options") == "nosniff"
assert b"self.addEventListener('fetch'" in worker
assert b"cache.addAll(STATIC)" not in worker

for icon in manifest["icons"]:
    status, headers, image = get(icon["src"])
    assert status == 200, icon
    assert headers.get("Content-Type") == "image/png", icon
    assert image.startswith(b"\x89PNG\r\n\x1a\n"), icon
    w, h = struct.unpack(">II", image[16:24])
    expected = int(icon["sizes"].split("x")[0])
    assert (w, h) == (expected, expected), icon

status, headers, homepage = get("/")
html = homepage.decode("utf-8")
assert status == 200
assert re.search(r'<link rel="manifest" href="/pwa/manifest\?v=4"', html)
assert 'data-pwa-install' in html
assert 'lc-pwa-install-help' in html
assert '/assets/pwa.js?v=4' in html
assert '/assets/pwa-install.css?v=4' in html

status, _, js = get("/assets/pwa.js?v=4")
assert status == 200
assert b"register('/pwa/worker'" in js
assert b"beforeinstallprompt" in js
assert b"Adicionar" in js, "iOS/Android manual fallback missing"

status, _, css = get("/assets/pwa-install.css?v=4")
assert status == 200
assert b'[data-pwa-install][hidden]' in css

print("PWA_INSTALL_HTTP_OK")
