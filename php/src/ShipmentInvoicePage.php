<?php
declare(strict_types=1);
namespace Omnigo;

/** Keeps an invoice group on one page while retaining the original editable shipment rows. */
final class ShipmentInvoicePage
{
    private array $groups=[];

    public function __construct(private int $page,private int $pageSize){}

    public function add(array $items,array $matches):void
    {
        foreach($items as $item){
            $key=self::groupKey($item);
            $this->groups[$key]??=['ids'=>[],'matched'=>false];
            $this->groups[$key]['ids'][]=$item['id'];
            if($matches[$item['id']])$this->groups[$key]['matched']=true;
        }
    }

    public function result():array
    {
        $matched=array_values(array_filter($this->groups,fn($group)=>$group['matched']));
        $total=count($matched);
        $page=min($this->page,max(0,(int)ceil($total/$this->pageSize)-1));
        return ['invoiceGroups'=>array_column(array_slice($matched,$page*$this->pageSize,$this->pageSize),'ids'),
            'total'=>$total,'page'=>$page,'pageSize'=>$this->pageSize];
    }

    private static function groupKey(array $item):string
    {
        $returned=(int)($item['marketplaceReturn']['returnedQuantity']??0);
        if(($item['invoiceOrderIdentity']??'')===''||!empty($item['marketplaceCancellation']['cancelledQuantity'])
            ||($returned>0&&(!empty($item['marketplaceReturn']['partial'])||$returned!==(int)($item['quantity']??0))))return 'row:'.$item['id'];
        $fields=['provider','invoiceOrderIdentity','orderedAt','customerName','customerNameSource','invoiceName','invoiceAddress',
            'recipientEmail','phone','customerType','taxOffice','taxNumber','rowStatus','displayRowStatus','marketplaceDelivered','cancelled'];
        $values=array_map(fn($field)=>$item[$field]??null,$fields);
        $values[]=$returned>0;
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
}
