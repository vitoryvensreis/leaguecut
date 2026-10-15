# Bloco de persistência híbrida — execução segura

Branch: `feat/hybrid-clip-persistence` (derivada de `feat/hybrid-clip-verification`).

## Mudanças
- SQL incremental em `database/migrations/20261014_hybrid_verification.sql`, só CREATE TABLE IF NOT EXISTS, com FKs para `clips` e `social_accounts`.
- `database/schema.sql` atualizado somente para instalações novas.
- Durante `POST /clipes`, imediatamente após inserir o clipe na transação já existente, grava-se estado de verificação pendente ou revisão manual; uma falha impede o commit do clipe. Não atualiza `verified_views` nem `ledger_entries`.
- Links legados TikTok encurtados continuam aceitos como revisão manual (sem ID e sem métricas confirmadas); links canônicos incompatíveis com a rede são recusados.
- Observações futuras têm proveniência em tabela própria e exigem análise administrativa; integração com provedores, OAuth, ranking e créditos continuam desativados.

## Validação pré-deploy
1. Faça backup real do banco MySQL, confirme recuperação e compare schema atual com a migração.
2. Teste em banco descartável/restaurado, rodando SQL duas vezes; confira chaves estrangeiras, performance e reversão.
3. Execute `php -l app/hybrid-verification.php`, `php -l app/hybrid-persistence.php`, `php -l app/routes.php`, `php -l app/routes-clipador.php`.
4. Execute `php tests/hybrid-verification.php` e `php tests/hybrid-persistence.php`.
5. Teste submissão real com Google Drive em staging e verifique que `clip_verification_state` recebe um único registro para cada clipe; se transação falhar, ambos devem reverter.
6. Não mesclar/publicar sem migração aplicada **antes** do novo PHP; até lá o endpoint de envio geraria erro por falta de tabela.

## Reversão (somente após verificar dependências e fazer backup)
Reverter os commits de PHP primeiro; só apagar as três novas tabelas se não houver histórico a preservar. Ordem de exclusão: `social_oauth_connections`, `clip_verification_observations`, `clip_verification_state`.

Nenhum SQL foi executado em produção. Esta branch não é um deploy. Nenhuma conexão OAuth está ativa.
