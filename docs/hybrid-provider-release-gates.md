# Portão de liberação das APIs oficiais

Validação automática confirmada: PR #28 PHP passou; PR #29 MySQL descartável passou. Isso NÃO equivale a autorização real nem teste com dados de produção.

Antes de habilitar `HYBRID_SOCIAL_OAUTH_ENABLED`, `HYBRID_TIKTOK_SYNC_ENABLED`, `HYBRID_INSTAGRAM_SYNC_ENABLED` ou `HYBRID_YOUTUBE_SYNC_ENABLED`:
- Confirmar apps Meta/TikTok aprovados, escopos autorizados e redirect URIs reais.
- Validar token lifecycle (Instagram e TikTok), reconexão e revogação, com identidade remota.
- Testar pelo menos 1 vídeo controlado por provedor, comparando API com postagem.
- Revisar permissões, paginação/Insights Meta e fallback sem inventar visualizações.
- Preparar backup/restore e aplicar migração em staging representativo.
- Garantir que observações jamais atualizam ledger/verified_views sem revisão.

Sem credenciais e contas de teste autorizadas, **pular testes de rede** e manter integração desligada. Não usar scraping não autorizado para substituir a autorização.
