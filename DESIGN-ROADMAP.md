# LEAGUECUT — roadmap de redesign em blocos

- [x] **Bloco 1 — Design system dark premium + ícones vetoriais**: componentes PHP, menu com navegação arredondada, cartões, tabelas, formulário, responsividade, estados de interação. Não mexer em regras financeiras ou banco.
- [x] **Bloco 2 — Home LP + catálogo separado**: / como landing de conversão e /explorar como catálogo; CTAs e testes de rotas.
- [x] **Bloco 3 — Banner animado administrável**: home hero com imagem/vídeo/animação CSS; edição de textos/mídia via ADM, upload seguro, validação e fallback, migração SQL.
- [x] **Bloco 4 — Dashboards premium e fluxos**: revisão UX do administrador e clipador, navegação lateral, formulários, feedback e acessibilidade.
- [x] **Bloco 5 — WebSocket**: serviço isolado, autenticação de canais, eventos da aplicação, fallback sem websocket, status/alertas em tempo real.
- [x] **Bloco 6 — Polimento geral**: responsivo, performance, UX e testes ponta a ponta.

Regra do ciclo: um bloco por entrega, GitHub -> testes -> deploy -> verificação humana -> aprovação antes do próximo.

## Integrações operacionais

- [x] **Bloco 7 — Google Drive operacional**: conexão OAuth pelo ADM com token criptografado e pasta privada, status no painel, acesso ao vídeo original na conferência. **Ativação real depende da autorização Google pelo administrador.**
- [ ] **Bloco 8 — Notificações externas**: SMTP e WhatsApp com diagnóstico, templates, retries e visibilidade da fila.
- [ ] **Bloco 9 — Pagamentos**: homologação de provedor e reconciliação financeira; sem efetuar Pix automaticamente até validação operacional.
