<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Dormant intake composition. No routes, globals, sessions or output on include.
 * Caller holds server session lock and supplies native request arrays, never proxies.
 */
final class ShowcaseIntakeController
{
 private $request; private $forms; private $action; private $consent; private $enabled; private $admit;
 public function __construct(ShowcaseRequest $request, ShowcaseSessionForms $forms, string $action, callable $enabled, callable $admit)
 {
  $consent=$request->consentText();
  // Validate trusted rendering configuration before any form can be issued.
  ShowcaseForm::intake($action,str_repeat('a',64),str_repeat('b',64),$consent);
  $this->request=$request; $this->forms=$forms; $this->action=$action; $this->consent=$consent; $this->enabled=$enabled; $this->admit=$admit;
 }
 /** enabled():bool and admit(method):bool must return literal true.
  * Configure request gate too, preserving its pre-decode resource guard.
  */
 public function handle(array $server, array $post, array $files): array
 {
  $tokens=null;
  try {
   $method=$server['REQUEST_METHOD'] ?? '';
   if (!in_array($method,['GET','POST'],true)) return self::response(405,'unavailable');
   if (($this->enabled)()!==true) return self::response(404,'unavailable');
   if (($this->admit)($method)!==true) return self::response(429,'unavailable');
   if ($method==='GET') {
    $tokens=$this->forms->issue();
    return self::response(200,null,ShowcaseForm::intake($this->action,$this->csrf($tokens),$tokens['form_id'],$this->consent));
   }
   try { $tokens=$this->forms->resolve($post['form_id'] ?? null); }
   catch (\InvalidArgumentException $e) {return self::response(400,'expired');}
   $receipt=$this->request->submit($server,$post,$files,$this->csrf($tokens),$tokens['form_id']);
   return self::response(200,null,ShowcaseForm::receipt($receipt));
  } catch (\InvalidArgumentException $e) {
   // Do not echo submitted personal information or raw exception details.
   return self::response(400,'invalid_input');
  } catch (\DomainException $e) {
   return self::response(409,'conflict');
  } catch (\Throwable $e) {
   return self::response(503,'unavailable');
  }
 }
 private function csrf(array $tokens): string
 {
  return hash_hmac('sha256',$this->request->consentVersion().chr(0).$this->request->consentText(),$tokens['csrf_token']);
 }
 private static function response(int $status, ?string $error, string $body=''): array
 {
  $messages=['invalid_input'=>'Check the form fields and choose a JPEG or PNG photo again. No new submission has been confirmed.',
   'expired'=>'This form expired. Reload the page before trying again.',
   'conflict'=>'We could not save these details. Reload the page before trying again. If this continues, contact us.',
   'unavailable'=>'We could not confirm this submission. Keep this page and try again with the same details and photo.'];
  if ($error!==null) $body='<p role="alert">'.$messages[$error].'</p>';
  return ['status'=>$status,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'private, no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff'],'body'=>$body,'error'=>$error];
 }
}
