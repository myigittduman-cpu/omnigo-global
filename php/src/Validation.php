<?php
declare(strict_types=1);
namespace Omnigo;

final class Validation
{
    public const KINDS = ['channels', 'sales', 'payments', 'tasks', 'reports'];
    public static function text(mixed $value, string $label, int $max = 250, bool $optional = false): string
    {
        if ($optional && ($value === null || $value === '')) return '';
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) Http::fail(400, "$label alanını kontrol edin.");
        return trim($value);
    }
    public static function choice(mixed $value, array $choices): string
    {
        if (!in_array($value, $choices, true)) Http::fail(400, 'Seçim geçersiz.');
        return $value;
    }
    public static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) Http::fail(400, 'Tarih geçersiz.');
        [$year,$month,$day] = array_map('intval', explode('-', $value));
        if (!checkdate($month,$day,$year)) Http::fail(400, 'Tarih geçersiz.');
        return $value;
    }
    public static function month(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value)) Http::fail(400, 'Dönem seçin.');
        return $value;
    }
    public static function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 100000000000) Http::fail(400, 'Tutar veya adet geçersiz.');
        return $value;
    }
    public static function account(array $data,bool $storeUser=false): array
    {
        $email = mb_strtolower(self::text($data['email'] ?? null, 'E-posta', 200));
        $name = self::text($data['name'] ?? null, 'Ad', 100);
        $password = $data['password'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::fail(400, 'Geçerli bir e-posta yazın.');
        if ($storeUser) $password=StoreAccountCredentials::validate($password);
        elseif (!is_string($password) || mb_strlen($password) < 12 || strlen($password) > 128 || str_contains($password, "\0")) Http::fail(400, 'Şifre 12–128 karakter aralığında olmalı.');
        return compact('email','name','password');
    }
    public static function record(string $kind, array $p): array
    {
        $labels = ['name'=>'Pazaryeri','storeName'=>'Mağaza adı','channel'=>'Pazaryeri','source'=>'Bilgi kaynağı','title'=>'Başlık','description'=>'Açıklama','owner'=>'Sorumlu','content'=>'Rapor içeriği','confirmation'=>'Teyit kaynağı'];
        $text = fn($key, $max = 250, $optional = false) => self::text($p[$key] ?? null, $labels[$key] ?? 'Metin', $max, $optional);
        $choice = fn($key, $values) => self::choice($p[$key] ?? null, $values);
        $int = fn($key) => self::integer($p[$key] ?? null);
        if ($kind === 'channels') return ['name'=>$text('name',60),'status'=>$choice('status',['active','inactive']),'storeName'=>$text('storeName',150,true),'connection'=>'not_connected'];
        if ($kind === 'sales') return ['channel'=>$text('channel',60),'month'=>self::month($p['month']??null),'grossCents'=>$int('grossCents'),'cancelCents'=>$int('cancelCents'),'returnCents'=>$int('returnCents'),'orders'=>$int('orders'),'source'=>$text('source')];
        if ($kind === 'payments') {
            $result = ['channel'=>$text('channel',60),'expectedDate'=>self::date($p['expectedDate']??null),'expectedCents'=>$int('expectedCents'),'status'=>$choice('status',['pending','sent','paid']),'source'=>$text('source'),'actualCents'=>null,'paidDate'=>'','confirmation'=>''];
            $result['verification']=self::choice($p['verification']??'estimated',['estimated','confirmed']);
            $result['verificationSource']=$result['verification']==='confirmed'?self::text($p['verificationSource']??null,'Pazaryeri teyit kaynağı',250):'';
            if ($result['status'] === 'paid') {
                $result['actualCents'] = $int('actualCents');
                $result['paidDate'] = self::date($p['paidDate']??null);
                if ($result['paidDate'] > (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d')) Http::fail(400, 'Hesaba geçiş tarihi gelecekte olamaz.');
                $result['confirmation'] = $text('confirmation',250,true);
            }
            return [...$result,...PaymentDetails::validate($p)];
        }
        if ($kind === 'tasks') return ['title'=>$text('title',150),'description'=>$text('description',5000),'channel'=>$text('channel',60),'date'=>self::date($p['date']??null),'status'=>$choice('status',['planned','running','done']),'visibility'=>$choice('visibility',['internal','client']),'owner'=>$text('owner',100)];
        if ($kind === 'reports') return ['title'=>$text('title',150),'month'=>self::month($p['month']??null),'content'=>$text('content',30000),'status'=>$choice('status',['draft','published'])];
        Http::fail(400, 'Kayıt türü geçersiz.');
    }
}
