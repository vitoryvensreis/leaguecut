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
7. A publicação é disparada após os testes de integração na branch main pelo workflow de deploy. Confirme o resultado nas Actions: merge não garante deploy concluído.

## Integrações
- **Drive:** OAuth 2.0 da conta do projeto com escopo `drive.file`, refresh token válido. Upload para pasta privada. Necessário configurar limite de upload no PHP/Nginx; upload multipart/chunked é próxima melhoria para arquivos grandes.
- **TikTok:** API oficial pode fornecer `view_count` apenas com autorização do titular e escopo `video.list`. **Instagram:** Graph API/Instagram Login para contas profissionais e permissões específicas. Usuário informar @ não comprova posse.
- **WhatsApp:** notificações iniciadas pela empresa exigem opt-in do usuário, template aprovado e token/ID do WhatsApp Business.
- **Pix:** solicitações são manuais até integrar PSP que disponibilize Pix-out, comprovantes e KYC.

NUNCA versionar `.env`, credenciais OAuth, tokens, vídeos de usuários ou dados de produção.
 

---

## Roadmap incremental — solicitação de 15/10/2026

**Regra de execução:** PHP/MySQL na VPS existente, branch/PR com CI antes do merge, backup pré-migração; não reiniciar TaskEsc. Nunca armazenar chaves, dados Pix, tokens, senhas e documentos em commits ou logs.

| # | Requisito | Situação | Bloco |
|---|---|---|---|
| 1 | Cadastrar ADM no painel | Implementado no código B1: formulário em /admin/administradores, confirmação da senha do operador e auditoria | B1 |
| 2 | 2FA Google Authenticator para ADM, clipador e criador | **B2a implementado no código:** TOTP por chave manual no aplicativo, challenge de login, códigos de recuperação de uso único, rate limit e revogação das sessões antigas. Falta QR local e validação real de navegador. | B2 |
| 3 | Item não consta da lista original | N/A | — |
| 4 | Logo 70% maior | Implementado no código B1: cabeçalhos com ajuste responsivo | B1 |
| 5 | Indicações de clipadores/criadores e comissões fixas/percentuais editáveis | Pendente: atribuição, antifraude, reversões e créditos em centavos | B3 |
| 6 | ADM editar todas as contas, acessos, senha, e-mail, telefone e Pix | Implementado no código B1: /admin/contas com confirmação da senha do operador; protege último ADM | B1 |
| 7 | Máscaras CPF, WhatsApp, moeda e demais formulários | Parcial B1: máscara JS de telefone e CPF. Valores monetários e auditoria de formulários pendentes | B4 |
| 8 | Auditoria de ações e acessos de TODOS os usuários | **Parcial B2a:** conta ADM (B1) e eventos de autenticação/MFA e alterações dos próprios dados em account_security_events. Auditoria transversal de acessos, navegação, exclusões, financeiro e retenção pendente. | B2 |
| 9 | SEO gerenciado pelo ADM (ícones, OG, keywords, metadata, imagem, sitemap) | Pendente: editor e testes de compartilhamento/indexação | B5 |
| 10 | Muitos bônus mensais cadastráveis | Pendente: regras de bônus, limites e não duplicação | B4 |
| 11 | Usuário altera dados de acesso/Pix, saque protegido por e-mail/WhatsApp/TOTP | **Parcial B2a:** /conta permite alterar dados, senha e Pix com TOTP se ativo; saque exige TOTP ou senha atual; alteração revoga outras sessões; Pix bloqueado durante saques pendentes. Código enviado por e-mail/WhatsApp ainda pendente. | B2 |
| 12 | Neon animado sutil em todo o site | Parcial B1: fundo luminoso e hover discretos. Acabamento global pendente | B4 |
| 13 | Nick único, login por nick/e-mail, ranking geral público de views com nick | Pendente: índice único, privacidade, views verificadas | B3 |
| 14 | Ocultar/revelar dados sensíveis com botão olho | Pendente: interface e revisão de permissões no servidor | B3 |
| 15 | Cards gamificados e animações inspiradas em games | Pendente: pontuações, conquistas e movimento reduzido | B4 |

### Etapas e critérios de aceite

**B1 — Administração e identidade (primeiro commit):** cadastro e edição de contas, auditoria inicial, 70% na logo, máscaras de CPF/telefone e neon parcial. Testes do fluxo e SQL de migração obrigatórios. Após merge, validar o deploy real da VPS e a tabela de auditoria criada. Não confundir CI verde com site atualizado.

