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

def register(client,email,name):
    return submit(client,"/cadastrar",{
      "full_name":name,"email":email,"password":"This-Is-A-Test-Password-123",
      "instagram":"testcreator","whatsapp":"85999998888","portfolio_url":"",
      "pix_key":"test@example.com","consent_whatsapp":"1"
    },"/cadastrar")


def main():
    creator=make_client()
    admin=make_client()
    home=get(creator,"/")
    assert "Seu talento." in home, "Landing hero"
    assert "Perguntas frequentes" in home, "FAQ section"
    assert 'href="/explorar"' in home, "Catalog link"
    assert "Novas campanhas em breve" in get(creator,"/explorar"), "Catalog empty state"
    assert "Campanhas</a>" in home, "Catalog navigation"
    assert 'assets/landing.css' in home, "Landing stylesheet"
    assert 'assets/v2.css' in get(creator,"/")
    assert 'assets/icons.svg' in get(creator,"/")
    assert 'lc-icon' in get(creator,"/")
    assert "Junte-se aos criadores." in get(creator,"/cadastrar")
    creator_page=register(creator,"creator@leaguecut.test","Criador Teste")
    assert "analisando" in creator_page.lower(), "Creator pending page"
    register(admin,"admin@leaguecut.test","Admin Teste")
    import subprocess
    subprocess.check_call(["php","bin/make-admin.php","admin@leaguecut.test"])
    from urllib.error import HTTPError
    # Bloco 7: Google Drive connection is admin-only and never exposes OAuth tokens.
    integrations=get(admin,"/admin/integracoes")
    assert "Google Drive" in integrations
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
    home=get(creator,"/")
    assert "Campanha Teste Real" not in home, "No campaign cards on marketing home"
    catalog=get(creator,"/explorar")
    assert "Campanha Teste Real" in catalog, "Campaign in public catalog"
    assert "Campanha Teste Real" in get(creator,"/explorar?q=Real"), "Search works"
    assert "Campanha Teste Real" in get(creator,"/explorar?categoria=Entrevistas"), "Category filter works"
    assert "Nenhuma campanha encontrada" in get(creator,"/explorar?q=termo-inexistente-123"), "Search no results"
    slug=re.search(r'href="/campanhas/([a-z0-9-]+)"',catalog)
    assert slug, "Campaign URL"
    submit(creator,"/redes",{"network":"instagram","username":"criador.validado"},"/redes")
    account_page=get(creator,"/redes")
    assert "criador.validado" in account_page
    campaign=get(creator,"/campanhas/"+slug.group(1))
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
    reviews=re.findall(r'action="(/admin/clipes/(\d+)/revisar)"',review)
    assert len(reviews)>=5, "5 videos to review"
    for i,(url,_id) in enumerate(reviews[:5]):
        submit(admin,url,{"verified_views":"1000000" if i==0 else "200","decision":"approved"},"/admin/clipes")
    assert "Clipe de teste 04" in get(admin,"/admin/clipes?status=approved"), "Approved filter"
    assert "Clipe de teste 04" not in get(admin,"/admin/clipes?status=pending"), "Pending filter"
    finance=get(creator,"/financeiro")
    assert "R$ 95,00" in finance, "Five clips (R$75) + views bonus (R$20)"
    submit(admin,reviews[0][0],{"verified_views":"1000000","decision":"approved"},"/admin/clipes")
    finance=get(creator,"/financeiro")
    assert "R$ 95,00" in finance, "Idempotence"
    submit(creator,"/financeiro/saques",{"amount":"10,00"},"/financeiro")
    finance=get(creator,"/financeiro")
    assert "R$ 85,00" in finance, "Withdrawal reserves available balance"
    creator_latest=json.loads(get(creator,"/api/realtime/snapshot"))
    assert creator_latest["state"]["available_cents"]==8500
    assert creator_latest["state"]["approved_clips"]==5
    assert creator_latest["state"]["verified_views"]>=1000000
    assert creator_latest["state"]["pending_clips"]==0
    assert "withdrawal.requested" in [x["type"] for x in creator_latest["events"]]
    admin_latest=json.loads(get(admin,"/api/realtime/snapshot"))
    assert admin_latest["state"]["pending_withdrawals"]==1
    assert "withdrawal.requested" in [x["type"] for x in admin_latest["events"]]
    assert "creator.approved" not in [x["type"] for x in admin_latest["events"]], "No creator-only event leakage"
    assert "Campanha Teste Real" in get(creator,"/explorar")
    assert "Campanha Teste Real" not in get(creator,"/")
    print("SMOKE PASSED: register -> admin approval -> campaigns -> social -> 5 clips -> views bonus -> idempotence -> withdrawal")


if __name__ == "__main__":
    main()
