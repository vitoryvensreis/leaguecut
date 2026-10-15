# League Cut — liberação controlada no próprio leaguecut.com

## Situação
Branch de revisão sobre o conjunto híbrido. **Não mesclar diretamente na main**, porque existem alterações acumuladas, e a main também recebeu alterações próprias (páginas legais). Reconciliar primeiro.

## Pré-checagem somente leitura
Depois que um candidato for disponibilizado em ambiente isolado com o mesmo formato de .env, executar `php bin/hybrid-production-safety-gate.php`. Mostra apenas presença de credenciais e flags; nunca valores. Exit 2 indica bloqueio. Não consulta MySQL, APIs nem faz deploy.

## Antes de publicar
1. Criar dump consistente e testar restauração em banco separado. Confirmar schema existente e migrations aplicadas.
2. Reconciliar a branch híbrida com main sem descartar as páginas legais.
3. Executar CI PHP + MySQL 8, migração idempotente e testes da aplicação existente.
4. Revisar callbacks Meta de desautorização: o PR de inbox está em outra branch e ainda não está no candidato. **Não declarar conformidade nem ativar OAuth antes de integrar e testar o mapeamento de identidade.**
5. Após revisão, mesclar um PR de release e conferir Actions e health endpoint antes de qualquer enable flag.
6. Testar Instagram com conta autorizada e acesso limitado, primeiro desabilitando sincronização e sem creditar valores financeiros.

## Operador
Manter HYBRID_SOCIAL_OAUTH_ENABLED=0, HYBRID_INSTAGRAM_SYNC_ENABLED=0, HYBRID_TIKTOK_SYNC_ENABLED=0, HYBRID_YOUTUBE_SYNC_ENABLED=0 e HYBRID_BACKFILL_ENABLED=0. Não postar segredos, tokens nem dumps no chat.
