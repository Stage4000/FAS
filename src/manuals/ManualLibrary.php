<?php
declare(strict_types=1);
namespace FAS\Manuals;

/** Immutable PDF originals live outside every configured web root. No URL imports. */
final class ManualStorage
{
    public const MAX_BYTES = 52428800;
    private $root;
    /** Open descriptors prove ownership of files exclusively created by this instance. */
    private $newFiles = [];
    public function __destruct()
    {
        // An abandoned/uncertain operation preserves its files. Destruction only
        // closes descriptors; it is never an orphan collector.
        foreach ($this->newFiles as $handle) if (is_resource($handle)) fclose($handle);
    }
    public function __construct(string $root, string $documentRoot)
    {
        $normalize = static function (string $p): string { return DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('\\','/',$p)) : $p; };
        $configured = $normalize($root);
        $absolute = DIRECTORY_SEPARATOR === '\\' ? preg_match('~\A(?:[a-z]:/|//[^/]+/[^/]+(?:/|$))~D',$configured) === 1 : strpos($configured,'/') === 0;
        if (!$absolute || strpos($root,"\0") !== false || strpos($documentRoot,"\0") !== false) throw new \RuntimeException('Configure an absolute canonical private manuals directory.');
        // realpath enforces open_basedir. Convert only warnings from these local
        // directory checks into a fail-closed exception; restore the caller's
        // handler even on failure. Do not probe inaccessible ancestor paths.
        set_error_handler(static function (): void { throw new \RuntimeException('The configured manuals directories could not be resolved.'); }, E_WARNING);
        try {
            $real = realpath($root); $web = realpath($documentRoot);
            if (!$real || !$web || !is_dir($real) || !is_dir($web) || !is_writable($real)) throw new \RuntimeException('Configure an existing writable private manuals directory.');
        } finally { restore_error_handler(); }
        // A canonical absolute spelling cannot contain symlink aliases or dot
        // segments. Comparing it rejects every symlink component without walking
        // above an allowed directory. A trailing separator is harmless.
        if (rtrim($configured,'/') !== rtrim($normalize($real),'/')) throw new \RuntimeException('Configure an absolute canonical private manuals directory without symlinks or path aliases.');
        $r = rtrim($normalize($real),'/').'/'; $w = rtrim($normalize($web),'/').'/';
        if (strpos($r,$w) === 0 || strpos($w,$r) === 0) throw new \RuntimeException('Manuals storage must be separate from the web root.');
        $this->root = $real;
    }
    public static function uploadLimit(): int
    {
        $bytes = static function (string $value): int {
            $value = trim($value);
            if ($value === '' || $value === '0' || $value === '-1') return self::MAX_BYTES + 65536;
            if (!preg_match('/\A([0-9]+)([KMG]?)\z/iD', $value, $m)) return 0;
            return (int) min(PHP_INT_MAX, (float) $m[1] * (1024 ** ([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])])));
        };
        return max(0, min(self::MAX_BYTES, $bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')) - 65536));
    }
    /** Track fresh HTTP copies; the caller must settle against the same catalog in a finally block. */
    public function acceptUpload(array $upload): array
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) throw new \InvalidArgumentException('Choose a PDF upload that completed successfully.');
        if (filesize($upload['tmp_name']) > self::uploadLimit()) throw new \InvalidArgumentException('This PDF exceeds the server upload limit. Ask the site operator about a larger authorized import.');
        return $this->storeValidated($upload['tmp_name'], true);
    }
    /** CLI-only import. Opt in to ownership tracking only with settleNewFiles() in a finally block. */
    public function importLocal(string $path, bool $trackForCreate = false): array
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Local import is CLI-only.');
        return $this->storeValidated($path, $trackForCreate);
    }
    private function storeValidated(string $path, bool $trackForCreate): array
    {
        if (is_link($path) || !is_file($path)) throw new \InvalidArgumentException('The PDF is not a regular file.');
        $size = filesize($path);
        if (!$size || $size > self::MAX_BYTES) throw new \InvalidArgumentException('PDFs must be no larger than 50 MiB.');
        $input = fopen($path,'rb');
        if (!$input) throw new \RuntimeException('The uploaded PDF could not be read.');
        try {
            $magic = fread($input,8);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!preg_match('/\A%PDF-[12]\.[0-9]/D',$magic) || $mime !== 'application/pdf') throw new \InvalidArgumentException('Only PDF files are accepted.');
            rewind($input);
            $key = bin2hex(random_bytes(24)).'.pdf';
            $target = $this->root.DIRECTORY_SEPARATOR.$key;
            $output = fopen($target,'xb');
            if (!$output) throw new \RuntimeException('The PDF could not be stored.');
            // Retain this exact descriptor through catalog creation/settlement.
            // A replacement path cannot impersonate a file owned by this request.
            if ($trackForCreate) $this->newFiles[$key] = $output;
            try {
                $written = stream_copy_to_stream($input,$output,self::MAX_BYTES+1);
                if ($written !== $size || !fflush($output)) throw new \RuntimeException('The PDF upload was incomplete.');
            } finally { if (!$trackForCreate) fclose($output); }
            if (filesize($target) !== $size) throw new \RuntimeException('The PDF upload was incomplete.');
            return ['key'=>$key,'bytes'=>$size,'sha256'=>hash_file('sha256',$target)];
        } finally { fclose($input); }
    }
    /**
     * Retain referenced originals and discard only this instance's unused fresh
     * files. A SQLite writer lock makes the reference check/deletion indivisible
     * with respect to other catalog writers. Uncertain outcomes are preserved.
     * Never call this inside another transaction or use it as general cleanup.
     */
    public function settleNewFiles(\PDO $db): void
    {
        if (!$this->newFiles) return;
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION
            || $db->inTransaction() || !ManualLibrary::installed($db)) throw new \RuntimeException('New manual files were preserved because catalog references could not be safely checked.');
        $transactionOwned = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $transactionOwned = true;
            $query = $db->prepare("SELECT count(*) FROM main.manuals WHERE json_extract(file_json,'$.key')=?");
            foreach ($this->newFiles as $key=>$handle) {
                $query->execute([$key]);
                if ((int)$query->fetchColumn() === 0) {
                    $path = $this->root.DIRECTORY_SEPARATOR.$key;
                    // Convert only warnings in this bounded filesystem check to
                    // a clean preservation error; never hide unrelated warnings.
                    set_error_handler(static function (): void { throw new \RuntimeException('An unused new manual file was preserved because its identity or removal could not be verified.'); }, E_WARNING);
                    try {
                        clearstatcache(true, $path);
                        $fileStat = is_resource($handle) ? fstat($handle) : false;
                        $current = lstat($path);
                        if (!$fileStat || !$current || ($current['mode'] & 0170000) !== 0100000
                            || $current['dev'] !== $fileStat['dev'] || $current['ino'] !== $fileStat['ino']
                            || realpath(dirname($path)) !== $this->root) throw new \RuntimeException('An unused new manual file was preserved because its identity changed.');
                        if (!unlink($path)) throw new \RuntimeException('An unused new manual file could not be removed.');
                    } finally { restore_error_handler(); }
                }
                fclose($handle);
                unset($this->newFiles[$key]);
            }
            $db->exec('COMMIT');
            $transactionOwned = false;
        } catch (\Throwable $e) {
            // Older PDO SQLite versions do not track SQL-begun transactions.
            // BEGIN failure never grants ownership of a caller's transaction.
            if ($transactionOwned) { try { $db->exec('ROLLBACK'); } catch (\PDOException $rollbackError) {} }
            throw $e;
        }
    }
    public function verifiedPath(array $file): string
    {
        $input = $this->openVerified($file);
        fclose($input);
        return $this->root.DIRECTORY_SEPARATOR.$file['key'];
    }
    /** Verify the complete immutable original, then return that same readable descriptor. */
    public function openVerified(array $file)
    {
        $key = $file['key'] ?? '';
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{48}\.pdf\z/D',$key)) throw new \RuntimeException('Invalid stored PDF identity.');
        $path = $this->root.DIRECTORY_SEPARATOR.$key;
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || realpath(dirname($path)) !== $this->root || !is_readable($path)) throw new \RuntimeException('The stored PDF is unavailable.');
        $before = lstat($path);
        $input = fopen($path, 'rb');
        if (!$input) throw new \RuntimeException('The stored PDF is unavailable.');
        try {
            $stat = fstat($input);
            if (!$before || !$stat || ($stat['mode'] & 0170000) !== 0100000 || $before['dev'] !== $stat['dev'] || $before['ino'] !== $stat['ino']
                || !is_int($file['bytes'] ?? null) || $file['bytes'] < 1 || $file['bytes'] > self::MAX_BYTES || $stat['size'] !== $file['bytes']) throw new \RuntimeException('The stored PDF failed its integrity check.');
            $hash = hash_init('sha256');
            // A file growing after fstat must not turn integrity validation into
            // an unbounded read. One extra byte detects that growth and fails closed.
            $read = hash_update_stream($hash, $input, $file['bytes'] + 1);
            if ($read !== $file['bytes'] || !hash_equals((string)($file['sha256'] ?? ''), hash_final($hash)) || !rewind($input)) throw new \RuntimeException('The stored PDF failed its integrity check.');
            return $input;
        } catch (\Throwable $e) { fclose($input); throw $e; }
    }
}

