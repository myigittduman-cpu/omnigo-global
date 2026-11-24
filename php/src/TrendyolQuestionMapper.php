<?php
declare(strict_types=1);
namespace Omnigo;

final class TrendyolQuestionMapper
{
    public static function validId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }

    public static function thread(array $raw, string $seller): array
    {
        $id = (string)($raw['id'] ?? '');
        $sourceStatus = $raw['status'] ?? null;
        if (!self::validId($id)) throw new SyncFailure(422);
        foreach (['supplierId', 'sellerId'] as $key) {
            if (isset($raw[$key]) && (string)$raw[$key] !== $seller) throw new SyncFailure(422);
        }

        $status = match ($sourceStatus) {
            TrendyolQuestionClient::WAITING => 0,
            TrendyolQuestionClient::ANSWERED => 1,
            TrendyolQuestionClient::APPROVAL_PENDING => 2,
            'REPORTED', 'REJECTED', 'UNANSWERED' => 4,
            default => throw new SyncFailure(422),
        };
        $created = self::date($raw['creationDate'] ?? null);
        $body = $raw['text'] ?? null;
        if (!is_string($body)) throw new SyncFailure(422);
        $messages = [MarketplaceQuestionMapper::message($id.':question', $body, $created)];
        $answer = $raw['answer'] ?? null;
        if ($status === 1) {
            if (!is_array($answer) || !self::validId((string)($answer['id'] ?? '')) || !is_string($answer['text'] ?? null)) {
                throw new SyncFailure(422);
            }
            $body = !empty($answer['hasPrivateInfo']) ? 'Özel bilgi içeren yanıtı Trendyol Satıcı Panelinden görüntüleyin.' : $answer['text'];
            $messages[] = MarketplaceQuestionMapper::message($id.':answer:'.$answer['id'], $body, self::date($answer['creationDate'] ?? null), true);
        }

        $title = DeliveryMapper::text($raw['productName'] ?? '', 500);
        $updated = max(array_column($messages, 'created'));
        foreach (['lastModifiedDate', 'rejectedDate', 'reportedDate'] as $key) {
            if (!empty($raw[$key])) $updated = max($updated, self::date($raw[$key]));
        }
        $rejection = null;
        $rejectedAnswer = $raw['rejectedAnswer'] ?? null;
        $rejectedAt = $rejectedAnswer['creationDate'] ?? null;
        $rejectedText = $rejectedAnswer['text'] ?? null;
        if ($status === 0 && is_int($rejectedAt) && $rejectedAt > 0 && $rejectedAt <= 4102444800000
            && is_string($rejectedText) && trim($rejectedText) !== '' && empty($rejectedAnswer['hasPrivateInfo'])) {
            $rejection = ['createdAt' => $rejectedAt, 'bodyHash' => hash('sha256', trim($rejectedText))];
            $updated = max($updated, self::date($rejectedAt));
        }
        $identifiers = InboxQuestionFilters::metadata(productCode:$raw['productMainId'] ?? null);
        return MarketplaceQuestionMapper::thread($id, $title === '' ? 'Trendyol sorusu' : $title, $messages, $status, [
            'updated' => $updated,
            'sourceStatus' => $sourceStatus,
            ...($rejection ? ['rejectedReplyEvidence' => $rejection] : []),
            ...($identifiers ? ['questionIdentifiers'=>$identifiers] : []),
        ]);
    }

    private static function date(mixed $milliseconds): string
    {
        if (!is_int($milliseconds) || $milliseconds < 1 || $milliseconds > 4102444800000) throw new SyncFailure(422);
        return gmdate('c', intdiv($milliseconds, 1000));
    }
}
