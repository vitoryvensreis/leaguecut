#!/usr/bin/env python3
"""Verify the complete two-popup protocol over HTTP (CI-only ephemeral account)."""
import base64
import hashlib
import hmac
import http.cookiejar
import json
import re
import struct
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

BASE="http://127.0.0.1:8080"
EMAIL="auth-popup-ci@leaguecut.test"
SECRET="GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def fetch(opener,path):
    with opener.open(BASE+path,timeout=15) as resp:
        return resp.read().decode("utf-8")

def submit(opener,path,fields):
    form=dict(fields)
    match=re.search(r'name="_csrf" value="([^"]+)"',fetch(opener,"/entrar"))
    assert match,"CSRF missing"
    form["_csrf"]=match.group(1)
    request=urllib.request.Request(BASE+path,data=urllib.parse.urlencode(form).encode(),method="POST",
      headers={"X-LC-AUTH":"1","Accept":"application/json"})
    try:
        with opener.open(request,timeout=15) as r:
            return r.status,json.loads(r.read().decode())
    except urllib.error.HTTPError as exc:
        return exc.code,json.loads(exc.read().decode())

def otp(secret):
    key=base64.b32decode(secret)
    msg=struct.pack(">Q",int(time.time())//30)
    digest=hmac.new(key,msg,hashlib.sha1).digest()
    offset=digest[-1]&15
    number=struct.unpack(">I",digest[offset:offset+4])[0]&0x7fffffff
    return f"{number%1000000:06d}"

def main():
    subprocess.check_call(["php","tests/auth_popup_fixture.php","create"])
    try:
        browser=client()
        html=fetch(browser,"/entrar")
        assert 'id="lc-auth-mfa"' in html and 'data-auth-async="mfa"' in html,"MFA dialog absent from login"
        assert 'data-auth-async="login"' in html,"Async login form absent"
        status,bad=submit(browser,"/entrar",{"email":EMAIL,"password":"wrong"})
        assert status==401 and bad["step"]=="error" and not bad["ok"],(status,bad)
        status,mfa=submit(browser,"/entrar",{"email":EMAIL,"password":"auth-ci-password-only"})
        assert status==200 and mfa["step"]=="mfa" and mfa["ok"] and not mfa["redirect"],mfa
        challenge=fetch(browser,"/dashboard")
        assert "Confirme seu acesso" in challenge,"MFA cannot be skipped by visiting dashboard"
        status,invalid=submit(browser,"/seguranca/verificar",{"mfa_code":"000000" if otp(SECRET)!="000000" else "999999"})
        assert status==401 and invalid["step"]=="mfa",invalid
        status,verified=submit(browser,"/seguranca/verificar",{"mfa_code":otp(SECRET)})
        assert status==200 and verified["ok"] and verified["step"]=="redirect" and verified["redirect"]=="/dashboard",verified
        approved=fetch(browser,"/dashboard")
        assert "Campanhas das quais participo" in approved,"MFA did not authenticate session"
        # Current logged-in users cannot see login page again.
        assert "Campanhas das quais participo" in fetch(browser,"/entrar")
        print("AUTH_POPUP_ASYNC_OK")
    finally:
        subprocess.check_call(["php","tests/auth_popup_fixture.php","delete"])

if __name__=="__main__":
    main()
