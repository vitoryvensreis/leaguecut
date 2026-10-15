<?php
declare(strict_types=1);
/**
 * Public informational pages required for app-review compliance.
 * Editorial copy must be approved by the operator before publishing.
 */
function lc_legal_routes(string $method,string $path):bool {
    $titles=[
      '/politica-de-privacidade'=>'Política de Privacidade',
      '/termos-de-uso'=>'Termos de Uso',
      '/exclusao-de-dados'=>'Exclusão de Dados'
    ];
    if($method!=='GET'||!isset($titles[$path]))return false;
    $title=$titles[$path];
    header('Cache-Control: public, max-age=300');
    lc_page($title,static function()use($path,$title){ ?>
<main class="container container-md" style="max-width:900px;padding:clamp(24px,5vw,64px) 16px;">
 <div class="page-heading"><span class="eyebrow">Transparência e proteção de dados</span><h1><?=lc_e($title)?></h1>
 <p>League Cut — informação pública sobre o uso da plataforma.</p></div>
 <div class="panel" style="padding:clamp(20px,4vw,40px);line-height:1.8">
 <p><small>Atualizado em 14 de outubro de 2026.</small></p>
 <?php if($path==='/politica-de-privacidade'): ?>
 <h2>1. Quem somos</h2><p>O League Cut é uma plataforma de campanhas e clipes, usada por clipadores, criadores e administradores. Esta política explica as informações necessárias à operação do serviço e ao uso opcional das integrações sociais.</p>
 <h2>2. Informações tratadas</h2><p>Conforme a funcionalidade utilizada, podemos tratar dados cadastrais e de contato, perfis de redes sociais cadastrados, links de publicações, vídeos enviados, registros de campanhas, visualizações e resultados, dados de pagamento e registros técnicos de segurança e acesso.</p>
 <h2>3. Perfis e métricas sociais</h2><p>Os perfis de Instagram são cadastrados manualmente. Enquanto a integração oficial da Meta aguarda aprovação, consultamos visualizações públicas de publicações elegíveis usando a Apify, um prestador externo; o cadastro do perfil não comprova sua titularidade. Em outras redes, onde houver conexão autorizada, podemos tratar identificadores, permissões e tokens necessários à integração. Os tokens não são exibidos publicamente.</p>
 <h2>4. Finalidades e fundamentos</h2><p>Os dados são usados para cadastro e acesso, gestão e verificação de clipes, execução de campanhas, atendimento, prevenção de fraude, segurança, obrigações legais e financeiras e integrações autorizadas. O tratamento deve observar as bases legais aplicáveis da LGPD, conforme cada finalidade.</p>
 <h2>5. Compartilhamento e armazenamento</h2><p>O serviço pode utilizar prestadores técnicos necessários à hospedagem, arquivos e integrações, incluindo a Apify para observação de visualizações públicas no Instagram, bem como as plataformas de redes sociais autorizadas. O acesso é limitado conforme a necessidade operacional. Dados podem ser mantidos pelo tempo necessário à finalidade, a obrigações legais e à defesa de direitos.</p>
 <h2>6. Direitos do titular</h2><p>Você pode solicitar informações, acesso, correção ou exclusão de dados, quando cabíveis, além de revogar conexões com plataformas. Alguns registros podem precisar ser conservados por obrigações legais.</p>
 <h2>7. Contato</h2><p>Para solicitações de privacidade, envie um e-mail para <a href="mailto:vitoryvensreisdeoliveira@gmail.com">vitoryvensreisdeoliveira@gmail.com</a>. Consulte também as <a href="/exclusao-de-dados">instruções de exclusão de dados</a>.</p>
 <?php elseif($path==='/termos-de-uso'): ?>
 <h2>1. Uso da plataforma</h2><p>O League Cut organiza campanhas, publicações e clipes. Ao se cadastrar, o usuário deve fornecer informações verdadeiras, manter a segurança de sua conta e respeitar as regras das campanhas e das redes sociais utilizadas.</p>
 <h2>2. Conteúdos e direitos</h2><p>O usuário declara possuir os direitos e autorizações necessários sobre os vídeos, marcas, imagens e materiais enviados, respeitando direitos de terceiros. O envio permite o tratamento do conteúdo para análise, organização e execução da campanha, observadas suas condições específicas.</p>
 <h2>3. Aprovação, métricas e remuneração</h2><p>Envios podem passar por revisão. A existência de visualizações observadas não implica aprovação nem pagamento automático. Metas, elegibilidade, prazos, valores e eventuais bônus dependem das regras expressas de cada campanha ou contratação.</p>
 <h2>4. Condutas proibidas</h2><p>São vedados fraudes de métricas, uso de contas de terceiros sem autorização, manipulação de resultados, violações de propriedade intelectual e tentativas de comprometer a segurança da plataforma.</p>
 <h2>5. Disponibilidade e terceiros</h2><p>Funcionalidades podem depender de Google, Apify, TikTok, serviços de armazenamento e outras plataformas. Alterações, limitações ou indisponibilidades desses serviços podem afetar a integração.</p>
 <h2>6. Privacidade e suporte</h2><p>O tratamento de dados é descrito na <a href="/politica-de-privacidade">Política de Privacidade</a>. Para dúvidas, utilize <a href="mailto:vitoryvensreisdeoliveira@gmail.com">vitoryvensreisdeoliveira@gmail.com</a>.</p>
 <?php else: ?>
 <h2>Como solicitar a exclusão de dados</h2><p>Você pode solicitar a exclusão da sua conta no League Cut e dos dados pessoais associados, incluindo dados recebidos por integrações autorizadas com Instagram e TikTok, conforme aplicável.</p>
 <h2>Passo 1 — Envie a solicitação</h2><p>Envie um e-mail para <a href="mailto:vitoryvensreisdeoliveira@gmail.com?subject=League%20Cut%20-%20Solicitacao%20de%20exclusao%20de%20dados">vitoryvensreisdeoliveira@gmail.com</a> com o assunto <strong>League Cut — Solicitação de exclusão de dados</strong>. Informe o e-mail cadastrado e, opcionalmente, o nome de usuário da rede conectada. Não envie senhas nem tokens.</p>
 <h2>Passo 2 — Confirmação de titularidade</h2><p>Para proteger seus dados, a equipe poderá confirmar sua identidade por meio do endereço cadastrado antes de processar a solicitação.</p>
 <h2>Passo 3 — Análise e exclusão</h2><p>Após a confirmação, o pedido será analisado e os dados elegíveis serão excluídos ou anonimizados. Informações cuja conservação seja legalmente necessária, como registros financeiros, poderão ser retidas pelo prazo aplicável. Você receberá uma resposta pelo canal utilizado.</p>
 <h2>Revogar acesso às redes sociais</h2><p>Você também pode revogar a autorização concedida ao League Cut nas configurações de aplicativos conectados do Instagram ou TikTok. A revogação impede novos acessos autorizados, mas não substitui a solicitação de exclusão dos dados já armazenados.</p>
 <h2>Outras informações</h2><p>Consulte nossa <a href="/politica-de-privacidade">Política de Privacidade</a> e os <a href="/termos-de-uso">Termos de Uso</a>. Esta página é pública e não exige login.</p>
 <?php endif; ?>
 </div>
 <nav aria-label="Documentos legais" style="display:flex;gap:18px;flex-wrap:wrap;margin-top:20px;">
 <a href="/politica-de-privacidade">Privacidade</a>
 <a href="/termos-de-uso">Termos de Uso</a>
 <a href="/exclusao-de-dados">Exclusão de Dados</a>
 </nav>
</main>
<?php });
    return true;
}
