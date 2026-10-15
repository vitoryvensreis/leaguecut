# League Cut — verificação híbrida (implementação incremental)

## Estado da primeira entrega
Esta branch implementa **somente** o núcleo sem efeitos colaterais em `app/hybrid-verification.php`, com testes em `tests/hybrid-verification.php`.

- Identifica links HTTPS de publicações Instagram, vídeos TikTok com ID e YouTube (Shorts, watch, youtu.be).
- Rejeita hosts semelhantes, credenciais embutidas, portas inesperadas e URLs não reconhecidas.
- Classifica observações oficiais autorizadas como `observed`, **não** como visualizações verificadas.
- Fontes públicas ou não autorizadas exigem revisão humana; nenhuma pontuação, crédito ou pagamento é lançado automaticamente.
- Não modifica endpoints, schema, jobs, tokens ou configurações de produção.

## Próximas entregas antes de habilitar o sistema
1. Criar migração incremental e reversível para conexões OAuth criptografadas, consentimento e observações com proveniência.
2. Adaptar cadastro de redes e envio de clipes sem quebrar o vínculo atual de `social_accounts`.
3. Implementar OAuth oficial por rede com state/PKCE quando aplicável, scopes mínimos e revogação.
4. Implementar coleta por tarefas agendadas, limites de taxa, logs e reconciliação idempotente.
5. Adicionar fila de revisão no painel administrativo e integração explícita (após aprovação) com contadores/finanças.
6. Testar localmente, executar migrações em cópia de banco, aprovar PR e só depois planejar deploy.

## Teste
```bash
php tests/hybrid-verification.php
```

Não ativar Apify ou novas rotinas automaticamente nesta etapa. Não armazenar tokens OAuth em logs ou commits.
