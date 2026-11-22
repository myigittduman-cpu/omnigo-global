<?php
declare(strict_types=1);
namespace Omnigo;

final class ChatDeletion
{
    private const OWN_MESSAGES = 'thread_id=? AND sender_id=?';

    public function __construct(private Database $db) {}

    public function availableMessages(array $user, int $thread): array
    {
        Security::admin($user);
        (new ChatAccess($this->db))->thread($user, $thread);
        $row = $this->db->one('SELECT COUNT(*) total,MAX(id) through_id FROM chat_messages WHERE '
            .self::OWN_MESSAGES.' AND deleted_at IS NULL', [$thread, $user['id']]);
        return ['total'=>(int)$row['total'], 'through'=>(int)$row['through_id']];
    }

    public function messages(array $user, int $thread, array $selection): int
    {
        Security::admin($user);
        $all = array_key_exists('through', $selection);
        $through = $all ? self::id($selection['through']) : null;
        $ids = $all ? ($selection['except'] ?? []) : ($selection['ids'] ?? null);
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000 || (!$all && !$ids)) {
            Http::fail(400, 'Silmek istediğiniz mesajları yeniden seçin.');
        }
        $ids = array_values(array_unique(array_map(self::id(...), $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return TransactionRetry::run($this->db, function() use ($user,$thread,$through,$ids,$placeholders) {
            (new ChatAccess($this->db))->thread($user, $thread, true);
            $params = [$thread, $user['id']];
            if ($through === null) {
                $filter = " AND id IN ($placeholders)";
                $params = [...$params, ...$ids];
                $row = $this->db->one('SELECT COUNT(*) total FROM chat_messages WHERE '.self::OWN_MESSAGES.$filter, $params);
                if ((int)$row['total'] !== count($ids)) Http::fail(404, 'Seçili mesajlardan biri bulunamadı.');
            } else {
                $filter = ' AND id<=?';
                $params[] = $through;
                if ($ids) {
                    $filter .= " AND id NOT IN ($placeholders)";
                    $params = [...$params, ...$ids];
                }
            }
            return $this->db->run("UPDATE chat_messages SET body='',deleted_at=? WHERE "
                .self::OWN_MESSAGES.$filter.' AND deleted_at IS NULL', [time(), ...$params])->rowCount();
        });
    }

    public function threads(array $user, mixed $selection): int
    {
        Security::admin($user);
        if (!is_array($selection) || !array_is_list($selection) || !$selection || count($selection) > 100) {
            Http::fail(400, 'Kaldırmak istediğiniz konuşmaları yeniden seçin.');
        }
        $threads = [];
        foreach ($selection as $item) {
            $entry = (array)$item;
            $thread = self::id($entry['id'] ?? null);
            $threads[$thread] = self::id($entry['through'] ?? null, true);
        }
        ksort($threads, SORT_NUMERIC);
        $access = new ChatAccess($this->db);
        $upsert = $this->db->mysql()
            ? ' ON DUPLICATE KEY UPDATE through_id=GREATEST(through_id,VALUES(through_id))'
            : ' ON CONFLICT(thread_id,user_id) DO UPDATE SET through_id=MAX(through_id,excluded.through_id)';
        return TransactionRetry::run($this->db, function() use ($user,$threads,$access,$upsert) {
            foreach ($threads as $thread => $through) {
                $access->thread($user, $thread, true);
                if ($through && !$this->db->one('SELECT id FROM chat_messages WHERE thread_id=? AND id=?', [$thread, $through])) {
                    Http::fail(409, 'Konuşma değişti. Listeyi yenileyip tekrar seçin.');
                }
                $this->db->run('INSERT INTO chat_thread_dismissals(thread_id,user_id,through_id) VALUES(?,?,?)'.$upsert,
                    [$thread, $user['id'], $through]);
                $this->db->run('UPDATE chat_members SET read_id=? WHERE thread_id=? AND user_id=? AND read_id<?',
                    [$through, $thread, $user['id'], $through]);
            }
            return count($threads);
        });
    }

    private static function id(mixed $value, bool $allowZero = false): int
    {
        if (!is_int($value) || $value < ($allowZero ? 0 : 1) || $value > 999999999999999) {
            Http::fail(400, 'Sohbet seçimi geçersiz.');
        }
        return $value;
    }
}
