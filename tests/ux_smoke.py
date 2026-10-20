#!/usr/bin/env python3
"""LeagueCut UX regression: role-based assets, accessibility, forms and frontend delivery.

Runs after smoke.py against the disposable MySQL CI database; never touches production data.
"""
from html.parser import HTMLParser
import http.cookiejar
import re
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8080"
PASSWORD = "This-Is-A-Test-Password-123"

class Markup(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ids = []
        self.links = []
        self.tags = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        self.tags.append((tag,a))
        if a.get("id"): self.ids.append(a["id"])
        if tag == "link" and a.get("rel") == "stylesheet":
            self.links.append(a.get("href", ""))
    def classes(self, name):
        return [a for _,a in self.tags if name in a.get("class","").split()]
    def by(self, tag):
        return [a for t,a in self.tags if t == tag]

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def page(opener,path):
    with opener.open(BASE + path,timeout=15) as response:
        assert response.status==200,(path,response.status)
        html=response.read().decode("utf-8")
    assert "<!doctype html>" in html.lower(), path
    doc=Markup()
    doc.feed(html)
    return html,doc

def login(opener,email):
    html,_=page(opener,"/entrar")
    match=re.search(r'name="_csrf" value="([^"]+)"',html)
    assert match, "CSRF missing from login"
    body=urllib.parse.urlencode({"_csrf":match.group(1),"email":email,"password":PASSWORD}).encode()
    req=urllib.request.Request(BASE+"/entrar",data=body,method="POST")
    with opener.open(req,timeout=15) as response:
        assert response.status==200, response.status

def verify(opener,url,expected,forbidden=(),active=None):
    html,doc=page(opener,url)
    links=" ".join(doc.links)
    for name in expected: assert "assets/"+name in links,(url,name,links)
    for name in forbidden: assert "assets/"+name not in links,(url,name,links)
    assert len(doc.ids)==len(set(doc.ids)), "Duplicate IDs at "+url
    assert any(t=="main" and a.get("id")=="conteudo" and a.get("tabindex")=="-1" for t,a in doc.tags), "Accessible main at "+url
    assert any(t=="a" and a.get("href")=="#conteudo" and "skip-link" in a.get("class","") for t,a in doc.tags), "Skip link at "+url
    if active is not None:
        assert any(t=="a" and a.get("href")==active and a.get("aria-current")=="page" for t,a in doc.tags), "Current page: "+url
    for name in expected:
        target=next(x for x in doc.links if "/assets/"+name in x)
        with opener.open(BASE+target,timeout=10) as resp:
            assert resp.status==200 and b"{" in resp.read(4096),(url,target)
    return html

def main():
    guest=client()
    home=verify(guest,"/",["app.css","v2.css","landing.css","banner.css","polish.css"],
                ["workspace.css","realtime.css"],"/")
    assert "Pular para o conteúdo" in home
    catalog=verify(guest,"/explorar",["landing.css","polish.css"],["workspace.css","banner.css"],"/explorar")
    signup=verify(guest,"/cadastrar",["app.css","v2.css","polish.css"],["workspace.css","landing.css"])
    signin=verify(guest,"/entrar",["app.css","v2.css","polish.css"],["workspace.css","landing.css"])
    assert 'id="lc-auth-signup"' in signup and 'action="/cadastrar"' in signup, "Signup must load directly in modal"
    assert 'id="lc-auth-login"' in signin and 'action="/entrar"' in signin, "Login must load directly in modal"
    assert "assets/ux-upgrade.css" in signup and "assets/ux-upgrade.js" in signup
    assert '<a href="/planos">Planos para criadores</a>' in home, "Creator sales link belongs in footer"
    assert 'href="/planos"' not in home.split('</header>',1)[0], "Creator plans should not be in header"
    assert 'name="q"' in catalog, "Catalog search form"
    assert 'name="categoria"' in catalog, "Catalog category filter"
    assert 'lc-catalog-showcase' in catalog and 'lc-catalog-shelf' in catalog, "Catalog showcase and shelves"
    assert 'campaign-experience.css' in catalog
    assert 'workspace-motion.css' not in catalog and 'workspace-motion.css' not in home, "No entrance animation in public marketing LP"
    with guest.open(BASE+"/assets/campaign-experience.css",timeout=10) as asset:
        css=asset.read().decode("utf-8")
        assert 'overflow-x:clip' in css and '@media(max-width:580px)' in css


    admin=client()
    login(admin,"admin@leaguecut.test")
    dashboard=verify(admin,"/admin",["workspace.css","realtime.css","polish.css"],["landing.css","banner.css"])
    assert "Precisa da sua atenção" in dashboard
    assert "Top clipadores" in dashboard and "Top campanhas" in dashboard, "Rankings must appear on admin homepage"
    assert "Views observadas" in dashboard, "View count KPI missing"
    assert 'workspace-motion.css' in dashboard, "Admin panels animate"
    with admin.open(BASE+"/assets/workspace-motion.css",timeout=10) as asset:
        anim=asset.read().decode("utf-8")
        assert 'prefers-reduced-motion:reduce' in anim
        assert 'translate3d(0,12px,0)' in anim and 'translate3d(0,6px,0)' in anim
        assert '.workspace-page' in anim, "Workspace animations must be scoped"
    admin_campaigns=verify(admin,"/admin/campanhas",
        ["workspace.css","workspace-motion.css","campaign-experience.css"],["landing.css"])
    assert 'lc-catalog-showcase' in admin_campaigns and 'lc-catalog-shelves' in admin_campaigns

    assert 'aria-label="Atualizações"' in dashboard
    assert 'data-realtime-connection' in dashboard
    assert 'data-live="pending_users"' in dashboard
    assert any(a.get("action")=="/sair" for a in MarkupForm(dashboard).forms)
    verify(admin,"/admin/home",["workspace.css","banner.css","realtime.css"],["landing.css"])
    hero_manager=verify(admin,"/admin/banners-clipador",["workspace.css","clipper-ux.css"],["landing.css"])
    assert 'name="cta_label"' in hero_manager and 'name="image"' in hero_manager, "Admin can manage clipper hero media and CTA"
    verify(admin,"/admin/clipadores?status=pending",["workspace.css"],["landing.css"])
    verify(admin,"/admin/clipes?status=pending",["workspace.css"],["landing.css"])

    creator=client()
    login(creator,"creator@leaguecut.test")
    clipper=verify(creator,"/dashboard",["workspace.css","realtime.css","polish.css"],["landing.css","banner.css"])
    assert 'data-live="available_cents"' in clipper
    assert 'workspace-motion.css' in clipper, "Creator panel animation loaded"
    assert 'id="lc-quick-clip"' in clipper and 'name="campaign_id"' in clipper and 'name="published_url"' in clipper, "Two-step clip uploader is available"
    assert 'name="video"' in clipper and 'data-clip-open' in clipper, "Sidebar has quick clip publishing"
    assert 'lc-clipper-hero' in clipper and 'Campanhas das quais participo' in clipper, "Campaign hero and joined campaign section"
    assert 'name="title"' not in clipper.split('id="lc-quick-form"',1)[1].split('</form>',1)[0], "Quick upload does not request a title"
    clipper_catalog=verify(creator,"/explorar",["workspace.css","clipper-ux.css"],[])
    assert 'class="workspace-shell"' in clipper_catalog, "Logged-in clipper always uses lateral navigation"
    profile=verify(creator,"/conta",["workspace.css"],[])
    assert 'action="/conta/foto"' in profile and 'name="avatar"' in profile, "Profile photo upload available"

    for target in ("/redes","/biblioteca","/financeiro"):
        verify(creator,target,["workspace.css","realtime.css"],["landing.css"])
    assert any(a.get("href")=="/dashboard" and a.get("aria-current")=="page" for t,a in MarkupLinks(clipper).tags if t=="a")
    print("UX SMOKE PASSED: contextual assets, HTTP frontend files, accessible nav, roles and page responses")

class MarkupForm(HTMLParser):
    def __init__(self,html):
        super().__init__();self.forms=[];self.feed(html)
    def handle_starttag(self,tag,attrs):
        if tag=="form":self.forms.append(dict(attrs))

class MarkupLinks(HTMLParser):
    def __init__(self,html):
        super().__init__();self.tags=[];self.feed(html)
    def handle_starttag(self,tag,attrs):
        self.tags.append((tag,dict(attrs)))

if __name__=="__main__":
    main()
