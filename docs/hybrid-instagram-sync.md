# Instagram — métricas via API oficial (não habilitar em produção)

Branch baseada no PR #26, integração em rascunho.

Implementado um adapter observacional para Instagram profissional, com Instagram Login e escopo `instagram_business_basic`. O adaptador lista mídias da conta autorizada, compara permalink/shortcode e username exatos, então consulta `/{media-id}/insights?metric=views`. Caso a publicação não esteja na primeira página ou Insights não retorne a métrica, **não** transforma ausência em zero views. Paginação e diferenças de tipo de mídia ainda demandam testes com a API real.

O CLI `bin/hybrid-instagram-sync.php` é opt-in pela flag `HYBRID_INSTAGRAM_SYNC_ENABLED=1`, desligada por padrão. Lotes de 1–10, clipes aprovados, OAuth ativo e 24h de cooldown. Não cria cron.

Os resultados alimentam apenas `clip_verification_state` / `clip_verification_observations`. Não altera `clips.verified_views`, `ledger_entries`, Ranking ou pagamentos.

**Não mesclar/deploy** antes de: testes PHP e banco staging, aprovação do aplicativo Meta, verificação dos endpoints e tokens, testes reais de insights para Reels, paginação, auditoria de escopos, expiração/renovação e tratamento de limites. Revisar todos os PRs encadeados #20–#26 e migração antes de instalar.

Referências: https://www.postman.com/meta/instagram/folder/6raa77c/instagram-api-with-instagram-login e documentação Instagram Platform Meta.
