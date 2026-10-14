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
    assert "Painel administrativo" in get(admin,"/admin")
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
    assert "Olá," in get(creator,"/dashboard"), "Creator dashboard"

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
    for i in range(5):
        submit(creator,"/clipes",{
          "campaign_id":campaign_id,"social_account_id":account_id,
          "title":"Clipe de teste %02d" % i,
          "published_url":"https://www.instagram.com/reel/test%d/" % i
        },"/campanhas/"+slug.group(1))
    clips_page=get(creator,"/biblioteca")
    assert "Clipe de teste 04" in clips_page
    review=get(admin,"/admin/clipes")
    reviews=re.findall(r'action="(/admin/clipes/(\d+)/revisar)"',review)
    assert len(reviews)>=5, "5 videos to review"
    for i,(url,_id) in enumerate(reviews[:5]):
        submit(admin,url,{"verified_views":"1000000" if i==0 else "200","decision":"approved"},"/admin/clipes")
    finance=get(creator,"/financeiro")
    assert "R$ 95,00" in finance, "Five clips (R$75) + views bonus (R$20)"
    submit(admin,reviews[0][0],{"verified_views":"1000000","decision":"approved"},"/admin/clipes")
    finance=get(creator,"/financeiro")
    assert "R$ 95,00" in finance, "Idempotence"
    submit(creator,"/financeiro/saques",{"amount":"10,00"},"/financeiro")
    finance=get(creator,"/financeiro")
    assert "R$ 85,00" in finance, "Withdrawal reserves available balance"
    assert "Campanha Teste Real" in get(creator,"/explorar")
    assert "Campanha Teste Real" not in get(creator,"/")
    print("SMOKE PASSED: register -> admin approval -> campaigns -> social -> 5 clips -> views bonus -> idempotence -> withdrawal")


if __name__ == "__main__":
    main()
