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
