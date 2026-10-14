# Bloco 5 — LeagueCut Realtime

O código PHP já entrega a **API HTTP de fallback** em `GET /api/realtime/snapshot` (autenticada). O worker WebSocket usa Ratchet, escuta apenas `127.0.0.1:18765` e NÃO deve ser exposto diretamente na internet.

## Para ligar WSS depois do deploy

1. Verifique na VPS `sudo -u leaguecut php /home/leaguecut/current/bin/realtime-server.php` (teste de escuta; interrompa com Ctrl+C).
2. Copie `ops/leaguecut-realtime.service` para `/etc/systemd/system/leaguecut-realtime.service` como root, rode `systemctl daemon-reload`, `systemctl enable --now leaguecut-realtime` e `systemctl status leaguecut-realtime` (se o binário PHP estiver em outro caminho, altere ExecStart).
3. No CloudPanel, adicione o conteúdo de `ops/nginx-ws-snippet.conf` **dentro do bloco HTTPS público** de `leaguecut.com`, antes do `location /`. Não coloque no bloco 8080 e não edite outros domínios.
4. Execute `nginx -t && systemctl reload nginx` e confirme que o site continua respondendo em `/health`.
5. Configure `REALTIME_WS_ENABLED=1` no arquivo privado `/home/leaguecut/shared/.env`. Nenhuma chave é enviada ao GitHub.
6. Atualize o dashboard e confira a legenda da bandeja: **Em tempo real**. Se aparecer **Atualização automática**, o fallback HTTP continua ativo.
7. Em novos deploys que alterem o worker, execute `systemctl restart leaguecut-realtime`. A execução sob systemd é independente do PHP-FPM e dos demais sites.

**Rollback:** remova a localização `/ws` do vhost, retorne `REALTIME_WS_ENABLED=0` e execute `systemctl disable --now leaguecut-realtime`. A API HTTP permanece disponível.

## Segurança e confiabilidade

- Entrada WSS de mesmo domínio; a conexão só é autenticada quando envia um ticket HMAC de até 60 segundos via primeiro frame (nenhum token na URL).
- Tickets são gerados via POST protegido por sessão e CSRF.
- O backend confere role e status em cada conexão; o worker revalida usuários periodicamente.
- O worker publica somente eventos administrativos para administradores e eventos pessoais para o dono da conta.
- Os eventos são salvos na mesma transação MySQL de aprovação, revisão e saque. Não há envio de valores Pix, dados pessoais ou tokens pelos eventos.
- A aplicação reconcilia contadores via HTTPS mesmo quando WSS está conectado; se o worker cair, usa polling a cada 12 segundos.
- `realtime_events` é append-only; retenção e limpeza segura por cron deverão ser adicionadas antes de volumes maiores.

## Limites atuais

O serviço envia atualização de status e indicadores. As visualizações externas de TikTok/Instagram continuam exigindo verificação; WebSocket não coleta métricas das plataformas. Notificações já existentes por email/WhatsApp são outro fluxo.
