<?php
declare(strict_types=1);
namespace Omnigo;

/** Acknowledgements are per reviewer and per rejected reply, without deleting the conversation. */
final class InboxRejections
{
    private const ACTION = 'Soru reddi incelendi';

    public function __construct(private Database $db) {}

    public static function reviewable(): string
    {
        return '(' . InboxStatus::displayed() . ")='rejected' AND (reply.id IS NOT NULL OR t.provider='trendyol')";
    }

    public function unreviewed(string $reviewer): string
    {
        $value = "JSON_EXTRACT(a.after_value,'$.reply')";
        if ($this->db->mysql()) $value = "JSON_UNQUOTE($value)";
        return "NOT EXISTS(SELECT 1 FROM audit a WHERE a.company_id=t.company_id AND a.record_id=t.id
            AND a.user_id=$reviewer AND a.action='".self::ACTION."' AND $value=COALESCE(reply.id,'source'))";
    }

    public function count(int $user, ?int $company, ?string $provider): int
    {
        $scope = $company === null ? '' : ' AND t.company_id=?';
        $filter = $provider === null ? '' : ' AND t.provider=?';
        $row = $this->db->one('SELECT COUNT(*) AS n FROM inbox_threads t'.InboxStatus::replyJoin().' WHERE '
            .self::reviewable().' AND '.$this->unreviewed('?').$scope.$filter,
            [$user,...($company===null?[]:[$company]),...($provider===null?[]:[$provider])]);
        return (int)$row['n'];
    }

    public function review(int $user, int $company, int $thread): void
    {
        $this->db->transaction(function()use($user,$company,$thread){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one('SELECT reply.id AS reply_id FROM inbox_threads t'.InboxStatus::replyJoin()
                .' WHERE t.id=? AND t.company_id=? AND '.self::reviewable(),[$thread,$company]);
            if(!$row)Http::fail(409,'İncelenecek ret kaydı bulunamadı.');
            $reply=$row['reply_id']??'source';
            $values=json_encode(['reply'=>$reply],JSON_THROW_ON_ERROR);
            if($this->db->one('SELECT id FROM audit WHERE company_id=? AND user_id=? AND record_id=? AND action=?
                AND '.($this->db->mysql()?'JSON_UNQUOTE(JSON_EXTRACT(after_value,\'$.reply\'))':'JSON_EXTRACT(after_value,\'$.reply\')').'=?',
                [$company,$user,$thread,self::ACTION,$reply]))return;
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',
                [$user,$company,self::ACTION,$thread,$values,gmdate('c')]);
        });
    }
}
