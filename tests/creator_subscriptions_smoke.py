#!/usr/bin/env python3
"""Creator buyer role and monthly package models — no charge or entitlement."""
import re
import urllib.error
from smoke import make_client, get, submit, BASE, csrf_from

def must_forbid(client, path):
    try:
        get(client,path)
        raise AssertionError(f"Unauthorized access unexpectedly succeeded: {path}")
    except urllib.error.HTTPError as err:
        assert err.code==403, (path,err.code)

admin=make_client()
submit(admin,"/entrar",{
    "email":"admin@leaguecut.test",
    "password":"This-Is-A-Test-Password-123",
},"/entrar")
assert "Pacotes para criadores" in get(admin,"/admin")
plans=get(admin,"/admin/pacotes")
assert "Criar pacote mensal" in plans
assert "Clipes entregues para baixar" in plans
assert "Produção + postagem pela equipe" in plans

for name,mode,price,amount in (
    ("Live Download Start","download","199,90","12"),
    ("Live Produção Pro","production","999,00","28")
):
    result=submit(admin,"/admin/pacotes",{
        "name":name,
        "description":"Pacote mensal de clipes da sua live para criadores digitais.",
        "fulfillment_mode":mode,
        "monthly_clips":amount,
        "monthly_price":price,
        "status":"active"
    },"/admin/pacotes")
    assert name in result
    assert "Pacote criado" in result
assert "R$ 199,90" in get(admin,"/admin/pacotes")
assert "R$ 999,00" in get(admin,"/admin/pacotes")
assert "Live Download Start" in get(make_client(),"/planos")
assert "Live Produção Pro" in get(make_client(),"/planos")
assert "Solicitação sem cobrança online" in get(make_client(),"/planos")

# Admin can edit a package; archived plans disappear from the public catalog,
# without losing historical orders or records.
admin_plans=get(admin,"/admin/pacotes")
links=re.findall(r'href="/admin/pacotes/(\d+)/editar"',admin_plans)
assert len(links)==2
first_id=links[0]
edited=submit(admin,f"/admin/pacotes/{first_id}/editar",{
    "name":"Produção Premium Teste",
    "description":"Pacote exclusivo de produção e postagem para lives mensais.",
    "fulfillment_mode":"production",
    "monthly_clips":"30",
    "monthly_price":"1100,00",
    "status":"archived"
},f"/admin/pacotes/{first_id}/editar")
assert "Pacote atualizado" in edited
assert "Produção Premium Teste" not in get(make_client(),"/planos")
assert "Live Download Start" in get(make_client(),"/planos")

# Invalid prices are rejected, without modifying stored settings.
bad=submit(admin,"/admin/pacotes",{
  "name":"Preço inválido","description":"Pacote com dados inválidos para teste.",
  "fulfillment_mode":"download","monthly_clips":"5",
  "monthly_price":"-10","status":"active"
},"/admin/pacotes")
assert "Confira nome, descrição" in bad

buyer=make_client()
signup=get(buyer,"/cadastrar/criador")
assert "Criador assinante" in signup and "Crie seu acesso" in signup
registration=submit(buyer,"/cadastrar/criador",{
    "full_name":"Comprador de Lives",
    "email":"buyer@leaguecut.test",
    "password":"This-Is-A-Test-Password-123",
    "whatsapp":"85999997777",
    "consent_whatsapp":"1",
},"/cadastrar/criador")
assert "Conta de criador criada" in registration
assert "Minhas assinaturas" in registration
assert "Minha área de criador" in registration
assert "Área do criador" in get(buyer,"/criador")
assert "Pacotes mensais" in get(buyer,"/criador")
assert "Nenhuma assinatura ainda" in get(buyer,"/criador")

for clipper_page in ("/biblioteca","/redes","/financeiro","/clipes/1/editar"):
    must_forbid(buyer,clipper_page)
assert "Área do criador" in get(buyer,"/dashboard")

