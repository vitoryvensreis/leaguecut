# Hybrid integration gate — revisão antes da produção

Este branch reúne as mudanças dos PRs #20–#27 **por herança de branches**, sem fazer merge no main e sem deploy.

Workflow `hybrid-integration.yml`:
- PHP 8.4 syntax check dos módulos, CLI e principais rotas;
- execução de oito testes unitários isolados;
- smoke tests que garantem que os três sincronizadores saiam desativados sem flags.

## Limitações
- CI não substitui staging MySQL e não verifica a execução da migração incremental.
- OAuth Meta/TikTok ainda exige contas, permissões e confirmação dos endpoints publicados.
- Testes remotos / dados reais / concorrência de atualizações e rollback não cobertos.
- Nenhuma configuração de tokens, cobrança, saldo, ranking ou cron é alterada.

## Critérios antes de merge
1. Workflow verde no GitHub.
2. Backup e restore do MySQL em ambiente isolado.
3. Migração rodada duas vezes em staging, sem perdas, FKs verificadas.
4. Submissão ponta a ponta com Google Drive e rollback de transação.
5. Autorização real Instagram/TikTok com contas de teste e consentimento.
6. Comparar views corretas e observadas, nunca liberar saldo automaticamente.
7. Revisão de segurança, política de retenção/revogação de tokens e rate-limits.

**Não colocar o branch em produção diretamente.**
