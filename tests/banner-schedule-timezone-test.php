<?php
/** Actual admin conversion + banner model, fixed clock and memory-only SQLite. */
namespace FAS\Models { function time() { return $GLOBALS['banner_fixture_now']; } }
namespace {
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require $argv[1] ?? __DIR__.'/../src/models/Banner.php';
require __DIR__.'/../src/utils/Timezone.php';
$db=new \PDO('sqlite::memory:'); $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE banners(id INTEGER PRIMARY KEY,message TEXT,bg_color TEXT,text_color TEXT,link_url TEXT,link_text TEXT,is_dismissible INT,is_active INT,sort_order INT,show_countdown INT,countdown_end TEXT,starts_at TEXT,ends_at TEXT)');
$model=new \FAS\Models\Banner($db);$checks=0;$failures=[];
function check($ok,$label){global $checks,$failures;$checks++;if(!$ok)$failures[]=$label;}
function snapshot($db){return $db->query('SELECT * FROM banners ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);}
$sites=['UTC','America/Chicago','America/New_York','America/Los_Angeles','Europe/London','Europe/Berlin','Asia/Kathmandu','Pacific/Auckland','Pacific/Kiritimati'];
$users=['UTC','America/Chicago','Asia/Tokyo'];
$inputs=['2026-01-15T12:00','2026-03-08T01:30','2026-07-15T12:00','2026-11-01T01:30'];
foreach($sites as $site){date_default_timezone_set($site);
 foreach($users as $user){$_COOKIE['fas_user_timezone']=$user;
  foreach($inputs as $input){
   $stored=\FAS\Utils\Timezone::fromUserInput($input);
   $epoch=(new \DateTimeImmutable($stored,new \DateTimeZone('UTC')))->getTimestamp();
   foreach(['starts_at','ends_at'] as $field){
    $db->exec('DELETE FROM banners');$model->create(['message'=>'Synthetic scheduled banner','is_active'=>1,$field=>$stored]);
    foreach([-1,0,1] as $offset){
     $GLOBALS['banner_fixture_now']=$epoch+$offset;$before=snapshot($db);$visible=count($model->getActive())===1;
     $expected=$field==='starts_at' ? $offset>=0 : $offset<=0;
     $label="$site / $user / $input / $field / $offset";
     check($visible===$expected,$label.' visibility');check(snapshot($db)===$before,$label.' no mutation');
    }
   }
   $db->exec('DELETE FROM banners');$model->create(['message'=>'Synthetic unscheduled','is_active'=>1]);
   $model->update(1,['message'=>'Synthetic updated schedule','is_active'=>1,'starts_at'=>$stored]);
   $GLOBALS['banner_fixture_now']=$epoch;check(count($model->getActive())===1,"$site / $user / $input update at start");
   check(\FAS\Utils\Timezone::toUtcTimestamp($stored)===$epoch,"$site / $user / $input display contract");
  }
 }
 // All existing supported structured forms represent naive UTC operational timestamps.
 foreach(['2026-11-12 18:00:00','2026-11-12 18:00','2026-11-12T18:00:00','2026-11-12T18:00'] as $stored){
  $db->exec('DELETE FROM banners');$model->create(['message'=>'Synthetic structured form','is_active'=>1,'starts_at'=>$stored]);
  $GLOBALS['banner_fixture_now']=1794506400; // 2026-11-12 18:00:00 UTC
  check(count($model->getActive())===1,"$site structured format $stored");
 }
 foreach([null,''] as $empty){$db->exec('DELETE FROM banners');$model->create(['message'=>'Synthetic unscheduled','is_active'=>1,'starts_at'=>$empty,'ends_at'=>$empty]);check(count($model->getActive())===1,"$site empty schedule");}
 $db->exec('DELETE FROM banners');$model->create(['message'=>'Inactive fixture','is_active'=>0]);check(count($model->getActive())===0,"$site inactive excluded");
 // Explicit timezone legacy values keep the existing fallback contract.
 foreach(['2026-11-12T18:00:00Z','2026-11-12T12:00:00-06:00','Thu, 12 Nov 2026 18:00:00 +0000'] as $offsetDate){
  $db->exec('DELETE FROM banners');$model->create(['message'=>'Explicit offset fixture','is_active'=>1,'starts_at'=>$offsetDate]);
  $GLOBALS['banner_fixture_now']=1794506400;check(count($model->getActive())===1,"$site explicit offset $offsetDate");
 }
 $db->exec('DELETE FROM banners');$model->create(['message'=>'Invalid fixture','is_active'=>1,'starts_at'=>'not-a-date']);check(count($model->getActive())===0,"$site invalid date rejected");
 // Free-form legacy dates are deliberately outside this narrow correction.
 $legacy='November 12 2026 18:00:00';$db->exec('DELETE FROM banners');$model->create(['message'=>'Legacy local fixture','is_active'=>1,'starts_at'=>$legacy]);
 $GLOBALS['banner_fixture_now']=strtotime($legacy);check(count($model->getActive())===1,"$site legacy fallback unchanged");
}
echo json_encode(['scope'=>'Actual conversion and model with fixed clock/memory-only SQLite; no live data','checks'=>$checks,'passed'=>$checks-count($failures),'failed_count'=>count($failures),'first_failures'=>array_slice($failures,0,20)],JSON_PRETTY_PRINT)."\n";
exit($failures?1:0);
}
