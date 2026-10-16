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
| 2 | 2FA Google Authenticator para ADM, clipador e criador | **B2a publicado:** TOTP por chave manual no aplicativo, challenge de login, códigos de recuperação de uso único, rate limit e revogação das sessões antigas. QR local SVG incluído no B2b1; validação real de navegador pendente. | B2 |
| 3 | Item não consta da lista original | N/A | — |
| 4 | Logo 70% maior | Implementado no código B1: cabeçalhos com ajuste responsivo | B1 |
| 5 | Indicações de clipadores/criadores e comissões fixas/percentuais editáveis | **B3b publicado (PR #79, deploy verde):** links pessoais para ambos os tipos, atribuição única no cadastro, regras por perfil (fixo/percentual), comissão pendente somente por evento financeiro comprovado, conferência e aprovação do ADM antes de entrar no saldo. Reversões após pagamento aprovado exigem processo financeiro manual. | B3 |
| 6 | ADM editar todas as contas, acessos, senha, e-mail, telefone e Pix | Implementado no código B1: /admin/contas com confirmação da senha do operador; protege último ADM | B1 |
| 7 | Máscaras CPF, WhatsApp, moeda e demais formulários | **B4b publicado (PR #83, deploy e UX Probe aprovados):** máscaras BRL também nos preços de pacotes mensais, comissões fixas, saques, bônus e pagamentos por campanha; percentual preservado sem prefixo de moeda. Teste JS valida precisão em centavos e formulários dinâmicos. Revisão manual completa pendente. | B4 |
| 8 | Auditoria de ações e acessos de TODOS os usuários | **Parcial B2a:** conta ADM (B1) e eventos de autenticação/MFA e alterações dos próprios dados em account_security_events. B2b1 adiciona trilha GET/POST de páginas e operações, sem payload nem query string; classificação detalhada e cron de retenção pendentes. | B2 |
| 9 | SEO gerenciado pelo ADM (ícones, OG, keywords, metadata, imagem, sitemap) | **B5 publicado (PR #85, testes e deploy aprovados):** editor ADM com prévias de busca/compartilhamento, uploads PNG/JPG/WebP em armazenamento privado, títulos/descriptions/keywords e X, OG/Twitter, canonical, JSON-LD, favicon, robots/sitemap dinâmicos, noindex em rotas internas; testes de acesso e HTML. | B5 |
| 10 | Muitos bônus mensais cadastráveis | **B4a publicado (PR #81, migração e deploy aprovados):** programas independentes por campanha e critério (views verificadas ou quantidade de clipes aprovados), valores por programa, ativar/pausar e apuração manual por mês com crédito único. Bônus mensal antigo permanece disponível. | B4 |
| 11 | Usuário altera dados de acesso/Pix, saque protegido por e-mail/WhatsApp/TOTP | **B2b2 publicado (PR #77; entrega real de canais ainda requer homologação):** contas com TOTP confirmam no autenticador; sem TOTP, SMTP/WPPConnect configurado e autorizado permite código temporário de uso único antes de mudar dados ou criar saque. Se nenhum canal estiver operacional, mantém senha atual como fallback temporário. | B2 |
| 12 | Neon animado sutil em todo o site | **B4b publicado (PR #83, deploy e UX Probe aprovados):** nova camada visual nos rankings, cards e KPIs com reflexos sutis, bordas neon, foco acessível e respeito a movimento reduzido. Revisão visual mobile pendente. | B4 |
| 13 | Nick único, login por nick/e-mail, ranking geral público de views com nick | **B3a publicado (PR #78, deploy verde):** nickname case-insensitive exclusivo, login com nickname ou e-mail, nicks provisórios para contas antigas, ranking /ranking e Top 5 na home somente com @nickname; métricas são views **observadas** pela Apify de clipes aprovados, não garantem verificação financeira. | B3 |
| 14 | Ocultar/revelar dados sensíveis com botão olho | **B3c publicado (PR #80, deploy verde):** botão de olho em telas autenticadas com valores e dados pessoais; mostra/oculta nesta aba e oculta ao trocar de aba. Cobertura complementar de módulos legados permanece pendente. | B3 |
| 15 | Cards gamificados e animações inspiradas em games | **B4b publicado (PR #83, deploy e UX Probe aprovados):** pódio ouro/prata/bronze no ranking geral, Top 5 da home, ranking ADM e campanha; animações escalonadas e reflexos. XP existente continua ilustrativo, sem gerar saldo/bonificações. Conquistas persistentes seguem pendentes. | B4 |

### Etapas e critérios de aceite

**B1 — Administração e identidade (primeiro commit):** cadastro e edição de contas, auditoria inicial, 70% na logo, máscaras de CPF/telefone e neon parcial. Testes do fluxo e SQL de migração obrigatórios. Após merge, validar o deploy real da VPS e a tabela de auditoria criada. Não confundir CI verde com site atualizado.

**B2 — Autenticação e segurança:** TOTP opt-in com segredo criptografado e códigos de recuperação, invalidação de sessões em alterações sensíveis, confirmação de Pix e saques, registro centralizado de acessos e ações (sem guardar credenciais), prazo de retenção e rate limiting.

**B3 — Indicações, nick e privacidade:** regras de comissão do ADM, rastreio antifraude, nick case-insensitive único, login por nick/email, ranking público somente de nick e views verificadas, botão de ocultar informações.

**B4 — Financeiro e experiência:** múltiplos bônus por campanha/mês com deduplicação, máscaras monetárias compatíveis com parsing em centavos, neon e animações gamificadas, acesso por teclado e movimento reduzido.

**B5 — SEO publicado:** editor `/admin/seo` integrado à main via PR #85, deploy confirmado e Production UX Probe aprovado. Necessário configurar favicon/OG pelo ADM e validar exibição real nas redes, sem garantia de posição no Google.

### Como retomar depois de interrupção

1. Leia este checklist e identifique a próxima tarefa marcada Pendente ou Parcial. Confira histórico e diff das PRs no GitHub, começando do HEAD da main.
2. Leia o último run de CI e do workflow de deploy. Se houver falha, não anuncie que chegou à VPS.
3. Na VPS, confira /home/leaguecut/current, /health e logs sem revelar segredos; só então marque como verificado em produção.
4. A cada bloco concluído, atualize esta tabela, testes, PR, data, pendências, alterações do banco e próximo bloco.
5. **B3a/B3b/B3c e B4a publicados** e verificados no GitHub Actions. Próximo: B4b revisão geral de máscaras de dinheiro, neon e gamificação; em seguida B5 SEO. Antifraude avançada e estorno financeiro auditável continuam pendentes.


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

- Estado: **publicado** no PR #78; CI e deploy confirmados no GitHub Actions.
- `users.nickname` recebe índice único por collation MySQL case-insensitive. Cadastros legados ganham `lc_<id>` como nickname provisório sem trocar login ou senha. Novos cadastros clipador/criador podem escolher nick; a ausência no formulário legado gera `lc_<id>` para compatibilidade. Prefixo `lc_` reservado pelo sistema, nomes falsos de administração bloqueados.
- Login aceita email ou nickname, mantendo senha, bloqueio de tentativas e 2FA existente. Página `/conta` permite editar nickname após confirmação pelo mecanismo já implementado (TOTP ou senha+OTP se disponível).
- Ranking público `/ranking` e Top 5 da home mostram somente `@nickname`, clipes aprovados e a **última observação pública por clipe** (não somar snapshots de dias diferentes). O ranking por campanha deixa de mostrar nome real; ADM ainda vê nome real e nickname internamente.
- Contas suspensas ou clipes não aprovados não aparecem no ranking público. Métricas Apify de views são observadas, **não** são recibos/validação financeira; resultados não acionam créditos.
- Testes e2e: cadastro legado, ranking de campanha sem nomes reais, rank da home, migração idempotente e uniqueness da nickname.
- **Próxima entrega: B3b** com links de indicação clipador/criador, regras do ADM e comissões somente após eventos financeiros elegíveis, sem pagar por cadastro falso.


### B3b — Indicações e comissões configuráveis (16/10/2026)

- Estado: **publicado** no PR #79; CI, deploy e produção confirmados no GitHub Actions.
- Um link de indicação por usuário é gerado com código aleatório de 16 caracteres hexadecimais; em `/indicacoes`, cada clipador/criador pode copiar seu convite separado para clipador e criador. Não expõe e-mail/telefone.
- O cadastro clipador/criador com parâmetro `?ref=` atribui no MySQL um único indicador (sem poder trocar depois). Rejeita autoindicação e tentativas com o mesmo WhatsApp entre indicador e indicado; aprovações financeiras permanecem manuais para prevenção de fraude adicional.
- `/admin/indicacoes`: regras separadas por tipo de indicado, inicialmente DESATIVADAS; valores fixos até R$ 500,00 uma única vez ou percentuais até 50,00% de cada evento elegível. Confirmação da senha e 2FA do ADM se ativo para alterações e liberações.
- **Base financeira do percentual**: clipador = pagamento por clipe inserido após cumprir meta semanal; criador = valor do ciclo mensal com recebimento confirmado manualmente pelo ADM. Cadastro e visualizações isoladas não geram comissão.
- Cada evento cria comissão `pending` com origem única e valores congelados conforme regra vigente. A comissão NÃO compõe saldo nem saque até revisão individual `approved` no ADM. Rejeição não credita nada.
- Aprovação verifica se o evento original ainda é válido e credita `ledger_entries.kind=adjustment` com chave deduplicada. Créditos já aprovados não podem ser aprovados novamente. Cancelamento/reembolso após crédito requer análise e ajuste financeiro manual; evitar reversões silenciosas.
- Migration idempotente com backup: tabelas `referral_codes`, `referral_rules`, `referrals`, `referral_commissions`, regras padrão desativadas. Integração de teste cobre referência, autoindicação, limites e emissão única de crédito.
- Próximo passo B3c: opção visual de ocultar/revelar dados pessoais e financeiros sem enviar dados não autorizados ao frontend; análise reforçada antifraude de múltiplas contas, fraude de IP/aparelhos, reversão de comissões após estorno e avisos nos eventos.


### B3c — Ocultação visual de dados autenticados (16/10/2026)

- Estado: **publicado** no PR #80, commit `c33060d`, com deploy e Production UX Probe aprovados.
- Botão de **olho** aparece onde há dados pessoais ou financeiros marcados, somente após a autorização normal do usuário. Na abertura, valores ocultos; a revelação não persiste no navegador e é revertida ao trocar de aba ou sair.
- Cobertura inicial: nomes reais, e-mails, WhatsApp, chave Pix e saques do ADM; saldo/extrato/saques de clipador; comissões e relatórios de indicações; edição de conta; saldo em tempo real do clipador continua atualizando enquanto oculto.
- Helper `lc_private()` escapa HTML contra XSS; CSS/JS/ícones são locais. O comportamento sem JavaScript permanece legível, e o botão funciona por teclado e leitor de tela.
- **Atenção:** é uma camada de privacidade visual, NÃO substitui criptografia/controle de acesso. Dados na página continuam acessíveis no DOM para quem já pode ver a página. A autorização segue no PHP/MySQL; não enviar dados alheios a usuários comuns.
- Cobertura de testes: helper escapando HTML, presença dos marcadores financeiros em páginas autenticadas e ausência na campanha pública. Revisão manual de Firefox/Safari e cobertura de módulos legados ainda pendentes.
- Após B3c, próximo bloco prioritário: **B4** — bônus mensais múltiplos, máscaras R$ 0,00 e animação gamificada. Revisão reforçada de fraude nas indicações e estorno automático auditável continuam pendentes.


### B4a — Múltiplos bônus mensais e máscara BRL (16/10/2026)

- Estado: **publicado** no PR #81, commit `b4cc986`, com migração `MIGRACAO_MONTHLY_BONUS_OK`, CI e Production UX Probe aprovados.
- Na tela `/admin/bonus-mensal`, os programas extras permitem criar vários bônus (inclusive na mesma campanha), nomear cada prêmio, escolher classificação por maior soma de views **verificadas** ou mais clipes **aprovados** e definir valor em reais. O programa pode ser pausado/reativado sem apagar lançamentos anteriores.
- Cada prêmio/mês encerrado exige apuração manual do ADM e confirmação de senha + 2FA quando ativo; desempate por menor ID para estabilidade. A recompensa entra no `ledger_entries.kind=adjustment`, `dedupe_key=monthly_bonus:<program_id>:<YYYY-MM>`, impedindo pagamento duplicado. Só inclui usuários ativos/clipadores e clipes aprovados enviados no período.
- **Compatibilidade:** o fechamento do bônus legado da campanha segue disponível; programas extras não modificam automaticamente bônus já existentes nem distribuem créditos durante a migração.
- Migração idempotente com backup MySQL cria `monthly_bonus_programs`; novos sites recebem a tabela em `database/schema.sql`.
- Máscara JS BRL formata como `R$ 0,00`, sem dependência externa, e converte para `0,00` ao enviar; o parser PHP `lc_cents` continua validando centavos. A máscara não cria créditos nem altera valores persistidos.
- Testes incluem dois programas na mesma campanha/mês, idempotência, rejeição de mês aberto e de programa pausado, além de presença do formulário na tela do ADM e validação sintática do JS.
- Próxima fase do B4: edição avançada dos programas, critérios por intervalo e múltiplas colocações, animações de gamificação, revisão de todas as máscaras, acessibilidade e prevenção de pagamentos indevidos em casos de reversões.


### Registro de publicação B4a

- PR #81: https://github.com/vitoryvensreis/leaguecut/pull/81
- Commit em produção: `b4cc986f3a7bfa97a4fe13ea2090c8e91d49f5f6`.
- Deploy: https://github.com/vitoryvensreis/leaguecut/actions/runs/38844516447 — sucesso, backup MySQL antes do DDL, marcador `MIGRACAO_MONTHLY_BONUS_OK` e `LEAGUECUT_PUBLICADO` confirmado.
- Verificação pública após deploy: https://github.com/vitoryvensreis/leaguecut/actions/runs/38844549018 — sucesso.
- Pendências de UX e regras futuras: edição da definição de programa, limites por campanha/competição, várias colocações, reversões auditáveis, testes reais mobile/Firefox/Safari e leitura manual dos resultados financeiros. **Não ativar pagamentos automáticos sem provedor verificado.**


### B4b — Máscaras monetárias e acabamento neon/gamificado (16/10/2026)

- **Estado: publicado.** PR #83, commit `105a0609cde30ca141eb6bc1d21b5a8df74a6537`; teste integrado, revisão PHP, publicação da VPS e Production UX Probe concluídos com sucesso.
- Máscaras JS de BRL agora alcançam `monthly_price` (pacotes do criador) e `fixed_value` (comissão fixa), além de `payment_per_video`, `milestone_bonus`, `monthly_leader_bonus`, `amount` (saque) e `bonus_amount`. O helper `lc_field` gera `data-lc-mask="money"` nos nomes permitidos. Campos `percent_value` permanecem numéricos percentuais (sem prefixo R$) para não adulterar as regras de comissão.
- Conversão ao envio: `R$ 1.234,56` para `1234,56`, compatível com `lc_cents()`; valores monetários persistidos continuam em centavos inteiros, com validações PHP existentes. Preservar o código digitado do Google Authenticator e senhas; CPF/telefone continuam somente com dígitos ao enviar.
- Novo estilo `public/assets/b4b-arena-polish.css`, sem bibliotecas extras: detalhes ouro/prata/bronze para top 3 em ranking geral, home, ranking de campanhas e ADM; linhas neon e reflexos elegantes nos cards, brilho nos KPIs e animações curtas e escalonadas. Métricas, XP e regras financeiras NÃO são alterados.
- A animação é decorativa, com `prefers-reduced-motion` para não forçar movimento em quem opta por reduzir. Cor e texto do placar permanecem legíveis e sem colocar nome real no ranking público.
- Testes novos: `node tests/masks-b4b.cjs` assegura preço, comissões, percentual separado, CPF/telefone, valores BRL e formulários dinâmicos; `tests/smoke.py` verifica máscaras dos painéis e posições do pódio. CI integra os testes sem necessidade de migração MySQL.
- **Pendente após B4b:** verificação manual Chrome, Safari e Firefox e formulários menos usados; conquistas persistentes e critérios de gameplay avançados; revisão antifraude e estornos financeiros. **Próximo bloco B5:** SEO totalmente administrável (metadados, ícones, OG/Twitter, imagem social, canonical, sitemap e robots).


### Registro de publicação B4b

- PR #83: https://github.com/vitoryvensreis/leaguecut/pull/83
- Commit VPS: `105a0609cde30ca141eb6bc1d21b5a8df74a6537`.
- Deploy: https://github.com/vitoryvensreis/leaguecut/actions/runs/38890353685 — `LEAGUECUT_PUBLICADO` confirmado.
- Production UX Probe: https://github.com/vitoryvensreis/leaguecut/actions/runs/38890397886 — sucesso.
- Testes do PR: `SMOKE PASSED`, `B4B_MASKS_OK`, `UX SMOKE PASSED`. Sem nova tabela ou alteração do saldo.
- **Próxima entrega: B5 — configurações SEO e compartilhamento editáveis pelo administrador**. Pendências de B4b: validação visual manual em múltiplos navegadores e eventual expansão das conquistas gamificadas.


### B5 — SEO, compartilhamento social e indexação pelo ADM (16/10/2026)

- **Estado: publicado em produção.** PR #85, commit `de7703adee076e1d492ce4243b9b844898e9fc74`, suíte integrada, verificação PHP, deploy VPS e Production UX Probe aprovados.
- Nova aba do ADM `/admin/seo` com prévias ilustrativas de Google e card para redes sociais; configura títulos de home, catálogo, ranking, planos, descriptions, palavras-chave, ícone, imagem social, usuário X, base canonical e chave de indexação das páginas públicas. Operações exigem senha administrativa e TOTP quando ativado; ajustes ficam em `platform_settings` e são auditados.
- URLs canônicas geradas no host oficial `https://leaguecut.com`, **nunca** do header Host/query string. A base editável pelo ADM é validada para impedir domínio externo; detalhes de campanhas ativas têm título, descrição e capa individual. Rotas autenticadas, cadastro, login, campanhas ocultas/inativas e páginas de erro são noindex; não exibir dados de usuários em OG/JSON-LD.
- Cabeçalho público: meta title/description/keywords/robots, canonical, Open Graph `og:type,title,description,url,site_name,locale,image`, X/Twitter Card, favicon/apple-touch-icon e JSON-LD `schema.org/WebSite`. Sem imagem social configurada, usa card reduzido em vez de publicar imagem incorreta. Imagens OG são absolutas HTTPS.
- Uploads PNG/JPG/WebP de até 4 MB: favicon quadrado de pelo menos 48 × 48; card social pelo menos 300 × 157 (ideal 1200 × 630). Arquivos com MIME e dimensões verificados e armazenados em pasta privada compartilhada, fora do Git e da pasta web; rota pública `/media/seo/{icon|social}/TOKEN` serve somente imagens que estejam selecionadas no painel. Variável opcional `SEO_MEDIA_DIR`, padrão `/home/leaguecut/shared/storage/seo`. Evitar enviar logotipos SVG diretamente como OG.
- `/robots.txt` dinâmico bloqueia áreas privadas e referencia `/sitemap.xml`; sitemap contém home, explorar, ranking, planos e **somente** campanhas ativas, com `lastmod`. Desativar indexação no painel aplica noindex nas páginas e `Disallow: /` no robots; reativação não força recrawl imediato.
- Testes de CI: `tests/seo_security.php` (XSS, links maliciosos, canonical, privacidade, OG e JSON-LD); smoke Python configura SEO autenticado, envia favicon e imagem social PNG válidos, confere conteúdo e endpoints, verifica robots/sitemap e nega painel para clipadores. **Não existe migração DDL:** a tabela `platform_settings` já é usada no site.
- **Limitações/validação manual:** visualização real de cards depende do cache e das políticas do WhatsApp/Facebook/X/Google; reindexação depende de rastreamento e Google Search Console. SEO não garante ranking, CTR ou indexação. Verificar links finais em redes após deploy. Arquivos antigos não são removidos automaticamente e devem integrar futura política de retenção após substituir uploads.


### Registro de publicação B5

- PR #85: https://github.com/vitoryvensreis/leaguecut/pull/85
- SHA implantado: `de7703adee076e1d492ce4243b9b844898e9fc74` (marker `LEAGUECUT_PUBLICADO` no deploy).
- Deploy: https://github.com/vitoryvensreis/leaguecut/actions/runs/38893334757 — sucesso.
- Production UX Probe: https://github.com/vitoryvensreis/leaguecut/actions/runs/38893373802 — sucesso.
- Testes: `B5_SEO_UPLOAD_OK`, `B5_SEO_SECURITY_OK`, `SMOKE PASSED` e `UX SMOKE PASSED`.
- A ativação/configuração no painel é manual: enviar o favicon e a imagem social. Recomenda-se PNG quadrado para ícone, PNG/JPG 1200x630 para OG. Prévia de WhatsApp/Google depende de caches e rastreadores externos; não se afirma verificação real nessas plataformas.
- **Pendências futuras:** testes manuais de cards reais, Search Console, monitoração de indexação, expurgo seguro de imagens substituídas e parametrização por campanha individual além dos metadados automáticos por título/descrição/capa. Outras demandas históricas seguem no checklist da tabela.


## Bloco 6 — Ajustes pós-B5 (16/10/2026)

### B6A/B6B — Navegação, logo, rodapé e links sociais
- Estado: **implementado em branch; aguarda CI, deploy e verificação de produção**. PR e SHA devem ser registrados ao finalizar.
- Desktop (>=971px): largura da logo reduzida aproximadamente à metade da configuração B5, mantendo proporção do arquivo; a barra superior reserva espaço exclusivo para usuário e Sair. Links de navegação longos passam a ter rolagem horizontal, sem sobreposição. Em tablet/mobile, a barra continua responsiva com links roláveis.
- Sidebar desktop: cabeçalho da área e cartão do perfil ficam visíveis, enquanto **apenas a lista de opções** tem rolagem vertical própria. `position:sticky` mantém a sidebar imóvel durante a rolagem da página enquanto a workspace está visível. Sidebar mobile permanece recolhida no menu móvel.
- Documentos legais já existentes antes deste bloco: `/politica-de-privacidade`, `/termos-de-uso`, `/exclusao-de-dados`. Mantidos os links públicos do rodapé e verificados no teste de integração. Textos exigem revisão jurídica pelo operador antes de uso em escala.
- `/admin/seo`: novos campos de links HTTPS oficiais de Instagram, TikTok, YouTube, Facebook, X/Twitter e LinkedIn; valores ficam em `platform_settings` e usam o fluxo de senha/2FA/auditoria já aplicado ao SEO. Links em branco desaparecem do rodapé. Só são aceitos domínios oficiais, sem usuário/senha na URL ou portas especiais.
- Nenhuma migration MySQL, nem alteração em pagamentos. CSS `/assets/b6-layout.css` carregado após o design anterior para evitar regras antigas de escala da logo. Testes de integração para links sociais e documentos legais; validação visual Chrome desktop/tablet/mobile recomendada após deploy.

### Próximos blocos
- **B6C1 implementado em branch, aguardando CI/deploy:** recuperação de senha por contato previamente cadastrado com código temporário de uso único, limite por conta e sessão, resposta genérica e revogação de sessões ao concluir. **B6C2 em validação:** manter logado com tokens opacos revogáveis e verificação do 2FA.
- **B6D:** PWA com manifesto, ícones, service worker restrito a recursos estáticos e páginas públicas; implementação de Web Push com opt-in, VAPID, armazenamento protegido de subscriptions, respeitar permissão do navegador e controle de sons/alertas. Não armazenar HTML privado em cache offline.
- **B6E:** suporte via formulário de chamados para clipadores/criadores, fila ADM com status/prioridades/respostas e notificações de atualização; permissão por autor e trilha de auditoria.


### B6C1 — Recuperação de senha com e-mail/WhatsApp (16/10/2026)

- Estado: **implementado na branch `feat/b6c1-password-recovery-20261016`, aguardando testes/deploy**. Separado do login persistente por segurança.
- Rotas públicas `/recuperar-senha` e `/recuperar-senha/confirmar`. O modal de login exibe **Esqueci minha senha**. Digitar e-mail ou nickname, selecionar o canal; a LeagueCut usa somente o contato existente no banco. Mensagem é genérica inclusive para contas desconhecidas ou canais sem configuração.
- Integração reaproveita `lc_otp_send` com SMTP configurado ou WPPConnect ativo/consentido. Não afirmar entrega confirmada ao destinatário — somente aceite do provedor. Se SMTP/WPP indisponíveis, nenhum código é emitido como válido; administrador deve configurar o provedor.
- Código aleatório de **8 dígitos**, armazenado apenas como HMAC no MySQL, vinculado à sessão HTTP e com validade de **10 minutos**, cinco tentativas máximas, no máximo 4 solicitações por usuário em 20 minutos (e 60 segundos entre emissões), throttle de 45 segundos por navegador. Senha nova de 12–128 caracteres; nenhuma senha é enviada em e-mail/WhatsApp.
- Atualização da senha é atômica com consumo do código e rotação de `account_mfa.session_version`, encerrando sessões anteriores, inclusive com Google Authenticator. O código não pode ser usado novamente; MFA continua habilitado e necessário para próximos logins.
- Migração aditiva `password_reset_challenges` com backup antes do DDL, sem alterar o resto do banco. Testes verificam conta desconhecida, código errado, senha curta, mudança com hash e replay. Validar envio real em contas de teste antes de divulgação; não alterar senha real no teste.
- **B6C2 implementado em branch, aguardando CI/deploy:** login persistente revogável com cookie opaco Secure/HttpOnly e verificação de 2FA; painel de gestão individual de dispositivos permanece para uma revisão posterior.


### B6C2 — Manter logado neste dispositivo (16/10/2026)

- **Estado: publicado em produção.** PR #89, commit `22d74ad830a747397811e8740c70e31f98aa9a60`, suíte CI e Production UX Probe confirmados.
- Checkbox opcional desmarcado no login; contas com 2FA só registram dispositivo após confirmar o segundo fator.
- Cookie `__Host-lc_remember` com selector 12 bytes + validator 32 bytes, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, sem Domain; HTTPS obrigatório, expiração em até 30 dias. Segredo armazenado apenas em forma de HMAC no MySQL.
- Ao restaurar a sessão, verifica status da conta, validade, segredo, versão do MFA, rotaciona token atomicamente e rejeita replay. Até oito dispositivos por usuário; tokens mais antigos revogados.
- Sair elimina o dispositivo atual. Redefinir a senha elimina todos os tokens e invalida sessões antigas sem desativar o 2FA.
- Migration com backup idempotente `account_remember_tokens`, testes de token válido, expirado, reutilizado e revogado. **Pendente:** painel para gerenciar dispositivos e revisão manual em múltiplos navegadores. Próximos blocos B6D (PWA/notificações) e B6E (suporte/chamados).


### B6D1 — PWA instalável (16/10/2026)

- **Estado: publicado em produção.** PR #90, commit `e3905b533dce533924e846dd9fb0b9cf5d692974`, deploy e Production UX Probe aprovados. Manifesto `GET /manifest.webmanifest` e ícones PNG de 192/512 pixels gerados internamente sem GD ou serviços externos. Cabeçalho com manifest e apple-touch-icon. `public/assets/pwa.js` registra service worker sob HTTPS e apresenta botão Instalar quando o navegador oferece `beforeinstallprompt`.
- Service Worker em `/sw.js` intercepta apenas solicitações GET de **arquivos estáticos públicos** no namespace `/assets/` (CSS, JS, imagens e fontes). Nunca intercepta navegação HTML, APIs, usuário logado, dados financeiros, arquivos privados, respostas autorizadas ou POST. Navegação precisa de internet; não há armazenamento offline de páginas protegidas.
- Cache público versionado (`leaguecut-public-static-v1`), substituído na ativação. Manifeste `display:standalone`, `start_url:/`, tema escuro. No iOS, adição à Tela de Início depende do menu Compartilhar do Safari.
- Teste PHP valida PNG e restrições do worker; smoke via HTTP confere manifesto, ícones binários, registro JS e controle de instalação. **B6D2 pendente:** implementar permissão de notificações Web Push, assinatura revogável, servidor VAPID, entregas via worker e controles de som. Instalar o PWA sozinho não habilita envio push.

### B6D2a — Avisos com a LeagueCut aberta e som opcional (16/10/2026)

- **Estado: publicado em produção.** PR #91, commit `145c9df40ec5b252913dfb5840826a463575fe8f`, CI, deploy e UX Probe aprovados. Branch anterior `feat/b6d2-opt-in-webpush-20261016` foi preservada **sem merge** como referência para B6D2b.
- Rota autenticada `/notificacoes` nos menus de ADM, criador e clipador: permissão explícita de notificações do navegador, opt-out e som de aviso opcional. Preferências locais por navegador, sem coleta de dados sensíveis.
- A atualização em tempo real já existente passa a disparar evento local `leaguecut:live-event`; notificações e som só aparecem se autorizados. Textos genéricos sem nomes, dados bancários ou saldos; áudio sintetizado e disparado após interação do usuário.
- **Limite deliberado:** não é Web Push em segundo plano. Notificações de navegador dependem da plataforma ativa e dos eventos recebidos; não funcionam com o site fechado. Não há nova migração MySQL nem biblioteca Composer nesta entrega.
- **B6D2b seguinte:** importar de forma revisada os endpoints de assinatura Web Push, migração, worker VAPID e envio em segundo plano em PR separado; chaves VAPID somente no .env privado, never GitHub. Verificar permissões, iOS PWA, expiração e revogação.
- **B6E depois:** suporte para usuários e fila de chamados ADM, com permissões, status, histórico e auditoria.

### B6D2b1 — Armazenamento seguro de assinaturas Web Push (16/10/2026)

- **Estado: publicado e validado na VPS.** PR #92, commit `cabfaeb88420bf44e345ad90a6bde672656a1693`. CI, migração, deploy e Production UX Probe concluídos. O flag segue desligado: sem disparos nesta fase.
- Migração backup-first idempotente: `push_subscriptions`, `push_preferences` e `push_deliveries` com índices, vínculo por usuário e fila deduplicada por (dispositivo, evento). Instalação limpa também inclui as tabelas no schema.
- API autenticada `/api/push/status`, `/api/push/subscribe` e `/api/push/unsubscribe` protegida por CSRF nas alterações e desligada por padrão (`PUSH_REGISTRATION_ENABLED=0`). Com a chave desligada devolve HTTP 503, sem armazenar endpoints.
- Endpoint push só pode ser de provedores de navegador permitidos via HTTPS. Chaves `p256dh` e `auth`, além do endpoint, persistem criptografadas por `APP_KEY`; hash do endpoint garante unicidade sem salvar URL em claro. Máximo de 8 dispositivos; cada usuário revoga apenas os próprios endpoints.
- Testes em MySQL descartável garantem deduplicação, criptografia, isolamento entre contas, revogação e estado opt-in desligado. **NÃO contém biblioteca Web Push nem worker nesta etapa.**
- **Próximo B6D2b2:** integrar biblioteca VAPID, gerar chaves privadas diretamente na VPS, habilitar assinaturas apenas após health check do worker, UI de autorização Web Push em segundo plano e envio sem dados sensíveis; separar teste de entrega e monitoramento. **B6E:** chamados/suporte após concluir push.

### Registro de retomada e deploy — B6D2 (16/10/2026)

- **B6D2a:** PR #91 integrado, SHA `145c9df40ec5b252913dfb5840826a463575fe8f`; deploy https://github.com/vitoryvensreis/leaguecut/actions/runs/38912634829 e Production UX Probe https://github.com/vitoryvensreis/leaguecut/actions/runs/38912684540 aprovados.
- **B6D2b1:** PR #92 integrado, SHA `cabfaeb88420bf44e345ad90a6bde672656a1693`; deploy https://github.com/vitoryvensreis/leaguecut/actions/runs/38913996679 e Production UX Probe https://github.com/vitoryvensreis/leaguecut/actions/runs/38914054963 aprovados.
- Branch legada `feat/b6d2-opt-in-webpush-20261016` permanece **sem merge**, como referência histórica. Não publicar diretamente aquela branch: mistura envio, banco, UI e dependências e não tem CI aprovado. Próximo passo é B6D2b2 em branch nova, importando apenas código revisado, com os testes próprios.
- **B6D2b2 pendente:** instalar biblioteca PHP Web Push compatível, configurar VAPID no .env privado, adicionar UI para assinatura em segundo plano e worker por cron existente; só então habilitar `PUSH_REGISTRATION_ENABLED=1`. Confirmar envio efetivo com dispositivo de teste e revogação antes do rollout geral.
- **B6E pendente:** aba Suporte para usuários e Chamados para ADM, com permissão por ticket, status, histórico e auditoria.

### B6D2b2 — Web Push VAPID e entrega em segundo plano (16/10/2026)

- **Estado:** implementado na branch `feat/b6d2b2-vapid-delivery-20261016`, sujeito a CI/deploy. Não ativar os flags até a validação num dispositivo real; push aceito pelo provedor não comprova exibição no navegador.
- Biblioteca `minishlink/web-push:^11.0` (PHP >=8.2), instalada pelo Composer. Envio ocorre no cron existente `bin/automation-worker.php`, sob flock; nenhum serviço do TaskEsc é alterado.
- **Fail closed:** `PUSH_REGISTRATION_ENABLED=0` e `PUSH_SENDING_ENABLED=0` inicialmente. Ambos devem estar em 1, com VAPID completo e biblioteca instalada, para permitir inscrições e envio. A chave privada permanece exclusivamente no .env da VPS.
- Configurar no terminal SSH, depois do deploy, usando a conta leaguecut: `cd /home/leaguecut/current && php bin/push-vapid-setup.php mailto:contato@seu-dominio.com` (usar e-mail real). Este comando gera a chave pública/privada direto em `/home/leaguecut/shared/.env` com backup e permissão 0600, sem imprimi-las nem ativar os flags. Não colocar segredo em chat ou GitHub.
- Após confirmar HTTPS/serviço, habilitar os dois flags no `.env` privado apenas para teste com dispositivo autorizado. A aba `/notificacoes` permite ativação/desativação por clique. Só são enviados eventos posteriores à assinatura; remoção de um dispositivo não afeta os demais.
- Worker deduplica pelo par dispositivo/evento, limita a 20 envios por execução, tenta no máximo quatro vezes, aplica backoff e apaga assinaturas expiradas. Tipos de eventos são limitados; payload contém apenas texto genérico e URL interna, sem nome, telefone, CPF ou dados financeiros. O status `sent` confirma aceite do provedor, não visualização.
- O service worker abre avisos com a plataforma em segundo plano. Sons/tipo de alerta são controlados pelo navegador e sistema. iOS exige PWA instalado na tela inicial e versão compatível.
- Testes: `tests/push_vapid_queue.php` (VAPID, dupla trava, fila/aceite simulados), `tests/smoke.py` (interface e SW), `node --check` do JS. Validar entrega real em Chrome, Firefox e iOS; não afirmar entrega ao aparelho baseada só no aceite da API.
- **Próximo B6E:** suporte/chamados para usuários/ADM, após homologar o B6D2b2.
