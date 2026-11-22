<?php
declare(strict_types=1);

// Run in its own PHP process: the schema receives a test double, never a live PDO connection.
namespace Omnigo {
    final class Database
    {
        public array $reads=[];
        public array $writes=[];
        public function __construct(public string $kindType,private bool $isMysql=true){}
        public function mysql():bool{return $this->isMysql;}
        public function one(string $sql):?array
        {
            $this->reads[]=$sql;
            if($sql!=="SHOW COLUMNS FROM records LIKE 'kind'")throw new \RuntimeException('Unexpected schema query.');
            return ['Type'=>$this->kindType];
        }
        public function run(string $sql):void
        {
            $this->writes[]=$sql;
            if(!preg_match('/^ALTER TABLE records MODIFY kind (ENUM\(.+\)) NOT NULL$/D',$sql,$matches))throw new \RuntimeException('Unexpected schema write.');
            // MySQL SHOW COLUMNS normalizes the ENUM keyword to lowercase.
            $this->kindType='enum'.substr($matches[1],4);
        }
    }
    require __DIR__.'/../src/MarketplaceConnectionSchema.php';
}

namespace {
    use Omnigo\{Database,MarketplaceConnectionSchema};
    function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
    $existing=['channels','sales','payments','tasks','reports','integration_koctas','sync_koctas','order_koctas','integration_n11','sync_n11','order_n11','stage_n11','integration_hepsiburada','sync_hepsiburada','integration_pttavm','sync_pttavm','integration_idefix','sync_idefix','order_idefix','stage_idefix','integration_health','shipment_auto_since','future_kind_v99'];
    $enum=static fn(array $values):string=>"enum('".implode("','",$values)."')";
    $required=['integration_trendyol','sync_trendyol'];
    $db=new Database($enum($existing));
    MarketplaceConnectionSchema::install($db);
    $expected=[...$existing,...$required];
    check($db->kindType===$enum($expected),'Yeni Trendyol değerleri yalnız sona eklenmeli; mevcut tüm değerler ve sıra korunmalı.');
    check($db->writes===['ALTER TABLE records MODIFY kind ENUM(\''.implode("','",$expected).'\') NOT NULL'],'DDL mevcut enum değerlerini birebir korumalı.');
    check(count($db->reads)===1,'Şema tek metadata okumasıyla hazırlanmalı.');
    MarketplaceConnectionSchema::install($db);
    check(count($db->writes)===1&&$db->kindType===$enum($expected),'İkinci çalıştırma idempotent olmalı; yeni ALTER çalışmamalı.');

    $ready=new Database($enum($expected));MarketplaceConnectionSchema::install($ready);
    check(!$ready->writes&&$ready->kindType===$enum($expected),'Şema zaten hazırsa DDL gönderilmemeli.');
    $sqlite=new Database('TEXT',false);MarketplaceConnectionSchema::install($sqlite);
    check(!$sqlite->reads&&!$sqlite->writes,'SQLite yolu metadata veya DDL çalıştırmamalı.');

    foreach(['',"varchar(255)","enum('sales'); DROP TABLE records; --","enum('sales','')","enum('sales','unsafe-value')"] as $type){
        $invalid=new Database($type);$failed=false;
        try{MarketplaceConnectionSchema::install($invalid);}
        catch(RuntimeException $error){$failed=$error->getMessage()==='Kayıt şeması doğrulanamadı.';}
        check($failed,'Geçersiz metadata güvenli biçimde reddedilmeli.');
        check(!$invalid->writes&&$invalid->kindType===$type,'Geçersiz şemada hiçbir DDL veya veri değişikliği yapılmamalı.');
    }
    echo "Pazaryeri şeması: Trendyol ikilisi sona ekleme, tüm mevcut değer/sıra koruması, idempotence, hazır şema DDL koruması, SQLite no-op ve geçersiz metadata kontrolleri geçti.\n";
}
