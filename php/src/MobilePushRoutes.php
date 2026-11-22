<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushRoutes
{
    public function __construct(private Database $db) {}
    public function handle(string $path,string $method,array $user): void
    {
        if (!str_starts_with($path,'/api/mobile-push/')) return;
        $devices=PushRoutes::devices($this->db);
        if ($path==='/api/mobile-push/config' && $method==='GET') {
            $config=MobilePushConfig::read();$status=['android'=>MobilePushConfig::status('android',$config),'ios'=>MobilePushConfig::status('ios',$config)];
            Http::json(['android'=>$status['android']['ready'],'ios'=>$status['ios']['ready'],'status'=>$status]);
        }
        if ($path==='/api/mobile-push/register' && $method==='POST') {
            $data=MobilePushRegistration::validate(Http::body(),$user);
            if (!MobilePushConfig::ready($data['kind'])) Http::fail(503,'Mobil bildirim hizmetinin mağaza bağlantısı henüz etkinleştirilmedi.');
            Http::json((new MobilePushDevices($this->db,new DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->save($user,$data));
        }
        if (preg_match('#^/api/mobile-push/devices/(\d+)(/test)?$#D',$path,$match)) {
            $id=(int)$match[1];$device=$devices->current($user,$id);
            if (!in_array($device['kind'],['ios','android'],true)) Http::fail(404,'Mobil cihaz bulunamadı.');
            if ($method==='GET') Http::json(['enabled'=>$devices->usable($device),'sound'=>(bool)$device['sound']]);
            if ($method==='POST' && isset($match[2])) {$devices->test($user,$id);Http::json(['queued'=>true]);}
            if ($method==='POST') Http::json($devices->change($user,$id,Http::body()));
        }
        Http::fail(405,'Mobil bildirim işlemi desteklenmiyor.');
    }
}
