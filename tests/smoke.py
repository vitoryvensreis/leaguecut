#!/usr/bin/env python3
"""LeagueCut end-to-end smoke test using only stdlib and disposable CI MySQL."""
import http.cookiejar
import re
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:8080"


def make_client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def get(client, path):
    with client.open(BASE + path, timeout=12) as response:
        data = response.read().decode("utf-8")
    assert "Não foi possível concluir esta operação" not in data, "PHP exception page"
    return data


def post(client, path, form):
    source = get(client, "/")
    token = re.search(r'name="_csrf" value="([^"]+)"', source)
    if not token:
        source = get(client, "/entrar")
        token = re.search(r'name="_csrf" value="([^"]+)"', source)
    assert token, "Missing CSRF token"
    form["_csrf"] = token.group(1)
    req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(form).encode(), method="POST")
    with client.open(req, timeout=15) as response:
        data = response.read().decode("utf-8")
    assert "Não foi possível concluir esta operação" not in data, "PHP exception in POST"
    return data


def csrf_from(client,path):
    result=get(client,path)
    token=re.search(r'name="_csrf" value="([^"]+)"',result)
    assert token, "CSRF missing at " + path
    return token.group(1)


def submit(client,path,fields,source):
    fields["_csrf"] = csrf_from(client,source)
    req=urllib.request.Request(BASE+path,data=urllib.parse.urlencode(fields).encode(),method="POST")
    with client.open(req,timeout=15) as resp:
        html=resp.read().decode("utf-8")
    assert "Não foi possível concluir esta operação" not in html, "Failure for " + path
    return html


def submit_video(client, campaign_id, account_id, title, url):
    """Real multipart upload in CI, where Drive is explicitly mocked only on localhost."""
    boundary="leaguecut-test-multipart"
    fields={
       "_csrf":csrf_from(client,"/campanhas/"+campaign_id[1]),
       "campaign_id":campaign_id[0],
       "social_account_id":account_id,
       "title":title,
       "published_url":url
    }
    parts=[]
    for key,value in fields.items():
        parts.append(("--"+boundary+"\r\nContent-Disposition: form-data; name=\""+key+"\"\r\n\r\n"+value+"\r\n").encode())
    # ISO BMFF magic recognized as video/mp4 by libmagic.
    mp4=b"\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41"
    parts.append(("--"+boundary+"\r\nContent-Disposition: form-data; name=\"video\"; filename=\"teste.mp4\"\r\nContent-Type: video/mp4\r\n\r\n").encode()+mp4+b"\r\n")
    parts.append(("--"+boundary+"--\r\n").encode())
    request=urllib.request.Request(BASE+"/clipes",data=b"".join(parts),method="POST",
       headers={"Content-Type":"multipart/form-data; boundary="+boundary,"X-Requested-With":"XMLHttpRequest"})
    with client.open(request,timeout=15) as resp:
        assert resp.headers.get("Content-Type","").startswith("application/json")
        import json
        payload=json.load(resp)
        assert payload.get("ok") is True, payload

def replace_clip_video(client, clip_id, account_id, title, url):
    """CI multipart edit: fake Google Drive is available only on localhost."""
    import json
    boundary="leaguecut-test-edit-multipart"
    fields={
       "_csrf":csrf_from(client,f"/clipes/{clip_id}/editar"),
       "social_account_id":str(account_id),
       "title":title,
       "published_url":url
    }
    parts=[]
    for key,value in fields.items():
        parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
    mp4=b"\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41"
    parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="video"; filename="novo.mp4"\r\nContent-Type: video/mp4\r\n\r\n').encode()+mp4+b"\r\n")
    parts.append((f'--{boundary}--\r\n').encode())
    req=urllib.request.Request(BASE+f"/clipes/{clip_id}/editar",
       data=b"".join(parts),method="POST",
       headers={"Content-Type":f"multipart/form-data; boundary={boundary}",
                "X-Requested-With":"XMLHttpRequest"})
    with client.open(req,timeout=15) as response:
        assert response.headers.get("Content-Type","").startswith("application/json")
        payload=json.load(response)
        assert payload.get("ok") is True,payload

def register(client,email,name):
    return submit(client,"/cadastrar",{
      "full_name":name,"email":email,"password":"This-Is-A-Test-Password-123",
      "instagram":"testcreator","whatsapp":"85999998888","portfolio_url":"",
      "pix_key":"test@example.com","consent_whatsapp":"1"
    },"/cadastrar")


