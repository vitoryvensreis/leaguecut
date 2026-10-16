# LeagueCut — inventário inicial de privacidade (revisão operacional necessária)

Estado: implementação técnica, **não é certificado de conformidade**.

## Escopo implementado
- Banner acessível com Aceitar opcionais, Rejeitar opcionais e Personalizar; decisão separada por categoria, inicialmente desligada.
- Cookie de preferência aleatório HttpOnly (180 dias) e trilha de decisões no banco com versão de política; sem armazenamento de IP na tabela de consentimentos.
- A recusa não impede login, PWA ou acesso às campanhas. Cookies essenciais (sessão/CSRF/autenticação) continuam.
- Links permanentes no rodapé, sem dependência de JavaScript para editar ou retirar autorização.
- Formulário autenticado de solicitações LGPD; confirmação por senha, protocolo, acompanhamento e painel do ADM, com audit de andamento.
- Remoção da requisição de fontes ao Google Fonts nas páginas públicas.
- Política de Cookies e atualização parcial de avisos; não habilita pixels nem analytics e não transmite dados a novos terceiros.

## Pendências que exigem conferência do responsável legal
1. **Identificação da empresa concluída:** LeagueCut é produto da ESCOTILHA PRODUTORA LTDA, CNPJ 64.069.224/0001-32, endereço cadastral Av. Pasteur, 1382, Altos, Bairro Álvaro Weyne, Fortaleza/CE, CEP 60335-000. E-mail de contato constante do documento cadastral: vitoryvensreisdeoliveira@gmail.com. Fonte: comprovante CNPJ fornecido pelo responsável, emitido em 16/12/2025. **Pendente:** validar se o canal de contato deve ser substituído por endereço corporativo, analisar necessidade de designação/divulgação de encarregado (DPO) e confirmar dados se alterados desde a emissão. Não anexar o PDF ao repositório.
2. Conferir bases legais e necessidade para cada dado: email, nome, WhatsApp, Instagram, links, arquivos no Drive, chaves Pix, tokens OAuth, métricas Apify, logs, pagamentos, dados de criadores.
3. Validar cláusulas e contratos com processadores: hospedagem/VPS, Google, Meta, TikTok, Apify, e-mail e WhatsApp; analisar transferências internacionais e segurança.
4. Definir e implementar uma tabela real de retenção e descarte para cada categoria; backups e registros fiscais/financeiros precisam regras próprias. Não aplicar deleção cega que destrua evidências.
5. Auditar cookies de todos os serviços ativos e integrações de terceiros. Nenhum script opcional pode ser carregado só porque o UI diz que está desligado. Este bloco não instala analytics/pixels.
6. Estabelecer responsáveis e procedimento de incidentes, registro de operações e processo de atendimento de solicitações; avaliar aviso à ANPD/titulares nos prazos legais e medidas.
7. Revisar tratamento de dados de crianças e adolescentes, provas de autorização e regras de elegibilidade; caso a plataforma não aceite menores, aplicar restrição real no cadastro.
8. Garantir resposta efetiva para pedidos; concluir no ADM não substitui disponibilizar os dados ou remover os dados elegíveis.
9. Aprovar juridicamente as versões públicas da Política de Privacidade, Termos de Uso e Política de Cookies.

## Referências regulatórias
- Guia orientativo de cookies e proteção de dados da ANPD: https://www.gov.br/anpd/pt-br/centrais-de-conteudo/materiais-educativos-e-publicacoes/guia-orientativo-cookies-e-protecao-de-dados-pessoais.pdf
- Direitos dos titulares da ANPD: https://www.gov.br/anpd/pt-br/assuntos/titular-de-dados/direito-dos-titulares
- RCIS e prazos para incidentes: https://www.gov.br/anpd/pt-br/canais_atendimento/agente-de-tratamento/comunicado-de-incidente-de-seguranca-cis

## Identidade do controlador publicada
- Nome empresarial: ESCOTILHA PRODUTORA LTDA
- Nome fantasia: ESCOTILHA
- CNPJ: 64.069.224/0001-32
- O LeagueCut é um produto da Escotilha Produtora LTDA.
- Endereço cadastral informado: Av. Pasteur, 1382, Altos, Álvaro Weyne, Fortaleza/CE, CEP 60335-000
- Canal de contato informado no comprovante e já presente nas políticas: vitoryvensreisdeoliveira@gmail.com
- O documento da Receita Federal comprova os dados na data de emissão de 16/12/2025; não substitui consulta de situação cadastral atual ou auditoria jurídica.
- Não foi identificado documento de designação de encarregado pelo tratamento (DPO), nem canal distinto para essa função.
