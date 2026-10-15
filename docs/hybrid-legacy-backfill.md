# Backfill seguro dos clipes anteriores ao motor híbrido

O novo motor só registra automaticamente `clip_verification_state` para envios posteriores à migração. Clipes antigos precisam ser incluídos sem revalidar, creditar ou modificar o registro original.

`php bin/hybrid-backfill.php` executa um **dry-run** padrão limitado a até 500 clipes e retorna apenas contagens.

Aplicação exige `HYBRID_BACKFILL_ENABLED=1 php bin/hybrid-backfill.php --apply`, somente **depois** da migração, backup e testes num staging representativo.

- Seleciona somente clipes sem estado de verificação, independentemente do status original.
- URLs e rede compatíveis viram `pending`; encurtadas, desconhecidas e incompatíveis viram `manual_review`.
- INSERT IGNORE impede sobrescrever estados já criados; idempotência por PK clip_id.
- Não altera `clips`, valores verificados, `ledger_entries`, ranking, tokens ou estatísticas.
- Rodar em lotes até `examined=0`. Em produção apenas mediante aprovação explícita e janela planejada.

**Dependência:** tabelas do PR #20 já aplicadas. Não ativar sem isso; flags continuam desligadas.
