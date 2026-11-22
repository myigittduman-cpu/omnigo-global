<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer response policy; source records and administrator views retain their lifecycle. */
final class CustomerSensitiveVisibility
{
    public static function flags(Database $db,array $user,int $company,?string $page=null):array
    {
        if($user['role']==='admin')return CustomerTableVisibility::ALL;
        if((int)($user['company_id']??0)!==$company)Http::fail(403,'Bu müşteriye erişemezsiniz.');
        return $page===null?CustomerTableVisibility::NONE
            :CustomerTableVisibility::fromAccess((new CustomerPermissions($db,$company))->access($user),$page);
    }
    public static function redact(array $value,array $flags):array
    {
        $afterCancellation=$flags['afterCancellationTurnover']??($flags['cancellations']&&$flags['returns']);
        if($flags['cancellations']&&$flags['returns']&&$afterCancellation)return $value;
        if(array_key_exists('grossCents',$value)||array_key_exists('turnoverCents',$value)){
            if(!$flags['cancellations']||!$flags['returns'])unset($value['netCents'],$value['averageCents']);
            if(!$afterCancellation&&($value['turnoverDisplayBasis']??null)!==CustomerTurnoverDisplay::BASIS){
                unset($value['panelSalesCents'],$value['salesCents']);
            }
        }
        foreach($value as $key=>$item){
            $sensitive=(!$flags['cancellations']&&in_array($key,['cancelCents','cancelledTurnoverCents','operationalCancelCents','cancelQty','cancelQuantity','cancelledQuantity','cancelledProducts','cancelled','cancellation','cancellationHistory','cancellationRequestHistory','cancellationNeedsReview','operationalCancellation','marketplaceCancellation'],true))
                ||(!$flags['returns']&&in_array($key,['returnCents','returnQty','returnQuantity','returnedQuantity','refundedQuantity','returnedOrders','returned','refundsVerified','returnAmountVerified','marketplaceReturn','returnRequests','returnItems'],true))
                ||(!$afterCancellation&&in_array($key,['salesTurnoverCents','salesTurnoverAverageCents','salesOrders'],true));
            if($sensitive){unset($value[$key]);continue;}
            if(is_string($item)&&in_array($key,['state','status','rowStatus','displayRowStatus','shippingState','deliveryStatus','fulfillmentStatus','statusLabel','marketplaceStatus','marketplaceStatusText','marketplaceStatusLabel','fulfillmentStatusText','packageStatus'],true)){
                $value[$key]=implode(' / ',array_filter(explode(' / ',$item),fn($label)=>!self::hiddenText($label,$flags)));
                continue;
            }
            if($key==='notes'&&is_string($item)&&self::hiddenText($item,$flags)){$value[$key]='';continue;}
            if($key==='notes'&&is_array($item))$item=array_values(array_filter($item,fn($note)=>!is_string($note)||!self::hiddenText($note,$flags)));
            if((!$flags['cancellations']||!$flags['returns'])&&$key==='products'&&is_array($item)&&array_is_list($item)){
                $item=array_values(array_filter($item,static function($product)use($flags):bool{
                    if(!is_array($product)||($product['turnoverDisplayBasis']??null)===CustomerTurnoverDisplay::BASIS)return true;
                    $qty=$product['quantity']??0;$gross=$product['grossCents']??0;
                    $cancelled=($qty>0&&($product['cancelledQuantity']??0)>=$qty)||($gross>0&&($product['cancelCents']??0)>=$gross);
                    $returned=($qty>0&&($product['returnedQuantity']??0)>=$qty)||($gross>0&&($product['returnCents']??0)>=$gross);
                    return ($flags['cancellations']||!$cancelled)&&($flags['returns']||!$returned);
                }));
            }
            if($key==='details'&&is_array($item)&&array_is_list($item))$item=array_values(array_filter($item,fn($row)=>!is_array($row)||!isset($row['section'],$row['label'])||!self::hiddenText((string)$row['label'],$flags)));
            if(is_array($item))$value[$key]=self::redact($item,$flags);
        }
        return $value;
    }
    private static function hiddenText(string $text,array $flags):bool
    {
        $text=strtr($text,['İ'=>'i','I'=>'i']);
        return (!$flags['cancellations']&&preg_match('/iptal|cancel/iu',$text)===1)||(!$flags['returns']&&preg_match('/iade|refund|return/iu',$text)===1);
    }
    public static function financial(Database $db,array $user,int $company,array $value,bool $salesAmounts=false,?string $page=null):array
    {
        $access=$salesAmounts&&$user['role']==='client'?(new CustomerPermissions($db,$company))->access($user):null;
        $flags=self::flags($db,$user,$company,$page);
        if($salesAmounts&&!$flags['cancellations']&&in_array($page,['analytics','overviewSales'],true)){
            $value['hideMarketplaceSalesCancellations']=true;
        }
        if($access!==null){
            $value=CustomerTurnoverDisplay::project($value,$flags['afterCancellationTurnover']);
            $value['turnoverDisplayBasis']=CustomerTurnoverDisplay::BASIS;
        }
        if($access!==null&&!CustomerTurnoverDisplay::canView($access)){
            $payments=$value['payments']??null;
            $value=CustomerTurnoverDisplay::withoutAmounts($value);
            if($payments!==null)$value['payments']=$payments;
            $flags['turnover']=false;
        }
        return [...self::redact($value,$flags),'sensitiveVisibility'=>$flags];
    }
}
