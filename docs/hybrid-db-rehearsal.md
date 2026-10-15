# Etapa 2 — ensaio de banco antes do deploy híbrido

## Regras de segurança
- Este documento **não autoriza** aplicar migrações no banco de produção.
- Não criar tabelas híbridas em produção enquanto o deploy não estiver aprovado.
- Nunca publicar dumps, .env, tokens ou dados financeiros no GitHub, CI ou chat.
- As flags HYBRID_*_ENABLED continuam em 0.
- A aprovação da CI descartável não demonstra compatibilidade com dados reais.

## 1. Verificação somente leitura na VPS
O operador pode executar no terminal, sem instalar arquivos:

```bash
cd /home/leaguecut/current
php -r 'require "app/core.php"; $r=lc_db()->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (\x27clips\x27,\x27social_accounts\x27,\x27clip_verification_state\x27,\x27clip_verification_observations\x27,\x27social_oauth_connections\x27,\x27meta_deauthorization_inbox\x27) ORDER BY TABLE_NAME"); foreach($r as $x) echo $x["TABLE_NAME"],PHP_EOL;'
```

Esperado antes das migrações: clips e social_accounts presentes; as quatro tabelas novas podem estar ausentes. Isso **não é erro**, é o estado esperado antes da implantação.

## 2. Backup e restauração representativa
O responsável pela infraestrutura deve gerar snapshot/dump consistente do banco MySQL do League Cut, guardado fora do webroot, com acesso restrito. Em instância/banco separados, restaurar esse dump, verificar contagens e constraints, e aplicar na **cópia** as duas migrações (20261014_hybrid_verification.sql e 20261015_meta_deauthorization_inbox.sql). Reexecutar ambas e conferir que não alteraram clipes, valores verificados ou ledger. Não há comandos de escrita destinados à VPS de produção neste documento.

## 3. Liberação
Somente avançar quando houver comprovantes de restauração bem-sucedida, compatibilidade do schema e aprovação explícita de release. A migração híbrida ainda não está no fluxo principal de bin/migrate.php, portanto o deploy automático da main **não é suficiente para aplicar essas tabelas**.

## 4. Observações Meta
Callback assinado com inbox não implica revogação efetiva: mapeamento entre ID de usuário scoped do app e perfil Instagram permanece pendente. Não habilitar OAuth para o público antes de tratar este caso.
