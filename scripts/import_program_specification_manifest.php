<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit('Not found.');}
if($argc<3)throw new InvalidArgumentException('Usage: php import_program_specification_manifest.php manifest.json source.pdf');
$manifestPath=realpath($argv[1]);$sourcePath=realpath($argv[2]);
if(!$manifestPath||!$sourcePath||strtolower(pathinfo($sourcePath,PATHINFO_EXTENSION))!=='pdf')throw new InvalidArgumentException('Manifest or PDF source is invalid.');
$data=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);$program=$data['program']??[];
foreach(['specification_title','program_name','program_code']as$field)if(trim((string)($program[$field]??''))==='')throw new InvalidArgumentException("Missing program.$field");
$config=require'C:\quality_system_config\database.php';$pdo=new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$root='C:\quality_system_storage\program_specification_sources';if(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))throw new RuntimeException('Cannot create protected source directory.');$hash=hash_file('sha256',$sourcePath);$safe=preg_replace('/[^A-Za-z0-9._-]+/','_',basename($sourcePath));$stored=$root.DIRECTORY_SEPARATOR.$hash.'_'.$safe;if(!is_file($stored)&&!copy($sourcePath,$stored))throw new RuntimeException('Cannot preserve source PDF.');
$program['previous_version_file']='protected-source:'.$hash;$program['notes']=trim((string)($program['notes']??''))."\nImported source SHA-256: $hash";
require_once'C:\quality_system\vendor\autoload.php';$editable=\QualitySystem\Repositories\ProgramSpecificationRepository::EDITABLE;
$children=[
 'plos'=>['program_specification_plos',['plo_code','plo_domain','plo_text','nqf_level','display_order','notes']],
 'courses'=>['program_specification_courses',['level_no','course_code','course_title','required_or_elective','prerequisite_courses','credit_hours','requirement_type','notes']],
 'kpis'=>['program_specification_kpis',['kpi_no','kpi_code','kpi_name','target_value','measurement_methods','measurement_time','kpi_type','notes']],
 'curriculum_structure'=>['program_specification_curriculum_structure',['structure_category','requirement_type','number_of_courses','credit_hours','percentage','notes']],
 'evaluation_matrix'=>['program_specification_evaluation_matrix',['evaluation_area','evaluation_sources','evaluation_methods','evaluation_time','notes']],
 'staffing_needs'=>['program_specification_staffing_needs',['academic_rank','general_specialty','specific_specialty','special_requirements','required_male','required_female','required_total','notes']],
 'tracks'=>['program_specification_tracks',['track_name','credit_hours','professions_jobs','notes']],
 'exit_points'=>['program_specification_exit_points',['exit_point_name','credit_hours','notes']],
];
$pdo->beginTransaction();try{$find=$pdo->prepare('SELECT id FROM program_specifications WHERE program_code=:code AND specification_version <=> :version LIMIT 1');$find->execute([':code'=>$program['program_code'],':version'=>$program['specification_version']??null]);$id=$find->fetchColumn();$fields=array_intersect_key($program,array_flip($editable));$parts=[];$params=[];foreach($fields as$k=>$v){$parts[]="`$k`=:$k";$params[":$k"]=$v;}if($id){$params[':id']=(int)$id;$pdo->prepare('UPDATE program_specifications SET '.implode(',',$parts).' WHERE id=:id')->execute($params);$id=(int)$id;}else{$pdo->prepare('INSERT INTO program_specifications SET '.implode(',',$parts))->execute($params);$id=(int)$pdo->lastInsertId();}foreach($children as$key=>[$table,$columns]){$pdo->prepare("DELETE FROM `$table` WHERE program_specification_id=:id")->execute([':id'=>$id]);foreach(($data[$key]??[])as$row){$values=array_intersect_key($row,array_flip($columns));$names=array_keys($values);$sql="INSERT INTO `$table`(program_specification_id,`".implode('`,`',$names).'`) VALUES(:program,:'.implode(',:',$names).')';$insert=$pdo->prepare($sql);$insert->execute([':program'=>$id]+array_combine(array_map(fn($n)=>":$n",$names),array_values($values)));}}$pdo->commit();echo json_encode(['program_specification_id'=>$id,'source'=>$stored,'sha256'=>$hash,'counts'=>array_map(fn($k)=>count($data[$k]??[]),array_keys($children))],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
