<?php
declare(strict_types=1);

/**
 * One-time, idempotent internal Drive upload test campaign.
 * Does not credit accounts, mark any clip approved, or issue payments.
 * The symbolic configured rewards (R$ 0,01) only apply if somebody
 * subsequently approves clips using the existing business rules.
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
if (getenv('LEAGUECUT_SEED_TEST')!=='CONFIRMO_TESTE_LEAGUECUT_2026') {
    fwrite(STDERR,"TESTE_SEED_BLOQUEADO: missing one-time confirmation.\n");exit(1);
}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';

$slug='teste-drive-completo-leaguecut-2026';
$title='TESTE INTERNO | Primeiro vídeo no Google Drive';
$description=<<<'BRIEFING'
CAMPANHA INTERNA DE TESTE — LEAGUECUT
IMPORTANTE: Esta campanha é apenas para validar o funcionamento da plataforma. Não é uma oferta comercial de trabalho nem uma promessa de remuneração. Os valores de R$ 0,01 são simbólicos e existem para demonstrar os campos de pagamento e bônus. NÃO REALIZE PAGAMENTOS PIX para este teste.

OBJETIVO
Gravar, publicar e enviar um vídeo curto para testar todo o caminho do clipador: campanha → perfil social → link da publicação → upload do arquivo original no Google Drive → análise do administrador → aprovação → histórico.

DESAFIO CRIATIVO
Produza um vídeo de 15 a 45 segundos, preferencialmente vertical 9:16, sobre o tema "Como seria transformar meus vídeos em novas oportunidades". Pode ser uma apresentação simples, um corte de algo que você gravou ou um conteúdo inteiramente original. O importante aqui é testar o fluxo técnico.

ROTEIRO SUGERIDO
1. Gancho de 2 a 4 segundos: "Você já imaginou transformar cortes em oportunidades?"
2. Desenvolvimento de 10 a 30 segundos: mostre uma edição, explique o que faz um clipador ou apresente uma ideia de conteúdo.
3. Fechamento de 3 a 7 segundos: "Este é o primeiro teste do LeagueCut".

REQUISITOS DE ENVIO
- Conta de clipador aprovada e perfil do Instagram ou TikTok cadastrado na plataforma.
- Link HTTPS válido da publicação na rede social escolhida.
- Título claro para identificar o vídeo.
- Anexar o arquivo original (preferencialmente MP4 com H.264, áudio AAC, 1080 x 1920; MOV/WebM também aceitos).
- Para o primeiro teste, use arquivo pequeno de 5 a 20 MB, porque há limites de upload no PHP/Nginx.
- Não utilize músicas, marcas, vídeos ou imagens de terceiros sem autorização.

COMO TESTAR O DRIVE
1. Faça login como clipador e abra esta campanha.
2. Selecione seu perfil social; se ainda não tiver, cadastre em "Minhas redes".
3. Informe um título e o link real HTTPS do post.
4. Anexe um vídeo original pequeno.
5. Clique em "Enviar para conferência".
6. No ADM, acesse "Conferir clipes" e confira o link "Abrir original no Drive".
7. Abra o arquivo no Google Drive usando a mesma conta Google conectada ao LeagueCut.

REGRAS SIMBÓLICAS DO SISTEMA
- Meta semanal: mínimo de 1 clipe aprovado na mesma semana.
- Valor por vídeo aprovado ao atingir a meta: R$ 0,01 (um centavo, somente teste).
- Meta de visualizações por vídeo: 100 views conferidas manualmente.
- Bônus ao atingir a meta de views: R$ 0,01 (um centavo, somente teste).
- Bônus de líder mensal por views aprovadas: R$ 0,01 (um centavo, somente teste; apuração manual).
- A aprovação e a contagem de visualizações são manuais. O envio sozinho não credita dinheiro.
- Não executar saques ou transferências reais relacionados a esta campanha.

CHECKLIST DE VALIDAÇÃO
[ ] Campanha visível em /explorar
[ ] Clipador aprovado consegue selecionar a rede
[ ] Envio de link HTTPS aceito
[ ] Upload do vídeo original concluído
[ ] Arquivo privado acessível ao administrador no Google Drive
[ ] Clipe aparece como "Pendente"
[ ] Notificação realtime recebida no painel ADM
[ ] Aprovação ou rejeição registrada corretamente

APÓS O TESTE
O administrador poderá editar a campanha e alterar seu status para "Encerrada" no painel, preservando o histórico de clipes.
BRIEFING;

try {
    if(lc_env('DB_NAME')!=='leaguecut')throw new RuntimeException('Banco de dados inesperado.');
    $db=lc_db();
    $db->beginTransaction();
    try {
        $existing=lc_one('SELECT id,slug,status FROM campaigns WHERE slug=? FOR UPDATE',[$slug]);
        if($existing) {
            $db->commit();
            echo 'TEST_CAMPAIGN_EXISTS id='.(int)$existing['id'].' slug='.$slug.' status='.$existing['status']."\n";
            exit(0);
        }
        $admin=lc_one("SELECT id FROM users WHERE role='admin' AND status='approved' ORDER BY id ASC LIMIT 1");
        if(!$admin)throw new RuntimeException('Nenhum administrador aprovado existe no banco.');
        $params=[
            $title,$description,'Laboratório / Testes',
            'https://leaguecut.com/assets/campaign-drive-test.svg',
            'active',1,1,100,1,1,'2026-10-14','2026-10-31',
            $slug,(int)$admin['id']
        ];
        $db->prepare('INSERT INTO campaigns(title,description,category,cover_url,status,min_videos_week,payment_per_video_cents,milestone_views,milestone_bonus_cents,monthly_leader_bonus_cents,starts_at,ends_at,slug,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($params);
        $id=(int)$db->lastInsertId();
        lc_realtime_emit('admin',null,'campaign.updated','campaign',$id);
        $db->commit();
        echo "TEST_CAMPAIGN_CREATED id=$id slug=$slug status=active rewards=symbolic\n";
    }catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}catch(Throwable $e){
    fwrite(STDERR,'TEST_CAMPAIGN_ERROR: '.$e->getMessage()."\n"); exit(1);
}
