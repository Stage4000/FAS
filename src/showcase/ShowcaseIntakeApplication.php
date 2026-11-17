<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Dormant composition. Caller must hold PHP's exclusive session lock throughout.
 * No bootstrap, routes, session_start, config loading or output on include.
 */
final class ShowcaseIntakeApplication
{
 private $repository; private $rate; private $slots; private $version; private $text; private $action;
 private $ttl; private $capacity; private $clock; private $enabled; private $identity;
 public function __construct(ShowcaseRepository $repository,ShowcaseRateAdmission $rate,ShowcaseConcurrency $slots,
  string $version,string $text,string $action,int $ttl,int $capacity,callable $clock,callable $enabled,callable $identity)
 {
  $this->repository=$repository;$this->rate=$rate;$this->slots=$slots;$this->version=$version;$this->text=$text;$this->action=$action;
  $this->ttl=$ttl;$this->capacity=$capacity;$this->clock=$clock;$this->enabled=$enabled;$this->identity=$identity;
 }
 /** Identity callback resolves a trusted server identity, never raw user POST data.
  * Configure concurrency's own gate as a strict capacity policy (not a second quota).
  */
 public function handle(array $server,array $post,array $files,array &$session):array
 {
  try {
   if(($this->enabled)()!==true)return self::failure(404);
   $method=$server['REQUEST_METHOD']??'';
   if(!in_array($method,['GET','POST'],true))return self::failure(405);
   if(session_status()!==PHP_SESSION_ACTIVE)return self::failure(503);
   $identity=($this->identity)();if(!is_string($identity)||$identity==='')return self::failure(503);
   $operation=function()use($server,$post,$files,$method,$identity,&$session):array {
    if(($this->enabled)()!==true)return self::failure(404);
    $this->rate->admit($identity); // Exactly once, prior to issuance/decode.
    if(($this->enabled)()!==true)return self::failure(404);
    $bucket=$session['showcase_forms']??[];
    if(!is_array($bucket))throw new \LogicException('Invalid session bucket.');
    $forms=new ShowcaseSessionForms($bucket,$this->ttl,$this->capacity,$this->clock);
    $admitted=true;
    try {
     $request=new ShowcaseRequest($this->repository,$this->version,$this->text,
      function($action)use(&$admitted):bool{return $admitted && ($this->enabled)()===true && $action==='submit';});
     $controller=new ShowcaseIntakeController($request,$forms,$this->action,
      function()use(&$admitted):bool{return $admitted && ($this->enabled)()===true;},
      static function($verb)use(&$admitted,$method):bool{return $admitted && $verb===$method;});
     $response=$controller->handle($server,$post,$files);
     if($method==='GET' && $response['status']===200){
      if(($this->enabled)()!==true)return self::failure(404);
      $session['showcase_forms']=$bucket;
     }
     return $response;
    } finally {$admitted=false;}
   };
   return $method==='POST' ? $this->slots->execute($operation) : $operation();
  }catch(\OverflowException $e){return self::failure(429);}
  catch(\Throwable $e){return self::failure(503);}
 }
 private static function failure(int $status):array
 {
  return ['status'=>$status,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'private, no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff'],
   'body'=>'<p role="alert">This request could not be completed. Please try again later.</p>','error'=>'unavailable'];
 }
}
