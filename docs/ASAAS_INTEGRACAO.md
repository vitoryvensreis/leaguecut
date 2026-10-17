# LeagueCut x Asaas — Etapa 1 (Sandbox)

Integração inicial apenas no Sandbox. Não haverá transferência real nem cobrança real nesta etapa.

## Como criar as chaves

1. Crie uma conta independente em https://sandbox.asaas.com/ .
2. Entre com administrador, abra Menu do usuário > Integrações > Chaves de API > Gerar nova API Key.
3. Dê o nome LeagueCut Sandbox e copie a credencial, que só aparece uma vez.
4. Em Integrações > Webhooks, crie um webhook para https://leaguecut.com/webhooks/asaas.
5. Gere um Auth Token próprio para o webhook (32 a 255 caracteres; não use a mesma API Key).
6. Eventos de cobrança: PAYMENT_CREATED, PAYMENT_CONFIRMED, PAYMENT_RECEIVED, PAYMENT_OVERDUE, PAYMENT_REFUNDED e PAYMENT_CHARGEBACK_REQUESTED. Eventos para futuros saques: TRANSFER_CREATED, TRANSFER_PENDING, TRANSFER_DONE, TRANSFER_FAILED, TRANSFER_CANCELLED.

## Configuração na VPS

Edite com segurança /home/leaguecut/shared/.env, que não é público. Adicione estas quatro configurações (valores reais devem ser inseridos exclusivamente na VPS):

    ASAAS_ENV=sandbox
    ASAAS_ENABLED=0
    ASAAS_SANDBOX_API_KEY=
    ASAAS_WEBHOOK_TOKEN=

Preencha com sua chave e token privados. Depois ligue ASAAS_ENABLED=1. Não faça commit de credenciais, não envie prints, não as cole no chat.

A URL para receber eventos é https://leaguecut.com/webhooks/asaas . Caso Cloudflare retorne 403, verifique logs e crie exceção restrita à rota, preservando a validação do token pelo backend.

## Fluxos

Recebimento Pix: criador solicita o pacote em /criador e pode gerar cobrança sandbox, informando CPF/CNPJ diretamente no formulário. Só é armazenado o ID do cliente Asaas, a referência única e o link da fatura, nunca o documento informado.

Os eventos têm token validado e ID persistido com unicidade contra reenvios. O webhook não libera dinheiro nem créditos. O master confere uma cobrança pelo GET no Asaas e só libera o ciclo se o status remoto for RECEIVED e os dados financeiros coincidirem.

Pagamento Pix: o master pode registrar uma intenção de transferência a partir de saque solicitado. A API POST /transfers permanece BLOQUEADA em qualquer ambiente até homologarmos autorização, estado bancário e idempotência.

Produção: POST bloqueado pelo código sempre que ASAAS_ENV=production. Esta implementação não inclui renovação automática, tratamento automático de estornos/chargebacks ou disparo de transferências.

## Documentação oficial
- https://docs.asaas.com/docs/chaves-de-api
- https://docs.asaas.com/docs/sandbox
- https://docs.asaas.com/docs/criar-novo-webhook-pela-aplicacao-web
- https://docs.asaas.com/docs/webhook-para-cobrancas
- https://docs.asaas.com/reference/criar-nova-cobranca
- https://docs.asaas.com/reference/transferir-para-conta-de-outra-instituicao-ou-chave-pix


## Verificar a conexão de produção sem movimentar dinheiro

1. No painel master /admin/asaas, use "Testar conexão Asaas (sem movimentar dinheiro)". O botão exige senha e 2FA quando ativado.
2. A verificação envia somente GET para o endpoint oficial de saldo. A interface mostra o status HTTP, nunca o valor do saldo.
3. Em paralelo, envia POST sem token para /webhooks/asaas. O esperado é HTTP 401 do aplicativo; HTTP 403 pode indicar bloqueio no Cloudflare.
4. A mesma checagem roda no deploy ou pode ser executada manualmente com php bin/asaas-connection-check.php na VPS.
5. O teste não envia PIX, não cria cobrança, não altera assinaturas e não substitui um evento real do Asaas.

Interpretação:
- ASAAS_API_READONLY=HTTP_200 => autenticação e leitura confirmadas.
- ASAAS_API_READONLY=HTTP_401 => revise a chave, preservando o prefixo $aact_prod_.
- ASAAS_API_READONLY=HTTP_403 => verifique allowlist do IP da VPS no Asaas.
- ASAAS_WEBHOOK_UNAUTHENTICATED_POST=HTTP_401 => endpoint alcançou autenticação do LeagueCut.
- ASAAS_WEBHOOK_UNAUTHENTICATED_POST=HTTP_403 => verifique as regras do Cloudflare para o Webhook.
- ASAAS_OPERATIONS_ENABLED=NO => o gateway permanece desligado (operações de produção ainda bloqueadas no código).