final class ManualLibrary
{
    private $db; private $authorize;
    private const SCHEMA = [
        'manuals' => "CREATE TABLE manuals (id INTEGER PRIMARY KEY AUTOINCREMENT, revision INTEGER NOT NULL CHECK(revision>0), request_key TEXT NOT NULL UNIQUE, draft_json TEXT NOT NULL, published_json TEXT, file_json TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        'manual_history' => "CREATE TABLE manual_history (id INTEGER PRIMARY KEY AUTOINCREMENT, manual_id INTEGER NOT NULL REFERENCES manuals(id), revision INTEGER NOT NULL, actor_id INTEGER NOT NULL, action TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(manual_id, revision))"
    ];
    public function __construct(\PDO $db, callable $authorize) {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) throw new \RuntimeException('Manuals require SQLite with exception mode.');
        $this->db=$db; $this->authorize=$authorize;
    }
    public static function install(\PDO $db): void {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Use the manuals maintenance CLI to initialize storage.');
        $db->beginTransaction();
        try { foreach (self::SCHEMA as $sql) $db->exec(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS main.',$sql)); self::installed($db); $db->commit(); }
        catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
    public static function installed(\PDO $db): bool {
        $count = (int)$db->query("SELECT count(*) FROM main.sqlite_master WHERE type='table' AND name IN ('manuals','manual_history')")->fetchColumn();
        if ($count===0) return false;
        if ($count!==2) throw new \RuntimeException('Manuals schema is incomplete.');
        foreach (self::SCHEMA as $table=>$expected) {
            $sql = $db->query("SELECT sql FROM main.sqlite_master WHERE name='$table'")->fetchColumn();
            $normal = static function ($s) { return preg_replace('/\s+/',' ',trim(str_replace(['IF NOT EXISTS ','main.'],'',$s))); };
            if ($normal($sql)!==$normal($expected)) throw new \RuntimeException('Manuals schema differs from the reviewed definition.');
        }
        return true;
    }
    public static function blank(): array { return ['title'=>'','manufacturer'=>'','model'=>'','years'=>'','language'=>'English','description'=>'','rights'=>'unverified','rights_note'=>'','source_url'=>'']; }
    public static function validate(array $input, bool $publishing=false): array {
        $data=[];
        foreach (['title'=>180,'manufacturer'=>80,'model'=>140,'years'=>60,'language'=>40,'description'=>1600,'rights'=>20,'rights_note'=>2000,'source_url'=>500] as $key=>$limit) {
            $v=$input[$key] ?? '';
            if (!is_string($v) || !preg_match('//u',$v) || mb_strlen($v,'UTF-8')>$limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)) throw new \InvalidArgumentException('Check the '.str_replace('_',' ',$key).' field and its length.');
            $data[$key]=trim($v);
        }
        if ($data['title']==='' || $data['manufacturer']==='') throw new \InvalidArgumentException('Title and manufacturer are required.');
        if (!in_array($data['rights'],['unverified','cleared','restricted'],true)) throw new \InvalidArgumentException('Choose a valid rights status.');
        if ($data['source_url']!=='' && (!filter_var($data['source_url'],FILTER_VALIDATE_URL) || strtolower((string)parse_url($data['source_url'],PHP_URL_SCHEME))!=='https' || parse_url($data['source_url'],PHP_URL_USER)!==null || parse_url($data['source_url'],PHP_URL_PASS)!==null)) throw new \InvalidArgumentException('The private source reference must be an HTTPS URL without credentials.');
        if ($publishing && ($data['rights']!=='cleared' || $data['rights_note']==='' || ($input['reviewed'] ?? false)!==true)) throw new \InvalidArgumentException('Publication requires cleared redistribution rights, a private evidence note, and explicit PDF review.');
        return $data;
    }
    private function ready(): void { if (!self::installed($this->db)) throw new \RuntimeException('Manuals storage has not been initialized.'); }
    private function allowed(int $actor,string $action): void { if ($actor<1 || ($this->authorize)($actor,$action)!==true) throw new \RuntimeException('An active administrator is required.'); }
    private static function encode(array $data): string { return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    private function find(int $id): ?array {
        $q=$this->db->prepare('SELECT * FROM main.manuals WHERE id=?'); $q->execute([$id]); $r=$q->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        foreach (['draft_json'=>'draft','published_json'=>'published','file_json'=>'file'] as $column=>$name) { $r[$name]=$r[$column]===null?null:json_decode($r[$column],true,32,JSON_THROW_ON_ERROR); unset($r[$column]); }
        $r['id']=(int)$r['id']; $r['revision']=(int)$r['revision']; return $r;
    }
    public function getAdmin(int $id,int $actor): ?array { $this->ready();$this->allowed($actor,'read'); return $this->find($id); }
    public function listAdmin(int $actor,int $offset=0): array {
        $this->ready(); $this->allowed($actor,'read');
        if ($offset<0 || $offset>1000000) throw new \InvalidArgumentException('Invalid offset.');
        $q=$this->db->prepare('SELECT id FROM main.manuals ORDER BY updated_at DESC,id DESC LIMIT 30 OFFSET ?'); $q->bindValue(1,$offset,\PDO::PARAM_INT);$q->execute();
        return ['items'=>array_map(function($id){return $this->find((int)$id);},$q->fetchAll(\PDO::FETCH_COLUMN)),'total'=>(int)$this->db->query('SELECT count(*) FROM main.manuals')->fetchColumn()];
    }
    public function create(array $input,array $file,int $actor,string $requestKey): array {
        $this->ready();$this->allowed($actor,'create'); $data=self::validate($input);
        if (!preg_match('/\A[a-f0-9]{48}\z/D',$requestKey)) throw new \InvalidArgumentException('Reload the upload form.');
        if (!preg_match('/\A[a-f0-9]{48}\.pdf\z/D',(string)($file['key']??'')) || !is_int($file['bytes']??null) || $file['bytes']<1 || $file['bytes']>ManualStorage::MAX_BYTES || !preg_match('/\A[a-f0-9]{64}\z/D',(string)($file['sha256']??''))) throw new \InvalidArgumentException('Invalid PDF storage record.');
        // Acquire the writer reservation before the request-key lookup so two
        // simultaneous retries cannot both choose the new-record path.
        $transactionOwned=false;
        try {
            $this->db->exec('BEGIN IMMEDIATE');$transactionOwned=true;
            $this->allowed($actor,'create');
            $q=$this->db->prepare('SELECT id FROM main.manuals WHERE request_key=?');$q->execute([$requestKey]); $existing=$q->fetchColumn();
            if ($existing) { $r=$this->find((int)$existing); if ($r['draft']!==$data || $r['file']['sha256']!==$file['sha256']) throw new \DomainException('This upload form was already used with different content.'); $this->db->exec('COMMIT');$transactionOwned=false;return $r; }
            $now=gmdate('Y-m-d H:i:s');
            $this->db->prepare('INSERT INTO main.manuals(revision,request_key,draft_json,file_json,created_at,updated_at) VALUES(1,?,?,?,?,?)')->execute([$requestKey,self::encode($data),self::encode($file),$now,$now]);
            $id=(int)$this->db->lastInsertId();$this->audit($id,1,$actor,'create',$now);$r=$this->find($id);$this->db->exec('COMMIT');$transactionOwned=false;return $r;
        } catch (\Throwable $e) {
            // Always release our SQL transaction, even when inTransaction() is
            // false. A lost COMMIT acknowledgment can leave nothing to roll back;
            // preserve that original error and let settlement recheck references.
            if($transactionOwned){try{$this->db->exec('ROLLBACK');}catch(\PDOException $rollbackError){}}
            throw $e;
        }
    }
    public function save(int $id,int $revision,array $input,string $action,int $actor): array {
        $this->ready();$this->allowed($actor,$action);
        if ($revision<1 || $revision===PHP_INT_MAX || !in_array($action,['draft','publish','withdraw'],true)) throw new \InvalidArgumentException('Invalid manual action or revision.');
        $data=$action==='withdraw'?null:self::validate($input,$action==='publish');
        $this->db->beginTransaction();
        try {
            $this->allowed($actor,$action);$old=$this->find($id);
            if (!$old) throw new \OutOfBoundsException('Manual not found.');
            if ($old['revision']!==$revision) throw new \DomainException('Another administrator changed this manual. Copy your text, then reload and compare.');
            $draft=$data??$old['draft'];$public=$old['published'];
            if ($action==='publish')$public=$draft;
            // A rights withdrawal also immediately removes a previously published snapshot.
            if ($action==='withdraw'||$draft['rights']!=='cleared')$public=null;
            $now=gmdate('Y-m-d H:i:s');
            $q=$this->db->prepare('UPDATE main.manuals SET revision=revision+1,draft_json=?,published_json=?,updated_at=? WHERE id=? AND revision=?');
            $q->execute([self::encode($draft),$public===null?null:self::encode($public),$now,$id,$revision]);
            if($q->rowCount()!==1)throw new \DomainException('This manual changed while saving. Reload it.');
            $this->audit($id,$revision+1,$actor,$action,$now);$r=$this->find($id);$this->db->commit();return $r;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function audit(int $id,int $revision,int $actor,string $action,string $now):void {$this->db->prepare('INSERT INTO main.manual_history(manual_id,revision,actor_id,action,created_at) VALUES(?,?,?,?,?)')->execute([$id,$revision,$actor,$action,$now]);}
    public function getPublished(int $id): ?array {
        if(!self::installed($this->db))return null; $r=$this->find($id);
        if(!$r || !$r['published'] || $r['published']['rights']!=='cleared' || $r['draft']['rights']!=='cleared')return null;
        $data=$r['published']; unset($data['rights'],$data['rights_note'],$data['source_url']);
        return ['id'=>$r['id'],'metadata'=>$data,'file'=>$r['file'],'updated_at'=>$r['updated_at']];
    }
    public function searchPublished(string $query='',string $manufacturer='',int $offset=0): array {
        if(!preg_match('//u',$query) || !preg_match('//u',$manufacturer) || mb_strlen($query)>160 || mb_strlen($manufacturer)>80 || $offset<0 || $offset>1000000)throw new \InvalidArgumentException('Search is too long or page is invalid.');
        if(!self::installed($this->db))return ['items'=>[],'total'=>0,'manufacturers'=>[],'manufacturers_truncated'=>false];
        // Public metadata is in a bounded JSON snapshot. SQLite json_extract keeps private drafts out of search.
        $gate="published_json IS NOT NULL AND json_extract(published_json,'$.rights')='cleared' AND json_extract(draft_json,'$.rights')='cleared'";
        $facet=$this->db->query("SELECT json_extract(published_json,'$.manufacturer') AS name,count(*) AS total FROM main.manuals WHERE $gate GROUP BY name ORDER BY name COLLATE NOCASE,name LIMIT 101")->fetchAll(\PDO::FETCH_ASSOC);
        // Bound suggestions, not the exact manufacturer filter. One extra detects truncation.
        $facetsTruncated=count($facet)>100; if($facetsTruncated)array_pop($facet);
        $where=$gate;$params=[];
        if($manufacturer!==''){$where.=" AND json_extract(published_json,'$.manufacturer')=?";$params[]=$manufacturer;}
        $terms=preg_split('/\s+/u',trim($query),-1,PREG_SPLIT_NO_EMPTY);
        if(count($terms)>12)throw new \InvalidArgumentException('Use no more than twelve search terms.');
        foreach($terms as $term) {
            $where.=" AND (json_extract(published_json,'$.title') || ' ' || json_extract(published_json,'$.manufacturer') || ' ' || json_extract(published_json,'$.model') || ' ' || json_extract(published_json,'$.years') || ' ' || json_extract(published_json,'$.description')) LIKE ? ESCAPE '\\'";
            $params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%';
        }
        $q=$this->db->prepare("SELECT count(*) FROM main.manuals WHERE $where");$q->execute($params);$total=(int)$q->fetchColumn();
        $q=$this->db->prepare("SELECT id FROM main.manuals WHERE $where ORDER BY json_extract(published_json,'$.manufacturer') COLLATE NOCASE,json_extract(published_json,'$.title') COLLATE NOCASE,id LIMIT 24 OFFSET ?");
        foreach($params as $i=>$p)$q->bindValue($i+1,$p);$q->bindValue(count($params)+1,$offset,\PDO::PARAM_INT);$q->execute();
        $items=[];foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $id){$item=$this->getPublished((int)$id);if($item===null)continue;unset($item['file']);$items[]=$item;}
        return ['items'=>$items,'total'=>$total,'manufacturers'=>$facet,'manufacturers_truncated'=>$facetsTruncated];
    }
}
