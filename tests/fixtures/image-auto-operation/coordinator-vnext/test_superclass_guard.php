<?php
declare(strict_types=1);
if(!class_exists('PDO')||!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"UNACCEPTED: PDO SQLite required\n");exit(2);}
// This separate process loads ONLY a deliberately changed synthetic copy, never mutates frozen source.
$root=getcwd().'/operation-superclass-guard-'.bin2hex(random_bytes(8));foreach([$root,$root.'/candidate',$root.'/candidate/src',$root.'/operation-v6',$root.'/coordinator-vnext']as$p)mkdir($p,0700);
foreach(['ImagePilot.php','ResponsiveImage.php','ImportedImage.php','ImageIntakeBinding.php','ImageSourceSelection.php','PosixImageIntakeStore.php','ImageSourceIntake.php']as$name)copy(__DIR__.'/../candidate/src/'.$name,$root.'/candidate/src/'.$name);
copy(__DIR__.'/../operation-v6/ImageOperationBinding.php',$root.'/operation-v6/ImageOperationBinding.php');copy(__DIR__.'/ImageOperationPilot.php',$root.'/coordinator-vnext/ImageOperationPilot.php');
$original=hash_file('sha256',__DIR__.'/../candidate/src/ImagePilot.php');file_put_contents($root.'/candidate/src/ImagePilot.php',"\n",FILE_APPEND);require $root.'/coordinator-vnext/ImageOperationPilot.php';
try{new \FAS\Utils\ImageOperationPilot(new PDO('sqlite::memory:'),[],'/missing-private','/missing-site',true);throw new RuntimeException('Drifted superclass accepted');}catch(RuntimeException$e){if($e->getMessage()!=='Pinned superclass effect boundaries changed')throw$e;}
if(hash_file('sha256',__DIR__.'/../candidate/src/ImagePilot.php')!==$original)throw new RuntimeException('Original changed');echo "PASS isolated superclass drift refusal; frozen source unchanged\n";
