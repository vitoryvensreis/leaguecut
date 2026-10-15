# LeagueCut — Métricas com Instagram Post Scraper oficial (Apify)

- Actor: https://apify.com/apify/instagram-post-scraper
- O sistema consulta links **exatos** dos clipes registrados; não varre perfis inteiros.
- Entrada: `username: ["https://www.instagram.com/reel/SHORTCODE/"]`, `resultsLimit: 1`, `dataDetailLevel: "detailedData"`.
- A Apify pode cobrar mais por dados detalhados, imprescindíveis para coletar `videoViewCount` ou `videoPlayCount`. O preço anunciado como "a partir de" não é garantia de preço final. Confira o extrato da Apify.
- O LeagueCut aceita apenas retorno com shortcode e autor do post correspondentes ao registro do clipador. Views ausentes não são substituídas por zero inventado.
- `clip_view_samples` registra visualizações **observadas**, não verifica propriedade da conta e não libera saldo/pagamentos. Admin aprova métricas financeiras separadamente.
- O histórico `apify_profile_runs` mantém reservas por publicação, inclusive falhas, prevenindo repetição no mesmo dia. O nome da coluna `username` é mantido para compatibilidade com a migração existente e armazena `post:SHORTCODE` para as novas execuções.
- O job usa `maxItems=1` e `maxTotalChargeUsd` por execução, teto diário agregado e máximo de execuções/dia; padrão: US$ 0,05, US$ 0,15/dia, 3 execuções/dia, 24h entre consultas ao mesmo post.
- **Agendamento ativo:** o deploy instala uma entrada isolada no crontab do usuário `leaguecut`, que dispara à hora cheia e apenas coleta na janela das **02:00 de Fortaleza**, independentemente do fuso do servidor. O GitHub Actions às 05:00 UTC permanece como contingência, pois seus agendamentos podem atrasar. A Apify exige `APIFY_API_TOKEN` no `.env` privado; a antiga flag `APIFY_SYNC_ENABLED` é ignorada.
- **Segurança:** limites de custo e de chamadas continuam obrigatórios; o token não deve aparecer em logs. Uso de scraping não equivale a dados autorizados da Meta Graph API.

## Operação diária
1. Confirme que `APIFY_API_TOKEN` existe em `/home/leaguecut/shared/.env`; sem ele, o workflow informa falha e não dispara Actor cobrado.
2. Confira a confirmação `APIFY_VPS_CRON_02H_INSTALLED` no deploy; monitore `/home/leaguecut/logs/apify-cron.log` no servidor e o job de contingência **GitHub Actions → LeagueCut views daily 02h Fortaleza - limited Apify**.
3. Views não são tempo real. A consulta usa orçamento e cache; o Instagram pode ocultar métricas. Para suspender cobranças, desative o workflow agendado no GitHub ou remova o token do `.env`.

## Seleção automática de clipes (teste real)
O scraper só consulta clipes **aprovados**, com vídeo original presente no Google Drive e pertencentes a campanhas ativas. Pendentes e rejeitados não consomem chamadas. Os valores encontrados são apenas *views observadas* e não geram créditos sem revisão.
