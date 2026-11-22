<?php
declare(strict_types=1);
namespace Omnigo;

/** Login failures have their own budget; successful passwords never consume it. */
final class LoginAttemptBudget
{
    private const LIMIT=10;
    private const WINDOW=30;
    public function __construct(private Database $db){}

    public function verify(string $email,string $ip,callable $verifyPassword): ?array
    {
        // A shared connection must not lock unrelated accounts. Use fresh keys so
        // existing fifteen-minute IP locks cannot carry into the shorter policy.
        $keys=[hash('sha256','login-short:pair:'.$ip.':'.$email),hash('sha256','login-short:email:'.$email)];
        return $this->db->transaction(function()use($keys,$verifyPassword){
            $now=time();$budgets=[];
            foreach($keys as $key){
                $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
                $this->db->run("$insert INTO login_attempts(attempt_key,attempt_count,reset_at) VALUES(?,0,?)",[$key,$now+self::WINDOW]);
                $row=$this->db->one('SELECT * FROM login_attempts WHERE attempt_key=?'.$this->db->lock(),[$key]);
                $expired=(int)$row['reset_at']<=$now;
                $count=$expired?0:(int)$row['attempt_count'];
                if($count>=self::LIMIT){
                    $retryAfter=max(1,min(self::WINDOW,(int)$row['reset_at']-$now));
                    if(PHP_SAPI!=='cli')header('Retry-After: '.$retryAfter);
                    Http::fail(429,'Kısa sürede çok fazla deneme yapıldı. '.$retryAfter.' saniye sonra tekrar deneyin.');
                }
                $budgets[]=['key'=>$key,'count'=>$count,'resetAt'=>$expired?$now+self::WINDOW:(int)$row['reset_at']];
            }
            // Keep the locks until the result is recorded so concurrent failures share the limit.
            $user=$verifyPassword();
            if(!$user){
                foreach($budgets as $budget)$this->db->run('UPDATE login_attempts SET attempt_count=?,reset_at=? WHERE attempt_key=?',[$budget['count']+1,$budget['resetAt'],$budget['key']]);
                return null; // Commit the failure before the caller returns an authentication error.
            }
            foreach($keys as $key)$this->db->run('UPDATE login_attempts SET attempt_count=0,reset_at=? WHERE attempt_key=?',[$now+self::WINDOW,$key]);
            return $user;
        });
    }
}
