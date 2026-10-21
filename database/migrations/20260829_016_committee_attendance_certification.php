<?php
declare(strict_types=1);
return static function(PDO$pdo):void{
 $attendance=$pdo->query('SHOW COLUMNS FROM committee_meeting_attendance')->fetchAll(PDO::FETCH_COLUMN);if(!in_array('signature_name',$attendance,true))$pdo->exec('ALTER TABLE committee_meeting_attendance ADD COLUMN signature_name VARCHAR(255) NULL AFTER attendance_status,ADD COLUMN signed_at DATETIME NULL AFTER signature_name');
 $meetings=$pdo->query('SHOW COLUMNS FROM committee_meetings')->fetchAll(PDO::FETCH_COLUMN);if(!in_array('attendance_approved_by_staff_id',$meetings,true))$pdo->exec('ALTER TABLE committee_meetings ADD COLUMN required_quorum_count INT UNSIGNED NULL AFTER quorum_met,ADD COLUMN attendance_approved_by_staff_id INT NULL AFTER required_quorum_count,ADD COLUMN attendance_approved_at DATETIME NULL AFTER attendance_approved_by_staff_id,ADD CONSTRAINT fk_meeting_attendance_approver FOREIGN KEY(attendance_approved_by_staff_id) REFERENCES program_staff(id) ON DELETE SET NULL');
};
