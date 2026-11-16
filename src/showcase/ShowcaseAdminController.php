<?php
declare(strict_types=1);
namespace FAS\Showcase;

/** Dormant composition only. Caller owns current locked admin session and native request arrays. */
final class ShowcaseAdminController
{
    // Scrub before a document can be frozen; a restored document must reauthorize.
    // CSP permits only these exact bytes, never user-provided inline scripts.
    private const HISTORY_GUARD = "addEventListener('pagehide',function(){document.body.replaceChildren(document.createTextNode('Checking administrator access.'));});addEventListener('pageshow',function(event){if(event.persisted){location.reload();}});";
    private $repository; private $request; private $actor; private $enabled; private $paths;
    public function __construct(ShowcaseRepository $repository, ShowcaseRequest $request, callable $actor, callable $enabled, array $paths)
    {
        $keys=array_keys($paths); sort($keys);
        if ($keys!==['detail','moderate','photo','queue']) throw new \InvalidArgumentException('Explicit admin routes required.');
        foreach ($paths as $path) if (!is_string($path) || !preg_match('~\A/admin/[A-Za-z0-9][A-Za-z0-9_./-]{0,180}\z~D',$path) || strpos($path,'..')!==false) throw new \InvalidArgumentException('Trusted admin paths required.');
        $this->repository=$repository; $this->request=$request; $this->actor=$actor; $this->enabled=$enabled; $this->paths=$paths;
    }
    /** endpoint is a fixed route dispatch value. csrf is current server-side session state, never POST-derived. */
    public function handle(string $endpoint, array $server, array $query, array $post, string $csrf): array
    {
        try {
            if (($this->enabled)()!==true) return self::response(404,'Not found.');
            try { $actor=($this->actor)(); } catch (\Throwable $error) { return self::response(403,'Administrator access required.'); }
            if (!is_int($actor) || $actor<1) return self::response(403,'Administrator access required.');
            if (!isset($this->paths[$endpoint])) return self::response(404,'Not found.');
            $method=$endpoint==='moderate' ? 'POST' : 'GET';
            if (($server['REQUEST_METHOD']??null)!==$method) return self::response(405,'Method not allowed.',['Allow'=>$method]);
            if ($endpoint==='moderate') {
                if ($query) throw new \InvalidArgumentException('Unexpected query.');
                $receipt=$this->request->moderate($server,$post,$csrf,$actor);
                return self::response(303,'',['Location'=>$this->paths['detail'].'?id='.rawurlencode($receipt['id'])]);
            }
            if ($post) throw new \InvalidArgumentException('Unexpected body.');
            if ($endpoint==='queue') {
                if (array_diff(array_keys($query),['status','before_time','before_id'])) throw new \InvalidArgumentException('Unexpected query.');
                $status=$query['status']??'pending_review';
                if (!is_string($status)) throw new \InvalidArgumentException('Invalid status.');
                $before=null;
                if (array_key_exists('before_time',$query) || array_key_exists('before_id',$query)) {
                    if (!is_string($query['before_time']??null) || !is_string($query['before_id']??null)) throw new \InvalidArgumentException('Complete cursor required.');
                    $before=['accepted_at'=>$query['before_time'],'id'=>$query['before_id']];
                }
                return self::response(200,ShowcaseViews::queue($this->repository->moderationQueue($actor,$status,20,$before),$this->paths['detail'],$this->paths['queue'],$status));
            }
            if (array_keys($query)!==['id'] || !is_string($query['id'])) throw new \InvalidArgumentException('Submission identifier required.');
            $row=$this->repository->readForModeration($query['id'],$actor);
            if ($endpoint==='photo') return self::response(200,$row['photo_jpeg'],['Content-Type'=>'image/jpeg','Content-Length'=>(string)strlen($row['photo_jpeg'])]);
            return self::response(200,ShowcaseViews::review($row,$this->repository->moderationAudit($row['id'],$actor),$csrf,$this->paths['moderate'],$this->paths['photo']));
        } catch (\OutOfBoundsException $error) { return self::response(404,'Not found.'); }
        catch (\DomainException $error) { return self::response(409,'This submission changed. Reload it before deciding.'); }
        catch (\InvalidArgumentException $error) { return self::response(400,'Invalid review request. Reload the review page.'); }
        catch (\Throwable $error) { return self::response(503,'Photo review is temporarily unavailable.'); }
    }
    private static function response(int $status,string $body,array $extra=[]): array
    {
        $headers=array_merge([
            'Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'private, no-store','Pragma'=>'no-cache',
            'X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer',
            'Content-Security-Policy'=>"default-src 'none'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ],$extra);
        if ($status === 200 && $headers['Content-Type'] === 'text/html; charset=utf-8') {
            $headers['Content-Security-Policy'] .= "; script-src 'sha256-" . base64_encode(hash('sha256',self::HISTORY_GUARD,true)) . "'";
            $body .= '<script data-showcase-history-guard>' . self::HISTORY_GUARD . '</script>';
        }
        return ['status'=>$status,'body'=>$body,'headers'=>$headers];
    }
}
