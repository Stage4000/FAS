<?php
// Synthetic CLI-server fixture only. Never install in a public document root.
$key=getenv('SHOWCASE_FIXTURE_KEY');
if (PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'],true) || !is_string($key) || strlen($key)!==64 || !is_string($_SERVER['HTTP_X_FIXTURE_KEY'] ?? null) || !hash_equals($key, $_SERVER['HTTP_X_FIXTURE_KEY'])) {http_response_code(404);exit;}
require __DIR__.'/../src/showcase/ShowcaseSessionForms.php';
session_start();
$now=(int)($_SERVER['HTTP_X_FIXTURE_TIME'] ?? 1000);
try {
 if ($_SERVER['REQUEST_METHOD']==='GET') {$_SESSION['forms'] ??= [];}
 if (!isset($_SESSION['forms'])) {throw new InvalidArgumentException('No forms');}
 $pool=new FAS\Showcase\ShowcaseSessionForms($_SESSION['forms'],10,2,fn()=>$now);
 $value=$_SERVER['REQUEST_METHOD']==='GET' ? $pool->issue() : $pool->resolve($_POST['form_id'] ?? null);
 echo json_encode($value);
} catch (Throwable $e) {http_response_code(400);echo json_encode(['count'=>count($_SESSION['forms'] ?? [])]);}
