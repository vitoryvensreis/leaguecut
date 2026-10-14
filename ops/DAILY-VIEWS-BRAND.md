# LeagueCut — visual e métricas diárias (14/10/2026)

## Views salvas no banco

- `clip_daily_views` guarda 1 linha por clipe e data local (America/Fortaleza), atualizada quando a Apify retorna um contador legítimo de reprodução e autor+shortcode correspondem ao perfil cadastrado.
- `clip_view_samples` preserva o histórico de alterações de contador. `clips.verified_views` permanece **separado**, conferido no ADM. A leitura automática não gera Pix, bônus nem créditos.
- Job `.github/workflows/apify-metrics.yml`: cron `0 5 * * *` (05:00 UTC = **02:00 em Fortaleza**). O GitHub Actions pode atrasar em dias de fila; não é garantia de execução no segundo exato.
- Um lote pode incluir até 20 URLs exatas; a Apify recebe `detailedData`, `maxItems` e `maxTotalChargeUsd`. Padrões: até 3 lotes/dia e US$ 0,15 de teto reservado por dia. O teto de cobrança não equivale ao gasto real. Para mais clipes, aumente a capacidade financeira com consciência.
- A plataforma tenta consultar **todos os vídeos elegíveis dentro da capacidade disponível**: clipes Instagram aprovados, com vídeo original. Clipes TikTok, rejeitados ou sem arquivo não são atualizados pelo Actor Instagram.
- Cada link é consultado **no máximo uma vez por dia local**, mesmo se a execução do GitHub sofrer atraso. A coluna Views públicas em `/admin/clipes` mostra o último dia com resultado confirmado ou "Aguardando coleta".
- O agendamento fica ligado por padrão quando `APIFY_API_TOKEN` está configurado. Para pausar sem apagar o token, defina `APIFY_SYNC_ENABLED=0` no `.env` privado.

## Upload da logo

- ADM → Personalização → Enviar arquivo da logo; formatos suportados: PNG, JPG/JPEG, WebP, GIF, AVIF ou SVG, tamanho máximo 5 MB.
- Logos ficam em `/home/leaguecut/shared/storage/brand` ou `BRAND_MEDIA_DIR` configurada; fora do document root, com nomes aleatórios.
- SVG é revalidado e higienizado com whitelist de elementos e atributos para evitar scripts, links externos, DTDs e conteúdo executável. Alguns SVGs complexos do Illustrator podem precisar ser exportados novamente como SVG padrão ou PNG.
- A logo aparece em todo o site e no ADM pelo endpoint público `/media/brand/{token}`, que só serve o arquivo atualmente configurado.
- A URL HTTPS externa continua suportada como alternativa.
- Não comite logotipos privados no repositório nem arquivos `.env`.

## Cantos reduzidos

Os `border-radius` em todos os nove arquivos CSS do produto foram reduzidos em no mínimo 90%. A experiência mantém tamanhos e espaçamentos e não usa widgets de emojis.
