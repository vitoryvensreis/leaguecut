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
    feed=verify(guest,"/feed",["social-feed.css"],["workspace.css"],"/feed")
    assert 'href="/feed"' in home and 'Feed</a>' in home, "Feed link is visible in public top navigation"
    assert 'id="lc-feed-title"' in feed and 'lc-feed-filters' in feed, "Feed has heading and network filters"
    assert 'assets/social-feed.js?v=1' in feed, "Feed embeds are loaded only on click"
    instagram_feed=verify(guest,"/feed?rede=instagram",["social-feed.css"],["workspace.css"],"/feed")
    tiktok_feed=verify(guest,"/feed?rede=tiktok",["social-feed.css"],["workspace.css"],"/feed")
    assert 'aria-current="page"' in instagram_feed and 'Instagram' in instagram_feed
    assert 'aria-current="page"' in tiktok_feed and 'TikTok' in tiktok_feed
    with guest.open(BASE+"/assets/social-feed.js?v=1",timeout=10) as response:
        js=response.read().decode("utf-8")
        assert 'data-feed-play' in js and 'dataset.embed' in js and 'replaceChildren(frame)' in js, "Embeds opt in on click"
    with guest.open(BASE+"/assets/social-feed.css?v=1",timeout=10) as response:
        css=response.read().decode("utf-8")
        assert 'grid-template-columns:repeat(4' in css and '@media(max-width:520px)' in css, "Feed adapts 4 desktop cards to one mobile card"
    signup=verify(guest,"/cadastrar",["app.css","v2.css","polish.css"],["workspace.css","landing.css"])
    signin=verify(guest,"/entrar",["app.css","v2.css","polish.css"],["workspace.css","landing.css"])
    assert 'id="lc-auth-signup"' in signup and 'action="/cadastrar"' in signup, "Signup must load directly in modal"
    assert 'name="nickname"' in signup and 'data-nickname-check' in signup and 'data-nickname-feedback' in signup, "Required nickname has live availability UI"
    assert 'assets/signup-nickname.js?v=1' in signup and 'assets/signup-dialog-polish.css?v=2' in signup, "Live nickname check and compact dialogs are loaded"
    assert 'Junte-se ao League Cut' not in signup, "Signup has no redundant eyebrow"
    assert '<span>Nickname <span class="lc-required-mark"' in signup and 'Nickname público <span' not in signup, "Signup field is simply Nickname"
    assert 'class="lc-nickname-hint"' in signup and '3 a 24 caracteres: letras, números e _.' in signup, "Inline nickname guidance"
    assert re.search(r'<small class="lc-nickname-feedback"[^>]*></small>', signup), "Availability feedback starts empty"
    assert 'assets/paint-mask-surfaces.css?v=1' in home and 'assets/paint-mask-surfaces.css?v=1' in catalog, "Paint mask CSS is enabled"
    with guest.open(BASE+"/assets/paint-mask-surfaces.css",timeout=10) as asset:
        mask_css=asset.read().decode("utf-8")
        assert '-webkit-mask-image' in mask_css and 'mask-image' in mask_css, "Real CSS masks required"
        assert '.lp-feature' in mask_css and '.explore-catalog .campaign-card' in mask_css, "Paint on non-button surfaces"
        assert 'prefers-reduced-motion' in mask_css, "Accessible reduced-motion fallback"

    assert 'Nickname público (opcional)' not in signup, "Nickname no longer optional"
    assert '<div class="full"><label class="field"><span>Chave Pix' not in signup, "Pix and portfolio share the same desktop form row"
    assert 'id="lc-auth-login"' in signin and 'action="/entrar"' in signin, "Login must load directly in modal"
    assert 'assets/stable-scroll-layout.css?v=1' in signup and 'assets/stable-scroll-layout.css?v=1' in signin, "Signup/login use stable popup scrolling"
    # Verify it loads after prior modal/sidebar styles, so the override wins.
    assert signup.index('assets/stable-scroll-layout.css?v=1') > signup.index('assets/signup-dialog-polish.css?v=2')
    assert signin.index('assets/stable-scroll-layout.css?v=1') > signin.index('assets/clipper-ux.css?v=1')
    with guest.open(BASE+"/assets/stable-scroll-layout.css",timeout=10) as asset:
        scroll_css=asset.read().decode("utf-8")
    assert '.workspace-page .workspace-sidebar>nav' in scroll_css and 'scrollbar-gutter: stable' in scroll_css, "Sidebar scrolling retains stable layout"
    assert 'overflow-y: auto' in scroll_css and 'overscroll-behavior-y: contain' in scroll_css, "Sidebar retains independent scrolling"
    assert '.lc-auth-dialog > .lc-auth-dialog-inner' in scroll_css and 'overflow: hidden !important' in scroll_css, "Popup corners no longer scroll"
    assert 'scrollbar-width: none' in scroll_css and '::-webkit-scrollbar { display: none; }' in scroll_css, "Popup scrollbars hidden across engines"
    assert 'overflow-x: hidden' in scroll_css and 'grid-template-columns: minmax(0,1fr)' in scroll_css, "No popup horizontal scroll, signup stays single-column on mobile"
    assert '@media (max-height: 610px)' in scroll_css, "Short screens preserve keyboard/touch vertical access"
    assert 'id="lc-auth-mfa"' in signin and 'action="/seguranca/verificar"' in signin, "2FA must be a popup too"
    assert 'data-auth-async="login"' in signin and 'data-auth-async="mfa"' in signin, "Both auth phases use asynchronous forms"
    assert 'assets/auth-mfa-modal.js?v=1' in signin, "Async MFA script loaded"
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
    assert 'assets/stable-scroll-layout.css?v=1' in dashboard, "Admin sidebar receives stable scroll CSS"
    assert "Precisa da sua atenção" in dashboard
    assert "Top clipadores" in dashboard and "Top campanhas" in dashboard, "Rankings must appear on admin homepage"
    assert 'data-admin-nav' in dashboard and dashboard.count('data-admin-group=')==9, "Nine expandable administrative groups including personal account"
    assert 'lc-admin-nav-child' in dashboard and 'data-admin-group="accounts"' in dashboard, "Submenus have icon-labelled links"
    assert '<svg class="lc-icon' in dashboard.split('lc-admin-nav-child',1)[1], "Child submenu icon SVG rendered"
    assert 'assets/admin-navigation.js?v=1' in dashboard, "Expanded group persists on navigation"
    assert 'id="lc-privacy-toggle"' in dashboard and 'lc-privacy-toolbar' not in dashboard, "Privacy eye belongs in header"
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
    assert 'aria-label="Campanhas e clipes"' in admin_campaigns and 'lc-admin-tab' in admin_campaigns, "Internal admin tabs preserve contextual navigation"

    assert 'aria-label="Atualizações"' in dashboard
    assert 'data-realtime-connection' in dashboard
    assert 'data-live="pending_users"' in dashboard
    assert any(a.get("action")=="/sair" for a in MarkupForm(dashboard).forms)
    verify(admin,"/admin/home",["workspace.css","banner.css","realtime.css"],["landing.css"])
    identity=verify(admin,"/admin/configuracoes",["workspace.css","interface-refine.css"],["landing.css"])
    assert 'data-brand-preview' in identity and 'data-brand-image' in identity and 'data-preview-upload' in identity, "Brand editing previews active logo and selected file"
    assert 'assets/personalization-preview.js?v=1' in identity, "Live brand previews script loaded"
    seo=verify(admin,"/admin/seo",["workspace.css","seo-admin.css","interface-refine.css"],["landing.css"])
    assert seo.count('data-editor-tab=')==6 and seo.count('data-editor-panel=')==6, "SEO settings are divided into six internal tabs"
    assert 'data-preview-image="seo_favicon_upload"' in seo and 'data-preview-image="seo_social_image_upload"' in seo, "Active/favicon/social previews visible"
    assert 'name="admin_password"' in seo and 'name="seo_home_title"' in seo and 'name="seo_canonical_base"' in seo, "SEO keeps all inputs and administrative confirmation"
    hero_manager=verify(admin,"/admin/banners-clipador",["workspace.css","clipper-ux.css"],["landing.css"])
    assert 'name="cta_label"' in hero_manager and 'name="image"' in hero_manager, "Admin can manage clipper hero media and CTA"
    assert 'data-banner-preview' in hero_manager and 'data-banner-mode="mobile"' in hero_manager, "Clipper banners have desktop and mobile previews"
    verify(admin,"/admin/clipadores?status=pending",["workspace.css"],["landing.css"])
    verify(admin,"/admin/clipes?status=pending",["workspace.css"],["landing.css"])

    creator=client()
    login(creator,"creator@leaguecut.test")
    clipper=verify(creator,"/dashboard",["workspace.css","realtime.css","polish.css"],["landing.css","banner.css"])
    assert 'data-live="available_cents"' in clipper
    assert 'href="/feed"' in clipper and 'Feed' in clipper, "Approved clipper has Feed in sidebar"
    assert 'workspace-motion.css' in clipper, "Creator panel animation loaded"
    assert 'id="lc-quick-clip"' in clipper and 'name="campaign_id"' in clipper and 'name="published_url"' in clipper, "Two-step clip uploader is available"
    assert 'name="video"' in clipper and 'data-clip-open' in clipper, "Sidebar has quick clip publishing"
    assert 'lc-clipper-hero' in clipper and 'Campanhas das quais participo' in clipper, "Campaign hero and joined campaign section"
    assert 'lc-clipper-dashboard-page' in clipper and 'id="lc-privacy-toggle"' in clipper, "Full-bleed clipper hero and compact privacy button"
    assert 'lc-clipper-mobile-dock' in clipper and 'lc-dock-create' in clipper, "Clipper mobile dock offers account, publish and menu"
    assert clipper.count('data-sidebar-toggle')==1, "Only one drawer trigger per clipper layout"
    assert 'name="title"' not in clipper.split('id="lc-quick-form"',1)[1].split('</form>',1)[0], "Quick upload does not request a title"
    clipper_catalog=verify(creator,"/explorar",["workspace.css","clipper-ux.css"],[])
    assert 'class="workspace-shell"' in clipper_catalog, "Logged-in clipper always uses lateral navigation"
    profile=verify(creator,"/conta",["workspace.css"],[])
    assert 'action="/conta/foto"' in profile and 'name="avatar"' in profile, "Profile photo upload available"
    assert 'até 10 MB' in profile, "Profile photo UI explains the 10 MB limit"

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
