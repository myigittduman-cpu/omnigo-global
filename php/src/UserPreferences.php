<?php
declare(strict_types=1);
namespace Omnigo;

/** Cosmetic profiles belong to the authenticated account and named operator. */
final class UserPreferences
{
    public function __construct(private Database $db){}
    public static function defaults(array $user=[]): array
    {
        return ['theme'=>WorkspaceAppearance::defaultTheme($user),'fontSize'=>'standard','fontFamily'=>'system','density'=>'comfortable','reducedMotion'=>false,'language'=>'tr','shipmentLayouts'=>[]];
    }
    private function profile(array $user): string
    {
        ClientSessionOperator::requireName($user);
        $name=$user['role']==='client'?mb_strtolower(ClientSessionOperator::name($user['operatorName'])):'admin';
        return hash('sha256',$user['role'].':'.$name);
    }
    public function read(array $user): array
    {
        $row=$this->db->one('SELECT payload,version FROM user_preferences WHERE user_id=? AND profile_key=?',[$user['id'],$this->profile($user)]);
        return ['preferences'=>WorkspaceAppearance::normalize(array_replace(self::defaults($user),$row?json_decode($row['payload'],true,8,JSON_THROW_ON_ERROR):[]),$user),'version'=>(int)($row['version']??0)];
    }
    public function save(array $user,array $data): array
    {
        $profile=$this->profile($user);$values=$data['preferences']??null;
        if($values instanceof \stdClass)$values=(array)$values;
        // Preserve newer preferences when an older installed client saves.
        $required=array_diff(array_keys(self::defaults()),['language','shipmentLayouts']);
        if(!is_array($values)||array_diff(array_keys($values),array_keys(self::defaults()))||array_diff($required,array_keys($values))||!is_int($data['version']??null)||$data['version']<0)Http::fail(400,'Görünüm ayarlarını kontrol edin.');
        // Older clients may still send the retired theme IDs; normalization uses the account-safe default.
        $themeChoices=[...WorkspaceAppearance::THEMES,'petrol','graphite'];
        foreach(['theme'=>$themeChoices,'fontSize'=>['standard','large','larger'],'fontFamily'=>['system','arial'],'density'=>['comfortable','compact']] as $field=>$choices)Validation::choice($values[$field]??null,$choices);
        if(!is_bool($values['reducedMotion']??null))Http::fail(400,'Hareket tercihini kontrol edin.');
        if(array_key_exists('language',$values))Validation::choice($values['language'],['tr','en','de']);
        if(array_key_exists('shipmentLayouts',$values))$values['shipmentLayouts']=self::shipmentLayouts($user,$values['shipmentLayouts']);
        return $this->db->transaction(function()use($user,$data,$values,$profile){
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            $current=$this->read($user);
            if($current['version']!==$data['version'])Http::fail(409,'Ayarlar başka bir cihazda değişti. Pencereyi yeniden açın.');
            $values['language']=$values['language']??$current['preferences']['language'];
            $values['shipmentLayouts']=$values['shipmentLayouts']??$current['preferences']['shipmentLayouts'];
            $values=WorkspaceAppearance::normalize($values,$user);
            $version=$current['version']+1;$payload=json_encode($values,JSON_THROW_ON_ERROR);
            if($current['version'])$this->db->run('UPDATE user_preferences SET payload=?,version=?,updated=? WHERE user_id=? AND profile_key=?',[$payload,$version,time(),$user['id'],$profile]);
            else $this->db->run('INSERT INTO user_preferences(user_id,profile_key,payload,version,updated) VALUES(?,?,?,?,?)',[$user['id'],$profile,$payload,$version,time()]);
            return ['preferences'=>$values,'version'=>$version];
        });
    }
    private static function shipmentLayouts(array $user,mixed $value): array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value)||count($value)>500)Http::fail(400,'Tablo düzenlerini kontrol edin.');
        foreach($value as $company=>$layout){
            if(!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$company)||(int)$company>2147483647)Http::fail(400,'Geçersiz mağaza seçimi.');
            if($user['role']!=='admin'&&(int)$company!==(int)($user['company_id']??$user['companyId']??0))Http::fail(403,'Bu mağaza için ayar kaydedemezsiniz.');
            Validation::choice($layout,['invoice','grouped']);
        }
        return $value;
    }
}
