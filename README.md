# LeagueCut — MVP de clipadores

Plataforma de campanhas de conteúdo e recompensas (PHP 8.4 + MySQL + CloudPanel/Nginx).

## Entregas e critério de aceite (alvo inicial: 16/10/2026)

1. **Base segura:** URL amigável, pasta `public/`, MySQL isolado, login, cadastro e status pendente, layout responsivo tipo catálogo.
2. **Operação:** aprovação manual por administrador, campanhas com valores e regras parametrizáveis, cadastro de perfis sociais, envio de links publicados, biblioteca individual.
3. **Conferência financeira:** revisão de clipes/views pelo administrador, geração idempotente de créditos, metas semanais, bônus por vídeo e ranking mensal, saldo e solicitações de saque.
4. **Integrações:** upload privado no Google Drive por OAuth de conta proprietária; fila de notificações (email e WhatsApp template aprovado); futuras conexões OAuth TikTok/Instagram.
5. **Pagamento e escala:** Pix automatizado via provedor homologado, verificação de titulares, antifraude, testes E2E, logs, backups, política de privacidade/LGPD.

**MVP utilizável** requer etapas 1–3. Etapas 4–5 dependem de credenciais/API e validação financeira; sem acesso, o sistema **não simula** envios ou pagamentos como concluídos.

## Regras financeiras
- Valores armazenados em **centavos inteiros**, nunca ponto flutuante.
- Meta mínima semanal é condição para liberar valor base dos clipes aprovados naquela semana. Sem meta, valor não vira saldo disponível.
- Bônus por visualizações usa contagem validada pelo administrador até integração oficial.
- Bônus de líder mensal somente após fechamento do período por administrador; cada campanha/mês paga uma única vez.
- Entradas financeiras possuem `dedupe_key` única; pedidos de saque reservam saldo disponível; nenhum saque automatizado sem provedor/conta autorizados.

## Instalação (não executar em produção sem revisar)
1. No CloudPanel, rotacione a senha exposta em captura de tela e confirme o banco `leaguecut` e usuário isolado.
2. Use uma pasta de aplicação fora do Document Root. Aponte o Nginx exclusivamente para `.../public` e configure `try_files $uri $uri/ /index.php?$query_string;` no **vhost deste site**, preservando `/.well-known`.
3. Copie `.env.example` para `.env` **fora da pasta pública**; preencha `DB_*`, `APP_URL`, `APP_KEY` e email/Drive somente quando disponíveis.
4. Selecione o banco `leaguecut` no phpMyAdmin e importe `database/schema.sql` (o arquivo não cria o banco), configure permissões mínimas e `composer install --no-dev --optimize-autoloader`.
5. Acesse `/cadastrar`, crie usuário pessoal e promova-o via `php bin/make-admin.php email@example.com` no terminal com as credenciais do banco configuradas.
6. Teste cadastro, status pendente, aprovação, notificações em fila, campanhas, clipes, saldos e saques antes de liberar usuários.
7. Criar chave SSH própria para deploy (já testada). O workflow de deploy **não está habilitado ainda**; o workflow `ssh-test.yml` permanece apenas diagnóstico.

## Integrações
- **Drive:** OAuth 2.0 da conta do projeto com escopo `drive.file`, refresh token válido. Upload para pasta privada. Necessário configurar limite de upload no PHP/Nginx; upload multipart/chunked é próxima melhoria para arquivos grandes.
- **TikTok:** API oficial pode fornecer `view_count` apenas com autorização do titular e escopo `video.list`. **Instagram:** Graph API/Instagram Login para contas profissionais e permissões específicas. Usuário informar @ não comprova posse.
- **WhatsApp:** notificações iniciadas pela empresa exigem opt-in do usuário, template aprovado e token/ID do WhatsApp Business.
- **Pix:** solicitações são manuais até integrar PSP que disponibilize Pix-out, comprovantes e KYC.

NUNCA versionar `.env`, credenciais OAuth, tokens, vídeos de usuários ou dados de produção.
