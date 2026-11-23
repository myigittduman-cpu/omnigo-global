<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxStatus
{
    public const FILTERS=['all','waiting','answered','approval_pending','rejected','published'];
    public static function value(array $thread): string
    {
        if(($thread['questionStatus']??null)===0&&($thread['localReplyState']??null)==='sent')return 'answered';
        if(isset($thread['questionStatus']))return [0=>'waiting',1=>'answered',2=>'approval_pending',3=>'rejected',4=>'closed',5=>'unavailable'][(int)$thread['questionStatus']]??'waiting';
        return !empty($thread['replyNeeded'])?'waiting':'answered';
    }
    /** Both SQLite and MySQL support these JSON scalar comparisons. */
    public static function sql(): string
    {
        return "CASE WHEN t.provider IN ('pazarama','n11','idefix','hepsiburada','trendyol') THEN CASE CAST(JSON_EXTRACT(t.payload,'$.questionStatus') AS CHAR) WHEN '1' THEN 'answered' WHEN '2' THEN 'approval_pending' WHEN '3' THEN 'rejected' WHEN '4' THEN 'closed' WHEN '5' THEN 'unavailable' ELSE CASE WHEN JSON_EXTRACT(t.payload,'$.localReplyState')='sent' OR JSON_EXTRACT(t.payload,'$.localReplyState')='\"sent\"' THEN 'answered' ELSE 'waiting' END END ELSE CASE WHEN CAST(JSON_EXTRACT(t.payload,'$.replyNeeded') AS CHAR) IN ('true','1') THEN 'waiting' ELSE 'answered' END END";
    }
    public static function visible(): string {return '('.self::sql().")<>'rejected'";}
    public static function waiting(): string {return '('.self::sql().")='waiting'";}

    /** Apply delivery evidence only to the current inbound question. */
    public static function replyJoin(): string
    {
        return " LEFT JOIN inbox_jobs reply ON reply.id=(
            SELECT j.id FROM inbox_jobs j
            WHERE j.thread_id=t.id AND j.company_id=t.company_id AND j.kind='reply'
              AND j.created>=COALESCE((SELECT MAX(q.created) FROM inbox_messages q WHERE q.thread_id=t.id AND q.inbound=1),'')
            ORDER BY j.created DESC,j.id DESC LIMIT 1
        )";
    }

    public static function pendingReply(): string
    {
        return "EXISTS(SELECT 1 FROM inbox_jobs k WHERE k.thread_id=t.id AND k.company_id=t.company_id
            AND k.kind='reply' AND k.state IN ('pending','running','verifying','unknown'))";
    }

    public static function displayed(): string
    {
        $source='('.self::sql().')';
        return "CASE WHEN t.provider='trendyol' AND CAST(JSON_EXTRACT(t.payload,'$.sourceStatus') AS CHAR) IN ('REJECTED','\"REJECTED\"') THEN 'rejected'
            WHEN reply.id IS NULL THEN $source
            WHEN $source<>'waiting' THEN $source
            WHEN reply.state IN ('sent','confirmed_sent') THEN 'answered'
            WHEN reply.state IN ('pending','running','verifying','unknown','accepted') THEN 'approval_pending'
            WHEN reply.state IN ('failed','cancelled') THEN 'rejected'
            ELSE $source END";
    }
}
