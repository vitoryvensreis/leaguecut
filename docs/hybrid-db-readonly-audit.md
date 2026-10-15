# Auditoria somente leitura do banco do League Cut

Com o comando disponível em uma release de homologação:
`php bin/hybrid-schema-audit.php`

O script consulta apenas information_schema.COLUMNS e informa tabelas/colunas ausentes. Não imprime dados pessoais, senhas ou tokens, não modifica o banco nem executa migrações.

**Não rodar no site atual** antes de incluir o script no código do servidor: ele existe somente nesta branch. A falta das tabelas híbridas é esperada enquanto as migrações ainda não tiverem sido aplicadas.

Antes de qualquer migração real: backup completo + teste de restauração em banco separado, comparar com estrutura existente e planejar rollback. A aprovação em MySQL descartável não substitui isso.
