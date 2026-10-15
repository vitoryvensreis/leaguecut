# OAuth social — preparação (sem ativação)

A implementação de `app/social-oauth.php` prepara URLs oficiais com estado aleatório criptograficamente forte, validade de 10 minutos, vínculo a usuário e perfil social e consumo único. NÃO disponibiliza endpoints/botões nem tenta trocar tokens.

## Configuração futura (somente .env privado)
- `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`
- `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET`
- `APP_URL` precisa ser HTTPS, com domínio próprio e sem caminho.

URIs de callback planejados:
- `https://SEU-DOMINIO/redes/oauth/tiktok/callback`
- `https://SEU-DOMINIO/redes/oauth/instagram/callback`

Antes da ativação: trocar `code` por token **no backend**, verificar escopos e ID remoto; comparar titular autorizado com perfil selecionado, criptografar access/refresh token com `lc_encrypt`, aplicar refresh/revogação e consentimento. Nunca marcar `oauth_verified` pelo simples cadastro de @ ou pelo callback sem confirmação da identidade.

Os escopos pretendidos são `user.info.basic,video.list` (TikTok, dependentes de aprovação) e `instagram_business_basic` (Instagram profissional, permissões aprovadas). Confirmar endpoints/versão da Meta antes de finalizar troca de token.

## Testes a executar
`php -l app/social-oauth.php` e `php tests/social-oauth.php`.
Revisão de redirecionamentos/CSRF, staging com HTTPS e autenticação vinculada ao usuário antes de produção.

Esta branch é preparatória e não altera banco, rotas de produção, ranking, valores financeiros ou jobs.
