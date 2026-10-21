<?php
declare(strict_types=1);
namespace QualitySystem\Controllers;
use PDO;
use QualitySystem\Security\AuditLogger;
use QualitySystem\Services\AuthenticationService;
final class LoginController
{
    public function __construct(private readonly PDO $pdo) {}
    public function authenticate(string $email,string $password,string $ip): array
    {
        $result=(new AuthenticationService($this->pdo))->attempt($email,$password,$ip);
        AuditLogger::record($this->pdo,'login_' . $result['status'],$result['status']==='allowed'?'allowed':'denied','Authentication');
        return $result;
    }
}
