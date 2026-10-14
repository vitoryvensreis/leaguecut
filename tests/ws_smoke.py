#!/usr/bin/env python3
"""Smoke check actual Ratchet WebSocket authorization and audience isolation."""
import base64, hashlib, http.cookiejar, json, os, socket, struct, subprocess, time
import urllib.parse, urllib.request

HOST="127.0.0.1"
WEB="http://127.0.0.1:8080"
PORT=18765

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def get(c,path):
    return c.open(WEB+path,timeout=15).read().decode("utf-8")

def signin(c,email):
    from re import search
    h=get(c,"/entrar")
    token=search(r'name="_csrf" value="([^"]+)"',h)
    assert token
    body=urllib.parse.urlencode({"_csrf":token.group(1),"email":email,"password":"This-Is-A-Test-Password-123"}).encode()
    assert "LeagueCut" in c.open(urllib.request.Request(WEB+"/entrar",body,method="POST"),timeout=15).read().decode()

def ticket(c,source):
    from re import search
    h=get(c,source)
    token=search(r'name="_csrf" value="([^"]+)"',h).group(1)
    body=urllib.parse.urlencode({"_csrf":token}).encode()
    j=json.load(c.open(urllib.request.Request(WEB+"/api/realtime/token",body,method="POST"),timeout=15))
    assert j["ttl"]==60
    return j["ticket"]

def recv_exact(s,n):
    buf=b""
    while len(buf)<n:
        part=s.recv(n-len(buf))
        if not part: raise EOFError("Closed socket")
        buf+=part
    return buf

def read_frame(s):
    header=recv_exact(s,2)
    op=header[0]&15
    length=header[1]&127
    if length==126:length=int.from_bytes(recv_exact(s,2),"big")
    elif length==127:length=int.from_bytes(recv_exact(s,8),"big")
    if header[1]&128:recv_exact(s,4)
    payload=recv_exact(s,length)
    if op==8:return {"type":"closed"}
    if op!=1:return {"type":"other"}
    return json.loads(payload.decode("utf-8"))

def send_frame(s,data):
    payload=json.dumps(data).encode()
    mask=os.urandom(4)
    if len(payload)<126: head=bytes([0x81,0x80|len(payload)])
    else: head=bytes([0x81,0x80|126])+struct.pack("!H",len(payload))
    masked=bytes(x^mask[i%4] for i,x in enumerate(payload))
    s.sendall(head+mask+masked)

def connect(origin=WEB):
    s=socket.create_connection((HOST,PORT),timeout=6)
    s.settimeout(6)
    key=base64.b64encode(os.urandom(16)).decode()
    request=(f"GET /ws HTTP/1.1\r\nHost: localhost:{PORT}\r\n"
      f"Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\n"
      f"Sec-WebSocket-Key: {key}\r\nOrigin: {origin}\r\n\r\n")
    s.sendall(request.encode())
    buff=b""
    while b"\r\n\r\n" not in buff:
        buff+=s.recv(1024)
        if len(buff)>8192:raise AssertionError("Oversized HTTP response")
    assert b"101 Switching Protocols" in buff, buff[:200]
    assert buff.endswith(b"\r\n\r\n"), "Handshake had unexpected data"
    return s

def main():
    adm=client();creator=client()
    signin(adm,"admin@leaguecut.test")
    signin(creator,"creator@leaguecut.test")
    ta=ticket(adm,"/admin")
    tc=ticket(creator,"/dashboard")
    creator_id=json.loads(base64.urlsafe_b64decode(tc.split(".")[0]+"=="))["uid"]

    a=connect();c=connect()
    try:
        send_frame(a,{"type":"auth","ticket":ta})
        send_frame(c,{"type":"auth","ticket":tc})
        assert read_frame(a)["type"]=="ready"
        assert read_frame(c)["type"]=="ready"
        # Ensure events cannot be delivered across audiences.
        expression='require "app/core.php"; require "app/realtime.php"; lc_realtime_emit("admin",null,"creator.reviewed","user",1); lc_realtime_emit("user",'+str(creator_id)+',"creator.approved","user",'+str(creator_id)+');'
        subprocess.run(["php","-r",expression],check=True,capture_output=True)
        got_a=read_frame(a)
        got_c=read_frame(c)
        assert got_a["type"]=="event" and got_a["event"]=="creator.reviewed",got_a
        assert got_c["type"]=="event" and got_c["event"]=="creator.approved",got_c
        a.settimeout(1);c.settimeout(1)
        for target in (a,c):
            try:
                msg=read_frame(target)
                assert msg.get("type")=="heartbeat","Unauthorized audience event received: "+repr(msg)
            except socket.timeout:pass
    finally:
        a.close();c.close()
    forged=connect()
    try:
        send_frame(forged,{"type":"auth","ticket":tc+"bad"})
        assert read_frame(forged).get("type")=="closed"
    finally:forged.close()
    print("WS PASSED: authenticated ticket, admin/user isolation, invalid credential rejection")

if __name__=="__main__":
    main()
