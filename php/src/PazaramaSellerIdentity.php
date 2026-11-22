<?php
declare(strict_types=1);
namespace Omnigo;

final class PazaramaSellerIdentity
{
    /** Only accepts a token obtained directly from the TLS-verified token endpoint. */
    public static function verify(string $accessToken,string $sellerId): void
    {
        $parts=explode('.',$accessToken);
        if(count($parts)!==3)throw new SyncFailure(422);
        $decoded=base64_decode(strtr($parts[1],'-_','+/'),true);
        $claims=$decoded===false?null:json_decode($decoded,true,16);
        if(!is_string($claims['client_seller_id']??null)||strcasecmp($claims['client_seller_id'],$sellerId)!==0)throw new SyncFailure(403);
    }
}
