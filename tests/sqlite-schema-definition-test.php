<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/utils/SqliteSchemaDefinition.php';
use FAS\Utils\SqliteSchemaDefinition as Definition;
$checks=0;
function definitionAccept(string $a,string $b):void{Definition::assertMatches($a,$b);$GLOBALS['checks']++;}
function definitionReject(string $a,string $b):void{try{Definition::assertMatches($a,$b);}catch(RuntimeException $e){$GLOBALS['checks']++;return;}throw new RuntimeException('Unexpected schema acceptance');}
$ddl="CREATE TABLE sample (id INTEGER PRIMARY KEY, state TEXT CHECK(state IN ('prepared','conflict')), note TEXT DEFAULT 'a  b''c -- /* literal */')";
definitionAccept($ddl,$ddl);definitionAccept("--prefix\r\ncreate /* table */ TABLE IF NOT EXISTS sample(id integer primary key,state text check(state in ('prepared','conflict')),note text default 'a  b''c -- /* literal */'); -- tail",$ddl);
foreach([
 str_replace("CHECK(state IN ('prepared','conflict'))","CONSTRAINT \"check(statein('prepared','conflict'))\" CHECK(1)",$ddl),
 str_replace("CHECK(state IN ('prepared','conflict'))","CHECK(1 OR \"check(statein('prepared','conflict'))\")",$ddl),
 str_replace("CHECK(state IN ('prepared','conflict'))","/* CHECK(state IN ('prepared','conflict')) */ CHECK(1)",$ddl),
 str_replace("'prepared'","'PREPARED'",$ddl),str_replace("'prepared'","'pre pared'",$ddl),str_replace("a  b''c","a b''c",$ddl),str_replace("-- /* literal */","-- /* changed */",$ddl),
 $ddl.';;',
] as $bad){definitionReject($bad,$ddl);}
foreach(["CREATE TABLE sample(id TEXT DEFAULT 'unterminated)",'CREATE TABLE sample(id TEXT DEFAULT "unterminated)',"CREATE TABLE sample(id INTEGER) /* unterminated", "CREATE TABLE sample(id INTEGER)\0", "CREATE TABLE sample(id INTEGER)\x01",'--comment only', ''] as $bad){definitionReject($bad,$ddl);}
definitionReject('CREATE TABLE sample(id INTEGER CHECK(id > = 0))','CREATE TABLE sample(id INTEGER CHECK(id >= 0))');
definitionReject('CREATE TABLE "Sample"(id INTEGER)','CREATE TABLE "sample"(id INTEGER)');
definitionAccept("CREATE TABLE sample(note TEXT DEFAULT 'a\nb')","create table sample ( note text default 'a\nb' );");
definitionReject("CREATE TABLE sample(note TEXT DEFAULT 'a\nb')","CREATE TABLE sample(note TEXT DEFAULT 'a b')");
// Actual SQLite sqlite_master definitions retain valid DDL semantics and are accepted.
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec($ddl);$stored=$db->query("SELECT sql FROM sqlite_master WHERE name='sample'")->fetchColumn();definitionAccept($stored,$ddl);
// Self-contained representative queue DDL: this shared test does not require queue feature files.
$queueSql="CREATE TABLE sample_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT NOT NULL, work_key TEXT NOT NULL, state TEXT NOT NULL CHECK(state IN ('pending','done')), attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5), UNIQUE(event_key,work_key));";
$db->exec($queueSql);$table=$db->query("SELECT sql FROM sqlite_master WHERE name='sample_jobs'")->fetchColumn();definitionAccept($table,$queueSql);
// SQLite -- comments end only at LF/end, never at CR. Prove CHECK is absent in execution.
$spoof="CREATE TABLE cr_comment (value TEXT -- hidden\r CHECK(value='safe')\n)";
$db->exec($spoof);$db->exec("INSERT INTO cr_comment(value) VALUES ('unsafe')");
if($db->query("SELECT value FROM cr_comment")->fetchColumn()!=='unsafe'){throw new RuntimeException('CR-comment regression fixture did not execute');}$checks++;
$stored=$db->query("SELECT sql FROM sqlite_master WHERE name='cr_comment'")->fetchColumn();
definitionReject($stored,"CREATE TABLE cr_comment(value TEXT CHECK(value='safe'))");
definitionAccept($stored,'CREATE TABLE cr_comment(value TEXT)');
echo "Passed: $checks; Failed: 0\n";
