<?php
declare(strict_types=1);
namespace Omnigo;

final class MailTransport
{
    public function __construct(private array $config) {}

    private function password(): string
    {
        $file=dirname(__DIR__).'/mail-password.txt';
        if (!is_file($file)) return '';
        return rtrim((string)file_get_contents($file),"\r\n");
    }

    private function smtpHost(): string
    {
        $host=(string)($this->config['host']??getenv('OMNIGO_SMTP_HOST')?:'');
        return trim($host);
    }

    private function smtpPort(): int
    {
        $raw=$this->config['port']??getenv('OMNIGO_SMTP_PORT')?:465;
        $port=(int)$raw;
        return $port>0&&$port<=65535 ? $port : 465;
    }

    private function smtpUser(): string
    {
        $user=(string)($this->config['username']??getenv('OMNIGO_SMTP_USER')?:$this->config['from']);
        return trim($user);
    }

    private function smtpSecure(): string
    {
        $secure=strtolower((string)($this->config['secure']??getenv('OMNIGO_SMTP_SECURE')?:'smtps'));
        if ($secure==='ssl') return 'smtps';
        return in_array($secure,['smtps','tls'],true) ? $secure : 'smtps';
    }

    public function ready(): bool
    {
        if (($this->config['transport']??'mail')!=='smtp') return true;
        $password=$this->password();
        $host=$this->smtpHost();
        return $host!=='' && !in_array($host,['smtp.example.com','mail.example.com'],true)
            && $password!=='' && !str_starts_with($password,'REPLACE_');
    }

    public function send(string $to,string $subject,string $body): bool
    {
        if (($this->config['transport']??'mail')!=='smtp') return mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,['From'=>'Omnigo Global <'.$this->config['from'].'>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
        if (!$this->ready()) throw new \RuntimeException('SMTP ayarları hazır değil. Sunucu ve şifreyi ortam değişkeni veya yapılandırma ile tanımlayın.');
        foreach (['Exception','PHPMailer','SMTP'] as $class) require_once dirname(__DIR__).'/vendor/phpmailer/'.$class.'.php';
        $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host=$this->smtpHost();
        $mailer->Port=$this->smtpPort();
        $mailer->SMTPSecure=$this->smtpSecure()==='tls'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mailer->SMTPAuth=true;
        $mailer->Username=$this->smtpUser();
        $mailer->Password=$this->password();
        $mailer->Timeout=20; $mailer->Timelimit=30; $mailer->SMTPDebug=0;
        $mailer->CharSet='UTF-8'; $mailer->setFrom($this->config['from'],'Omnigo Global');
        $mailer->addAddress($to); $mailer->Subject=$subject; $mailer->Body=$body;
        return $mailer->send();
    }
}
