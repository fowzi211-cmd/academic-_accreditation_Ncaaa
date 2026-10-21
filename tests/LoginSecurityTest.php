<?php
declare(strict_types=1);
namespace QualitySystem\Tests;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QualitySystem\Security\LoginThrottle;
final class LoginSecurityTest extends TestCase
{
    private PDO $pdo;
    private string $identity;
    protected function setUp(): void
    {
        $c=require 'C:\\quality_system_config\\database.php';
        $this->pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->identity='phpunit-' . bin2hex(random_bytes(8)) . '@invalid.test';
    }
    protected function tearDown(): void
    {
        $hash=\QualitySystem\Security\AuditLogger::hash($this->identity); $s=$this->pdo->prepare('DELETE FROM login_attempts WHERE identity_hash=:hash'); $s->execute([':hash'=>$hash]);
    }
    #[Group('database')]
    public function testFiveFailuresTriggerDistributedThrottle(): void
    {
        $throttle=new LoginThrottle($this->pdo);
        for($i=0;$i<5;$i++) $throttle->record($this->identity,'192.0.2.10',false);
        self::assertTrue($throttle->isBlocked($this->identity,'192.0.2.11'));
    }
}