def seo_upload_and_save(admin):
    """Real multipart SEO upload and metadata publish using disposable CI images."""
    import struct
    import zlib

    def image_png(width, height):
        def chunk(label, data):
            return struct.pack(">I", len(data)) + label + data + struct.pack(">I", zlib.crc32(label + data) & 0xffffffff)
        ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
        pixels = b"".join(b"\x00" + b"\xdd\x23\x4f" * width for _ in range(height))
        return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", zlib.compress(pixels)) + chunk(b"IEND", b"")

    token=csrf_from(admin,"/admin/seo")
    fields={
        "_csrf":token,"seo_home_title":"LeagueCut — Campanhas e conquistas",
        "seo_home_description":"Descubra campanhas, publique cortes e alcance novas conquistas com a LeagueCut.",
        "seo_default_description":"Participe de campanhas para clipadores e acompanhe conteúdos e rankings na LeagueCut.",
        "seo_explore_title":"Campanhas disponíveis | LeagueCut",
        "seo_ranking_title":"Ranking de views | LeagueCut",
        "seo_plans_title":"Planos para criadores | LeagueCut",
        "seo_keywords":"leaguecut, clipadores, criadores, vídeos",
        "seo_canonical_base":"https://leaguecut.com",
        "seo_twitter_handle":"@leaguecut",
        "seo_index_enabled":"1","seo_favicon_url":"","seo_social_image_url":"",
        "social_instagram":"https://www.instagram.com/leaguecut/",
        "social_tiktok":"https://www.tiktok.com/@leaguecut",
        "admin_password":"This-Is-A-Test-Password-123"
    }
    boundary="leaguecut-ci-seo-upload"
    payload=[]
    for k,v in fields.items():
        payload.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n').encode())
    for key,name,image in [
        ("seo_favicon_upload","icon.png",image_png(96,96)),
        ("seo_social_image_upload","card.png",image_png(1200,630))
    ]:
        payload.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n').encode()+image+b'\r\n')
    payload.append((f'--{boundary}--\r\n').encode())
    req=urllib.request.Request(BASE+"/admin/seo",data=b''.join(payload),method="POST",
       headers={"Content-Type":"multipart/form-data; boundary="+boundary})
    with admin.open(req,timeout=18) as response:
        panel=response.read().decode('utf-8')
    assert "SEO atualizado" in panel, "Successful authenticated SEO update"
    assert 'name="social_instagram"' in panel and 'name="social_tiktok"' in panel, "Admin SEO has official social settings"

    homepage=get(make_client(),"/")
    assert '/assets/b6-layout.css?v=1' in homepage, "Responsive logo/sidebar/header stylesheet"
    assert 'href="https://www.instagram.com/leaguecut/"' in homepage and 'href="https://www.tiktok.com/@leaguecut"' in homepage, "Configured footer social links"
    assert '/politica-de-privacidade' in homepage and '/exclusao-de-dados' in homepage and '/termos-de-uso' in homepage, "Legal footer pages linked"
    for legal in ['/politica-de-privacidade','/exclusao-de-dados','/termos-de-uso']:
        legal_html=get(make_client(),legal)
        assert '<h1>' in legal_html, "Legal pages readable without login"

    assert 'LeagueCut — Campanhas e conquistas' in homepage, "Home meta title editable"
    assert '<meta property="og:title"' in homepage and '<meta name="twitter:card" content="summary_large_image">' in homepage
    assert 'href="https://leaguecut.com/"' in homepage, "Fixed canonical URL"
    icon=re.search(r'<link rel="icon" href="https://leaguecut.com(/media/seo/icon/[a-f0-9]{36})">',homepage)
    social=re.search(r'<meta property="og:image" content="https://leaguecut.com(/media/seo/social/[a-f0-9]{36})">',homepage)
    assert icon and social, "Images use safe absolute URLs for sharing"
    for path in [icon.group(1),social.group(1)]:
        with admin.open(BASE+path,timeout=12) as response:
            assert response.headers.get("Content-Type")=="image/png"
            assert response.read(8)==b"\x89PNG\r\n\x1a\n", "Public image response"
    sitemap=get(admin,"/sitemap.xml")
    assert '<urlset' in sitemap and '<loc>https://leaguecut.com/</loc>' in sitemap, "XML sitemap public URLs"
    robots=get(admin,"/robots.txt")
    assert "Disallow: /admin/" in robots and "Sitemap: https://leaguecut.com/sitemap.xml" in robots, "Private routes disallowed"
    private=get(admin,"/admin/seo")
    assert '<meta name="robots" content="noindex, nofollow, noarchive">' in private, "Admin pages noindex"
    assert '<meta property="og:title"' not in private, "Private pages have no social metadata"
    print("B5_SEO_UPLOAD_OK")