AVISO: não ativar cobranças ou saques reais apenas com GET 200; a homologação de recebimentos, reembolsos, idempotência e transferências Pix é uma etapa separada.


## Aba Testes Pix (Produção, somente master)

Endereço: https://leaguecut.com/admin/asaas/testes

A aba realiza dois fluxos financeiros reais **sob autorização explícita do master**:
1. "Receber": informar valor de R$ 1 a R$ 50 e CPF/CNPJ do pagador. Cria um cliente Asaas caso não exista, envia uma cobrança Pix avulsa para POST /v3/payments, recupera o QR Code dinâmico em GET /v3/payments/{id}/pixQrCode e mostra código copia e cola. O pagamento deve ser feito em outro banco; pode sofrer taxas, prazo de liquidação e estornos.
2. "Enviar": informar valor de R$ 1 a R$ 50, tipo e chave Pix. O primeiro botão apenas prepara a operação, criptografa a chave e exibe revisão com destino mascarado. Um segundo formulário exige senha master/2FA, digitar ENVIAR PIX e confirmar. Só então chama POST /v3/transfers. O saldo precisa estar disponível. Limites globais: máximo R$ 100 e 5 tentativas de Pix de saída por dia.

Exclusão e separação: todas as operações entram em asaas_pix_tests, separadas de asaas_charges, withdrawals e dos créditos de criadores. Nenhum Pix de teste libera clipes, remove saldos ou marca saques de usuários como pagos.

Após criar cobrança ou transferência, use "Consultar status atualizado" na própria aba para obter o resultado diretamente do Asaas. Os Webhooks já entram na caixa de eventos /admin/asaas; não autorizam por si só alterações de saldo. Operações com timeout/status "review_required" NÃO são refeitas automaticamente — confira manualmente no painel do Asaas.

### Como habilitar especificamente os testes reais

No arquivo PRIVADO /home/leaguecut/shared/.env, inclua:
    ASAAS_ENV=production
    ASAAS_ENABLED=0
    ASAAS_LIVE_TEST_ENABLED=1

A chave ASAAS_PRODUCTION_API_KEY e o token ASAAS_WEBHOOK_TOKEN já precisam estar configurados. ASAAS_ENABLED=0 permanece, pois o processamento financeiro normal do LeagueCut ainda não foi homologado. ASAAS_LIVE_TEST_ENABLED=1 ativa somente esta aba e permite **movimentação real** depois das confirmações do master.

Por padrão, ASAAS_LIVE_TEST_ENABLED=0 (ou ausente), e a aba mostra campos bloqueados. Não coloque credenciais ou documentos pessoais em commit, chat ou prints.

Para interromper novos testes, volte ASAAS_LIVE_TEST_ENABLED=0 no .env. Histórico e operações já enviadas continuarão existindo no Asaas.


## Correção de erro HTTP 400 em eventos Asaas (outubro/2026)

Os identificadores de evento do Asaas podem conter o caractere ampersand (exemplo oficial `evt_05b708f961d739ea7eba7e4db318f621&368604920`). Um validador anterior do LeagueCut só permitia letras, números, hífen e sublinhado, devolvendo HTTP 400 antes da persistência. Agora IDs documentados com `&` são aceitos com autenticação do token, tamanho limitado e unicidade no banco.

Após a implantação **e após confirmar o teste HTTP 200**, no Asaas Produção:
1. Integrações → Webhooks → selecione LeagueCut - Pagamentos.
2. Se aparecer penalização, use **Remover penalização** uma vez. Isto acelera o reenvio; não exclua ou recrie a configuração.
3. Se a **fila** estiver interrompida (não apenas penalizada), reative a sincronização na configuração do Webhook.
4. Em Logs de Webhooks, confira novas tentativas com **HTTP 200** (e na aba do LeagueCut "Webhooks recebidos" procure por `PAYMENT_CREATED` e outros eventos).
5. Não recrie cobranças ou transferências para disparar teste: Asaas reenvia eventos com entrega pendente e mantém retenção de até 14 dias.

Os eventos recebidos são registrados uma vez por identificador; esta etapa **não credita clipadores, não ativa assinaturas nem marca saques automaticamente**.
