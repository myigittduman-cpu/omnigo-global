<?php
declare(strict_types=1);
namespace Omnigo;

/** Last complete claim scan. A failed display projection cannot interrupt order imports. */
final class ReturnRequestSnapshot
{
    public static function publish(array &$state,array $items,string $from,string $through): void
    {
        try{
            if(count($items)>20000)throw new \RuntimeException('capacity');
            $seen=[];
            foreach($items as $item){
                if(!is_array($item)||empty($item['id'])||empty($item['orderNumber'])||empty($item['status']))throw new \RuntimeException('shape');
                $key=(string)$item['id'];
                if(isset($seen[$key])&&$seen[$key]!==$item)throw new \RuntimeException('conflict');
                $seen[$key]=$item;
            }
            $state['returnRequests']=['items'=>array_values($seen),'from'=>$from,'through'=>$through,'checkedAt'=>gmdate('c')];
            unset($state['returnRequestError']);
        }catch(\Throwable){$state['returnRequestError']=['at'=>gmdate('c'),'message'=>'İade listesi doğrulanamadı; son doğrulanan kayıtlar korunuyor.'];}
    }
}
