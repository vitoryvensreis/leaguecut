# LeagueCut — Bloco 7: conectar Google Drive

## Ativação (somente administrador)

1. No Google Cloud Console, crie ou selecione um projeto e habilite **Google Drive API**.
2. Configure **Tela de consentimento OAuth** para usuário externo (ou interno se aplicável). Adicione sua conta entre os usuários de teste, caso o aplicativo ainda esteja em teste. Recomendamos publicar o app após revisão; refresh tokens de apps externos em modo "Testing" podem expirar em 7 dias.
3. Em **Credenciais**, crie **Cliente OAuth 2.0 — Aplicativo da Web**, com redirecionamento autorizado exato:
   `https://leaguecut.com/admin/integracoes/google/callback`
4. No arquivo privado `/home/leaguecut/shared/.env` da VPS, configure apenas:
   `GOOGLE_CLIENT_ID` e `GOOGLE_CLIENT_SECRET`. **Nunca envie Client Secret, refresh token ou APP_KEY ao GitHub ou chat.**
5. Acesse `https://leaguecut.com/admin/integracoes`, clique em **Conectar conta Google**, faça login e autorize o acesso ao Drive.
6. O LeagueCut criará automaticamente **LeagueCut - Clipes privados** na conta Google e salvará o refresh token **criptografado com AES-256-GCM** na tabela privada `google_drive_connections`. A pasta é exclusiva do app e não é compartilhada automaticamente.
7. Clique em **Testar acesso à pasta**; o teste deve confirmar que o Google Drive está acessível.
8. Em uma campanha ativa, um clipador aprovado verá a opção de envio de MP4/MOV/WebM; o ADM poderá abrir o vídeo original privado em **Admin > Conferir clipes**.

## O que permanece igual

O método legado via `GOOGLE_REFRESH_TOKEN` e `GOOGLE_DRIVE_FOLDER_ID` no .env continua compatível enquanto não houver conexão pelo painel. Conectar via ADM passa a priorizar o novo token/pasta criptografados. Desconectar pelo ADM não apaga os arquivos nem revoga o acesso no Google; para revogar também faça isso nas configurações de segurança da conta Google. Qualquer conta Google distinta pode não ver o arquivo original por não possuir acesso: use a conta autorizada no OAuth.

## Restrições importantes

- O limite lógico existente é **1 GB por vídeo**, porém o CloudPanel/PHP/Nginx podem bloquear uploads consideravelmente menores. Verifique upload_max_filesize, post_max_size, client_max_body_size e timeouts antes de testar arquivos grandes.
- Esta versão envia o upload através do servidor PHP. Upload direto para o Drive com retomada pelo navegador, maior estabilidade em arquivos de centenas de MB e acompanhamento de progresso é uma evolução posterior.
- O escopo OAuth é somente **drive.file**. A plataforma cria pasta própria, não acessa toda sua conta Google e não concede permissões públicas. A visualização do original ocorre no domínio Google Drive, sujeita à autorização Google.
- `APP_KEY` precisa permanecer a mesma: alterar a chave impossibilita ler o refresh token criptografado; nesse caso, conecte novamente no painel.
- Não há upload de mídia para Google sem autorização explícita do administrador; contas e campanhas existentes permanecem inalteradas.
- A API externa real NÃO é testada pelo CI com contas de produção. Os testes automatizados cobrem autenticação, permissões e criptografia. A etapa humana de OAuth e upload real é obrigatória.
