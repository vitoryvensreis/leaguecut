# League Cut — pré-publicação com segurança

CLI `php bin/hybrid-release-preflight.php` executa apenas validações locais, **sem acesso ao banco nem chamadas de rede**. Não mostra valores de APP_KEY nem de credenciais. Verifica requisitos PHP, presença da migração, URL HTTPS e que as flags de integração continuam desligadas antes da aprovação.

Para instalar: primeiro backup + recuperação do banco em staging; depois migração; então verificar fluxo de envio de clipes antigos e novos; só habilitar APIs separadamente com credenciais Meta/TikTok/Google testadas e autorizadas.

**Não confundir preflight local aprovado com liberação de produção.** A conexão OAuth Instagram ainda requer revisão do ciclo de tokens oficial. Teste real das APIs permanece bloqueado até credenciais e consentimento. Nenhuma execução de SQL nem deploy automático está prevista.
