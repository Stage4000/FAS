<?php
declare(strict_types=1);
namespace FAS\Utils;

require_once __DIR__.'/SqliteSchemaDefinition.php';

use PDO;
use RuntimeException;
use DomainException;
use Throwable;

final class ProductSyncConflict extends RuntimeException {}

/** Only name and description ownership; editorial ProductContent remains independent. */
final class ProductSyncOwnership
{
    private const EXPECTED_DDL = [
        'product_sync_ownership' => "CREATE TABLE IF NOT EXISTS product_sync_ownership(product_id INTEGER NOT NULL,field TEXT NOT NULL CHECK(field IN ('name','description')),local_owned INTEGER NOT NULL CHECK(local_owned IN(0,1)),revision INTEGER NOT NULL CHECK(revision>0),updated_at TEXT NOT NULL,PRIMARY KEY(product_id,field))",
        'product_sync_ownership_history' => 'CREATE TABLE IF NOT EXISTS product_sync_ownership_history(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,changes_json TEXT NOT NULL,before_hash TEXT NOT NULL,after_hash TEXT NOT NULL,created_at TEXT NOT NULL)',
        'product_sync_ownership_meta' => 'CREATE TABLE IF NOT EXISTS product_sync_ownership_meta(id INTEGER PRIMARY KEY CHECK(id=1),migration_version INTEGER NOT NULL CHECK(migration_version=1),baseline_product_count INTEGER NOT NULL CHECK(baseline_product_count>=0),initialized_at TEXT NOT NULL)',
    ];
    private const FIELDS=['name','description'];
    private const EDITABLE=['id','ebay_item_id','sku','name','description','price','sale_price','quantity','category','manufacturer','model','condition_name','weight','length','width','height','image_url','images','ebay_url','source','show_on_website','free_shipping','ebay_store_cat1_id','ebay_store_cat1_name','ebay_store_cat2_id','ebay_store_cat2_name','ebay_store_cat3_id','ebay_store_cat3_name','is_active'];
    public static function install(PDO $db):void
    {
        if(PHP_SAPI!=='cli'){throw new RuntimeException('Install ownership storage using CLI maintenance.');}
        self::connection($db);if($db->inTransaction()){throw new RuntimeException('Installation requires its own transaction.');}
        self::installed($db); // Refuse damaged/partial prior storage before any repair-like DDL.
        $db->beginTransaction();try{
            $db->exec("CREATE TABLE IF NOT EXISTS product_sync_ownership(product_id INTEGER NOT NULL,field TEXT NOT NULL CHECK(field IN ('name','description')),local_owned INTEGER NOT NULL CHECK(local_owned IN(0,1)),revision INTEGER NOT NULL CHECK(revision>0),updated_at TEXT NOT NULL,PRIMARY KEY(product_id,field))");
            $db->exec("CREATE TABLE IF NOT EXISTS product_sync_ownership_history(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,changes_json TEXT NOT NULL,before_hash TEXT NOT NULL,after_hash TEXT NOT NULL,created_at TEXT NOT NULL)");
            $db->exec("CREATE TABLE IF NOT EXISTS product_sync_ownership_meta(id INTEGER PRIMARY KEY CHECK(id=1),migration_version INTEGER NOT NULL CHECK(migration_version=1),baseline_product_count INTEGER NOT NULL CHECK(baseline_product_count>=0),initialized_at TEXT NOT NULL)");
            if(!self::installed($db)){
                if((int)$db->query('SELECT COUNT(*) FROM product_sync_ownership')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM product_sync_ownership_history')->fetchColumn()!==0){throw new RuntimeException('Ownership exists without migration marker; reconcile before initialization.');}
                // This first write reserves SQLite's writer before enumerating the baseline.
                // Marker is invisible to other connections until seeding commits with it.
                $db->exec("INSERT INTO product_sync_ownership_meta(id,migration_version,baseline_product_count,initialized_at) VALUES(1,1,0,datetime('now'))");
                $db->exec("INSERT INTO product_sync_ownership(product_id,field,local_owned,revision,updated_at) SELECT id,'name',1,1,datetime('now') FROM products");
                $db->exec("INSERT INTO product_sync_ownership(product_id,field,local_owned,revision,updated_at) SELECT id,'description',1,1,datetime('now') FROM products");
                $db->exec('UPDATE product_sync_ownership_meta SET baseline_product_count=(SELECT COUNT(*) FROM products) WHERE id=1');
            }
            if(!self::installed($db)){throw new RuntimeException('Ownership installation was not verified.');}
            if(!$db->commit()){throw new RuntimeException('Installation commit unconfirmed.');}
        }catch(Throwable $e){self::rollback($db);throw $e;}
    }
    public static function installed(PDO $db):bool
    {
        self::connection($db);
        $tables=[
            'product_sync_ownership'=>['product_id'=>'INTEGER','field'=>'TEXT','local_owned'=>'INTEGER','revision'=>'INTEGER','updated_at'=>'TEXT'],
            'product_sync_ownership_history'=>['id'=>'INTEGER','product_id'=>'INTEGER','actor_id'=>'INTEGER','changes_json'=>'TEXT','before_hash'=>'TEXT','after_hash'=>'TEXT','created_at'=>'TEXT'],
            'product_sync_ownership_meta'=>['id'=>'INTEGER','migration_version'=>'INTEGER','baseline_product_count'=>'INTEGER','initialized_at'=>'TEXT']
        ];$found=0;
        foreach($tables as $table=>$columns){
            $q=$db->prepare("SELECT type,sql FROM sqlite_master WHERE name=?");$q->execute([$table]);$definition=$q->fetch(PDO::FETCH_ASSOC);if(!$definition){continue;}if($definition['type']!=='table'){throw new RuntimeException('Ownership schema is not a table.');}$found++;
            SqliteSchemaDefinition::assertMatches((string)$definition['sql'], self::EXPECTED_DDL[$table]);
            $info=$db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);$actual=[];$pk=[];
            foreach($info as $column){$actual[$column['name']]=strtoupper($column['type']);if($column['name']!=='id' && (int)$column['notnull']!==1){throw new RuntimeException('Ownership nullability invalid.');}if((int)$column['pk']>0){$pk[(int)$column['pk']]=$column['name'];}}
            ksort($pk);if($actual!==$columns || array_values($pk)!==($table==='product_sync_ownership'?['product_id','field']:['id'])){throw new RuntimeException('Ownership schema is incompatible.');}
        }
        if($found===0){return false;}if($found!==3){throw new RuntimeException('Ownership schema installation is incomplete.');}
        if($db->query("SELECT 1 FROM product_sync_ownership WHERE typeof(product_id)!='integer' OR product_id<1 OR field NOT IN('name','description') OR typeof(local_owned)!='integer' OR local_owned NOT IN(0,1) OR typeof(revision)!='integer' OR revision<1 LIMIT 1")->fetchColumn()!==false){throw new RuntimeException('Ownership state is invalid.');}
        $markers=$db->query('SELECT * FROM product_sync_ownership_meta')->fetchAll(PDO::FETCH_ASSOC);
        if(!$markers){if((int)$db->query('SELECT COUNT(*) FROM product_sync_ownership')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM product_sync_ownership_history')->fetchColumn()!==0){throw new RuntimeException('Ownership migration marker is missing.');}return false;}
        if(count($markers)!==1 || (string)$markers[0]['id']!=='1' || (string)$markers[0]['migration_version']!=='1' || filter_var($markers[0]['baseline_product_count'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false || $markers[0]['initialized_at']===''){throw new RuntimeException('Ownership migration marker is invalid.');}
        return true;
    }
    public static function states(PDO $db,int $id):array
    {
        if(!self::installed($db)){throw new RuntimeException('Ownership storage is not installed.');}$rows=self::ownership($db,$id);return ['name'=>(bool)$rows['name']['local_owned'],'description'=>(bool)$rows['description']['local_owned']];
    }
    public static function snapshot(PDO $db,array $product):string
    {
        if(!self::installed($db)){throw new RuntimeException('Ownership storage is not installed.');}$id=filter_var($product['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false){throw new DomainException('Invalid product.');}$values=[];foreach(self::EDITABLE as $key){$values[$key]=array_key_exists($key,$product)&&$product[$key]!==null?(string)$product[$key]:null;}return hash('sha256',json_encode(['product'=>$values,'ownership'=>self::ownership($db,$id)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    /** Callback is the existing Product::update on this same PDO; no external work or nested transaction. */
    public static function save(PDO $db,int $id,array $data,string $expectedHash,int $actor,callable $write,array $releaseFields=[]):bool
    {
        self::connection($db);if($db->inTransaction()){throw new RuntimeException('Product ownership save cannot join a transaction.');}if(!self::installed($db)){throw new RuntimeException('Install product ownership storage before saving.');}
        if($id<1||$actor<1||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)||!array_is_list($releaseFields)||count(array_unique($releaseFields,SORT_REGULAR))!==count($releaseFields)){throw new DomainException('Invalid save request.');}
        foreach($releaseFields as $field){if(!is_string($field)||!in_array($field,self::FIELDS,true)){throw new DomainException('Invalid ownership field.');}}
        foreach(self::FIELDS as $field){if(array_key_exists($field,$data)&&!is_string($data[$field])){throw new DomainException('Invalid text field.');}}
        $db->beginTransaction();try{
            $before=self::product($db,$id);if(!hash_equals(self::snapshot($db,$before),$expectedHash)){throw new ProductSyncConflict('Product changed. Reload and compare before saving.');}
            $states=self::ownership($db,$id);$changes=[];
            foreach(self::FIELDS as $field){$changed=array_key_exists($field,$data)&&$data[$field]!== (string)($before[$field]??'');$release=in_array($field,$releaseFields,true);if($changed&&$release){throw new DomainException('Do not edit and return the same field to eBay in one save.');}$next=$release?0:($changed?1:(int)$states[$field]['local_owned']);if($next!==(int)$states[$field]['local_owned']){$revision=(int)$states[$field]['revision']+1;$q=$db->prepare("INSERT INTO product_sync_ownership(product_id,field,local_owned,revision,updated_at) VALUES(?,?,?,?,datetime('now')) ON CONFLICT(product_id,field) DO UPDATE SET local_owned=excluded.local_owned,revision=excluded.revision,updated_at=excluded.updated_at");$q->execute([$id,$field,$next,$revision]);$changes[$field]=$next?'website':'ebay_next_sync';}elseif($changed){$changes[$field]='website_text_updated';}}
            $result=$write($id,$data);if(!is_bool($result)){throw new RuntimeException('Product write result was invalid.');}
            $after=self::product($db,$id);
            foreach($data as $key=>$value){if(!in_array($key,self::EDITABLE,true)||in_array($key,['id','ebay_item_id','is_active'],true)){continue;}$expected=is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):($value===null?null:(string)$value);$actual=$after[$key]??null;$actual=$actual===null?null:(string)$actual;if($actual!==$expected){throw new RuntimeException('Product write did not persist requested values.');}}
            $afterHash=self::snapshot($db,$after);
            if(!hash_equals($expectedHash,$afterHash)){$q=$db->prepare("INSERT INTO product_sync_ownership_history(product_id,actor_id,changes_json,before_hash,after_hash,created_at) VALUES(?,?,?,?,?,datetime('now'))");$q->execute([$id,$actor,json_encode($changes,JSON_THROW_ON_ERROR),$expectedHash,$afterHash]);}
            if(!$db->commit()){throw new RuntimeException('Product save commit unconfirmed; check before retrying.');}return true;
        }catch(Throwable $e){self::rollback($db);throw $e;}
    }
    private static function ownership(PDO $db,int $id):array
    {
        $rows=['name'=>['local_owned'=>0,'revision'=>0],'description'=>['local_owned'=>0,'revision'=>0]];$q=$db->prepare('SELECT field,local_owned,revision FROM product_sync_ownership WHERE product_id=? ORDER BY field');$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){if(!in_array($row['field'],self::FIELDS,true)||!in_array((string)$row['local_owned'],['0','1'],true)||(int)$row['revision']<1){throw new RuntimeException('Invalid ownership state.');}$rows[$row['field']]=['local_owned'=>(int)$row['local_owned'],'revision'=>(int)$row['revision']];}return $rows;
    }
    private static function product(PDO $db,int $id):array{$q=$db->prepare('SELECT * FROM products WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row){throw new DomainException('Product unavailable.');}return $row;}
    private static function connection(PDO $db):void{if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'||$db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION){throw new RuntimeException('Ownership storage requires SQLite exception mode.');}}
    private static function rollback(PDO $db):void{try{if($db->inTransaction()&&!$db->rollBack()){throw new RuntimeException('Rollback was not confirmed.');}}catch(Throwable $error){throw new RuntimeException('Product save outcome uncertain; do not retry automatically.',0,$error);}}
}
