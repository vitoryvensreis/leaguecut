# OAuth lifecycle — preparatório (PR em rascunho)

## Adicionado
- Status local do token: conectado, expirado, desconhecido, desconectado.
- Renovação sob demanda para TikTok usando endpoint oficial v2, validando o mesmo `open_id` e armazenando access e refresh tokens criptografados, inclusive refresh token rotacionado.
- POST de desconexão com CSRF global, usuário autenticado, propriedade do perfil e feature flag.
- Desconexão local transacional limpa os tokens e a flag de verificação; revogação remota TikTok é tentada sem bloquear a desconexão. Quando o provedor estiver indisponível, o usuário também deve revogar no próprio TikTok.
- Instagram não recebe refresh automático nesta etapa: ciclo de token e endpoints exigem validação específica antes de habilitar.

## Limitações / bloqueios
- NÃO configurar cron de refresh ainda, nem habilitar `HYBRID_SOCIAL_OAUTH_ENABLED` em produção.
- Corrigir/validar OAuth Meta e escopos antes de liberar usuários, verificar controles de reconexão, token refresh expiration e concorrência em staging.
- Sincronização de métricas das redes continua pendente.
- Nenhum SQL executado. Nenhum código mesclado ou publicado nesta fase.
- Testes PHP e teste E2E de autorização real ainda precisam ser executados em staging.

Fontes de especificação TikTok: https://developers.tiktok.com/docs/en/oauth-user-access-token-management
