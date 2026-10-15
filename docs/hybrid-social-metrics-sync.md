# TikTok — sincronização oficial com segurança financeira

Base: PR #25, branch `feat/hybrid-social-metrics-sync`.

Esta etapa implementa um adapter da API oficial TikTok `POST /v2/video/query/?fields=id,view_count`, que requer `video.list` autorizado. A consulta retorna apenas vídeo pertencente à conta autorizada. IDs são conferidos antes de registrar qualquer métrica.

`bin/hybrid-tiktok-sync.php` é **CLI e desligado por padrão**. Usa somente clipes aprovados, com ID TikTok canônico, conta social vinculada, token não revogado/não expirado e intervalo de 24h entre observações válidas. Limite de 1 a 20 consultas por execução. Nenhum cron é criado.

Métricas são observacionais e vão para `clip_verification_observations` e `clip_verification_state`. NÃO altera `clips.verified_views`, `ledger_entries`, pagamentos ou ranking. Não faz refresh em massa.

## Antes de ativar
- Revisão de segurança de todos os PRs #20–#25 e deste.
- Aplicar as migrações em banco de staging restaurado e comparar schemas; testar rollback.
- Aprovar app TikTok, permissão `video.list` e callback real.
- Testar `php -l app/tiktok-official-metrics.php`, `php -l bin/hybrid-tiktok-sync.php` e `php tests/tiktok-official-metrics.php`.
- No staging apenas, definir `HYBRID_TIKTOK_SYNC_ENABLED=1` no .env privado e executar manualmente `php bin/hybrid-tiktok-sync.php`.
- Não publicar nem mesclar até observar respostas reais com contas autorizadas.

Instagram: a API oficial para contas profissionais tem regras diferentes de objetos de mídia e Insights; não é correto adaptar cegamente a busca por shortcode do TikTok. A integração de métricas do Instagram continua pendente de revisão do media ID/insights e das permissões da Meta.
