<?php
declare(strict_types=1);
namespace Omnigo;

/** A missing detail is not a deleted order, an empty coupon, or a failed stock import. */
final class PttavmDetailRetry
{
 public static function defer(array $order,\Throwable $error,int $now):array{
  $status=$error instanceof SyncFailure?$error->httpStatus:503;
  $attempt=min(10,1+(int)($order['pricingDetailAttempts']??0));
  $delay=max($status===404?900:120,min(86400,60*(2**$attempt)),$error instanceof SyncFailure?$error->retryAfter:0);
  $order['pricingDetailAttempts']=$attempt;$order['pricingDetailRetryAt']=$now+$delay;
  $order['pricingDetailError']=['httpStatus'=>$status,'reason'=>match(true){$status===404=>'not_found',$status===429=>'rate_limited',in_array($status,[401,403],true)=>'authorization',$status===422=>'inconsistent',default=>'unavailable'},'checkedAt'=>gmdate('c',$now)];
  return $order;
 }
}
