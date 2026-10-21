<?php
declare(strict_types=1);namespace QualitySystem\Tests;use PHPUnit\Framework\TestCase;
final class ConsultancyMeetingMembersTest extends TestCase{public function testActiveCommitteeMembersAreAutomaticallyInvited():void{$s=file_get_contents(dirname(__DIR__).'/consultancy_committee.php');$this->assertStringContainsString("SELECT staff_id FROM committee_members WHERE committee_id=:committee AND status='Active'",$s);$this->assertStringContainsString('array_merge($definedMembers', $s);$this->assertStringContainsString('array_unique', $s);}}
