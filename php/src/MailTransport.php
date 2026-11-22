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
        return rtrim(file_get_contents($file),"\r\n");
    }
    public function ready(): bool
    {
        if (($this->config['transport']??'mail')!=='smtp') return true;
        $password=$this->password();
        return $password!=='' && !str_starts_with($password,'REPLACE_');
    }
    public function send(string $to,string $subject,string $body): bool
    {
        if (($this->config['transport']??'mail')!=='smtp') return mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,['From'=>'Omnigo Global <'.$this->config['from'].'>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
        if (!$this->ready()) throw new \RuntimeException('E-posta bağlantısı hazır değil.');
        foreach (['Exception','PHPMailer','SMTP'] as $class) require_once dirname(__DIR__).'/vendor/phpmailer/'.$class.'.php';
        $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host='srvc78.trwww.com'; $mailer->Port=465;
        $mailer->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mailer->SMTPAuth=true;
        $mailer->Username=$this->config['from']; $mailer->Password=$this->password();
        $mailer->Timeout=20; $mailer->Timelimit=30; $mailer->SMTPDebug=0;
        $mailer->CharSet='UTF-8'; $mailer->setFrom($this->config['from'],'Omnigo Global');
        $mailer->addAddress($to); $mailer->Subject=$subject; $mailer->Body=$body;
        return $mailer->send();
    }
}
