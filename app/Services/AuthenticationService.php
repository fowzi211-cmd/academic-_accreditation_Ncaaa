<?php
declare(strict_types=1);
namespace QualitySystem\Services;
use PDO;
use QualitySystem\Repositories\UserRepository;
use QualitySystem\Security\LoginThrottle;
final class AuthenticationService
{
    private UserRepository $users; private LoginThrottle $throttle;
    public function __construct(private readonly PDO $pdo){$this->users=new UserRepository($pdo);$this->throttle=new LoginThrottle($pdo);}
    public function attempt(string $email,string $password,string $ip): array
    {
        if($this->throttle->isBlocked($email,$ip)) return ['status'=>'throttled','user'=>null];
        $user=$this->users->findForLogin($email);
        if(!$user || ($user['status']??'')!=='Active' || $this->throttle->userIsLocked($user) || !password_verify($password,(string)$user['password_hash'])){
            $this->throttle->record($email,$ip,false); if($user)$this->throttle->failUser((int)$user['id']); return ['status'=>'denied','user'=>null];
        }
        $this->throttle->record($email,$ip,true);$this->throttle->succeedUser((int)$user['id']);return ['status'=>'allowed','user'=>$user];
    }
}
