<?php
declare(strict_types=1);
namespace QualitySystem\Tests;
use PDO;use PHPUnit\Framework\Attributes\Group;use PHPUnit\Framework\TestCase;use QualitySystem\Repositories\CourseReportRepository;use QualitySystem\Security\PermissionMatrix;
final class CourseReportAuthorizationTest extends TestCase
{
    private PDO $pdo;private int $owner;private int $other;private array $reports=[];
    protected function setUp():void{$c=require'C:\quality_system_config\database.php';$this->pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$x=bin2hex(random_bytes(4));$s=$this->pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,status) VALUES(?,?,?,?, 'Active')");$s->execute(['Report Owner',"report-owner-$x@invalid.test",password_hash($x,PASSWORD_DEFAULT),'Faculty']);$this->owner=(int)$this->pdo->lastInsertId();$s->execute(['Report Other',"report-other-$x@invalid.test",password_hash($x,PASSWORD_DEFAULT),'Faculty']);$this->other=(int)$this->pdo->lastInsertId();$i=$this->pdo->prepare("INSERT INTO course_reports(course_code,course_title,academic_year,semester,status,created_by_user_id) VALUES(?,?,?,?, 'Draft',?)");foreach([['OWN','Owned','2026','First',$this->owner],['OTHER','Foreign','2026','First',$this->other]]as$r){$i->execute($r);$this->reports[]=(int)$this->pdo->lastInsertId();}}
    protected function tearDown():void{if(!isset($this->pdo))return;$m=implode(',',array_fill(0,count($this->reports),'?'));$this->pdo->prepare("DELETE FROM course_reports WHERE id IN ($m)")->execute($this->reports);$this->pdo->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$this->owner,$this->other]);}
    #[Group('database')]
    public function testFacultyOnlySeesOwnedUnscopedReport():void{$r=new CourseReportRepository($this->pdo);self::assertNotNull($r->findScoped($this->reports[0],'Faculty',$this->owner));self::assertNull($r->findScoped($this->reports[1],'Faculty',$this->owner));self::assertNotNull($r->findScoped($this->reports[1],'Admin',$this->owner));}
    public function testMatrix():void{self::assertTrue(PermissionMatrix::allows('Reviewer','course_reports','view'));self::assertFalse(PermissionMatrix::allows('Reviewer','course_reports','update'));self::assertTrue(PermissionMatrix::allows('Faculty','course_reports','update'));self::assertFalse(PermissionMatrix::allows('Faculty','course_reports','delete'));}
}
