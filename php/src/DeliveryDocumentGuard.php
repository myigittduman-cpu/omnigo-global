<?php
declare(strict_types=1);
namespace Omnigo;

/** Legacy API compatibility. Source status and missing-data checks are applied by selection. */
final class DeliveryDocumentGuard
{
    public const MESSAGE='Siparişin gerçek durumu doğrulanamadı.';
    public static function reason(array $order): ?string{return null;}
    public static function assertVerified(array $order): void{}
}
