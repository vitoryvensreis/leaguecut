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
| 2 | 2FA Google Authenticator para ADM, clipador e criador | **B2a implementado no código:** TOTP por chave manual no aplicativo, challenge de login, códigos de recuperação de uso único, rate limit e revogação das sessões antigas. QR local SVG incluído no B2b1; validação real de navegador pendente. | B2 |
| 3 | Item não consta da lista original | N/A | — |
| 4 | Logo 70% maior | Implementado no código B1: cabeçalhos com ajuste responsivo | B1 |
| 5 | Indicações de clipadores/criadores e comissões fixas/percentuais editáveis | Pendente: atribuição, antifraude, reversões e créditos em centavos | B3 |
| 6 | ADM editar todas as contas, acessos, senha, e-mail, telefone e Pix | Implementado no código B1: /admin/contas com confirmação da senha do operador; protege último ADM | B1 |
| 7 | Máscaras CPF, WhatsApp, moeda e demais formulários | Parcial B1: máscara JS de telefone e CPF. Valores monetários e auditoria de formulários pendentes | B4 |
| 8 | Auditoria de ações e acessos de TODOS os usuários | **Parcial B2a:** conta ADM (B1) e eventos de autenticação/MFA e alterações dos próprios dados em account_security_events. B2b1 adiciona trilha GET/POST de páginas e operações, sem payload nem query string; classificação detalhada e cron de retenção pendentes. | B2 |
| 9 | SEO gerenciado pelo ADM (ícones, OG, keywords, metadata, imagem, sitemap) | Pendente: editor e testes de compartilhamento/indexação | B5 |
| 10 | Muitos bônus mensais cadastráveis | Pendente: regras de bônus, limites e não duplicação | B4 |
| 11 | Usuário altera dados de acesso/Pix, saque protegido por e-mail/WhatsApp/TOTP | **B2b2 em validação:** contas com TOTP confirmam no autenticador; sem TOTP, SMTP/WPPConnect configurado e autorizado permite código temporário de uso único antes de mudar dados ou criar saque. Se nenhum canal estiver operacional, mantém senha atual como fallback temporário. | B2 |
| 12 | Neon animado sutil em todo o site | Parcial B1: fundo luminoso e hover discretos. Acabamento global pendente | B4 |
| 13 | Nick único, login por nick/e-mail, ranking geral público de views com nick | **B3a implementado no código, aguardando testes/deploy:** nickname case-insensitive exclusivo, login com nickname ou e-mail, nicks provisórios para contas antigas, ranking /ranking e Top 5 na home somente com @nickname; métricas são views **observadas** pela Apify de clipes aprovados, não garantem verificação financeira. | B3 |
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
5. B3a: nickname e ranking público; **B3b:** indicação de clipadores/criadores, comissão fixa ou percentual configurável, antifraude e liquidação segura. Verificar canais OTP no navegador.


### B2a — Implementação retomada após o B1 (16/10/2026)

**B2a já foi integrado e publicado (commit cd4cd1b):**
- Login protegido com segundo fator TOTP de 6 dígitos para os três papéis.
- Ativação voluntária em /seguranca/2fa, exigindo senha atual. Adição no Google Authenticator via **inserção manual da chave**, sem serviços externos e sem vazamento por gerador de QR.
- Segredo TOTP guardado com criptografia AES-GCM usando APP_KEY já existente; dez códigos de recuperação de uso único mostrados uma vez e armazenados exclusivamente como HMAC.
- Janela de 30 s, tolerância de ±1 intervalo, rejeição de código repetido, seis falhas por 15 min; auditoria mínima de login/2FA.
- Sessões de outros navegadores invalidadas na ativação/desativação do 2FA, edições administrativas ou atualização dos próprios dados.
- Administração de contas exige TOTP recente quando o ADM configurou o fator; operações financeiras exigem TOTP se ativo, ou senha atual como fallback **temporário**.
- /conta: edição de nome, e-mail, telefone, senha e Pix; alteração de Pix bloqueada com saque solicitado/em análise.
- Migração idempotente: account_mfa, account_mfa_attempts, account_security_events. Sempre realizar backup antes de executar pela primeira vez.

**Ainda não entregue no B2:** confirmação por e-mail/WhatsApp com código de curta duração (depende de provedor operacional, opt-in e retenção), QR Code gerado localmente (B2b1), logs de **todas** as requisições/leituras/ações de usuários, recuperação assistida quando todos os códigos forem perdidos, avisos de mudança de endereço de e-mail, controles antifraude avançados e testes manuais de UX. **Nunca** confundir senha de confirmação com código OTP enviado por e-mail/WhatsApp.

**Checagens pós-deploy:**
1. Conta clipador sem 2FA continua fazendo login normalmente.
2. Em /seguranca/2fa, cadastrar chave manualmente no Google Authenticator, confirmar e salvar códigos de recuperação.
3. Sair, entrar com senha: sistema deve pedir código antes de estabelecer sessão. Testar código errado, código repetido e recuperação.
4. Alterar senha/Pix na página /conta e fazer saque com o segundo fator ativo; testar também conta sem segundo fator (confirmação de senha).
5. Alterar credenciais pelo ADM e garantir que outra sessão do usuário é invalidada.
6. Validar ausência de segredo TOTP, senha, chave Pix e códigos nos logs de aplicação.


### B2b1 — QR Code local e auditoria transversal (16/10/2026)

