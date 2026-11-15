<?php
declare(strict_types=1);
require __DIR__.'/../../src/manuals/ManualLibrary.php';
require __DIR__.'/../../src/manuals/ManualIntakeManifest.php';
use FAS\Manuals\ManualIntakeManifest as M;
$n=0;
function check($v): void {global $n;if(!$v)throw new RuntimeException('Check failed at '.($n+1));$n++;}
function inv(array $paths=['one.pdf']): array {$files=[];foreach($paths as $path)$files[]=['path'=>$path,'status'=>'candidate','bytes'=>10,'sha256'=>str_repeat('a',64)];return ['version'=>1,'complete'=>true,'errors'=>[],'files'=>$files,'candidate_count'=>count($files),'candidate_bytes'=>10*count($files)];}
const H="path,title,manufacturer,model,years,language,description\n";
function csv(array $row=['one.pdf','Workshop','Maker','Model','2020','','Notes']): string {$f=fopen('php://memory','w+b');fwrite($f,H);fputcsv($f,$row,',','"','');rewind($f);$s=stream_get_contents($f);fclose($f);return $s;}
function reject($i,$c): void {try{M::prepare($i,$c);}catch(InvalidArgumentException $e){check(true);return;}throw new RuntimeException('Invalid input accepted');}
$r=M::prepare(inv(),csv());check($r['ready_for_review']);check($r['draft_count']===1);$d=$r['drafts'][0];check($d['metadata']['rights']==='unverified'&&$d['metadata']['rights_note']===''&&$d['metadata']['source_url']==='');check(!$d['publication_requested']);check($d['metadata']['language']==='English');check($d['inventory_identity']['path']==='one.pdf');
$r=M::prepare(inv(),csv(['one.pdf','<b>Literal</b>','Maker','Model','2020','日本語',"comma, quote\"\nnext line"]));check($r['ready_for_review']);check($r['drafts'][0]['metadata']['description']==="comma, quote\"\nnext line");check($r['drafts'][0]['metadata']['title']==='<b>Literal</b>');
check(M::prepare(inv(),"\xEF\xBB\xBF".csv())['ready_for_review']);
check(M::prepare(inv(),"\xEF\xBB\xBF".str_replace('path,title','"path","title"',csv()))['ready_for_review']);
$r=M::prepare(inv(['two.pdf','one.pdf']),H."two.pdf,Two,M,,,,\none.pdf,One,M,,,,\n");check($r['ready_for_review']);check(array_column(array_column($r['drafts'],'inventory_identity'),'path')===['one.pdf','two.pdf']);
$r=M::prepare(inv(),H);check(!$r['ready_for_review']&&$r['errors'][0]['reason']==='metadata_missing');
$r=M::prepare(inv(),csv()."one.pdf,Again,M,,,,\n");check(!$r['ready_for_review']&&$r['draft_count']===1&&$r['errors'][0]['reason']==='duplicate_path');
$r=M::prepare(inv(),csv(['unknown.pdf','T','M','','','','']));check(!$r['ready_for_review']&&count($r['errors'])===2);
$r=M::prepare(inv(),H."one.pdf,Only\n");check(!$r['ready_for_review']&&$r['errors'][0]['reason']==='column_count');
foreach(['title'=>180,'manufacturer'=>80,'model'=>140,'years'=>60,'language'=>40,'description'=>1600] as $field=>$limit){
 $row=['path'=>'one.pdf','title'=>'T','manufacturer'=>'M','model'=>'','years'=>'','language'=>'English','description'=>''];$row[$field]=str_repeat('界',$limit);check(M::prepare(inv(),csv(array_values($row)))['ready_for_review']);$row[$field].='界';check(!M::prepare(inv(),csv(array_values($row)))['ready_for_review']);
}
foreach(['',"\x01bad"] as $bad){$r=M::prepare(inv(),csv(['one.pdf',$bad,'M','','','','']));check(!$r['ready_for_review']);}
$i=inv();$i['complete']=false;$i['errors']=[['reason'=>'read_budget']];check(!M::prepare($i,csv())['ready_for_review']);
$i=inv();$i['files'][]=['path'=>'video.mp4','status'=>'video','bytes'=>100];$r=M::prepare($i,csv());check($r['ready_for_review']&&$r['excluded_counts']===['video'=>1]);
foreach(['../one.pdf','/tmp/one.pdf','one//file.pdf','./one.pdf','one/../file.pdf','one/','',"one\0.pdf"] as $bad)reject(inv([$bad]),csv());
foreach(['version'=>true,'complete'=>1,'candidate_count'=>true,'candidate_bytes'=>11,'errors'=>null] as $key=>$bad){$i=inv();$i[$key]=$bad;reject($i,csv());}
$i=inv(['same.pdf','same.pdf']);reject($i,csv());
foreach(['bytes'=>true,'sha256'=>'bad','status'=>'read_budget'] as $key=>$bad){$i=inv();$i['files'][0][$key]=$bad;reject($i,csv());}
foreach([str_replace('description','rights',csv()),str_replace('description','title',csv()),H.'one.pdf,T,M,,,,"not closed',H.'one.pdf,T,M,,,,"closed"tail',H.'one.pdf,T,M,,,,un"quoted'] as $bad)reject(inv(),$bad);
check(M::prepare(inv(['123']),csv(['123','T','M','','','','']))['drafts'][0]['inventory_identity']['path']==='123');
$r=M::prepare(inv(),csv(['one.pdf','=1+1','M','','','','']));check($r['drafts'][0]['metadata']['title']==='=1+1');
reject(inv(),str_repeat('a',16777217));reject(inv(),"\xff");
$before=get_declared_classes();M::prepare(inv(),csv());check($before===get_declared_classes());
function cli(string $input): array {
    $process=proc_open([PHP_BINARY,__DIR__.'/../../scripts/manuals-intake-manifest.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('CLI test unavailable');
    $offset=0;while($offset<strlen($input)) {$written=fwrite($pipes[0],substr($input,$offset,65536));if(!$written)break;$offset+=$written;}
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    return [$code,json_decode($out,true,32,JSON_THROW_ON_ERROR),$err];
}
$a=cli(json_encode(['inventory'=>inv(),'metadata_csv'=>csv()]));check($a[0]===0 && $a[1]['ready_for_review'] && $a[2]==='');
$a=cli(json_encode(['inventory'=>inv(),'metadata_csv'=>H]));check($a[0]===2 && !$a[1]['ready_for_review']);
foreach(['{"private-secret":',"\xff",str_repeat(' ',33554433)] as $raw) {$a=cli($raw);check($a[0]===2 && $a[1]===['version'=>1,'error'=>'invalid_inventory_or_metadata','draft_only'=>true] && $a[2]==='');}
$envelope=json_encode(['inventory'=>inv(),'metadata_csv'=>csv()]);
foreach([
    str_replace('"complete":true','"complete":false,"complete":true',$envelope),
    str_replace('"complete":true','"complete":false,"complet\u0065":true',$envelope),
    str_replace('"version":1','"version":2,"version":1',$envelope),
    str_replace('"bytes":10','"bytes":20,"bytes":10',$envelope),
    '{"inventory":{},"inventory":'.json_encode(inv()).',"metadata_csv":'.json_encode(csv()).'}'
] as $ambiguous) {$a=cli($ambiguous);check($a[0]===2 && $a[1]['error']==='invalid_inventory_or_metadata');}
$a=cli(json_encode(['inventory'=>inv(),'metadata_csv'=>csv(['one.pdf','T','M','','','','literal "complete":false,"complete":true { [ ] }'])]));check($a[0]===0);
check(M::decodeInput('{"a":[{"x":1},{"x":2}],"b":{"x":3}}')===['a'=>[['x'=>1],['x'=>2]],'b'=>['x'=>3]]);
echo "$n draft-manifest checks passed\n";
