<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxReplyPolicy
{
    public static function productQuestions(string $provider): bool
    {
        return in_array($provider, ['hepsiburada','n11','idefix','trendyol'], true);
    }

    public static function supported(string $provider): bool
    {
        return self::productQuestions($provider) || in_array($provider, ['koctas','pazarama'], true);
    }

    public static function mayReply(string $provider, array $thread, ?int $now=null): bool
    {
        if (!self::supported($provider) || !empty($thread['partial']) || !empty($thread['archived']) || InboxStatus::value($thread)!=='waiting'
            || !InboxQuestionWindow::contains(InboxQuestionWindow::latestCustomerMessage($thread))) return false;
        if ($provider === 'koctas') return count($thread['recipients'] ?? []) === 1;
        if ($provider === 'trendyol') return ($thread['sourceStatus']??null)===TrendyolQuestionClient::WAITING;
        if ($provider !== 'hepsiburada') return true;

        $expiry = empty($thread['expiresAt']) ? false : strtotime($thread['expiresAt']);
        return $expiry !== false && $expiry > ($now ?? time());
    }
}
