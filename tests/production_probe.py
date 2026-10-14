#!/usr/bin/env python3
"""Production reachability check. Uses public routes only; no credentials or mutations."""
import os, socket, ssl, urllib.request, urllib.error

DOMAIN="leaguecut.com"
base="https://"+DOMAIN

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, url):
        return None

def check_http():
    with urllib.request.urlopen(base+"/health",timeout=15) as r:
        body=r.read(80).decode().strip()
        assert r.status==200 and body=="LeagueCut OK",body
    guest=urllib.request.build_opener(NoRedirect)
    try:
        guest.open(base+"/admin",timeout=15)
        raise AssertionError("Unauthenticated /admin must not be public")
    except urllib.error.HTTPError as e:
        assert e.code in (302,303,401,403), ("Unexpected admin response",e.code)
        if e.code in (302,303):
            assert "/entrar" in (e.headers.get("Location") or ""), "Admin login redirect wrong"
    with urllib.request.urlopen(base+"/assets/polish.css?v=6",timeout=15) as r:
        assert r.status==200 and b".skip-link" in r.read(14000), "Current frontend not published"
    print("PRODUCTION HTTPS OK: /health, unauthenticated /admin protected, Bloco 6 asset")

def check_wss():
    key=__import__("base64").b64encode(os.urandom(16)).decode()
    context=ssl.create_default_context()
    with socket.create_connection((DOMAIN,443),timeout=12) as raw:
        with context.wrap_socket(raw,server_hostname=DOMAIN) as s:
            s.settimeout(12)
            request=(f"GET /ws HTTP/1.1\r\nHost: {DOMAIN}\r\nOrigin: https://{DOMAIN}\r\n"
                     "Connection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\n"
                     f"Sec-WebSocket-Key: {key}\r\n\r\n")
            s.sendall(request.encode())
            response=b""
            while b"\r\n\r\n" not in response and len(response)<4096:
                segment=s.recv(1024)
                if not segment:break
                response+=segment
            status=response.split(b"\r\n",1)[0]
            assert b"101 Switching Protocols" in status, ("WSS handshake failed",status)
            assert b"sec-websocket-accept:" in response.lower(),"Upgrade response missing accept"
    print("PRODUCTION WSS OK: public /ws upgrade HTTP 101 (no private ticket used)")

if __name__=="__main__":
    check_http()
    check_wss()
