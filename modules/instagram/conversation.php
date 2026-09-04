<?php

declare(strict_types=1);
require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_ver'); auth_require_whatsapp_channel('instagram');

try {
    global $pdo; instagram_ensure_schema($pdo);
    $id=(int)($_GET['id']??0); if($id<=0) instagram_json(false,'Conversación inválida.',[],422);
    $conversation=ig_inbox_conversation_row($pdo,$id); if(!$conversation) instagram_json(false,'La conversación ya no existe.',[],404);
    $beforeId=max(0,(int)($_GET['before_id']??0)); $beforeTime=trim((string)($_GET['before_time']??''));
    if ($beforeId<=0 && (string)($_GET['repair']??'')==='1') ig_repair_conversation_texts($pdo,$id);
    $limit=max(20,min(100,(int)($_GET['limit']??60))); $cursorSql=''; $params=[':id'=>$id];
    if($beforeId>0 && $beforeTime!=='') { $cursorSql=' AND (creado_en < :before_time_a OR (creado_en = :before_time_b AND id < :before_id))'; $params[':before_time_a']=$beforeTime; $params[':before_time_b']=$beforeTime; $params[':before_id']=$beforeId; }
    $fetchLimit=$limit+1;
    $stmt=$pdo->prepare("SELECT m.*, u.nombre AS usuario_nombre FROM (SELECT id FROM instagram_mensajes WHERE conversacion_id=:id {$cursorSql}
        ORDER BY creado_en DESC,id DESC LIMIT {$fetchLimit}) recent INNER JOIN instagram_mensajes m ON m.id=recent.id
        LEFT JOIN usuarios_sistema u ON u.id=m.usuario_id ORDER BY m.creado_en ASC,m.id ASC");
    $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC); $hasMore=count($rows)>$limit; if($hasMore) array_shift($rows);
    $messages=array_map('ig_inbox_message_payload',$rows);
    if($beforeId<=0 && (int)$conversation['no_leidos']>0){$pdo->prepare("UPDATE instagram_conversaciones SET no_leidos=0 WHERE id=:id")->execute([':id'=>$id]);$conversation['no_leidos']=0;}
    $firstHumanSeconds=null; if(!empty($conversation['primer_mensaje_en'])&&!empty($conversation['primera_respuesta_humana_en'])){try{$firstHumanSeconds=max(0,(new DateTimeImmutable((string)$conversation['primera_respuesta_humana_en']))->getTimestamp()-(new DateTimeImmutable((string)$conversation['primer_mensaje_en']))->getTimestamp());}catch(Throwable $e){}}
    $conversation['id']=(int)$conversation['id'];$conversation['requiere_humano']=(int)$conversation['requiere_humano'];$conversation['asignado_a']=$conversation['asignado_a']!==null?(int)$conversation['asignado_a']:null;$conversation['no_leidos']=(int)$conversation['no_leidos'];
    $conversation['total_mensajes']=(int)$conversation['total_mensajes'];$conversation['total_entrantes']=(int)$conversation['total_entrantes'];$conversation['total_salientes']=(int)$conversation['total_salientes'];$conversation['tiempo_primera_respuesta_humana_seg']=$firstHumanSeconds;$conversation['telefono']=null;
    $window=ig_inbox_send_window($pdo,$id);$oldest=$messages[0]??null;
    instagram_json(true,'',['canal'=>'instagram','conversacion'=>$conversation,'mensajes'=>$messages,'historial'=>['has_more'=>$hasMore,'limit'=>$limit,'cursor'=>$oldest?['id'=>(int)$oldest['id'],'creado_en'=>(string)$oldest['creado_en']]:null],
        'ventana_24h'=>$window,'send_enabled'=>ig_inbox_send_enabled(),'can_send_media'=>ig_inbox_send_enabled()&&!empty($window['active']),'usuarios'=>auth_can_whatsapp('bandeja_gestionar')?ig_inbox_users($pdo):[],
        'can_modify'=>auth_can_whatsapp_any(['bandeja_responder','bandeja_gestionar']),'can_reply'=>auth_can_whatsapp('bandeja_responder'),'can_manage'=>auth_can_whatsapp('bandeja_gestionar')]);
}catch(Throwable $e){error_log('Instagram conversation: '.$e->getMessage());instagram_json(false,'No se pudo abrir la conversación de Instagram.',[],500);}
