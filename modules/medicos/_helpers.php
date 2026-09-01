<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
function medicos_json(bool $ok,string $message='',array $extra=[],int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(array_merge(['success'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE);exit;}
function medicos_input():array{$j=json_decode((string)file_get_contents('php://input'),true);return is_array($j)?$j:$_POST;}
function medicos_text($v,int $max=255):string{$s=trim((string)($v??''));return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max);}
function medicos_parse_specialties(?string $raw):array{$out=[];foreach(explode(';;',(string)$raw) as $part){if($part==='')continue;$p=explode('|',$part,3);$out[]=['id'=>(int)($p[0]??0),'nombre'=>$p[1]??'','principal'=>(int)($p[2]??0)];}return $out;}
