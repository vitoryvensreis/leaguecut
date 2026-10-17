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

## Retirada do antigo painel de testes Pix

A interface de cobrança e transferência Pix avulsas de teste foi removida do LeagueCut. A rota `/admin/asaas/testes` não existe mais. A variável legada `ASAAS_LIVE_TEST_ENABLED` não é consultada pelo aplicativo. A migração e o código que criavam a tabela `asaas_pix_tests` foram retirados; tabelas e registros financeiros existentes na VPS são preservados como histórico de operações reais e não devem ser apagados sem revisão contábil. A integração oficial do Asaas, os Webhooks e a futura conciliação de pagamentos de criadores/clipadores continuam disponíveis.
