<?php

require_once '../v8env.php';

$setting = new \V8Env\Setting(['addLoader'=>true, 'addRequire'=>true]);
$setting->addFile('lib.js');
$env = $setting->makeContext('that')->makeEnv();

try
{
  $out = $env->runFile('script.js', ['foo'=>'bar', 'bar'=>'world']);
}
catch (V8JsException $e)
{
  var_dump($e);
}

echo json_encode($out)."\n";
