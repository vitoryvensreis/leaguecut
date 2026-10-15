# LeagueCut — Automações + WPPConnect (VPS)

Painel: https://leaguecut.com/admin/automacoes

## Política de instalação

O PHP da LeagueCut só conversa com o WPPConnect via loopback (127.0.0.1).
O processo Node.js/Chromium deve ficar separado da pasta de releases.
Como o TaskEsc já usa WPPConnect, primeiro VERIFIQUE se o serviço existente
pode ser compartilhado usando uma NOVA sessão com nome "leaguecut".
NÃO altere sessão, porta, cron ou unidade do TaskEsc.

## 1. Inspecione a VPS sem alterar serviços

```bash
hostname
whoami
node -v || true
sudo ss -ltnp | grep -E ':(21465|21466)\b' || true
sudo systemctl list-units --type=service --all | grep -Ei 'wpp|taskesc|whatsapp' || true
pm2 list 2>/dev/null || true
sudo -u leaguecut bash -lc 'test -r /home/leaguecut/shared/.env && echo ENV_LEAGUECUT_OK'
```

Não cole .env, tokens, segredo do WPPConnect ou QR Code no chat.

## 2. Aproveite um WPPConnect existente quando possível

1. Identifique a porta local. Não suba outro serviço na porta ocupada.
2. Obtenha a secretKey de forma privada, no arquivo de configuração atual do servidor.
   Troque a chave padrão antes de disponibilizar API.
3. Gere um token para a NOVA sessão leaguecut usando a rota autenticada do serviço:
   POST /api/leaguecut/{secretKey}/generate-token.
4. Inicie a sessão via POST /api/leaguecut/start-session, Bearer token,
   Content-Type application/json, corpo {"webhook":"","waitQrCode":true}.
5. Leia QR em canal privado e conecte número autorizado; verifique
   GET /api/leaguecut/check-connection-session.
6. Edite SOMENTE /home/leaguecut/shared/.env:

```dotenv
WPP_ENABLED=1
WPP_BASE_URL=http://127.0.0.1:21465
WPP_SESSION=leaguecut
WPP_AUTH_TOKEN=TOKEN_DA_SESSAO_GERADO_NO_SERVIDOR
```

Use a porta real; o PHP restringe acesso de saída à interface loopback.

## 3. Se não houver serviço existente

Instale uma instância ISOLADA do WPPConnect Server fora de current;
documentação do projeto: https://wppconnect.io/docs/projects/wppserver/installation/

- Confira RAM, CPU, Node.js e espaço antes: Chromium consome recursos.
- Escolha porta local exclusiva (exemplo 21466), host 127.0.0.1 e secretKey aleatória.
- Persista perfil Chromium e sessões em storage não versionado.
- Gerencie via systemd ou PM2 próprio. Não exponha a API na Internet.
- Não altere webserver/proxy/domínios de outros projetos.

## 4. Testes e logs

No painel /admin/automacoes crie: "Cadastro aprovado" -> "Sempre" ->
"Mensagem WhatsApp" -> "Usuário do evento", com a mensagem:
"Olá, {nome}! Seu cadastro foi aprovado. Entre em: {link}".
Salve e ative manualmente. Utilize conta de teste consentida.
Verifique o histórico de execuções.

```bash
sudo -u leaguecut php /home/leaguecut/current/bin/automation-worker.php
sudo -u leaguecut crontab -l | grep -A1 LEAGUECUT_AUTOMATIONS_MANAGED
sudo -u leaguecut tail -n 40 /home/leaguecut/logs/automations.log
curl --fail -sS https://leaguecut.com/health
```

O workflow GitHub faz backup antes da migração e instala uma entrada de cron
no usuário leaguecut, uma vez por minuto e com bloqueio flock.
A automação é criada desativada por padrão. WhatsApp exige opt-in.
"sent" significa aceitação pelo provedor, não leitura no destinatário.
Quando o WPPConnect não está configurado, a fila permanece pendente.

## Segurança e limites

Não usar WPPConnect para campanhas de disparo indiscriminado ou sem
autorização; existe risco de restrição de conta por uso não oficial.
Para envio em escala e marketing, prefira a Plataforma Oficial do WhatsApp,
com consentimento e modelos aprovados.
