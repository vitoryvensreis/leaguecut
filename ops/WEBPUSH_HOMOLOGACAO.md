# LeagueCut — Homologação Web Push (16/10/2026)

## Implantação já verificada
- VAPID gerado na VPS sob usuário leaguecut, chaves mantidas exclusivamente em /home/leaguecut/shared/.env com backup privado e permissão 0600.
- Deploy: https://github.com/vitoryvensreis/leaguecut/actions/runs/38941056294 — sucesso, com marcadores LEAGUECUT_VAPID_CONFIGURED=1 e LEAGUECUT_PUBLICADO=e712fd02ddc9ed01230126ae525f489f311bc866.
- Production UX Probe: https://github.com/vitoryvensreis/leaguecut/actions/runs/38941116112 — sucesso.
- **Ainda não foi comprovado o recebimento em um dispositivo real.** Aceitação da API do provedor não significa exibição na tela.

## Checklist de teste com operador e dispositivo autorizados
1. Confirme usuário aprovado, dispositivo sob seu controle, navegador HTTPS e PWA instalado quando for iPhone.
2. Usando canal SSH privado, abra APENAS /home/leaguecut/shared/.env (não cole seu conteúdo em chats, GitHub ou logs).
3. Confirme a presença das variáveis PUSH_VAPID_PUBLIC, PUSH_VAPID_PRIVATE e PUSH_VAPID_SUBJECT sem imprimir valores. O deploy já testou o par.
4. Quando estiver pronto para testar em aparelho autorizado, configure temporariamente PUSH_REGISTRATION_ENABLED=1 e PUSH_SENDING_ENABLED=1 nesse arquivo privado. Não ligue os flags se não puder acompanhar a entrega.
5. No dispositivo autorizado: https://leaguecut.com/notificacoes → Ativar neste dispositivo → permitir notificações → Enviar Push de teste para minha conta.
6. Minimize a aba ou feche o PWA, aguarde o worker da fila e veja se o aviso aparece. Confirme o link ao tocar e revogue a assinatura para testar a desativação.
7. Se falhar ou parar a homologação, volte ambos os flags a 0. Não afirme sucesso de push real sem confirmação no dispositivo.

## Segmentação
- /admin/notificacoes: usuários específicos, todos os aprovados, clipadores ou criadores; senha ADM + CSRF, throttle de dois minutos e limite de destinatários.
- Para cada destinatário existe evento individual scope=user; mensagens privadas não são publicadas em feed geral.
- Conteúdo do Push de fundo é genérico, sem textos de chamados, PII ou financeiro. Conteúdo completo apenas na sessão autenticada.
- Dispositivos e notificações exigem opt-in do titular. O estado sent indica aceite por provedor, não recebimento visual.

## Blocos
- B7A PR #101: dispositivos confiáveis; commit 7fe7ace5680ddfc0544a2d752cb84171a5bddca0; deploy e probe aprovados após repetição do SSH.
- B7B PR #102: notificações direcionadas; commit 7936bfb6abd642ae497d050185d73dab9b81bfdb; deploy e probe aprovados.
- B7C PR #103: responsáveis, SLA e relatórios; commit f68d2f435b93ab9d785a74e380860666a3c056bf; deploy, migração com backup e probe aprovados.
- B7D PR #104: VAPID e teste por usuário; commit e712fd02ddc9ed01230126ae525f489f311bc866; instalação das chaves VAPID confirmada pelo deploy, disparos ainda desativados.
