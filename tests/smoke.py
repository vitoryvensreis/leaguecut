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
    assert "Seus vídeos." in get(creator,"/")
    assert "Junte-se aos criadores." in get(creator,"/cadastrar")
    creator_page=register(creator,"creator@leaguecut.test","Criador Teste")
    assert "analisando" in creator_page.lower(), "Creator pending page"
    register(admin,"admin@leaguecut.test","Admin Teste")
    import subprocess
    subprocess.check_call(["php","bin/make-admin.php","admin@leaguecut.test"])
    assert "Painel administrativo" in get(admin,"/admin")
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
    slug=re.search(r'href="/campanhas/([a-z0-9-]+)"',home)
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
    assert "Campanha Teste Real" in get(creator,"/")
    print("SMOKE PASSED: register -> admin approval -> campaigns -> social -> 5 clips -> views bonus -> idempotence -> withdrawal")


if __name__ == "__main__":
    main()
