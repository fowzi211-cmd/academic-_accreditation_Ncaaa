<?php
declare(strict_types=1);
return static function(PDO$pdo):void{$pdo->exec("ALTER TABLE program_plo_clo_matrix MODIFY alignment_level ENUM('Practical','Introductory','Mastery') NOT NULL DEFAULT 'Introductory'");};
