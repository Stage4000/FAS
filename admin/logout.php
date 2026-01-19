<?php
require_once __DIR__ . '/auth.php';

$auth = new AdminAuth();
$auth->logout();

header('Location: login.php');
exit;
