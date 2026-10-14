# LeagueCut — Apify Instagram views

## Ativação
1. Para primeiro teste, abra o Actor [danek/instagram-posts-scraper-ppr](https://apify.com/danek/instagram-posts-scraper-ppr) na Apify, rode 1 perfil e confira se o output inclui os campos do **shortcode, autor e views do vídeo**. Se faltar qualquer campo, não aplique valores manualmente sem conferir a fonte.
2. Guarde o token da conta Apify SOMENTE em `/home/leaguecut/shared/.env`: `APIFY_API_TOKEN=...`. Não publique nem envie o token no chat.
3. Há workflow agendado `.github/workflows/apify-metrics.yml` para 00:17, 06:17, 12:17 e 18:17 UTC (sujeito a atrasos/limites do GitHub). Sem token, não faz chamadas pagas.
4. Padrão por chamada: no máximo 20 posts; cobrança limitada pela API a USD 0.05; no máximo 3 execuções/dia e USD 0.15 de reserva diária; mínimo de 24 h entre consultas ao mesmo perfil. Os limites são persistidos no banco e respeitados mesmo após reinício da VPS.
5. A seleção é feita apenas entre perfis Instagram com clipes pendentes/aprovados em campanhas ativas, enviados nos últimos 30 dias.
6. O Actor recebe somente `username` e `max_posts` conforme seu formulário. Perfis que possuem muitos posts recentes podem ter clipes antigos não encontrados; não inventamos views.
7. O scraper precisa fornecer **shortcode exato, autor correspondente ao perfil cadastrado e contador de views**. Resultado com dados incompletos não altera contadores.
8. Dados coletados são mostrados como **observados via Apify**, com data, separados de `verified_views`. O administrador confirma os valores ao aprovar o vídeo ou atualizar clipes aprovados. Nenhum valor coletado credita dinheiro automaticamente.
9. Os custos citados são tetos configurados/reservados, **não uma leitura de gasto real da fatura Apify**. A Apify pode cobrar modelos de preços diferentes, e os valores atuais do Actor devem ser conferidos na conta.
10. A coleta não é tempo real: o Instagram pode atrasar/ocultar números e as consultas têm cache e limite diário. Para contas profissionais conectadas, a alternativa oficial Meta Graph API pode reduzir custo de scraping, mas requer permissões por conta e acesso a métricas autorizadas.

## Variáveis opcionais
```ini
APIFY_API_TOKEN=
APIFY_MAX_POSTS=20
APIFY_MAX_RUN_CHARGE_USD=0.05
APIFY_DAILY_BUDGET_USD=0.15
APIFY_MAX_RUNS_PER_DAY=3
APIFY_MIN_HOURS_BETWEEN=24
```

## Segurança
A aplicação não envia senha do Instagram à Apify. A API token é lida apenas pelo worker na VPS. Um lock MySQL impede simultaneidade e cada tentativa de ator é contabilizada antes da requisição. Em caso de falha, a reserva NÃO é apagada.

O upload original é obrigatório para novos clipes. Para vídeos antigos já aceitos sem original, a biblioteca mantém o registro de forma legada, sem criar arquivo falso. O player privado usa streaming HTTP por intervalos e exige sessão do dono ou do administrador.
