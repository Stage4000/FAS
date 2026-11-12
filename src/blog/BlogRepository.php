<?php
declare(strict_types=1);
namespace FAS\Blog;
require_once __DIR__ . '/BlogContent.php';

/** Drafts are private; only explicit published snapshots leave public methods. */
final class BlogRepository
{
    private const TABLES = [
        'blog_posts' => "CREATE TABLE blog_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            revision INTEGER NOT NULL CHECK(revision > 0),
            request_key_sha256 TEXT UNIQUE NOT NULL,
            creation_sha256 TEXT NOT NULL,
            draft_json TEXT NOT NULL,
            published_json TEXT,
            slug TEXT UNIQUE,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            published_at TEXT,
            first_published_at TEXT,
            CHECK((published_json IS NULL AND published_at IS NULL) OR (published_json IS NOT NULL AND published_at IS NOT NULL AND slug IS NOT NULL))
        )",
        'blog_history' => "CREATE TABLE blog_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL REFERENCES blog_posts(id),
            revision INTEGER NOT NULL,
            action TEXT NOT NULL CHECK(action IN ('create','draft','publish','withdraw')),
            actor_id INTEGER NOT NULL CHECK(actor_id > 0),
            created_at TEXT NOT NULL,
            UNIQUE(post_id,revision)
        )",
    ];
    private $db;
    private $authorize;

    public function __construct(\PDO $db, callable $authorize)
    {
        self::connection($db);
        $this->db = $db; $this->authorize = $authorize;
    }

    /** Missing storage is an empty public blog, never an implicit migration. */
    public static function installed(\PDO $db): bool
    {
        self::connection($db);
        $found = (int)$db->query("SELECT COUNT(*) FROM main.sqlite_master WHERE name IN ('blog_posts','blog_history')")->fetchColumn();
        if ($found === 0) { return false; }
        self::schema($db); return true;
    }

    public static function install(\PDO $db): void
    {
        if (PHP_SAPI !== 'cli') { throw new \RuntimeException('Initialize blog storage from the reviewed CLI maintenance flow.'); }
        self::connection($db);
        self::transaction($db, static function () use ($db): void {
            $count = (int)$db->query("SELECT COUNT(*) FROM main.sqlite_master WHERE name IN ('blog_posts','blog_history')")->fetchColumn();
            if ($count !== 0) { self::schema($db); return; }
            foreach (self::TABLES as $sql) { $db->exec(preg_replace('/^CREATE TABLE /','CREATE TABLE main.',$sql)); }
            self::schema($db);
        });
    }

    public function listAdmin(int $actor, int $limit = 30, int $offset = 0): array
    {
        $this->ready(); $this->authorized($actor,'review'); self::pagination($limit,$offset,100);
        $select = $this->db->prepare('SELECT * FROM main.blog_posts ORDER BY updated_at DESC,id DESC LIMIT :limit OFFSET :offset');
        $select->bindValue(':limit',$limit,\PDO::PARAM_INT); $select->bindValue(':offset',$offset,\PDO::PARAM_INT); $select->execute();
        $items = []; foreach ($select->fetchAll(\PDO::FETCH_ASSOC) as $row) { $items[] = $this->adminRow($row,false); }
        return ['items'=>$items,'total'=>(int)$this->db->query('SELECT COUNT(*) FROM main.blog_posts')->fetchColumn()];
    }

    public function getAdmin(int $id, int $actor): ?array
    {
        $this->ready(); $this->authorized($actor,'review'); self::id($id);
        $row = $this->find($id); return $row ? $this->adminRow($row,true) : null;
    }

    public function createDraft(array $input, int $actor, string $requestKey): array
    {
        $data = BlogContent::validate($input);
        if (!preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D',$requestKey)) { throw new \InvalidArgumentException('Refresh the new-post form and try again.'); }
        $this->ready(); $json = self::encode($data); $keyHash = hash('sha256',$requestKey); $payloadHash = hash('sha256',$json);
        return self::transaction($this->db,function () use ($actor,$json,$keyHash,$payloadHash): array {
            self::schema($this->db); $this->authorized($actor,'draft');
            $find = $this->db->prepare('SELECT * FROM main.blog_posts WHERE request_key_sha256=?'); $find->execute([$keyHash]);
            $old = $find->fetch(\PDO::FETCH_ASSOC);
            if ($old) {
                if (!hash_equals($old['creation_sha256'],$payloadHash)) { throw new \DomainException('This new-post form was already used with different text. Open a new form or edit the saved post.'); }
                return $this->adminRow($old,true);
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->db->prepare('INSERT INTO main.blog_posts(revision,request_key_sha256,creation_sha256,draft_json,created_at,updated_at) VALUES(1,?,?,?,?,?)')->execute([$keyHash,$payloadHash,$json,$now,$now]);
            $id = (int)$this->db->lastInsertId(); $this->audit($id,1,'create',$actor,$now);
            return $this->adminRow($this->find($id),true);
        });
    }

    public function save(int $id, int $expectedRevision, string $action, array $input, int $actor): array
    {
        self::id($id);
        if ($expectedRevision < 1 || $expectedRevision === PHP_INT_MAX || !in_array($action,['draft','publish','withdraw'],true)) { throw new \InvalidArgumentException('Choose a valid post action and revision.'); }
        if ($action === 'publish' && ($input['reviewed'] ?? null) !== true) { throw new \InvalidArgumentException('Confirm factual, photo and link review before publishing.'); }
        $data = $action === 'withdraw' ? null : BlogContent::validate($input,$action === 'publish');
        $this->ready();
        return self::transaction($this->db,function () use ($id,$expectedRevision,$action,$data,$actor): array {
            self::schema($this->db); $this->authorized($actor,$action);
            $old = $this->find($id);
            if (!$old) { throw new \OutOfBoundsException('Blog post not found.'); }
            if ($old['revision'] !== $expectedRevision) { throw new \DomainException('Another administrator changed this post. Reload and compare before saving.'); }
            if ($action === 'withdraw' && $old['published_json'] === null) { throw new \DomainException('This post is not published.'); }
            if ($data !== null && $old['slug'] !== null && $data['slug'] !== $old['slug']) { throw new \InvalidArgumentException('The canonical slug is fixed after first publication.'); }
            $now = gmdate('Y-m-d H:i:s');
            $draft = $data === null ? $old['draft_json'] : self::encode($data);
            $published = $old['published_json']; $publishedAt = $old['published_at']; $firstPublished = $old['first_published_at']; $slug = $old['slug'];
            if ($action === 'publish') {
                $slug = $data['slug'];
                $duplicate = $this->db->prepare('SELECT id FROM main.blog_posts WHERE slug=? AND id<>?'); $duplicate->execute([$slug,$id]);
                if ($duplicate->fetchColumn() !== false) { throw new \DomainException('That canonical slug is reserved by another post.'); }
                $published = $draft; $publishedAt = $now; $firstPublished = $firstPublished ?? $now;
            } elseif ($action === 'withdraw') { $published = null; $publishedAt = null; }
            $update = $this->db->prepare('UPDATE main.blog_posts SET revision=revision+1,draft_json=?,published_json=?,slug=?,updated_at=?,published_at=?,first_published_at=? WHERE id=? AND revision=?');
            $update->execute([$draft,$published,$slug,$now,$publishedAt,$firstPublished,$id,$expectedRevision]);
            if ($update->rowCount() !== 1) { throw new \DomainException('The post changed while saving. Reload it.'); }
            $this->audit($id,$expectedRevision+1,$action,$actor,$now);
            return $this->adminRow($this->find($id),true);
        });
    }

    public function listPublished(int $limit = 12, int $offset = 0): array
    {
        self::pagination($limit,$offset,100);
        if (!self::installed($this->db)) { return ['items'=>[],'total'=>0]; }
        $query = $this->db->prepare('SELECT id,slug,published_json,published_at,first_published_at FROM main.blog_posts WHERE published_json IS NOT NULL ORDER BY first_published_at DESC,id DESC LIMIT :limit OFFSET :offset');
        $query->bindValue(':limit',$limit,\PDO::PARAM_INT); $query->bindValue(':offset',$offset,\PDO::PARAM_INT); $query->execute();
        $items = []; foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) { $items[] = self::publicRow($row); }
        return ['items'=>$items,'total'=>(int)$this->db->query('SELECT COUNT(*) FROM main.blog_posts WHERE published_json IS NOT NULL')->fetchColumn()];
    }

    public function findPublished(string $slug): ?array
    {
        try { if ($slug === '' || BlogContent::validate(['slug'=>$slug])['slug'] !== $slug) { return null; } }
        catch (\InvalidArgumentException $invalidSlug) { return null; }
        if (!self::installed($this->db)) { return null; }
        $query = $this->db->prepare('SELECT id,slug,published_json,published_at,first_published_at FROM main.blog_posts WHERE slug=? AND published_json IS NOT NULL');
        $query->execute([$slug]); $row = $query->fetch(\PDO::FETCH_ASSOC); return $row ? self::publicRow($row) : null;
    }

    public function publishedSitemap(): array
    {
        if (!self::installed($this->db)) { return []; }
        $rows = $this->db->query('SELECT id,slug,published_json,published_at,first_published_at FROM main.blog_posts WHERE published_json IS NOT NULL ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) { $public = self::publicRow($row); $result[] = ['slug'=>$public['slug'],'updated_at'=>$public['updated_at']]; }
        return $result;
    }

    private function ready(): void
    {
        if (!self::installed($this->db)) { throw new \RuntimeException('Blog storage is not initialized.'); }
    }
    private function authorized(int $actor,string $action): void
    {
        if ($actor < 1 || ($this->authorize)($actor,$action) !== true) { throw new \RuntimeException('Current active-administrator authorization is required.'); }
    }
    private function find(int $id): ?array
    {
        $query = $this->db->prepare('SELECT * FROM main.blog_posts WHERE id=?'); $query->execute([$id]); return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    private function adminRow(array $row,bool $withHistory): array
    {
        $draft = self::decode($row['draft_json'],false);
        $published = $row['published_json'] === null ? null : self::decode($row['published_json'],true);
        $history = [];
        if ($withHistory) {
            $q = $this->db->prepare('SELECT revision,action,actor_id,created_at FROM main.blog_history WHERE post_id=? ORDER BY revision DESC LIMIT 30'); $q->execute([$row['id']]); $history = $q->fetchAll(\PDO::FETCH_ASSOC);
        }
        return ['id'=>$row['id'],'revision'=>$row['revision'],'draft'=>$draft,'published'=>$published,'slug'=>$row['slug'],
            'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at'],'published_at'=>$row['published_at'],'first_published_at'=>$row['first_published_at'],
            'state'=>$published === null ? ($row['slug'] === null ? 'Draft' : 'Withdrawn') : ($row['draft_json'] === $row['published_json'] ? 'Published' : 'Unpublished changes'),'history'=>$history];
    }
    private static function publicRow(array $row): array
    {
        $content = self::decode($row['published_json'],true);
        if ($content['slug'] !== $row['slug']) { throw new \UnexpectedValueException('Published blog identity is inconsistent.'); }
        foreach (['published_at','first_published_at'] as $field) {
            $value = $row[$field] ?? null;
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC')) : false;
            if (!$date || $date->format('Y-m-d H:i:s') !== $value) { throw new \UnexpectedValueException('Published blog date is invalid.'); }
        }
        if ($row['first_published_at'] > $row['published_at']) { throw new \UnexpectedValueException('Published blog dates are inconsistent.'); }
        return ['id'=>$row['id'],'slug'=>$row['slug'],'content'=>$content,'published_at'=>$row['published_at'],
            'first_published_at'=>$row['first_published_at'],'updated_at'=>$row['published_at']];
    }
    private function audit(int $id,int $revision,string $action,int $actor,string $time): void
    {
        $this->db->prepare('INSERT INTO main.blog_history(post_id,revision,action,actor_id,created_at) VALUES(?,?,?,?,?)')->execute([$id,$revision,$action,$actor,$time]);
    }
    private static function encode(array $data): string { return json_encode($data,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private static function decode(string $json,bool $published): array
    {
        $data = json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \UnexpectedValueException('Invalid stored blog content.'); }
        return BlogContent::validate($data,$published);
    }
    private static function id(int $id): void { if ($id < 1) { throw new \InvalidArgumentException('Choose a valid blog post.'); } }
    private static function pagination(int $limit,int $offset,int $max): void
    {
        if ($limit < 1 || $limit > $max || $offset < 0 || $offset > 1000000) { throw new \InvalidArgumentException('Invalid blog page range.'); }
    }
    private static function connection(\PDO $db): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES) !== false || $db->getAttribute(\PDO::ATTR_CASE) !== \PDO::CASE_NATURAL
            || $db->getAttribute(\PDO::ATTR_ORACLE_NULLS) !== \PDO::NULL_NATURAL || (int)$db->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new \LogicException('Blog storage requires SQLite exception mode, native fetches, natural names/nulls and foreign keys.');
        }
        if ($db->inTransaction()) { throw new \LogicException('Blog operations cannot own an existing transaction.'); }
        $journal = strtolower((string)$db->query('PRAGMA main.journal_mode')->fetchColumn());
        $persistent = false; foreach ($db->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) { if ($database['name'] === 'main') { $persistent = $database['file'] !== ''; } }
        if ($journal === 'off' || ($persistent && ($journal === 'memory' || (int)$db->query('PRAGMA main.synchronous')->fetchColumn() < 2))) { throw new \LogicException('Blog storage requires rollback-capable journaling and durable persistent synchronization.'); }
    }
    private static function schema(\PDO $db): void
    {
        foreach (self::TABLES as $name=>$sql) {
            $q = $db->prepare('SELECT type,sql FROM main.sqlite_master WHERE name=?'); $q->execute([$name]); $row = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$row || $row['type'] !== 'table' || trim($row['sql']) !== trim($sql)) { throw new \LogicException('The complete expected blog schema is required.'); }
        }
        foreach (['main','temp'] as $database) {
            if ((int)$db->query("SELECT COUNT(*) FROM " . $database . ".sqlite_master WHERE type='trigger' AND tbl_name COLLATE NOCASE IN ('blog_posts','blog_history')")->fetchColumn() !== 0) { throw new \LogicException('Unexpected blog schema triggers are not supported.'); }
        }
    }
    private static function transaction(\PDO $db,callable $operation)
    {
        $owned = false;
        try { $db->exec('BEGIN IMMEDIATE'); $owned = true; $result = $operation(); $db->exec('COMMIT'); $owned = false; return $result; }
        catch (\Throwable $e) {
            if ($owned) { try { $db->exec('ROLLBACK'); } catch (\Throwable $rollback) { throw new \RuntimeException('Blog rollback could not be verified.',0,$e); } }
            throw $e;
        }
    }
}
