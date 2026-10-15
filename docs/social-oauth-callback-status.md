# OAuth callback — branch em rascunho

Implementado em `app/social-oauth-callback.php`:
- HTTPS para hosts oficiais em lista fixa, sem redirects;
- token exchange no servidor, validação do titular por ID remoto **e** nome de usuário;
- verificação de `state` de uso único e correspondência com usuário logado, perfil escolhido e rede;
- armazenamento de access e refresh token criptografados com `APP_KEY` em `social_oauth_connections`;
- falha fechada sem credenciais, identidade verificada ou escopo necessário.

## BLOQUEADOR
A inclusão de `social-oauth-callback.php` no `app/routes.php` foi bloqueada pela ferramenta. **Rotas não foram montadas, UI não foi ativada, integração não funciona ainda.** Não efetuar merge/deploy. O painel `/redes` continua sem botão de conexão.

Antes de concluir:
1. Revisar a implementação contra a versão publicada da documentação Meta e TikTok;
2. Revisar exposição de query strings/tokens e aplicar autenticação/consentimento;
3. Montar callback no router e criar botões POST com CSRF em `/redes`;
4. Testar PHP, migração SQL e consentimento real em staging;
5. Implementar renovação, revogação, status de token e prevenção de reconexões cruzadas;
6. Validar permissões e aprovação das aplicações.

Nenhuma execução de PHP, migração SQL ou deploy foi realizada nesta branch.
