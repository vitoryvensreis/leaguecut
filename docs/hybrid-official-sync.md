# League Cut — API oficial e sincronização (fase preparatória)

Esta branch depende do PR #20 e **não está implantada**.

## Entrega
- Adapter `app/official-metrics.php` para estatísticas públicas de vídeo da API oficial YouTube Data API v3, validando ID exato e contador.
- CLI `bin/hybrid-youtube-sync.php` com flag de habilitação explícita, limite de 1 a 20 vídeos por execução, cooldown de 24 horas e somente clipes aprovados.
- Chamadas deixam observações auditáveis nas tabelas híbridas. **Nunca alteram `clips.verified_views`, ranking ou ledger financeiro.**
- Nada é executado automaticamente e nenhuma rota pública foi criada.

## Pré-requisitos para staging
1. Aplicar migração do PR #20 em banco de teste restaurado, após backup.
2. Configurar `YOUTUBE_API_KEY` no `.env` privado (chave da YouTube Data API v3 no Google Cloud; restringir a API e origem/IP quando possível).
3. Manter `HYBRID_YOUTUBE_SYNC_ENABLED=0` até testes. Ativar somente no staging.
4. Executar `php -l` nos arquivos PHP novos e `php tests/official-metrics.php`.
5. Executar manualmente `php bin/hybrid-youtube-sync.php`. Confirmar observações, ausência de crédito financeiro e limitação de quota.
6. Não criar cron ou publicar enquanto testes/limites não forem aprovados.

## Outras redes
Instagram Graph API e TikTok Display API dependem de registro de aplicativo, consentimento OAuth, escopos específicos, revisão/permissões e vínculos seguros entre conta local e titular autenticado. Implementar state, PKCE onde requerido, verificação da identidade remota, refresh/revogação e token criptografado antes de qualquer sincronização. Não usar login por @ como evidência de autorização.

Sem tokens oficiais, o fluxo híbrido continua em revisão manual. A Apify não é removida nem acionada por esta mudança.
