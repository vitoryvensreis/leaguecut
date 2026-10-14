# LeagueCut — Métricas com Instagram Post Scraper oficial (Apify)

- Actor: https://apify.com/apify/instagram-post-scraper
- O sistema consulta links **exatos** dos clipes registrados; não varre perfis inteiros.
- Entrada: `username: ["https://www.instagram.com/reel/SHORTCODE/"]`, `resultsLimit: 1`, `dataDetailLevel: "detailedData"`.
- A Apify pode cobrar mais por dados detalhados, imprescindíveis para coletar `videoViewCount` ou `videoPlayCount`. O preço anunciado como "a partir de" não é garantia de preço final. Confira o extrato da Apify.
- O LeagueCut aceita apenas retorno com shortcode e autor do post correspondentes ao registro do clipador. Views ausentes não são substituídas por zero inventado.
- `clip_view_samples` registra visualizações **observadas**, não verifica propriedade da conta e não libera saldo/pagamentos. Admin aprova métricas financeiras separadamente.
- O histórico `apify_profile_runs` mantém reservas por publicação, inclusive falhas, prevenindo repetição no mesmo dia. O nome da coluna `username` é mantido para compatibilidade com a migração existente e armazena `post:SHORTCODE` para as novas execuções.
- O job usa `maxItems=1` e `maxTotalChargeUsd` por execução, teto diário agregado e máximo de execuções/dia; padrão: US$ 0,05, US$ 0,15/dia, 3 execuções/dia, 24h entre consultas ao mesmo post.
- **Segurança:** `APIFY_SYNC_ENABLED=0` por padrão. Não ative cron até uma execução real confirmar valores. Apify API Token permanece no `.env` privado. Uso de scraping não equivale a dados autorizados da Meta Graph API.

## Após teste
1. Verifique em **ADM → Integrações** se as métricas apareceram e se o histórico registra uma execução.
2. Caso haja um vídeo válido e todos os limites confirmados, ajuste `APIFY_SYNC_ENABLED=1` no `.env` para habilitar os próximos ciclos agendados, ou mantenha desativado.
3. Views coletadas não são tempo real; o coletor consulta sob orçamento e cache e o Instagram pode ocultar métricas.

## Seleção automática de clipes (teste real)
O scraper só consulta clipes **aprovados**, com vídeo original presente no Google Drive e pertencentes a campanhas ativas. Pendentes e rejeitados não consomem chamadas. Os valores encontrados são apenas *views observadas* e não geram créditos sem revisão.
