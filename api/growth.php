<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/growth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$method=$_SERVER['REQUEST_METHOD']??'';
if(!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST');http_response_code(405);echo '{"success":false,"error":"Method not allowed."}';exit; }
if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') { http_response_code(403);echo '{"success":false,"error":"Use the form on this website."}';exit; }
$origin=$_SERVER['HTTP_ORIGIN']??'';
if($origin!=='' && parse_url($origin,PHP_URL_HOST)!==parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)) {
    http_response_code(403);echo '{"success":false,"error":"Use the form on this website."}';exit;
}
if(session_status()===PHP_SESSION_NONE)session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax']);
if(empty($_SESSION['growth_csrf']))$_SESSION['growth_csrf']=bin2hex(random_bytes(32));
if(empty($_SESSION['growth_owner']))$_SESSION['growth_owner']=bin2hex(random_bytes(32));
if($method==='GET') {
    fas_security_guard('growth_session',true);
    echo json_encode(['success'=>true,'csrf'=>$_SESSION['growth_csrf']]);exit;
}
$input=json_decode(fas_security_body(),true);
if(!is_array($input) || !is_string($input['csrf']??null) || !hash_equals($_SESSION['growth_csrf'],$input['csrf'])) {
    http_response_code(403);echo '{"success":false,"error":"Refresh this page and try again."}';exit;
}
$action=$input['action']??'';
try {
    if(in_array($action,['confirm','unsubscribe','restore'],true)) {
        fas_security_guard('growth_token',true);
        $token=$input['token']??'';
        if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('This link is not valid.');
        $growth=fas_growth();
        if($action==='restore')echo json_encode(['success'=>true,'items'=>$growth->restore($token)]);
        else {
            $ok=$action==='confirm'?$growth->confirm($token):$growth->unsubscribe($token);
            if(!$ok)throw new InvalidArgumentException('This link has expired or has already been used.');
            echo json_encode(['success'=>true,'message'=>$action==='confirm'?'Your email signup is confirmed.':'You have been unsubscribed from optional emails.']);
        }
    } elseif($action==='signup') {
        if(($input['consent']??false)!==true)throw new InvalidArgumentException('Please confirm that you want to receive these emails.');
        $email=\FAS\Marketing\Growth::email($input['email']??null);
        $limit=fas_security_check(['newsletter'=>fas_security_ip()['ip'],'newsletter_email'=>$email]);
        if(!$limit['allowed'])fas_security_deny($limit);
        fas_growth()->signup($email);
        echo json_encode(['success'=>true,'message'=>'Thanks—your signup request has been saved.']);
    } elseif($action==='save_cart') {
        fas_security_guard('cart_save');
        if(($input['consent']??false)!==true)throw new InvalidArgumentException('Please confirm that you want to receive this cart reminder.');
        $email=\FAS\Marketing\Growth::email($input['email']??null);
        $sendNow=($input['send_now']??false)===true;
        if($sendNow) {
            $limit=fas_security_check(['cart_email'=>$email]);
            if(!$limit['allowed'])fas_security_deny($limit);
        }
        if(!is_array($input['items']??null))throw new InvalidArgumentException('Invalid cart.');
        fas_growth()->saveCart($_SESSION['growth_owner'],$email,$input['items'],$sendNow);
        echo json_encode(['success'=>true,'message'=>'Your cart and email preference have been saved.']);
    } elseif($action==='update_cart') {
        fas_security_guard('cart_save');
        if(!is_array($input['items']??null))throw new InvalidArgumentException('Invalid cart.');
        fas_growth()->updateCart($_SESSION['growth_owner'],$input['items']);
        echo json_encode(['success'=>true]);
    } elseif($action==='stop_cart') {
        fas_security_guard('cart_save',true);
        fas_growth()->stopCart($_SESSION['growth_owner']);
        echo json_encode(['success'=>true,'message'=>'Cart reminders are turned off for this cart.']);
    } else { throw new InvalidArgumentException('Unknown request.'); }
} catch(InvalidArgumentException $e) {
    http_response_code(422);echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
} catch(Throwable $e) {
    http_response_code(503);header('Retry-After: 30');echo '{"success":false,"error":"We could not save this request right now. Please try again shortly."}';
}
