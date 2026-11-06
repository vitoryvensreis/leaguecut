<?php
declare(strict_types=1);

/** Routes and screens for the platform cash. Business rules live in platform-finance.php. */

function lc_pf_password_fields(array $actor, string $label = 'Sua senha'): void {
?><div class="lc-pf-confirm">
  <label class="field"><span><?=lc_e($label)?></span><input type="password" name="admin_password" autocomplete="current-password" required data-password-toggle></label>
  <?php if(lc_mfa_enabled((int)$actor['id'])):?><label class="field"><span>Código 2FA</span><input name="mfa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="60" required placeholder="000000"></label><?php endif;?>
</div><?php
}
function lc_pf_status_tag(string $status): string {
    $tone = match ($status) {
        'paid' => 'ok', 'awaiting_authorization', 'authorized', 'sending', 'processing' => 'wait',
        'unknown' => 'warn', default => 'off',
    };
    return '<span class="lc-pf-status is-' . $tone . '">' . lc_e(lc_pf_status_label($status)) . '</span>';
}
function lc_pf_date_param(string $key): ?string {
    $v = (string)($_GET[$key] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && strtotime($v) !== false ? $v : null;
}
function lc_pf_page(array $admin): void {
    lc_pf_expire_stale();
    $summary = lc_pf_summary();
    $isMaster = lc_is_master($admin);
    $kind = in_array($_GET['tipo'] ?? '', ['in', 'clipper', 'platform'], true) ? (string)$_GET['tipo'] : '';
    $from = lc_pf_date_param('de'); $to = lc_pf_date_param('ate');
    $page = max(1, min(10000, (int)($_GET['pagina'] ?? 1)));
    $statement = lc_pf_statement($kind, $from, $to, $page);
    $withdrawals = lc_all('SELECT p.*,u.full_name AS requester FROM platform_withdrawals p JOIN users u ON u.id=p.requested_by ORDER BY p.id DESC LIMIT 40');
    $events = lc_all('SELECT e.*,u.full_name FROM platform_finance_events e LEFT JOIN users u ON u.id=e.actor_user_id ORDER BY e.id DESC LIMIT 30');
    $wppReady = function_exists('lc_wpp_ready') && lc_wpp_ready();
    $payouts = lc_pf_payouts_enabled();
    try { $approverTail = substr(lc_pf_approver_phone(), -4); } catch (Throwable) { $approverTail = '????'; }
    $pages = max(1, (int)ceil($statement['total'] / 25));
    $query = static fn(int $p): string => '/admin/financeiro/caixa?' . http_build_query(array_filter(['tipo' => $kind, 'de' => $from, 'ate' => $to, 'pagina' => $p > 1 ? $p : null])) . '#extrato';
    lc_page('Caixa da plataforma', function () use ($admin, $summary, $isMaster, $kind, $from, $to, $page, $pages, $statement, $withdrawals, $events, $wppReady, $payouts, $approverTail, $query) { ?>
<div class="container lc-pf">
<?php lc_heading('Financeiro', 'Caixa da plataforma', 'O que entrou, o que saiu, o que está comprometido com clipadores e o que pode ser sacado.'); ?>
<?php if (!$wppReady): ?><div class="alert lc-pf-alert is-warn" role="status"><?=lc_icon('share-2')?> <span><strong>WhatsApp desconectado.</strong> Novos pedidos ficam registrados, mas o link de autorização só é enviado com a sessão ativa em <a href="/admin/whatsapp">Conexão WhatsApp</a>.</span></div><?php endif; ?>
<?php if (!$payouts): ?><div class="alert lc-pf-alert" role="status"><?=lc_icon('shield-check')?> <span><strong>Pix automático desligado no servidor.</strong> Saques autorizados ficam aguardando envio até o .env da VPS ter <code>ASAAS_ENABLED=1</code> e <code>ASAAS_PLATFORM_PAYOUTS_ENABLED=1</code>.</span></div><?php endif; ?>

<section class="lc-pf-kpis" aria-label="Resumo do caixa">
  <article class="lc-pf-kpi is-in"><small>Entradas</small><strong><?=lc_private(lc_money($summary['income']))?></strong><span>Neste mês: <?=lc_private(lc_money($summary['income_month']))?></span><em>Assinaturas de criadores confirmadas</em></article>
  <article class="lc-pf-kpi is-out"><small>Saídas</small><strong><?=lc_private(lc_money($summary['out']))?></strong><span>Neste mês: <?=lc_private(lc_money($summary['out_month']))?></span><em>Clipadores <?=lc_private(lc_money($summary['users_paid']))?> · Plataforma <?=lc_private(lc_money($summary['platform_paid']))?></em></article>
  <article class="lc-pf-kpi is-hold"><small>Comprometido</small><strong><?=lc_private(lc_money($summary['committed'] + $summary['platform_reserved']))?></strong><span>Saldo dos clipadores: <?=lc_private(lc_money($summary['committed']))?></span><em>Saques da plataforma em andamento: <?=lc_private(lc_money($summary['platform_reserved']))?></em></article>
  <article class="lc-pf-kpi is-free"><small>Disponível para saque</small><strong><?=lc_private(lc_money($summary['available']))?></strong><span>Caixa <?=lc_private(lc_money($summary['cash']))?> − comprometido</span><em>Não usa o saldo de outros produtos no Asaas</em></article>
</section>

<div class="lc-pf-grid">
<section class="panel lc-pf-request">
  <h2><?=lc_icon('wallet')?> Solicitar saque</h2>
  <p class="hint">O pedido reserva o valor e envia um link para o WhatsApp final <strong><?=lc_e($approverTail)?></strong>. Só a conta master autoriza, com senha e 2FA. Depois disso o Asaas faz o Pix.</p>
  <form method="post" action="/admin/financeiro/caixa/solicitar" class="lc-pf-form" data-pix-form data-validate novalidate><?=lc_csrf()?>
    <div class="form-grid">
      <label class="field"><span>Valor</span><input name="amount" type="text" inputmode="decimal" required placeholder="R$ 0,00" data-lc-mask="money" data-max-cents="<?=(int)$summary['available']?>" aria-describedby="pf-amount-help"><small id="pf-amount-help">Disponível: <?=lc_private(lc_money($summary['available']))?></small></label>
      <label class="field"><span>Tipo da chave Pix</span><select name="pix_key_type" required data-pix-type>
        <option value="">Selecione…</option><?php foreach (LC_PIX_KEY_TYPES as $value => $label): ?><option value="<?=$value?>"><?=lc_e($label)?></option><?php endforeach; ?>
      </select></label>
      <label class="field full"><span>Chave Pix de destino</span><input name="pix_key" type="text" required autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="77" placeholder="Escolha o tipo da chave primeiro" data-pix-input data-pix-lock disabled></label>
      <label class="field full"><span>Descrição (opcional)</span><input name="description" type="text" maxlength="120" placeholder="Ex.: retirada mensal" autocomplete="off"></label>
    </div>
    <?php lc_pf_password_fields($admin); ?>
    <div class="form-actions"><button class="btn" type="submit" data-loading-text="Registrando…">Solicitar e enviar link <?=lc_icon('arrow-right')?></button></div>
  </form>
</section>

<section class="panel lc-pf-requests">
  <h2><?=lc_icon('clipboard-check')?> Saques da plataforma</h2>
  <?php if (!$withdrawals): lc_empty('Nenhum saque ainda', 'Os pedidos e o andamento de cada um aparecem aqui.'); else: ?>
  <ul class="lc-pf-list">
  <?php foreach ($withdrawals as $w): $id = (int)$w['id']; ?>
    <li class="lc-pf-item">
      <div class="lc-pf-item-head"><a href="/admin/financeiro/caixa/<?=$id?>"><strong>#<?=$id?> · <?=lc_private(lc_money($w['amount_cents']))?></strong></a><?=lc_pf_status_tag((string)$w['status'])?></div>
      <p><?=lc_private($w['pix_key_hint'])?> · por <?=lc_e($w['requester'])?> · <?=lc_e(date('d/m H:i', strtotime((string)$w['created_at'])))?></p>
      <?php if ($w['last_error']): ?><p class="lc-pf-error"><?=lc_e($w['last_error'])?></p><?php endif; ?>
      <div class="lc-pf-actions">
        <?php if ($w['status'] === 'awaiting_authorization'): ?>
          <form method="post" action="/admin/financeiro/caixa/<?=$id?>/reenviar"><?=lc_csrf()?><button class="btn btn-ghost btn-sm" type="submit">Reenviar link</button></form>
          <form method="post" action="/admin/financeiro/caixa/<?=$id?>/cancelar"><?=lc_csrf()?><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Cancelar o saque #<?=$id?>?">Cancelar</button></form>
        <?php elseif ($w['status'] === 'authorized' && $isMaster): ?>
          <a class="btn btn-sm" href="/admin/financeiro/caixa/<?=$id?>">Enviar ao Asaas</a>
        <?php elseif (in_array($w['status'], ['processing', 'sending'], true) && $w['provider_transfer_id']): ?>
          <form method="post" action="/admin/financeiro/caixa/<?=$id?>/sincronizar"><?=lc_csrf()?><button class="btn btn-ghost btn-sm" type="submit">Atualizar status</button></form>
        <?php elseif ($w['status'] === 'unknown'): ?>
          <a class="btn btn-sm" href="/admin/financeiro/caixa/<?=$id?>">Conferir</a>
        <?php elseif ($w['status'] === 'paid' && $w['receipt_url']): ?>
          <a class="btn btn-ghost btn-sm" href="<?=lc_e($w['receipt_url'])?>" target="_blank" rel="noopener noreferrer">Comprovante</a>
        <?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
</div>

<section class="panel lc-pf-statement" id="extrato">
  <div class="lc-pf-section-head"><h2><?=lc_icon('chart-column')?> Extrato</h2>
  <form method="get" action="/admin/financeiro/caixa#extrato" class="lc-pf-filters">
    <label class="field"><span>Tipo</span><select name="tipo"><option value="">Tudo</option><option value="in" <?=$kind === 'in' ? 'selected' : ''?>>Entradas</option><option value="clipper" <?=$kind === 'clipper' ? 'selected' : ''?>>Saques de clipadores</option><option value="platform" <?=$kind === 'platform' ? 'selected' : ''?>>Saques da plataforma</option></select></label>
    <label class="field"><span>De</span><input type="date" name="de" value="<?=lc_e($from ?? '')?>"></label>
    <label class="field"><span>Até</span><input type="date" name="ate" value="<?=lc_e($to ?? '')?>"></label>
    <button class="btn btn-ghost btn-sm" type="submit">Filtrar</button>
  </form></div>
  <?php if (!$statement['rows']): lc_empty('Sem movimentações', 'Ajuste os filtros ou aguarde novas entradas e saídas.'); else: ?>
  <div class="table-wrap"><table><thead><tr><th>Data</th><th>Movimento</th><th>Pessoa</th><th class="num">Valor</th></tr></thead><tbody>
  <?php foreach ($statement['rows'] as $r): $label = ['in' => 'Assinatura #', 'clipper' => 'Saque de clipador #', 'platform' => 'Saque da plataforma #'][$r['kind']] ?? '#'; ?>
    <tr><td><?=lc_e($r['at'] ? date('d/m/Y H:i', strtotime((string)$r['at'])) : '—')?></td><td><?=lc_e($label . (int)$r['ref_id'])?></td><td><?=lc_private((string)($r['full_name'] ?? '—'))?></td>
    <td class="num <?=(int)$r['amount'] >= 0 ? 'is-in' : 'is-out'?>"><?=lc_private(((int)$r['amount'] >= 0 ? '+ ' : '− ') . lc_money(abs((int)$r['amount'])))?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><nav class="lc-pf-pager" aria-label="Páginas do extrato">
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?=lc_e($query($page - 1))?>">Anterior</a><?php endif; ?>
    <span>Página <?=$page?> de <?=$pages?></span>
    <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?=lc_e($query($page + 1))?>">Próxima</a><?php endif; ?>
  </nav><?php endif; ?>
  <?php endif; ?>
</section>

<section class="panel lc-pf-log">
  <h2><?=lc_icon('activity')?> Log de transações</h2>
  <p class="hint">Registro permanente de cada etapa. Não contém chaves Pix completas, links ou senhas.</p>
  <?php if (!$events): lc_empty('Sem registros', 'Cada solicitação, envio de link, autorização e retorno do Asaas aparece aqui.'); else: ?>
  <ol class="lc-pf-timeline">
  <?php foreach ($events as $e): ?>
    <li><time><?=lc_e(date('d/m H:i:s', strtotime((string)$e['created_at'])))?></time><div><strong><?=lc_e(lc_pf_event_label((string)$e['event']))?></strong><?php if ($e['withdrawal_id']): ?> · <a href="/admin/financeiro/caixa/<?=(int)$e['withdrawal_id']?>">#<?=(int)$e['withdrawal_id']?></a><?php endif; ?>
      <small><?=lc_e($e['full_name'] ?? 'Sistema')?><?=$e['detail'] !== '' ? ' · ' . lc_e($e['detail']) : ''?></small></div></li>
  <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>
</div>
<?php }, 'admin');
}
function lc_pf_detail_page(array $admin, int $id): void {
    $w = lc_one('SELECT p.*,u.full_name AS requester,a.full_name AS approver FROM platform_withdrawals p JOIN users u ON u.id=p.requested_by LEFT JOIN users a ON a.id=p.authorized_by WHERE p.id=?', [$id]);
    if (!$w) { http_response_code(404); lc_page('Saque não encontrado', fn() => lc_empty('Saque não encontrado', 'Confira o número do pedido.'), 'admin'); return; }
    $events = lc_all('SELECT e.*,u.full_name FROM platform_finance_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.withdrawal_id=? ORDER BY e.id ASC', [$id]);
    $isMaster = lc_is_master($admin);
    lc_page('Saque #' . $id, function () use ($w, $events, $isMaster, $admin, $id) { ?>
<div class="container container-md lc-pf">
<a class="lc-back-link" href="/admin/financeiro/caixa"><?=lc_icon('arrow-left')?> Caixa da plataforma</a>
<?php lc_heading('Saque da plataforma', 'Pedido #' . $id); ?>
<section class="panel lc-pf-detail">
  <dl class="lc-pf-facts">
    <div><dt>Valor</dt><dd><?=lc_private(lc_money($w['amount_cents']))?></dd></div>
    <div><dt>Situação</dt><dd><?=lc_pf_status_tag((string)$w['status'])?></dd></div>
    <div><dt>Destino</dt><dd><?=lc_private($w['pix_key_hint'])?></dd></div>
    <div><dt>Solicitado por</dt><dd><?=lc_e($w['requester'])?> · <?=lc_e(date('d/m/Y H:i', strtotime((string)$w['created_at'])))?></dd></div>
    <div><dt>Decisão</dt><dd><?=$w['approver'] ? lc_e($w['approver'] . ' · ' . date('d/m/Y H:i', strtotime((string)$w['authorized_at']))) : '—'?></dd></div>
    <div><dt>Referência</dt><dd><code><?=lc_e($w['provider_reference'])?></code></dd></div>
    <div><dt>Transferência Asaas</dt><dd><?=$w['provider_transfer_id'] ? '<code>' . lc_e($w['provider_transfer_id']) . '</code> · ' . lc_e((string)$w['provider_status']) : '—'?></dd></div>
    <?php if ($w['description']): ?><div><dt>Descrição</dt><dd><?=lc_e($w['description'])?></dd></div><?php endif; ?>
  </dl>
  <?php if ($w['last_error']): ?><p class="lc-pf-error"><?=lc_e($w['last_error'])?></p><?php endif; ?>
  <?php if ($w['receipt_url']): ?><a class="btn btn-ghost btn-sm" href="<?=lc_e($w['receipt_url'])?>" target="_blank" rel="noopener noreferrer">Abrir comprovante</a><?php endif; ?>
</section>
<?php if ($isMaster && $w['status'] === 'authorized'): ?>
<section class="panel"><h2>Enviar ao Asaas</h2><p class="hint">O saque já foi autorizado. Confirme para o Asaas criar o Pix.</p>
<form method="post" action="/admin/financeiro/caixa/<?=$id?>/enviar"><?=lc_csrf()?><?php lc_pf_password_fields($admin); ?>
<div class="form-actions"><button class="btn" type="submit" data-loading-text="Enviando…">Enviar Pix pelo Asaas <?=lc_icon('arrow-right')?></button></div></form>
<form method="post" action="/admin/financeiro/caixa/<?=$id?>/cancelar" class="lc-pf-inline"><?=lc_csrf()?><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Cancelar o saque #<?=$id?>?">Cancelar saque</button></form></section>
<?php elseif ($isMaster && $w['status'] === 'unknown'): ?>
<section class="panel"><h2>Conferir no Asaas</h2><p class="hint">Não houve confirmação do Asaas. Procure no painel Asaas a transferência com a referência <code><?=lc_e($w['provider_reference'])?></code>, ou pelo valor e horário.</p>
<form method="post" action="/admin/financeiro/caixa/<?=$id?>/conferir"><?=lc_csrf()?><input type="hidden" name="action" value="link">
<label class="field"><span>A transferência existe: ID no Asaas</span><input name="transfer_id" required maxlength="90" autocomplete="off" spellcheck="false"></label><?php lc_pf_password_fields($admin); ?>
<div class="form-actions"><button class="btn" type="submit">Vincular e atualizar</button></div></form>
<form method="post" action="/admin/financeiro/caixa/<?=$id?>/conferir" class="lc-pf-inline"><?=lc_csrf()?><input type="hidden" name="action" value="not_created"><?php lc_pf_password_fields($admin); ?>
<div class="form-actions"><button class="btn btn-ghost" type="submit" data-confirm="Confirma que NÃO existe transferência no Asaas para este pedido?">Não existe no Asaas</button></div></form></section>
<?php elseif (in_array($w['status'], ['processing', 'sending'], true) && $w['provider_transfer_id']): ?>
<form method="post" action="/admin/financeiro/caixa/<?=$id?>/sincronizar" class="lc-pf-inline"><?=lc_csrf()?><button class="btn btn-ghost" type="submit">Atualizar status no Asaas</button></form>
<?php endif; ?>
<section class="panel lc-pf-log"><h2>Log deste saque</h2>
<ol class="lc-pf-timeline"><?php foreach ($events as $e): ?><li><time><?=lc_e(date('d/m/Y H:i:s', strtotime((string)$e['created_at'])))?></time><div><strong><?=lc_e(lc_pf_event_label((string)$e['event']))?></strong><?php if ($e['status_to']): ?> → <?=lc_e(lc_pf_status_label((string)$e['status_to']))?><?php endif; ?><small><?=lc_e($e['full_name'] ?? 'Sistema')?><?=$e['detail'] !== '' ? ' · ' . lc_e($e['detail']) : ''?></small></div></li><?php endforeach; ?></ol>
</section>
</div>
<?php }, 'admin');
}
function lc_pf_authorize_page(array $user, ?array $row, int $id, string $token, ?string $error = null): void {
    lc_page('Autorizar saque', function () use ($user, $row, $id, $token, $error) { ?>
<div class="container container-sm lc-pf lc-pf-authorize">
  <div class="lc-pf-auth-card panel">
    <span class="eyebrow">Autorização de saque</span>
    <div class="lc-auth-mfa-symbol"><?=lc_icon('shield-check')?></div>
    <?php if ($error !== null): ?><p class="lc-auth-message is-error" role="alert"><?=lc_e($error)?></p><?php endif; ?>
    <?php if (!$row): ?>
      <h1>Link indisponível</h1>
      <p>Este link expirou, já foi usado ou não é válido. Gere um novo em <a href="/admin/financeiro/caixa">Caixa da plataforma</a> com “Reenviar link”.</p>
    <?php else: ?>
      <h1><?=lc_money((int)$row['amount_cents'])?></h1>
      <dl class="lc-pf-facts">
        <div><dt>Pedido</dt><dd>#<?=$id?> · <?=lc_e(date('d/m/Y H:i', strtotime((string)$row['created_at'])))?></dd></div>
        <div><dt>Chave Pix</dt><dd><strong><?=lc_e(LC_PIX_KEY_TYPES[$row['pix_key_type']] ?? $row['pix_key_type'])?>:</strong> <?=lc_e(lc_pix_key_format((string)$row['pix_key_type'], lc_decrypt((string)$row['pix_key_enc'])))?></dd></div>
        <div><dt>Solicitado por</dt><dd><?=lc_e($row['requester'] ?? '')?></dd></div>
        <?php if ($row['description']): ?><div><dt>Descrição</dt><dd><?=lc_e($row['description'])?></dd></div><?php endif; ?>
        <div><dt>Validade do link</dt><dd><?=max(1,(int)ceil(((int)($row['link_seconds_left'] ?? 0))/60))?> min restantes</dd></div>
      </dl>
      <p class="hint">Confira a chave com atenção. Ao autorizar, o Asaas recebe o pedido de Pix imediatamente.</p>
      <form method="post" action="/admin/financeiro/autorizar/<?=$id?>/<?=lc_e($token)?>" class="lc-pf-form"><?=lc_csrf()?>
        <?php lc_pf_password_fields($user, 'Senha da conta master'); ?>
        <div class="lc-pf-decide">
          <button class="btn" type="submit" name="decision" value="authorize" data-loading-text="Autorizando…">Autorizar saque <?=lc_icon('arrow-right')?></button>
          <button class="btn btn-ghost" type="submit" name="decision" value="refuse" data-confirm="Recusar o saque #<?=$id?>?">Recusar</button>
        </div>
        <p class="form-note">Recusar também exige senha<?=lc_mfa_enabled((int)$user['id']) ? ' e 2FA' : ''?>.</p>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php }, 'admin');
}
function lc_platform_finance_routes(string $method, string $path): bool {
    if (preg_match('~^/admin/financeiro/autorizar/([0-9]{1,18})/([A-Za-z0-9_-]{1,64})$~D', $path, $m)) {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $id = (int)$m[1]; $token = $m[2];
        if (!lc_user()) {
            if ($method === 'GET') $_SESSION['lc_after_login'] = $path;
            lc_flash('Entre na conta master para autorizar o saque.');
            lc_redirect('/entrar');
        }
        $actor = lc_admin();
        if (!lc_is_master($actor)) {
            lc_pf_log($id > 0 && lc_one('SELECT id FROM platform_withdrawals WHERE id=?', [$id]) ? $id : null, 'authorization_denied', (int)$actor['id'], null, null, 'Acesso ao link por conta não master.');
            http_response_code(403);
            lc_page('Acesso negado', fn() => lc_empty('Somente a conta master autoriza saques', 'Este acesso foi registrado no log financeiro.'), 'admin');
            return true;
        }
        $load = static fn(): ?array => ($r = lc_pf_link_row($id, $token)) ? $r + ['requester' => lc_one('SELECT full_name FROM users WHERE id=?', [(int)$r['requested_by']])['full_name'] ?? ''] : null;
        if ($method === 'POST') {
            $decision = lc_post('decision', 12);
            if (!lc_account_admin_password($actor)) {
                if (lc_one('SELECT id FROM platform_withdrawals WHERE id=?', [$id])) lc_pf_log($id, 'authorization_denied', (int)$actor['id'], null, null, 'Senha ou 2FA incorretos.');
                lc_pf_authorize_page($actor, $load(), $id, $token, 'Senha ou código 2FA incorretos.');
                return true;
            }
            try {
                $message = lc_pf_decide($id, $token, $actor, $decision === 'refuse' ? 'refuse' : 'authorize');
                lc_flash($message);
            } catch (Throwable $e) {
                lc_flash($e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Falha ao processar a autorização.', 'error');
            }
            lc_redirect('/admin/financeiro/caixa/' . $id);
        }
        lc_pf_authorize_page($actor, $load(), $id, $token);
        return true;
    }
    if ($path !== '/admin/financeiro/caixa' && !str_starts_with($path, '/admin/financeiro/caixa/')) return false;
    $admin = lc_admin();
    if (!lc_admin_allowed($admin, 'finance')) { http_response_code(403); exit('Sem permissão financeira.'); }
    if ($method === 'GET' && $path === '/admin/financeiro/caixa') { lc_pf_page($admin); return true; }
    if ($method === 'GET' && preg_match('~^/admin/financeiro/caixa/([0-9]{1,18})$~D', $path, $m)) { lc_pf_detail_page($admin, (int)$m[1]); return true; }
    if ($method !== 'POST') return false;
    $back = '/admin/financeiro/caixa';
    try {
        if ($path === '/admin/financeiro/caixa/solicitar') {
            if (!lc_account_admin_password($admin)) throw new InvalidArgumentException('Confirme sua senha' . (lc_mfa_enabled((int)$admin['id']) ? ' e o código 2FA' : '') . '.');
            $amount = lc_cents('amount', -1);
            if ($amount < 0) throw new InvalidArgumentException('Informe o valor no formato 1.234,56.');
            $id = lc_pf_request($admin, $amount, lc_post('pix_key_type', 10), lc_post('pix_key', 120), lc_post('description', 120));
            try { lc_pf_send_link($id, (int)$admin['id']); lc_flash('Saque #' . $id . ' registrado. Link de autorização enviado ao WhatsApp do master.'); }
            catch (Throwable $e) { lc_flash($e->getMessage(), 'error'); }
            lc_redirect($back);
        }
        if (!preg_match('~^/admin/financeiro/caixa/([0-9]{1,18})/(reenviar|cancelar|enviar|sincronizar|conferir)$~D', $path, $m)) return false;
        $id = (int)$m[1]; $action = $m[2];
        if (in_array($action, ['enviar', 'conferir'], true)) $back = '/admin/financeiro/caixa/' . $id;
        $message = match ($action) {
            'reenviar' => (static function () use ($id, $admin): string { lc_pf_send_link($id, (int)$admin['id']); return 'Novo link enviado. Links anteriores deixaram de funcionar.'; })(),
            'cancelar' => lc_pf_cancel($id, $admin),
            'sincronizar' => lc_pf_sync($id, (int)$admin['id']),
            'enviar' => (static function () use ($id, $admin): string {
                if (!lc_account_admin_password($admin)) throw new InvalidArgumentException('Senha ou código 2FA incorretos.');
                return lc_pf_dispatch($id, $admin);
            })(),
            'conferir' => (static function () use ($id, $admin): string {
                if (!lc_account_admin_password($admin)) throw new InvalidArgumentException('Senha ou código 2FA incorretos.');
                return lc_pf_resolve_unknown($id, $admin, lc_post('action', 20), lc_post('transfer_id', 90));
            })(),
        };
        lc_flash($message);
    } catch (Throwable $e) {
        lc_flash($e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Falha na operação financeira.', 'error');
        if (!($e instanceof InvalidArgumentException || $e instanceof RuntimeException)) error_log('LEAGUECUT_PF_ERROR ' . get_class($e));
    }
    lc_redirect($back);
}
