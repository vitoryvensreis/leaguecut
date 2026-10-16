<?php
declare(strict_types=1);

/**
 * Credits are granted only when the weekly minimum is reached by APPROVED clips.
 * Use within an existing transaction. Idempotent unique dedupe keys prevent double payment.
 */
function lc_settle_week(int $campaignId, int $userId, string $submittedAt): void {
    $campaign = lc_one('SELECT * FROM campaigns WHERE id=?',[$campaignId]);
    if (!$campaign) return;
    $begin = (new DateTimeImmutable($submittedAt))->modify('monday this week')->format('Y-m-d 00:00:00');
    $end = (new DateTimeImmutable($begin))->modify('+7 days')->format('Y-m-d H:i:s');
    $clips = lc_all("SELECT id FROM clips WHERE campaign_id=? AND user_id=? AND status='approved' AND submitted_at>=? AND submitted_at<? ORDER BY id",[$campaignId,$userId,$begin,$end]);
    if (count($clips) < (int)$campaign['min_videos_week']) return;
    foreach ($clips as $clip) {
        lc_query("INSERT IGNORE INTO ledger_entries(user_id,campaign_id,clip_id,kind,amount_cents,dedupe_key,description)
                  VALUES(?,?,?,'clip',?,?,'Valor por vídeo após meta semanal')",
                  [$userId,$campaignId,$clip['id'],$campaign['payment_per_video_cents'],'clip:' . $clip['id']]);
        if(lc_db()->query('SELECT ROW_COUNT()')->fetchColumn()==1){
            lc_referral_offer($userId,'clip_credit',(int)$clip['id'],(int)$campaign['payment_per_video_cents']);
        }
    }
}
function lc_credit_views(int $clipId): void {
    $clip = lc_one('SELECT c.*,p.milestone_views,p.milestone_bonus_cents FROM clips c JOIN campaigns p ON p.id=c.campaign_id WHERE c.id=?',[$clipId]);
    if (!$clip || $clip['status'] !== 'approved') return;
    if ((int)$clip['milestone_bonus_cents'] <= 0 || (int)$clip['milestone_views'] <= 0) return;
    if ((int)$clip['verified_views'] < (int)$clip['milestone_views']) return;
    lc_query("INSERT IGNORE INTO ledger_entries(user_id,campaign_id,clip_id,kind,amount_cents,dedupe_key,description)
              VALUES(?,?,?,'views_bonus',?,?,'Bônus de visualizações conferidas')",
              [$clip['user_id'],$clip['campaign_id'],$clipId,$clip['milestone_bonus_cents'],'views:' . $clipId]);
}
function lc_credit_monthly_leader(int $campaignId, string $month): ?array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || $month >= date('Y-m')) throw new RuntimeException('Só é possível fechar meses já encerrados.');
    $campaign = lc_one('SELECT monthly_leader_bonus_cents FROM campaigns WHERE id=?',[$campaignId]);
    if (!$campaign) throw new RuntimeException('Campanha não encontrada.');
    if ((int)$campaign['monthly_leader_bonus_cents'] <= 0) throw new RuntimeException('Essa campanha não oferece bônus mensal.');
    $first = $month . '-01 00:00:00';
    $last = (new DateTimeImmutable($first))->modify('+1 month')->format('Y-m-d H:i:s');
    $winner = lc_one("SELECT user_id, SUM(verified_views) AS views FROM clips
                     WHERE campaign_id=? AND status='approved' AND submitted_at>=? AND submitted_at<?
                     GROUP BY user_id ORDER BY views DESC,user_id ASC LIMIT 1",[$campaignId,$first,$last]);
    if (!$winner || (int)$winner['views'] <= 0) return null;
    lc_query("INSERT IGNORE INTO ledger_entries(user_id,campaign_id,kind,amount_cents,dedupe_key,description)
              VALUES(?,?,'monthly_leader',?,?,?)",
              [$winner['user_id'],$campaignId,$campaign['monthly_leader_bonus_cents'],"leader:$campaignId:$month",'Maior número de views em ' . $month]);
    return $winner;
}