- QR de configuração TOTP gerado no servidor por chillerlan/php-qrcode ^6.0; não transmite chave a terceiros. A inserção manual permanece disponível.
- Registro de navegação GET e ações POST por perfil/ID/data/status em request_audit. Sem dados de formulários, tokens, parâmetros de URL, senhas, CPF ou Pix; IP somente HMAC. Caminhos identificáveis são normalizados.
- Painel de auditoria em /admin/auditoria, com filtros e acesso exclusivo de administradores. Guarda até 250 eventos na exibição recente.
- Migração incremental idempotente com backup, testes de QR e sanitização do histórico no CI.
- Retenção por CLI em bin/audit-prune.php, padrão 90 dias (AUDIT_RETENTION_DAYS: 30 a 365). Na VPS, o cron precisa ser ativado manualmente sob o usuário leaguecut, por exemplo:
  15 3 * * * cd /home/leaguecut/current && /usr/bin/php bin/audit-prune.php >> /home/leaguecut/logs/audit-prune.log 2>&1
- B2b2 PENDENTE: códigos por SMTP/WhatsApp com entrega realmente confirmada e expiração, consentimento, limites de tentativas e vinculação ao saque/alteração. O worker bin/notify.php atualmente envia mensagem fixa de aprovação e NÃO deve ser reutilizado sem revisão.
- Verificação após merge: status do deploy, renderização do QR no navegador, auditoria GET/POST sem segredos, cron de retenção e rollback se algum teste falhar. Próximo bloco depois B2b2: B3 indicações, nickname e ranking.


### B2b2 — Códigos transacionais para saques e mudança de dados (16/10/2026)

- **Implementado no código, aguardando CI/deploy:** desafio OTP de seis dígitos, cinco minutos de validade, até cinco tentativas, até quatro emissões por 20 minutos e intervalo de 60 segundos entre solicitações (serializado via trava de linha do usuário).
- **E-mail:** envio síncrono via PHPMailer com TLS usando `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM`; nenhum segredo ou código vai para URL/log/banco em texto. **WhatsApp:** reutiliza a sessão WPPConnect exclusiva da LeagueCut somente quando automações estão habilitadas, token configurado e usuário autorizou WhatsApp. A mensagem é de segurança, não da fila de aprovações do `bin/notify.php`.
- **Não confundir envio com entrega:** o status HTTP/SMTP confirma apenas aceitação pelo provedor. A operação requer inserir o código correto na página `/seguranca/codigo`; se o transporte falhar, o desafio é invalidado e nenhum dado é alterado.
- **Operação atômica:** payload cifrado em `account_otp_challenges`, associação à sessão, conta e tipo de operação; o código é guardado como HMAC e fica inutilizável se for expirado, repetido ou invalidado. Verificação e mutação MySQL no mesmo commit. Dados sensíveis apagados do desafio após uso.
- **Mudança de perfil e Pix:** contato de segurança é o contato **anterior cadastrado**, e a senha atual é necessária para solicitar o código. Saques revalidam saldo sob trava de usuário no instante da confirmação. Contas com TOTP continuam usando Google Authenticator.
- **Fallback transitório controlado:** se o usuário não tiver TOTP e **nenhum** dos canais estiver operacional/consentido, permanece confirmação por senha atual para não bloquear saques de produção. Não equivale a OTP. Ativar SMTP, autorizar WhatsApp ou configurar TOTP para reforçar proteção; reavaliar este fallback depois da homologação do provedor.
- **Migração:** `database/migrations/20261016_account_otp.sql` adiciona tabela; executada com backup MySQL antes de criar. `bin/otp-prune.php` apaga operações expiradas, e `bin/audit-prune.php` remove logs antigos. Deploy registra um único cron de retenção às 03h15 na conta Unix `leaguecut`.
- **Teste de integração:** `tests/otp_security.php` testa código incorreto, replay, desafio ainda sem aceite, expiração e alteração atômica sem realizar envios reais no CI.
- **Pendente fora de B2b2:** prova de entrega/leitura de WhatsApp (não suportada por mera resposta síncrona), verificação manual de contas reais no navegador, políticas de recuperação caso se perca TOTP e códigos, garantia de consistência de contatos verificados, login/social, restante dos itens B3–B5.
- **Próximo bloco: B3**, começando por nickname único, ranking público só com nicks e programa de indicação/comissões com regras antifraude.


### B3a — Identidade pública e ranking geral (16/10/2026)

- Estado: **implementado em branch**; só considerar publicado após CI e GitHub Actions do deploy confirmarem.
- `users.nickname` recebe índice único por collation MySQL case-insensitive. Cadastros legados ganham `lc_<id>` como nickname provisório sem trocar login ou senha. Novos cadastros clipador/criador podem escolher nick; a ausência no formulário legado gera `lc_<id>` para compatibilidade. Prefixo `lc_` reservado pelo sistema, nomes falsos de administração bloqueados.
- Login aceita email ou nickname, mantendo senha, bloqueio de tentativas e 2FA existente. Página `/conta` permite editar nickname após confirmação pelo mecanismo já implementado (TOTP ou senha+OTP se disponível).
- Ranking público `/ranking` e Top 5 da home mostram somente `@nickname`, clipes aprovados e a **última observação pública por clipe** (não somar snapshots de dias diferentes). O ranking por campanha deixa de mostrar nome real; ADM ainda vê nome real e nickname internamente.
- Contas suspensas ou clipes não aprovados não aparecem no ranking público. Métricas Apify de views são observadas, **não** são recibos/validação financeira; resultados não acionam créditos.
- Testes e2e: cadastro legado, ranking de campanha sem nomes reais, rank da home, migração idempotente e uniqueness da nickname.
- **Próxima entrega: B3b** com links de indicação clipador/criador, regras do ADM e comissões somente após eventos financeiros elegíveis, sem pagar por cadastro falso.
