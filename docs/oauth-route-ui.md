# OAuth UI — fluxo protegido, ainda desativado

Branch `feat/hybrid-oauth-route-ui` baseada no PR #23.

- Registra `lc_social_oauth_route` no router principal.
- Exibe formulário de conexão em `/redes` somente se `HYBRID_SOCIAL_OAUTH_ENABLED=1` e houver credenciais da rede.
- Endpoint retorna 404 quando a feature flag não está ativada.
- Inicio é POST com CSRF global, conta precisa pertencer ao usuário autenticado.
- Callback compara estado temporário e identidade remota, armazena tokens criptografados.
- **Não ativar em produção.** Falta validação real de endpoints e escopos, políticas de tokens, renovação/revogação, prevenção de account takeover e testes E2E.

Passos para validar antes do merge: PHP lint de todos os arquivos alterados, revisar `app/routes-clipador.php`, testar UI no staging, confirmar migrations PR #20 e testar login/reconexão com contas controladas. Solicitar aprovação dos aplicativos de Meta e TikTok. Essa branch não altera schema nem ativa jobs.

Atenção: `HYBRID_SOCIAL_OAUTH_ENABLED` não deve ser ligado só porque o código foi mesclado. Ative somente após revisão de segurança e integração real.
