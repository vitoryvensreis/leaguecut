# League Cut — preparação de lançamento do motor híbrido

## Confirmado no GitHub
- PR #28: PHP 8.4 lint e testes isolados do motor híbrido passaram (run 38660092497).
- PR #29: migração SQL em MySQL 8 descartável passou, incluindo segunda execução (run 38660295533).
- PR #30: checks PHP passaram após ajuste de validação TikTok (run 38660455667).

Essas confirmações NÃO equivalem a teste OAuth com Meta/TikTok, nem migração em uma cópia do banco de produção.

## Regra de publicação
- Sem merge ou deploy antes da validação de credenciais e permissões reais de Meta e TikTok.
- Deixar todas as flags HYBRID_*_ENABLED desligadas.
- Aplicar migração em ambiente representativo ANTES de disponibilizar PHP que chama tabelas novas.
- Exigir backup testado, rollback preparado e comparação de schemas existentes.
- Nenhuma métrica observada pode atualizar automaticamente ledger ou views verificadas.

## Últimas duas etapas
1. Testar autorização oficial e um vídeo controlado para Instagram e TikTok; YouTube depende de API key. Não testar sem autorização.
2. Revisão final de segurança e dados, testes de rollback, aprovação dos PRs encadeados e deploy com checklist.

Este PR também endurece o refresh token CAS, evitando declarar sucesso se o banco não gravou a rotação (por corrida com outro refresh ou desconexão).