def main():
    creator=make_client()
    admin=make_client()
    home=get(creator,"/")
    assert "Seu talento." in home, "Landing hero"
    assert '<meta name="robots" content="index, follow' in home and '<link rel="canonical" href="https://leaguecut.com/">' in home, "Public home SEO"
    assert '<meta property="og:title"' in home and 'application/ld+json' in home, "Open Graph and JSON-LD"
    assert "Perguntas frequentes" in home, "FAQ section"
    assert 'href="/ranking"' in home, "Public nickname ranking linked from home"
    assert 'href="/explorar"' in home, "Catalog link"
    assert "Novas campanhas em breve" in get(creator,"/explorar"), "Catalog empty state"
    assert "Campanhas</a>" in home, "Catalog navigation"
    assert 'assets/landing.css' in home, "Landing stylesheet"
    assert 'assets/b4b-arena-polish.css?v=1' in home, "B4b polish stylesheet loaded"
    assert 'assets/v2.css' in get(creator,"/")
    assert 'assets/icons.svg' in get(creator,"/")
    assert 'lc-icon' in get(creator,"/")
    assert "Junte-se aos criadores." in get(creator,"/cadastrar")
    signin=get(creator,"/entrar")
    assert 'href="/recuperar-senha"' in signin, "Reset password entry point in modal"
    assert 'name="remember_me"' in signin, "Optional trusted-device checkbox"
    reset=get(creator,"/recuperar-senha")
    assert 'name="identifier"' in reset and 'name="channel"' in reset, "Recovery supports registered email/WhatsApp"
    confirm=get(creator,"/recuperar-senha/confirmar")
    assert 'name="code"' in confirm and 'name="new_password"' in confirm, "New password requires one-time code"

    assert 'privacy-toggle.js' not in get(make_client(),"/cadastrar"), "No private fields on public signup"
    creator_page=register(creator,"creator@leaguecut.test","Criador Teste")
    assert "analisando" in creator_page.lower(), "Creator pending page"
    register(admin,"admin@leaguecut.test","Admin Teste")
    import subprocess
    subprocess.check_call(["php","bin/make-admin.php","admin@leaguecut.test"])
    seo_upload_and_save(admin)
    from urllib.error import HTTPError
    try:
        get(creator,"/admin/seo")
        raise AssertionError("Clipador must never update site SEO")
    except HTTPError as exc:
        assert exc.code==403, "Admin SEO permissions enforced"
    from urllib.error import HTTPError
    # Bloco 7: Google Drive connection is admin-only and never exposes OAuth tokens.
    integrations=get(admin,"/admin/integracoes")
    bonus_page=get(admin,"/admin/bonus-mensal")
    assert 'Cadastrar novo bônus mensal' in bonus_page and 'name="bonus_name"' in bonus_page, "Multi-bonus form"
    assert 'name="bonus_amount"' in bonus_page and 'data-lc-mask="money"' in bonus_page, "Money mask"
    assert 'Fechar mês' in bonus_page, "Legacy monthly bonus preserved"
    referral_admin=get(admin,"/admin/indicacoes")
    assert 'name="fixed_value" data-lc-mask="money"' in referral_admin, "Fixed referral amount has BRL mask"
    assert 'name="percent_value"' in referral_admin and 'name="percent_value" data-lc-mask="money"' not in referral_admin, "Percentage is not money"
    package_admin=get(admin,"/admin/pacotes")
    assert 'name="monthly_price"' in package_admin and 'data-lc-mask="money"' in package_admin, "Creator monthly price uses currency mask"

    assert "Google Drive" in integrations
    assert "Métricas Instagram" in integrations
    assert "Verificar token (sem cobrança)" in integrations
    assert "Nenhum custo foi reservado." in integrations
    assert "GOOGLE_CLIENT_ID" in integrations, "Setup instructions"
    assert "admin/integracoes/google/callback" in integrations, "Google OAuth redirect"
    assert 'name="_csrf"' in integrations
    assert "Abrir original no Drive" not in integrations
    try:
        get(creator,"/admin/integracoes")
        raise AssertionError("Creator must not access Google Drive integration settings")
    except HTTPError as exc:
        assert exc.code==403, "Drive settings require admin role"
    guest_integrations=get(make_client(),"/admin/integracoes")
    assert "Entrar" in guest_integrations and "GOOGLE_CLIENT_ID" not in guest_integrations
    # Upload a real SVG logo to private storage and ensure the public URL is safe.
    brand_settings=get(admin,"/admin/configuracoes")
    assert "logo_upload" in brand_settings
    csrf=re.search(r'name="_csrf" value="([^"]+)"',brand_settings).group(1)
    svg=b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)" viewBox="0 0 120 40"><rect width="120" height="40" fill="#f00"/></svg>'
    boundary="leaguecut-ci-svg-brand"
    fields={"_csrf":csrf,"brand_name":"LeagueCut","primary_color":"#e50914","logo_url":""}
    segments=[]
    for k,v in fields.items():
        segments.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n').encode())
    segments.append((f'--{boundary}\r\nContent-Disposition: form-data; name="logo_upload"; filename="brand.svg"\r\nContent-Type: image/svg+xml\r\n\r\n').encode()+svg+b"\r\n")
    segments.append((f'--{boundary}--\r\n').encode())
    request=urllib.request.Request(BASE+"/admin/configuracoes",data=b"".join(segments),method="POST",
        headers={"Content-Type":"multipart/form-data; boundary="+boundary})
    with admin.open(request,timeout=15) as response:
        assert response.status==200
        assert "Marca atualizada" in response.read().decode()
    header=get(creator,"/")
    logo_match=re.search(r'src="(/media/brand/[a-f0-9]{36})"',header)
    assert logo_match,"Uploaded SVG must appear as public brand logo"
    with creator.open(BASE+logo_match.group(1),timeout=12) as logo_response:
        assert logo_response.headers.get("Content-Type")=="image/svg+xml"
        data=logo_response.read()
        assert b"<rect" in data and b"onload" not in data and b"javascript" not in data
    assert "LeagueCut" in get(admin,"/admin/configuracoes")
    admin_view=get(admin,"/admin")
    assert "Painel administrativo" in admin_view
    assert "Precisa da sua atenção" in admin_view
    assert "Ferramentas de gestão" in admin_view
    assert "ws-kpi-grid" in admin_view
    assert 'class="workspace-shell"' in admin_view
    assert 'assets/workspace.css' in admin_view
    assert 'href="/admin/clipes"' in admin_view
    assert 'href="/admin/home"' in admin_view
    assert 'href="/admin/clipadores?status=pending"' in admin_view
    assert 'href="/dashboard"' not in admin_view, "Admin cannot receive creator workspace shortcuts"
    from urllib.error import HTTPError
    try:
        get(creator,"/admin")
        raise AssertionError("Creators must not enter admin")
    except HTTPError as exc:
        assert exc.code==403, "Admin access control"
    assert "Criador Teste" in get(admin,"/admin/clipadores?status=pending")
    assert "Criador Teste" not in get(admin,"/admin/clipadores?status=approved")
    assert "Pendente" in get(admin,"/admin/clipadores?status=pending")
    import json
    from urllib.error import HTTPError
    stranger=make_client()
    try:
        get(stranger,"/api/realtime/snapshot")
        raise AssertionError("Unauthenticated realtime snapshot must fail")
    except HTTPError as error:
        assert error.code==401
    admin_snap=json.loads(get(admin,"/api/realtime/snapshot"))
    assert "pending_users" in admin_snap["state"]
    assert "available_cents" not in admin_snap["state"]
    assert "creator.registered" in [x["type"] for x in admin_snap["events"]]
    # Banner admin: edit all visible fields without altering active campaigns.
    home_editor=get(admin,"/admin/home")
    assert "Banner da página inicial" in home_editor, "Admin home editor"
    assert "home-media-upload" in home_editor, "Media upload input"

    banner_form={
      "enabled":"1","eyebrow":"Novo selo teste",
      "title_top":"Primeira linha teste","title_accent":"Campanha em destaque","title_bottom":"Terceira linha teste",
      "subtitle":"Banner configurado no painel administrativo e renderizado em produção.",
      "cta_primary_text":"Criar agora","cta_primary_url":"/cadastrar",
      "cta_secondary_text":"Abrir catálogo","cta_secondary_url":"/explorar",
      "media_type":"video","media_url":"https://cdn.example.com/demo.mp4",
      "overlay_opacity":"65","text_alignment":"center"
    }
    submit(admin,"/admin/home",dict(banner_form),"/admin/home")
    custom=get(creator,"/")
    assert "Primeira linha teste" in custom and "Campanha em destaque" in custom
    assert 'src="https://cdn.example.com/demo.mp4"' in custom, "Video hero background"
    assert "--home-overlay:0.65" in custom, "Overlay control"
    assert "lp-centered" in custom, "Text alignment"
    assert 'href="/explorar"' in custom and "Abrir catálogo" in custom

    invalid=dict(banner_form,cta_primary_url="javascript:alert(1)")
    submit(admin,"/admin/home",invalid,"/admin/home")
    assert "Primeira linha teste" in get(creator,"/"), "Unsafe URL rejected"

    # Stream a tiny uploaded valid PNG using the private storage endpoint.
    import base64
    png=base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5jAAAAABJRU5ErkJggg==")
    boundary="leaguecut-ci-upload-boundary"
    payload=dict(banner_form,media_type="image",media_url="",text_alignment="left")
    payload["_csrf"]=csrf_from(admin,"/admin/home")
    parts=[]
    for key,value in payload.items():
        parts.append(("--"+boundary+"\r\nContent-Disposition: form-data; name=\""+key+"\"\r\n\r\n"+value+"\r\n").encode())
    parts.append(("--"+boundary+"\r\nContent-Disposition: form-data; name=\"media_upload\"; filename=\"hero.png\"\r\nContent-Type: image/png\r\n\r\n").encode()+png+b"\r\n")
    parts.append(("--"+boundary+"--\r\n").encode())
    request=urllib.request.Request(BASE+"/admin/home",data=b"".join(parts),method="POST",headers={"Content-Type":"multipart/form-data; boundary="+boundary})
    with admin.open(request,timeout=15) as resp:
        assert b"atualizado" in resp.read(), "Upload was accepted"
    uploaded_home=get(creator,"/")
    found=re.search(r'src="(/media/home/[0-9a-f]{36})"',uploaded_home)
    assert found, "Uploaded asset appears on public hero"
    with creator.open(BASE+found.group(1),timeout=15) as response:
        assert response.headers.get("Content-Type")=="image/png", "Correct media type"
        assert response.read().startswith(b"\x89PNG\r\n\x1a\n"), "Media served successfully"

    disabled=dict(banner_form,media_type="default",media_url="")
    disabled.pop("enabled")
    submit(admin,"/admin/home",disabled,"/admin/home")
    fallback=get(creator,"/")
    assert "Seu talento." in fallback and "Primeira linha teste" not in fallback, "Disabled banner returns defaults"
    reset=dict(disabled,enabled="1")
    submit(admin,"/admin/home",reset,"/admin/home")
    assert "Primeira linha teste" in get(creator,"/"), "Can reactivate custom banner"
    # Restore the original text for the remaining smoke flow and initial publication.
    submit(admin,"/admin/home",{
      "enabled":"1","eyebrow":"Criadores no centro de tudo","title_top":"Seu talento.",
      "title_accent":"Mais alcance.","title_bottom":"Novas conquistas.",
      "subtitle":"O espaço de quem transforma vídeos em oportunidades. Participe de campanhas, publique seus conteúdos e acompanhe recompensas verificadas conforme as regras.",
      "cta_primary_text":"Quero ser clipador","cta_primary_url":"/cadastrar",
      "cta_secondary_text":"Conhecer campanhas","cta_secondary_url":"/explorar",
      "media_type":"default","media_url":"","overlay_opacity":"50","text_alignment":"left"
    },"/admin/home")

    pending=get(admin,"/admin/clipadores")
    match=re.search(r'action="(/admin/clipadores/(\d+)/aprovar)"',pending)
    assert match, "Expected pending creator"
    approval=submit(admin,match.group(1),{},"/admin/clipadores")
    assert "aprovado" in approval.lower(), "Approval response"
    creator_dashboard=get(creator,"/dashboard")
    assert "Olá," in creator_dashboard
    assert "Seu próximo passo" in creator_dashboard
    assert "Últimas publicações" in creator_dashboard
    assert "workspace-shell" in creator_dashboard
    assert 'href="/redes"' in creator_dashboard
    assert 'href="/admin"' not in creator_dashboard, "Creator cannot see admin sidebar"
    assert "Criador Teste" in get(admin,"/admin/clipadores?status=approved")
    assert "Criador Teste" not in get(admin,"/admin/clipadores?status=pending"), "Creator dashboard"
    creator_snap=json.loads(get(creator,"/api/realtime/snapshot"))
    assert creator_snap["state"]["available_cents"]==0
    assert "creator.approved" in [x["type"] for x in creator_snap["events"]]
    assert "creator.registered" not in [x["type"] for x in creator_snap["events"]], "No admin event leakage"

    created=submit(admin,"/admin/campanhas",{
      "title":"Campanha Teste Real",
      "description":"Recompensa por clipes aprovados com meta de cinco por semana.",
      "category":"Entrevistas","cover_url":"","status":"active",
      "min_videos_week":"5","payment_per_video":"15,00","milestone_views":"1000000",
      "milestone_bonus":"20,00","monthly_leader_bonus":"20,00",
      "starts_at":"","ends_at":""
    },"/admin/campanhas")
    assert "Campanha Teste Real" in created
    assert "lc-catalog-showcase" in created and "lc-catalog-shelves" in created, "Admin Netflix catalog"
    assert 'href="/admin/campanhas/' in created and 'Editar campanha' in created
    assert "Criar nova campanha" in created, "Admin creation form still usable"
    home=get(creator,"/")
    assert "Campanha Teste Real" not in home, "No campaign cards on marketing home"
    catalog=get(creator,"/explorar")
    assert "Campanha Teste Real" in catalog, "Campaign in public catalog"
    assert 'lc-catalog-showcase' in catalog and 'lc-catalog-shelf' in catalog, "Cinema campaign catalog"
    assert 'campaign-experience.css' in catalog
    assert 'workspace-motion.css' not in catalog, "Landing/public pages must not receive panel animations"
    assert 'workspace-motion.css' not in get(creator,"/"), "Marketing LP has no entry animation"
    assert "Campanha Teste Real" in get(creator,"/explorar?q=Real"), "Search works"
    assert "Campanha Teste Real" in get(creator,"/explorar?categoria=Entrevistas"), "Category filter works"
    assert "Nenhuma campanha encontrada" in get(creator,"/explorar?q=termo-inexistente-123"), "Search no results"
    slug=re.search(r'href="/campanhas/([a-z0-9-]+)"',catalog)
    assert slug, "Campaign URL"
    submit(creator,"/redes",{"network":"instagram","username":"criador.validado"},"/redes")
    account_page=get(creator,"/redes")
    assert "criador.validado" in account_page
    campaign=get(creator,"/campanhas/"+slug.group(1))
    assert 'Ranking da campanha' in campaign and 'Arena de criadores' in campaign
    assert "primeiro destaque" in campaign, "Empty ranking"
    assert "100 por clipe aprovado" in campaign, "XP formula must be transparent"
    campaign_id=re.search(r'name="campaign_id" value="(\d+)"',campaign).group(1)
    account_id=re.search(r'<option value="(\d+)">Instagram',campaign).group(1)
    # The original file is mandatory, even if someone bypasses HTML validation.
    missing_url="https://www.instagram.com/reel/missingfile/"
    from urllib.error import HTTPError
    try:
        submit(creator,"/clipes",{
          "campaign_id":campaign_id,"social_account_id":account_id,
          "title":"Clipe sem original",
          "published_url":missing_url
        },"/campanhas/"+slug.group(1))
    except HTTPError:
        pass
    assert "Clipe sem original" not in get(creator,"/biblioteca")
    for i in range(5):
        submit_video(creator,(campaign_id,slug.group(1)),account_id,
          "Clipe de teste %02d" % i,"https://www.instagram.com/reel/test%d/" % i)
    clips_before_edit=get(creator,"/biblioteca")
    edit_links=re.findall(r'href="/clipes/(\d+)/editar"',clips_before_edit)
    assert len(edit_links)==5, "Unapproved clips must show edit links"
    assert len(re.findall(r'action="/clipes/(\d+)/excluir"',clips_before_edit))==5
    pending_clip_id=edit_links[-1]
    edit_page=get(creator,f"/clipes/{pending_clip_id}/editar")
    assert 'data-optional-video="1"' in edit_page and 'name="social_account_id"' in edit_page
    assert "Salvar alterações" in edit_page and 'id="lc-upload-percent"' in edit_page
    changed=submit(creator,f"/clipes/{pending_clip_id}/editar",{
       "title":"Clipe corrigido sem troca de arquivo",
       "published_url":"https://www.instagram.com/reel/corrected_test/",
       "social_account_id":account_id
    },f"/clipes/{pending_clip_id}/editar")
    assert "Clipe corrigido sem troca de arquivo" in changed
    assert f'data-preview-url="/clipes/{pending_clip_id}/video"' in changed
    replace_clip_video(creator,pending_clip_id,account_id,
        "Clipe corrigido com novo original","https://www.instagram.com/reel/corrected_video/")
    assert "Clipe corrigido com novo original" in get(creator,"/biblioteca")
    from urllib.error import HTTPError
    try:
        get(creator,"/clipes/999999/editar")
        raise AssertionError("Foreign/missing clip exposed")
    except HTTPError as exc:
        assert exc.code==404
    try:
        get(admin,f"/clipes/{pending_clip_id}/editar")
        raise AssertionError("Admin must not edit via clipper workspace")
    except HTTPError as exc:
        assert exc.code==403
    # Delete an unapproved upload, including its Drive original.
    delete_clip_id=edit_links[-2]
    after_delete=submit(creator,f"/clipes/{delete_clip_id}/excluir",{},"/biblioteca")
    assert "original removido do Google Drive" in after_delete
    assert f'href="/clipes/{delete_clip_id}/editar"' not in after_delete
    submit_video(creator,(campaign_id,slug.group(1)),account_id,
        "Clipe substituto pelo criador","https://www.instagram.com/reel/creator_replace/")
    assert len(re.findall(r'href="/clipes/(\d+)/editar"',get(creator,"/biblioteca")))==5
    clips_page=get(creator,"/biblioteca")
    assert "Clipe de teste 04" in clips_page
    assert 'class="clip-preview-trigger"' in clips_page or 'clip-preview-trigger' in clips_page
    assert "Assistir" in clips_page
    assert 'src="/assets/clip-preview.js?v=8"' in clips_page
    assert 'data-preview-url="/clipes/' in clips_page
    assert "obrigatório" in get(creator,"/campanhas/"+slug.group(1))
    # Existing private videos cannot be fetched by unauthenticated visitors.
    from urllib.error import HTTPError
    guest_video=get(make_client(),"/clipes/1/video")
    assert 'Entrar' in guest_video and 'video/mp4' not in guest_video, "Preview must require authentication"

    creator_after=get(creator,"/dashboard")
    assert "Clipe de teste 04" in creator_after, "Recent activity includes real clip"
    assert "5" in creator_after, "Dashboard shows submitted clip count"
    assert "Clipes em análise" in creator_after
    assert "Clipe de teste 04" in get(admin,"/admin/clipes?status=pending")
    assert "Clipe de teste 04" not in get(admin,"/admin/clipes?status=approved")
    review=get(admin,"/admin/clipes")
    assert 'name="verified_views"' not in review, "Manual views input must be removed"
    assert '<select name="decision"' not in review, "Review select must be removed"
    assert 'value="approved"' in review, "Approvals use a hidden decision, not a select"
    assert 'Excluir' in review and '/excluir"' in review, "Delete button for all clips"
    reviews=re.findall(r'action="(/admin/clipes/(\d+)/revisar)"',review)
    assert len(reviews)>=5, "5 videos to review"
    # Delete an uncredited clip: it must disappear from the database, its Drive
    # original must be deleted (or the fake Drive must explicitly accept it).
    deleted_id=reviews[-1][1]
    removed=submit(admin,"/admin/clipes/"+deleted_id+"/excluir",{},"/admin/clipes")
    assert "original removido do Google Drive" in removed
    assert ("action=\"/admin/clipes/"+deleted_id+"/revisar\"") not in get(admin,"/admin/clipes")
    submit_video(creator,(campaign_id,slug.group(1)),account_id,
        "Clipe substituto","https://www.instagram.com/reel/substitution/")
    review=get(admin,"/admin/clipes")
    reviews=re.findall(r'action="(/admin/clipes/(\d+)/revisar)"',review)
    assert len(reviews)==5, "Five after deletion and replacement"
    first_url,first_id=reviews[0]
    # A single button switches from Approve to Reject according to status.
    approved=submit(admin,first_url,{"decision":"approved"},"/admin/clipes")
    assert "Clipe aprovado" in approved
    approved_page=get(admin,"/admin/clipes?status=approved")
    assert ('value="rejected"' in approved_page and
            'Reprovar</button>' in approved_page), "Approved clip must show Reprovar"
    rejected=submit(admin,first_url,{"decision":"rejected"},"/admin/clipes")
    assert "Clipe reprovado" in rejected
    rejected_page=get(admin,"/admin/clipes?status=rejected")
    assert 'Aprovar</button>' in rejected_page, "Rejected clip must show Aprovar"
    assert f'href="/clipes/{first_id}/editar"' in get(creator,"/biblioteca")
    revised=submit(creator,f"/clipes/{first_id}/editar",{
        "title":"Clipe revisado após reprovação",
        "published_url":"https://www.instagram.com/reel/after_rejection/",
        "social_account_id":account_id
    },f"/clipes/{first_id}/editar")
    assert "Clipe revisado após reprovação" in revised
    assert "Clipe revisado após reprovação" in get(admin,"/admin/clipes?status=pending")
    # Editing a rejected clip resets its state to pending.
    assert "Clipe revisado após reprovação" not in get(admin,"/admin/clipes?status=rejected")
    submit(admin,first_url,{"decision":"approved"},"/admin/clipes")
    for url,_id in reviews[1:]:
        submit(admin,url,{"decision":"approved"},"/admin/clipes")
    approved_library=get(creator,"/biblioteca")
    assert 'Bloqueado após aprovação' in approved_library
    for approved_url,approved_id in reviews:
        assert f'href="/clipes/{approved_id}/editar"' not in approved_library
        assert f'action="/clipes/{approved_id}/excluir"' not in approved_library
        try:
            get(creator,f"/clipes/{approved_id}/editar")
            raise AssertionError("Approved clip edit page exposed")
        except HTTPError as exc:
            assert exc.code==403
        try:
            submit(creator,f"/clipes/{approved_id}/excluir",{},"/biblioteca")
            raise AssertionError("Approved clip could be deleted")
        except HTTPError as exc:
            assert exc.code==403
    assert "Clipe de teste 04" in get(admin,"/admin/clipes?status=approved"), "Approved filter"
    assert "Clipe de teste 04" not in get(admin,"/admin/clipes?status=pending"), "Pending filter"
    finance=get(creator,"/financeiro")
    assert "R$ 75,00" in finance, "Five clips (R$75); public views do not auto-credit"
    assert 'data-lc-private' in finance and '/assets/privacy-toggle.js?v=1' in finance, "Clipper finance privacy toggle"
    assert 'lc-private-value' in finance, "Authorized amounts are marked for masking"
    admin_accounts=get(admin,"/admin/contas")
    assert 'data-lc-private' in admin_accounts, "Admin identities are maskable once accounts exist"
    rank_page=get(creator,"/campanhas/"+slug.group(1))
    assert "Ranking da campanha" in rank_page and "lc-podium" in rank_page
    assert re.search(r"@lc_\d+",rank_page) and "500 XP" in rank_page, "Five approved clips yield 500 XP with public nick"
    assert "5 clipes aprovados" in rank_page
    assert 'data-arena-position="1"' in rank_page, "Campaign podium carries medal rank"
    general_rank=get(make_client(),"/ranking")
    assert 'data-arena-position="1"' in general_rank, "Public leaderboard top position styled"

    guest_campaign=get(make_client(),"/campanhas/"+slug.group(1))
    assert 'data-lc-private' not in guest_campaign, "Anonymous campaign rankings must remain public without personal data markers"
    assert re.search(r"@lc_\d+",guest_campaign) and "Criador T." not in guest_campaign, "Anonymous rankings only expose nicks"
    # Once a weekly payment is credited, review/delete must require an audited
    # financial reversal rather than silently corrupting the user's balance.
    refused=submit(admin,first_url,{"decision":"rejected"},"/admin/clipes")
    assert "estorno auditável" in refused
    blocked=submit(admin,"/admin/clipes/"+first_id+"/excluir",{},"/admin/clipes")
    assert "estorno auditável" in blocked
    assert ('action=\"/admin/clipes/'+first_id+'/revisar\"') in get(admin,"/admin/clipes")
    finance=get(creator,"/financeiro")
    assert "R$ 75,00" in finance, "Credits unchanged by blocked actions"
    # Approving an already-approved clip is idempotent.
    submit(admin,first_url,{"decision":"approved"},"/admin/clipes")
    submit(creator,"/financeiro/saques",{"amount":"10,00","current_password":"This-Is-A-Test-Password-123"},"/financeiro")
    finance=get(creator,"/financeiro")
    assert "R$ 65,00" in finance, "Withdrawal reserves available balance"
    admin_finance=get(admin,"/admin/financeiro")
    assert 'data-lc-private' in admin_finance, "Admin Pix and withdrawal amounts must be visually concealable"
    creator_latest=json.loads(get(creator,"/api/realtime/snapshot"))
    assert creator_latest["state"]["available_cents"]==6500
    assert creator_latest["state"]["approved_clips"]==5
    assert creator_latest["state"]["verified_views"]==0, "No manual view credit in new flow"
    assert creator_latest["state"]["pending_clips"]==0
    assert "withdrawal.requested" in [x["type"] for x in creator_latest["events"]]
    admin_latest=json.loads(get(admin,"/api/realtime/snapshot"))
    assert admin_latest["state"]["pending_withdrawals"]==1
    assert "withdrawal.requested" in [x["type"] for x in admin_latest["events"]]
    assert "creator.approved" not in [x["type"] for x in admin_latest["events"]], "No creator-only event leakage"
    assert "Campanha Teste Real" in get(creator,"/explorar")
    assert "Campanha Teste Real" not in get(creator,"/")
    print("SMOKE PASSED: register -> admin approval -> campaigns -> social -> 5 clips -> rejection -> Drive deletion -> safe credits -> withdrawal")


if __name__ == "__main__":
    main()
