# LeagueCut — Apify Instagram views

## Ativação
1. O importador reconhece `post_url` e `author.username` (campos do exemplo oficial desse Actor), mas o exemplo público **não inclui um campo de views**. Antes de confiar nele, valide com uma saída real: se o resultado não trouxer `video_view_count`, `videoPlayCount` ou outro contador de vídeo reconhecido, o LeagueCut registra zero atualizações e NÃO inventa métricas. Consulte um Actor alternativo que forneça views caso necessário.
2. Para primeiro teste, abra o Actor [danek/instagram-posts-scraper-ppr](https://apify.com/danek/instagram-posts-scraper-ppr) na Apify, rode 1 perfil e confira se o output inclui os campos do **shortcode, autor e views do vídeo**. Se faltar qualquer campo, não aplique valores manualmente sem conferir a fonte.
3. Guarde o token da conta Apify SOMENTE em `/home/leaguecut/shared/.env`: `APIFY_API_TOKEN=...`. Não publique nem envie o token no chat.
4. Há workflow agendado `.github/workflows/apify-metrics.yml` para 00:17, 06:17, 12:17 e 18:17 UTC (sujeito a atrasos/limites do GitHub). Sem token, não faz chamadas pagas.
5. Padrão por chamada: no máximo 20 posts; cobrança limitada pela API a USD 0.05; no máximo 3 execuções/dia e USD 0.15 de reserva diária; mínimo de 24 h entre consultas ao mesmo perfil. Os limites são persistidos no banco e respeitados mesmo após reinício da VPS.
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

## Verificar sem pagar

Em **ADM → Integrações → Métricas Instagram**, clique em **Verificar token (sem cobrança)**. Essa verificação consulta somente `GET /v2/users/me` com autenticação, não inicia o Actor e não consome a reserva diária. Abaixo do botão o administrador vê o histórico recente: horário, sucesso/falha, posts retornados, clipes atualizados e teto previamente reservado. Não exibe tokens e não afirma gasto real.

O ator da imagem `danek/instagram-posts-scraper-ppr` é uma alternativa de coleta, mas a documentação pública não garante métricas de vídeos em seu retorno. O LeagueCut deve descartar respostas sem visualizações comprováveis, evitando créditos incorretos.

## Segurança: agendamento pausado por padrão
O token pode ser configurado e verificado pelo ADM, mas `APIFY_SYNC_ENABLED=0` bloqueia novas execuções agendadas enquanto os resultados reais do Actor não forem validados. A primeira execução do Actor `danek` retornou HTTP 201 com dataset vazio, sem views; `APIFY_SYNC_ENABLED=1` só deve ser usado quando um Actor confiável devolver campos de visualizações válidos.
