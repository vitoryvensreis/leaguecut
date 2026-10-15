# League Cut — candidato consolidado de homologação (NÃO publicar)

Esta branch herda sequencialmente o código dos PRs #20–#34. É somente um ponto único de revisão e homologação, não um merge no branch principal.

## Evidências de CI
- PHP lint/testes isolados: sucesso no GitHub Actions (run 38660092497).
- Migração aditiva MySQL 8 em banco descartável: sucesso (run 38660295533).
- Validação de TikTok fail-closed: sucesso (run 38660455667).
- Proteções OAuth/lifecycle: sucessos nos runs 38660586731 e 38660722876.
- Backfill histórico: lint aprovado (run 38660847146).
- Preflight read-only: lint aprovado (run 38661052638).

**Não significa E2E OAuth real**, equivalência com o banco produtivo ou prontidão para deploy.

## Flags obrigatoriamente desligadas
HYBRID_SOCIAL_OAUTH_ENABLED=0
HYBRID_TIKTOK_SYNC_ENABLED=0
HYBRID_INSTAGRAM_SYNC_ENABLED=0
HYBRID_YOUTUBE_SYNC_ENABLED=0
HYBRID_BACKFILL_ENABLED=0

## Bloqueios antes de publicação
1. Registrar apps Meta/TikTok, configurar redirect URIs e garantir permissões aprovadas (não colocar segredos no Git).
2. Resolver e testar o ciclo específico do token Meta/Instagram de longa duração (o guarda de expiração atualmente falha fechado quando a validade não é comprovada).
3. Testar OAuth real com contas controladas e conferir identidade, escopos, disconnect e contagens de cada API.
4. Comparar esquema da VPS contra migração, backup/restore verificado e testar staging com dados representativos.
5. Exigir aprovação explícita para aplicar migração, atualizar PHP e ativar flags separadamente.

Nenhuma operação automática de deploy, merge, cron, pagamentos ou ranking faz parte deste candidato.
