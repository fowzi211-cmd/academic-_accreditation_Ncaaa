<?php
declare(strict_types=1);
namespace QualitySystem\Repositories;
use PDO;
final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function findForLogin(string $email): ?array
    {
        $statement=$this->pdo->prepare('SELECT id,full_name,email,password_hash,role,status,locked_until FROM users WHERE email=:email LIMIT 1');
        $statement->execute([':email'=>$email]); $user=$statement->fetch(PDO::FETCH_ASSOC); return $user ?: null;
    }
}
