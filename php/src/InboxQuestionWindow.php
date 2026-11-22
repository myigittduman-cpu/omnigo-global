<?php
declare(strict_types=1);
namespace Omnigo;

/** The first day of the requested question period, midnight in Istanbul. */
final class InboxQuestionWindow
{
    public const START = '2026-10-31T21:00:00+00:00';

    public static function latestCustomerMessage(array $thread): ?string
    {
        $messages = array_filter($thread['messages'] ?? [],
            static fn(array $message): bool => $message['sender'] === 'CUSTOMER_USER');
        return $messages ? max(array_column($messages, 'created')) : ($thread['latestCustomerAt'] ?? null);
    }

    public static function contains(?string $created): bool
    {
        $timestamp = $created === null ? false : strtotime($created);
        return $timestamp !== false && $timestamp >= strtotime(self::START);
    }

    public static function hasCurrentMessageSql(): string
    {
        return "EXISTS(SELECT 1 FROM inbox_messages current_message WHERE current_message.thread_id=t.id
            AND current_message.inbound=1 AND current_message.created>='".self::START."')";
    }

    public static function activeMessageSql(Database $db, string $alias = 'm'): string
    {
        $value = static fn(string $field): string => $db->mysql()
            ? "JSON_UNQUOTE(JSON_EXTRACT(t.payload,'$.$field'))"
            : "JSON_EXTRACT(t.payload,'$.$field')";
        $partial = $value('partial');
        $archived = $value('archived');
        $expiry = $value('expiresAt');
        $recipients = ($db->mysql() ? 'JSON_LENGTH' : 'JSON_ARRAY_LENGTH')."(JSON_EXTRACT(t.payload,'$.recipients'))";
        $start = self::START;
        $now = gmdate('c');
        return "$alias.created>='$start' AND COALESCE(CAST($partial AS CHAR),'false') NOT IN ('true','1')
            AND COALESCE(CAST($archived AS CHAR),'false') NOT IN ('true','1')
            AND (t.provider<>'koctas' OR $recipients=1)
            AND (t.provider<>'hepsiburada' OR $expiry>'$now')";
    }
}
