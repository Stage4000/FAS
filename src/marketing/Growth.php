<?php
declare(strict_types=1);
namespace FAS\Marketing;
require_once __DIR__.'/../security/SecurityStore.php';

/** Consent-based email signup and cart recovery. Inventory remains authoritative. */
final class Growth
{
    public \PDO $db;
    private \PDO $inventory;
    private array $site;
    private $clock;
    private $presentProduct;
    public const NEWSLETTER_CONSENT = 'Email me new arrivals and offers from Flip and Strip. I can unsubscribe anytime.';
    public const CART_CONSENT = 'Email me this cart and one reminder if I do not check out. This does not sign me up for marketing emails.';
    public const REMINDER_CONSENT = 'Email me one reminder if I leave this cart behind. No newsletter signup.';

    public static function open(\PDO $inventory, array $site, ?callable $presentProduct=null): self
    {
        // Reuse the validated private directory, never the inventory's public directory.
        \FAS\Security\SecurityStore::open();
        $path = dirname(\FAS\Security\SecurityStore::path()).'/growth.sqlite';
        if (is_link($path)) throw new \RuntimeException('Unsafe email database path.');
        $mask = umask(0077);
        try {
            $db = new \PDO('sqlite:'.$path);
            @chmod($path,0600);
            return new self($db,$inventory,$site,null,$presentProduct);
        } finally { umask($mask); }
    }
    public function __construct(\PDO $db, \PDO $inventory, array $site, ?callable $clock=null, ?callable $presentProduct=null)
    {
        $this->db=$db; $this->inventory=$inventory; $this->site=$site;
        $this->clock=$clock ?: static function(){return time();};
        $this->presentProduct=$presentProduct;
        $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE,\PDO::FETCH_ASSOC);
        $db->exec('PRAGMA busy_timeout=1500');
        $db->exec("CREATE TABLE IF NOT EXISTS growth_settings(name TEXT PRIMARY KEY,value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS growth_contacts(email TEXT PRIMARY KEY,status TEXT NOT NULL,requested_at INTEGER NOT NULL,confirmed_at INTEGER,confirm_hash TEXT,confirm_expires INTEGER,unsubscribe_hash TEXT NOT NULL,consent TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS growth_carts(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_hash TEXT NOT NULL UNIQUE,email TEXT NOT NULL,items TEXT NOT NULL,consent TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,expires INTEGER NOT NULL,restore_hash TEXT NOT NULL UNIQUE,unsubscribe_hash TEXT NOT NULL,status TEXT NOT NULL,reminder_due INTEGER NOT NULL,restored_at INTEGER,order_floor INTEGER NOT NULL,order_id INTEGER,links TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS growth_cart_due ON growth_carts(status,reminder_due);
            CREATE TABLE IF NOT EXISTS growth_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,dedupe TEXT NOT NULL UNIQUE,email TEXT NOT NULL,kind TEXT NOT NULL,cart_id INTEGER,payload TEXT NOT NULL,status TEXT NOT NULL,due INTEGER NOT NULL,created_at INTEGER NOT NULL,claimed_at INTEGER,sent_at INTEGER,error TEXT);
            CREATE INDEX IF NOT EXISTS growth_message_due ON growth_messages(status,due);");
        $db->exec('CREATE TABLE IF NOT EXISTS growth_mail_limits(email TEXT NOT NULL,kind TEXT NOT NULL,last_attempt INTEGER NOT NULL,PRIMARY KEY(email,kind))');
    }
    public function run(string $sql,array $params=[]): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($params);return $s; }
    public function now(): int { return (int)($this->clock)(); }
    private function transaction(callable $fn) {
        $this->db->exec('BEGIN IMMEDIATE');
        try {$r=$fn();$this->db->exec('COMMIT');return $r;}
        catch (\Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
    }
    public function settings(): array {
        $s=['mail_enabled'=>'0','postal_address'=>'','from_email'=>'noreply@flipandstrip.com','reply_to'=>$this->site['reply_to_email']??'noreply@flipandstrip.com'];
        foreach($this->run('SELECT * FROM growth_settings')->fetchAll() as $r) if(array_key_exists($r['name'],$s)) $s[$r['name']]=$r['value'];
        return $s;
    }
    public function saveSettings(array $s): void {
        $from=self::email($s['from_email']??'');
        $reply=self::email($s['reply_to']??'');
        $address=trim(is_string($s['postal_address']??null)?$s['postal_address']:'');
        $enabled=($s['mail_enabled']??'0')==='1';
        if(strlen($address)>500 || ($enabled && $address==='')) throw new \InvalidArgumentException('Enter a business mailing address before enabling emails.');
        $this->transaction(function()use($from,$reply,$address,$enabled){
            foreach(['from_email'=>$from,'reply_to'=>$reply,'postal_address'=>$address,'mail_enabled'=>$enabled?'1':'0'] as $k=>$v)
                $this->run('INSERT OR REPLACE INTO growth_settings VALUES(?,?)',[$k,$v]);
        });
    }
    public function ready(): bool {
        $s=$this->settings();
        return $s['mail_enabled']==='1' && $s['postal_address']!=='' && (bool)filter_var($s['from_email'],FILTER_VALIDATE_EMAIL)
            && (bool)filter_var($s['reply_to'],FILTER_VALIDATE_EMAIL);
    }
    public static function email($value): string {
        if(!is_string($value) || strlen($value)>254 || preg_match('/[\r\n]/',$value) || !filter_var($value,FILTER_VALIDATE_EMAIL))
            throw new \InvalidArgumentException('Enter a valid email address.');
        return strtolower(trim($value));
    }
    private static function hash(string $token): string { return hash('sha256',$token); }
    private static function token(): string { return bin2hex(random_bytes(32)); }
    private function queue(string $key,string $email,string $kind,array $payload,?int $cart=null): void {
        $this->run("INSERT OR IGNORE INTO growth_messages(dedupe,email,kind,cart_id,payload,status,due,created_at) VALUES(?,?,?,?,?,'queued',?,?)",
            [$key,$email,$kind,$cart,json_encode($payload,JSON_THROW_ON_ERROR),$this->now(),$this->now()]);
    }
    public function signup(string $email): void {
        $email=self::email($email);
        $this->transaction(function()use($email){
            $existing=$this->run('SELECT * FROM growth_contacts WHERE email=?',[$email])->fetch();
            // Repeated signups never disclose status or repeatedly send confirmation emails.
            if($existing && ($existing['status']==='subscribed' || $existing['requested_at']>$this->now()-86400)) return;
            $confirm=self::token();$unsub=self::token();
            $this->run("INSERT OR REPLACE INTO growth_contacts VALUES(?,'pending',?,NULL,?,?,?,?)",
                [$email,$this->now(),self::hash($confirm),$this->now()+172800,self::hash($unsub),self::NEWSLETTER_CONSENT]);
            $this->queue('confirm:'.self::hash($email).':'.intdiv($this->now(),86400),$email,'confirmation',['confirm'=>$confirm,'unsubscribe'=>$unsub]);
        });
    }
    public function confirm(string $token): bool {
        return $this->run("UPDATE growth_contacts SET status='subscribed',confirmed_at=?,confirm_hash=NULL WHERE confirm_hash=? AND status='pending' AND confirm_expires>=?",
            [$this->now(),self::hash($token),$this->now()])->rowCount()>0;
    }
    public function unsubscribe(string $token): bool {
        return $this->transaction(function()use($token){
            $hash=self::hash($token);
            $contact=$this->run('SELECT email FROM growth_contacts WHERE unsubscribe_hash=?',[$hash])->fetchColumn();
            $cart=$this->run('SELECT email FROM growth_carts WHERE unsubscribe_hash=?',[$hash])->fetchColumn();
            $email=$contact ?: $cart;
            if(!$email) return false;
            // Persist suppression even for a cart-only recipient, including future unsolicited captures.
            $this->run("INSERT OR IGNORE INTO growth_contacts(email,status,requested_at,unsubscribe_hash,consent) VALUES(?,'unsubscribed',?,?,'')",
                [$email,$this->now(),$hash]);
            $this->run("UPDATE growth_contacts SET status='unsubscribed',confirm_hash=NULL WHERE email=?",[$email]);
            $this->run("UPDATE growth_carts SET status='unsubscribed' WHERE email=?",[$email]);
            $this->run("UPDATE growth_messages SET status='cancelled',payload='{}' WHERE email=? AND status='queued'",[$email]);
            return true;
        });
    }
    /** Only IDs and quantities are accepted from browsers; price and visibility come from inventory. */
    public function catalog(array $items): array {
        if(count($items)>50) throw new \InvalidArgumentException('Save up to 50 different items at a time.');
        $quantities=[];
        foreach($items as $item) {
            if(!is_array($item)) throw new \InvalidArgumentException('Invalid cart.');
            $id=filter_var($item['product_id']??$item['id']??null,FILTER_VALIDATE_INT);
            $qty=filter_var($item['quantity']??null,FILTER_VALIDATE_INT);
            if(!$id || $id<1 || !$qty || $qty<1 || $qty>99) throw new \InvalidArgumentException('Invalid cart item.');
            $quantities[$id]=min(99,($quantities[$id]??0)+$qty);
        }
        $result=[];
        $stmt=$this->inventory->prepare('SELECT * FROM products WHERE id=? AND is_active=1 AND show_on_website=1 AND quantity>0');
        foreach($quantities as $id=>$qty) {
            $stmt->execute([$id]);$p=$stmt->fetch(\PDO::FETCH_ASSOC);if(!$p)continue;
            $price=(float)$p['price'];$sale=(float)($p['sale_price']??0);
            if($sale>0 && $sale<$price)$price=$sale;
            $image=(string)($p['image_url']??'/gallery/default.jpg');
            if(!preg_match('~^(?:https://|/(?!/))~',$image))$image='/gallery/default.jpg';
            $item=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'price'=>$price,'quantity'=>min($qty,(int)$p['quantity']),
                'stock'=>(int)$p['quantity'],'sku'=>(string)($p['sku']??''),'image'=>$image,'category'=>$p['category']??'',
                'free_shipping'=>!empty($p['free_shipping']),'weight'=>(float)($p['weight']??1),
                'length'=>(float)($p['length']??10),'width'=>(float)($p['width']??10),'height'=>(float)($p['height']??10)];
            // The storefront supplies the same sale, shipping and image policies as product cards.
            $result[]=$this->presentProduct?array_replace($item,($this->presentProduct)($p)):$item;
        }
        return $result;
    }
    public function saveCart(string $owner,string $email,array $items,bool $sendNow): void {
        $email=self::email($email);
        if(strlen($owner)<32)throw new \InvalidArgumentException('Refresh the page and try again.');
        $catalog=$this->catalog($items);
        if(!$catalog)throw new \InvalidArgumentException('Add an available item to your cart first.');
        $minimal=array_map(static fn($p)=>['id'=>$p['id'],'quantity'=>$p['quantity']],$catalog);
        $this->transaction(function()use($owner,$email,$minimal,$sendNow){
            $hash=self::hash($owner);
            $old=$this->run('SELECT * FROM growth_carts WHERE owner_hash=?',[$hash])->fetch();
            $now=$this->now();
            // A repeated heartbeat updates the current cart without sending another message.
            if($old && $old['status']==='open' && $old['email']===$email && $old['expires']>$now) {
                $this->run('UPDATE growth_carts SET items=?,updated_at=?,reminder_due=?,consent=? WHERE id=?',
                    [json_encode($minimal),$now,$now+86400,$sendNow?self::CART_CONSENT:$old['consent'],$old['id']]);
                if($sendNow)$this->queue('cart:'.$old['id'].':'.intdiv($now,86400),$email,'cart_link',json_decode($old['links'],true),(int)$old['id']);
                return;
            }
            if($old)$this->run("UPDATE growth_messages SET status='cancelled',payload='{}' WHERE cart_id=? AND status='queued'",[$old['id']]);
            $restore=self::token();$unsub=self::token();
            $payload=['restore'=>$restore,'unsubscribe'=>$unsub];
            $floor=(int)$this->inventory->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn();
            // Keep one cart per owning browser; unrelated visitors cannot replace a cart by email.
            $this->run("INSERT INTO growth_carts(owner_hash,email,items,consent,created_at,updated_at,expires,restore_hash,unsubscribe_hash,status,reminder_due,order_floor,links)
                VALUES(?,?,?,?,?,?,?,?,?,'open',?,?,?) ON CONFLICT(owner_hash) DO UPDATE SET email=excluded.email,items=excluded.items,consent=excluded.consent,
                created_at=excluded.created_at,updated_at=excluded.updated_at,expires=excluded.expires,restore_hash=excluded.restore_hash,
                unsubscribe_hash=excluded.unsubscribe_hash,status='open',reminder_due=excluded.reminder_due,order_floor=excluded.order_floor,restored_at=NULL,order_id=NULL,links=excluded.links",
                [$hash,$email,json_encode($minimal),$sendNow?self::CART_CONSENT:self::REMINDER_CONSENT,$now,$now,$now+1209600,self::hash($restore),self::hash($unsub),$now+86400,$floor,json_encode($payload)]);
            $id=(int)$this->run('SELECT id FROM growth_carts WHERE owner_hash=?',[$hash])->fetchColumn();
            if($sendNow)$this->queue('cart:'.$id.':'.intdiv($now,86400),$email,'cart_link',$payload,$id);
            $this->queue('reminder:'.$id.':'.$now,$email,'cart_reminder',$payload,$id);
            $this->run("UPDATE growth_messages SET due=? WHERE cart_id=? AND kind='cart_reminder' AND status='queued'",[$now+86400,$id]);
        });
    }
    public function stopCart(string $owner): void {
        $this->transaction(function()use($owner){
            $id=$this->run('SELECT id FROM growth_carts WHERE owner_hash=?',[self::hash($owner)])->fetchColumn();
            if(!$id)return;
            $this->run("UPDATE growth_carts SET status='cancelled' WHERE id=?",[$id]);
            $this->run("UPDATE growth_messages SET status='cancelled',payload='{}' WHERE cart_id=? AND status='queued'",[$id]);
        });
    }
    public function updateCart(string $owner,array $items): void {
        $cart=$this->run("SELECT email FROM growth_carts WHERE owner_hash=? AND status='open'",[self::hash($owner)])->fetch();
        if(!$cart)return;
        if(!$items){$this->stopCart($owner);return;}
        $this->saveCart($owner,$cart['email'],$items,false);
    }
    public function restore(string $token): array {
        $cart=$this->run("SELECT * FROM growth_carts WHERE restore_hash=? AND expires>? AND status='open'",[self::hash($token),$this->now()])->fetch();
        if(!$cart)throw new \InvalidArgumentException('This saved cart link has expired or is no longer available.');
        if($this->purchaseState($cart)!=='open')throw new \InvalidArgumentException('This cart already has a payment or order in progress. Check your order before paying again.');
        $items=$this->catalog(json_decode($cart['items'],true));
        $this->run('UPDATE growth_carts SET restored_at=? WHERE id=?',[$this->now(),$cart['id']]);
        return $items;
    }
    private function purchaseState(array $cart): string {
        $stmt=$this->inventory->prepare("SELECT id,payment_status FROM orders WHERE lower(customer_email)=? AND (id>? OR updated_at>=?)
            AND payment_status IN ('completed','pending') ORDER BY CASE payment_status WHEN 'completed' THEN 0 ELSE 1 END,id DESC LIMIT 1");
        $stmt->execute([$cart['email'],$cart['order_floor'],gmdate('Y-m-d H:i:s',$cart['created_at'])]);
        $order=$stmt->fetch(\PDO::FETCH_ASSOC);
        if(!$order)return 'open';
        $state=$order['payment_status']==='completed'?'converted':'payment_pending';
        $this->run('UPDATE growth_carts SET status=?,order_id=? WHERE id=?',[$state,$order['id'],$cart['id']]);
        return $state;
    }
    private function eligible(array $m): bool {
        if($m['kind']==='confirmation') {
            $contact=$this->run('SELECT status,confirm_expires,confirm_hash FROM growth_contacts WHERE email=?',[$m['email']])->fetch();
            $payload=json_decode($m['payload'],true);
            return $contact && $contact['status']==='pending' && $contact['confirm_expires']>$this->now()
                && hash_equals($contact['confirm_hash']??'',self::hash($payload['confirm']??''));
        }
        if($this->run('SELECT status FROM growth_contacts WHERE email=?',[$m['email']])->fetchColumn()==='unsubscribed')return false;
        $cart=$this->run('SELECT * FROM growth_carts WHERE id=?',[$m['cart_id']])->fetch();
        if(!$cart || $cart['status']!=='open' || $cart['expires']<=$this->now())return false;
        if($this->purchaseState($cart)!=='open' || !$this->catalog(json_decode($cart['items'],true)))return false;
        if($m['kind']==='cart_reminder' && $cart['reminder_due']>$this->now()) {
            $this->run("UPDATE growth_messages SET status='queued',due=?,claimed_at=NULL WHERE id=?",[$cart['reminder_due'],$m['id']]);
            return false;
        }
        // A global per-address ceiling avoids multiple devices generating repeated reminders.
        return $this->transaction(function()use($m){
            $last=$this->run('SELECT last_attempt FROM growth_mail_limits WHERE email=? AND kind=?',[$m['email'],$m['kind']])->fetchColumn();
            if($last!==false && (int)$last>$this->now()-86400)return false;
            $this->run('INSERT OR REPLACE INTO growth_mail_limits VALUES(?,?,?)',[$m['email'],$m['kind'],$this->now()]);
            return true;
        });
    }
    public function message(array $m): array {
        $s=$this->settings();$p=json_decode($m['payload'],true);
        $base=rtrim((string)($this->site['url']??'https://flipandstrip.com'),'/');
        if(!preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?$~i',$base))throw new \RuntimeException('Configure a valid HTTPS site URL.');
        $unsubscribe=$base.'/email-preferences.php#unsubscribe='.rawurlencode($p['unsubscribe']);
        if($m['kind']==='confirmation') {
            $subject='Confirm your Flip and Strip email signup';
            $body="You asked for new arrivals and offers from Flip and Strip.\n\nConfirm your signup:\n".$base.'/email-preferences.php#confirm='.rawurlencode($p['confirm'])."\n\nIf you did not request this, you can ignore this email.";
        } else {
            $cart=$this->run('SELECT * FROM growth_carts WHERE id=?',[$m['cart_id']])->fetch();
            $subject=$m['kind']==='cart_link'?'Your saved Flip and Strip cart':'Still looking for those parts?';
            $body=$m['kind']==='cart_link'?"Here is the cart you asked us to save.":"You asked for one reminder about your saved cart.";
            $body.="\n\n";
            foreach($this->catalog(json_decode($cart['items'],true)) as $item)$body.=$item['quantity'].' × '.$item['name']."\n";
            $body.="\nReview your cart:\n".$base.'/recover-cart.php#'.rawurlencode($p['restore'])."\n\nPrices, shipping, and availability are checked again when you return. Items are not reserved.";
        }
        $body.="\n\nFlip and Strip\n".$s['postal_address']."\n\nUnsubscribe from optional emails:\n".$unsubscribe;
        return ['to'=>$m['email'],'subject'=>$subject,'body'=>$body,'from'=>$s['from_email'],'reply_to'=>$s['reply_to']];
    }
    /** A claim is never automatically retried after an uncertain transport outcome. */
    public function sendBatch(callable $sender,int $limit=20): array {
        if(!$this->ready())throw new \RuntimeException('Email sending is disabled or sender details are incomplete.');
        $result=['sent'=>0,'failed'=>0,'cancelled'=>0,'deferred'=>0];
        $this->run("UPDATE growth_messages SET status='uncertain',error='worker_interrupted' WHERE status='sending' AND claimed_at<?",[$this->now()-600]);
        for($i=0;$i<min(100,max(1,$limit));$i++) {
            $m=$this->transaction(function(){
                $m=$this->run("SELECT * FROM growth_messages WHERE status='queued' AND due<=? ORDER BY id LIMIT 1",[$this->now()])->fetch();
                if($m)$this->run("UPDATE growth_messages SET status='sending',claimed_at=? WHERE id=?",[$this->now(),$m['id']]);
                return $m;
            });
            if(!$m)break;
            try {
                if(!$this->eligible($m)) {
                    $cancelled=$this->run("UPDATE growth_messages SET status='cancelled',payload='{}' WHERE id=? AND status='sending'",[$m['id']])->rowCount();
                    $result[$cancelled?'cancelled':'deferred']++;continue;
                }
                $ok=$sender($this->message($m));
                $this->run("UPDATE growth_messages SET status=?,sent_at=?,error=?,payload='{}' WHERE id=?",
                    [$ok?'sent':'failed',$ok?$this->now():null,$ok?null:'transport_rejected',$m['id']]);
                $result[$ok?'sent':'failed']++;
            } catch(\Throwable $e) {
                $this->run("UPDATE growth_messages SET status='uncertain',error='delivery_unconfirmed' WHERE id=?",[$m['id']]);
                $result['failed']++;
            }
        }
        return $result;
    }
    public function reconcile(): void {
        foreach($this->run("SELECT * FROM growth_carts WHERE status IN ('open','payment_pending') ORDER BY updated_at DESC LIMIT 500")->fetchAll() as $cart) {
            if($cart['expires']<=$this->now())$this->run("UPDATE growth_carts SET status='expired' WHERE id=?",[$cart['id']]);
            else $this->purchaseState($cart);
        }
        $this->run("UPDATE growth_messages SET status='cancelled',payload='{}' WHERE status='queued' AND created_at<?",[$this->now()-1209600]);
        $this->run("DELETE FROM growth_messages WHERE id IN (SELECT id FROM growth_messages WHERE status<>'queued' AND created_at<? LIMIT 1000)",[$this->now()-2592000]);
        $this->run("DELETE FROM growth_carts WHERE id IN (SELECT id FROM growth_carts WHERE expires<? LIMIT 1000)",[$this->now()-2592000]);
        $this->run("DELETE FROM growth_contacts WHERE email IN (SELECT email FROM growth_contacts WHERE status='pending' AND requested_at<? LIMIT 1000)",[$this->now()-2592000]);
    }
    public function summary(): array {
        return [
            'subscribers'=>(int)$this->run("SELECT COUNT(*) FROM growth_contacts WHERE status='subscribed'")->fetchColumn(),
            'pending'=>(int)$this->run("SELECT COUNT(*) FROM growth_contacts WHERE status='pending'")->fetchColumn(),
            'saved_carts'=>(int)$this->run("SELECT COUNT(*) FROM growth_carts WHERE status='open'")->fetchColumn(),
            'restored'=>(int)$this->run("SELECT COUNT(*) FROM growth_carts WHERE restored_at IS NOT NULL")->fetchColumn(),
            'converted'=>(int)$this->run("SELECT COUNT(*) FROM growth_carts WHERE status='converted' AND restored_at IS NOT NULL")->fetchColumn(),
            'queued'=>(int)$this->run("SELECT COUNT(*) FROM growth_messages WHERE status='queued'")->fetchColumn(),
        ];
    }
}
