# LeagueCut — catálogo cinematográfico, ranking e motion

## Catálogo de campanhas

- A rota pública `/explorar` mantém busca/categorias e utiliza um destaque grande com fundo da capa, CTA "Explorar e ver ranking" e coleções por categoria, com cartões de capa responsivos.
- O ADM `/admin/campanhas` usa a mesma experiência e preserva criação/edição/status sem esconder campanhas em rascunho, pausadas ou encerradas (fora do público).
- Clipes devem ser enviados pela tela da campanha, exatamente como antes. Nenhuma regra de pagamentos mudou.

## Ranking por campanha

- `/campanhas/{slug}` tem pódio até 3 criadores e classificação até 30, sem migração: apenas `clips`, `users` e `clip_daily_views` existentes.
- Entram somente clipes **aprovados**. Views vêm do último snapshot público real de cada clipe; se não houve coleta, valem zero *apenas para pontuação*, sem inventar métricas financeiras.
- XP do jogo = **100 por clipe aprovado + 1 a cada 100 visualizações públicas observadas**. Cada nível tem 500 XP. Ranking: views públicas desc, clipes aprovados desc, id asc. Mostra barras de progresso, badges para ouro/prata/bronze e destaque da própria posição.
- O placar **não determina o bônus mensal** e não substitui verificação dos números. Nenhuma escrita financeira na consulta. Visitantes anônimos veem rótulos `Clipador #NNN`; logados veem primeiro nome + inicial do sobrenome.

## Animação e layout

- `workspace-motion.css` é carregado **apenas** em `workspace-page` (painel ADM e clipador); menus e painéis entram com fade + translação vertical pequena; nenhum movimento lateral.
- A LP em `/` **não recebe** animações de entrada nem stylesheet de motion. Animações ambientais antigas da mídia do banner ficam como estavam.
- Compatível com `prefers-reduced-motion`; todas as grades têm `minmax(0,1fr)` e breakpoints (1160, 820, 580 e 360px) para evitar overflow horizontal da página, sem obrigar o usuário a arrastar um carrossel.

## Testes

- Smoke completo testa catálogo para ADM/clipador, pesquisa/filtro, campanha com ranking vazio, ranking com 5 clipes aprovados e visitantes anonimizados.
- Teste PHP separado injeta snapshot dentro de transação descartável, verifica pontuação e que o placar não grava créditos.
- UX smoke confirma stylesheet de animação ausente da LP, disponível nos painéis, e contenção de largura.