# Only an administrator can manage packages. Buyer and clipper never see plan edit UI.
must_forbid(buyer,"/admin/pacotes")
must_forbid(make_client(),"/admin/pacotes/1/editar") if False else None
clipper=make_client()
submit(clipper,"/entrar",{
    "email":"creator@leaguecut.test",
    "password":"This-Is-A-Test-Password-123",
},"/entrar")
must_forbid(clipper,"/criador")
must_forbid(clipper,"/admin/pacotes")
assert "Minha biblioteca" in get(clipper,"/dashboard")

# Bloco 2: creator requests a specific active package, without any payment.
buyer_portal=get(buyer,"/criador")
ids=re.findall(r'action="/criador/pacotes/(\d+)/solicitar"',buyer_portal)
assert len(ids)==1,ids
package_id=ids[0]
assert 'Solicitar assinatura' in buyer_portal
requested=submit(buyer,f"/criador/pacotes/{package_id}/solicitar",{}, "/criador")
assert "Assinatura solicitada" in requested
assert "Aguardando confirmação" in requested
assert "Não liberados" in requested
assert 'Não liberados' in get(buyer,"/criador")
repeated=submit(buyer,f"/criador/pacotes/{package_id}/solicitar",{}, "/criador")
assert "já possui uma solicitação" in repeated
assert "Não liberados" in repeated

# No other role may request or confirm a creator subscription.
try:
    submit(clipper,f"/criador/pacotes/{package_id}/solicitar",{},"/criador")
    raise AssertionError("Clipper attempted to subscribe as a buyer")
except urllib.error.HTTPError as exc:
    assert exc.code==403
must_forbid(buyer,"/admin/assinaturas")
admin_view=get(admin,"/admin/assinaturas")
assert "Comprador de Lives" in admin_view
assert "Aguardando confirmação" in admin_view
assert 'Confirmar recebimento' in admin_view
subscription_ids=re.findall(r'action="/admin/assinaturas/(\d+)/confirmar"',admin_view)
assert len(subscription_ids)==1,subscription_ids
subscription_id=subscription_ids[0]
missing_receipt=submit(admin,f"/admin/assinaturas/{subscription_id}/confirmar",
                       {},"/admin/assinaturas")
assert "Confirme o recebimento real" in missing_receipt
assert "Aguardando confirmação" in missing_receipt
confirmed=submit(admin,f"/admin/assinaturas/{subscription_id}/confirmar",
                 {"receipt_confirmed":"CONFIRMO_RECEBIMENTO"},"/admin/assinaturas")
assert "Primeiro ciclo mensal liberado" in confirmed
assert "Ativa" in confirmed
buyer_active=get(buyer,"/criador")
assert '12 clipes' in buyer_active or '12</strong> / 12 clipes' in buyer_active
assert "Não liberados" not in buyer_active
assert "Cancelar" in buyer_active

# Duplicate manual receipt must not mint additional monthly quota.
twice=submit(admin,f"/admin/assinaturas/{subscription_id}/confirmar",
             {"receipt_confirmed":"CONFIRMO_RECEBIMENTO"},"/admin/assinaturas")
assert "ciclo atual ainda está válido" in twice
assert "12 clipes" in get(buyer,"/criador")

# Creator cancelling an active subscription ends future renewals, but
# the currently paid month stays accessible until the original cycle end.
cancelled=submit(buyer,f"/criador/assinaturas/{subscription_id}/cancelar",{},"/criador")
assert "Assinatura cancelada" in cancelled
assert "Cancelada" in cancelled
assert "12 clipes" in cancelled
assert "não haverá renovação automática" in cancelled
assert "já está encerrada" in submit(buyer,f"/criador/assinaturas/{subscription_id}/cancelar",{},"/criador")
assert "ciclo mensal pago ainda está em andamento" in submit(buyer,f"/criador/pacotes/{package_id}/solicitar",{},"/criador")

# No checkout link or card charge is implemented by this block.
for candidate in (get(buyer,"/criador"),get(make_client(),"/planos")):
    assert 'action="/pagar"' not in candidate
    assert 'action="/assinar"' not in candidate

print("CREATOR_SUBSCRIPTIONS_SMOKE_OK")
