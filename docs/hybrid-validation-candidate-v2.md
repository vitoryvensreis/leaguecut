# League Cut — Candidato v2 para homologação (sem deploy)

Branch acumulada após PR #40, contendo integrações híbridas, fluxo de envio/edição, controles OAuth, segurança de métricas e regressões PHP.

## Verificado
- PHP hybrid checks PR #40: success, GitHub Actions run 38663197187.
- MySQL descartável: validação anterior aprovada (run 38660295533).
- Flags das APIs continuam em 0; testes reais com credenciais não realizados.
- Nenhum merge em main, migração na VPS, OAuth externo ou publicação.

## Pendências inegociáveis
1. Testar OAuth e métricas de Instagram/TikTok com aplicativos aprovados e contas próprias autorizadas; YouTube com API key.
2. Comparar banco de staging representativo com esquema de produção, executar migração/rollback, revisar tokens, rate limits e concluir deploy somente após aprovação.

A cadeia de branches é acumulativa. Este candidato não deve ser mesclado automaticamente na main. O histórico de resultados de CI não substitui as duas etapas acima.