**B2 — Autenticação e segurança:** TOTP opt-in com segredo criptografado e códigos de recuperação, invalidação de sessões em alterações sensíveis, confirmação de Pix e saques, registro centralizado de acessos e ações (sem guardar credenciais), prazo de retenção e rate limiting.

**B3 — Indicações, nick e privacidade:** regras de comissão do ADM, rastreio antifraude, nick case-insensitive único, login por nick/email, ranking público somente de nick e views verificadas, botão de ocultar informações.

**B4 — Financeiro e experiência:** múltiplos bônus por campanha/mês com deduplicação, máscaras monetárias compatíveis com parsing em centavos, neon e animações gamificadas, acesso por teclado e movimento reduzido.

**B5 — SEO:** configuração de título, description, keywords, canonical, favicon, OG/Twitter Card, imagem de compartilhamento, sitemap.xml, robots.txt e metadados estruturados. Melhora a apresentação e indexabilidade, mas não garante aparecer em primeiro lugar no Google.

### Como retomar depois de interrupção

1. Leia este checklist e identifique a próxima tarefa marcada Pendente ou Parcial. Confira histórico e diff das PRs no GitHub, começando do HEAD da main.
2. Leia o último run de CI e do workflow de deploy. Se houver falha, não anuncie que chegou à VPS.
3. Na VPS, confira /home/leaguecut/current, /health e logs sem revelar segredos; só então marque como verificado em produção.
4. A cada bloco concluído, atualize esta tabela, testes, PR, data, pendências, alterações do banco e próximo bloco.
5. Próximo passo: **B2b**, códigos OTP transacionais por e-mail/WhatsApp (somente com provedor operacional e consentimento), auditoria global com políticas de retenção, QR Code local e revisão de controle de sessões.


### B2a — Implementação retomada após o B1 (16/10/2026)

**Entregue no código (aguardando CI, merge e verificação da VPS):**
- Login protegido com segundo fator TOTP de 6 dígitos para os três papéis.
- Ativação voluntária em /seguranca/2fa, exigindo senha atual. Adição no Google Authenticator via **inserção manual da chave**, sem serviços externos e sem vazamento por gerador de QR.
- Segredo TOTP guardado com criptografia AES-GCM usando APP_KEY já existente; dez códigos de recuperação de uso único mostrados uma vez e armazenados exclusivamente como HMAC.
- Janela de 30 s, tolerância de ±1 intervalo, rejeição de código repetido, seis falhas por 15 min; auditoria mínima de login/2FA.
- Sessões de outros navegadores invalidadas na ativação/desativação do 2FA, edições administrativas ou atualização dos próprios dados.
- Administração de contas exige TOTP recente quando o ADM configurou o fator; operações financeiras exigem TOTP se ativo, ou senha atual como fallback **temporário**.
- /conta: edição de nome, e-mail, telefone, senha e Pix; alteração de Pix bloqueada com saque solicitado/em análise.
- Migração idempotente: account_mfa, account_mfa_attempts, account_security_events. Sempre realizar backup antes de executar pela primeira vez.

**Ainda não entregue no B2:** confirmação por e-mail/WhatsApp com código de curta duração (depende de provedor operacional, opt-in e retenção), QR Code gerado localmente (atualmente manual), logs de **todas** as requisições/leituras/ações de usuários, recuperação assistida quando todos os códigos forem perdidos, avisos de mudança de endereço de e-mail, controles antifraude avançados e testes manuais de UX. **Nunca** confundir senha de confirmação com código OTP enviado por e-mail/WhatsApp.

**Checagens pós-deploy:**
1. Conta clipador sem 2FA continua fazendo login normalmente.
2. Em /seguranca/2fa, cadastrar chave manualmente no Google Authenticator, confirmar e salvar códigos de recuperação.
3. Sair, entrar com senha: sistema deve pedir código antes de estabelecer sessão. Testar código errado, código repetido e recuperação.
4. Alterar senha/Pix na página /conta e fazer saque com o segundo fator ativo; testar também conta sem segundo fator (confirmação de senha).
5. Alterar credenciais pelo ADM e garantir que outra sessão do usuário é invalidada.
6. Validar ausência de segredo TOTP, senha, chave Pix e códigos nos logs de aplicação.
