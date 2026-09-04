<?php

declare(strict_types=1);
require_once __DIR__ . '/queue-worker-lib.php';
auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo; messenger_ensure_schema($pdo); messenger_require_channel_permission($pdo);
    $id=(int)($_GET['id']??0); if($id<=0) messenger_json(false,'Conversación inválida.',[],422);
    $conversation=messenger_conversation_row($pdo,$id); if(!$conversation) messenger_json(false,'La conversación ya no existe.',[],404);
    $syncMeta=isset($_GET['sync_meta'])&&(string)$_GET['sync_meta']==='1';
    $historySync=['ok'=>true,'imported'=>0,'error'=>'','attempted'=>false];
    if($syncMeta){$historySync=messenger_sync_conversation_history($pdo,$conversation,20);$historySync['attempted']=true;if((int)($historySync['imported']??0)>0)$conversation=messenger_conversation_row($pdo,$id)?:$conversation;
        if(empty($conversation['nombre_contacto'])&&!empty($conversation['psid'])){$profile=messenger_profile($pdo,(string)$conversation['psid']);if($profile){$pdo->prepare("UPDATE messenger_conversaciones SET nombre_contacto=:nombre,foto_perfil_url=:foto WHERE id=:id")->execute([':nombre'=>$profile['name'],':foto'=>$profile['profile_pic'],':id'=>$id]);$conversation=messenger_conversation_row($pdo,$id)?:$conversation;}}}

    $limit=max(20,min(100,(int)($_GET['limit']??60)));$beforeId=max(0,(int)($_GET['before_id']??0));$beforeTime=trim((string)($_GET['before_time']??''));$cursorSql='';$params=[':id'=>$id];
    if($beforeId>0&&$beforeTime!==''){$cursorSql=' AND (creado_en < :before_time_a OR (creado_en = :before_time_b AND id < :before_id))';$params[':before_time_a']=$beforeTime;$params[':before_time_b']=$beforeTime;$params[':before_id']=$beforeId;}
    $fetchLimit=$limit+1;
    $stmt=$pdo->prepare("SELECT m.*,u.nombre AS usuario_nombre FROM (SELECT id FROM messenger_mensajes WHERE conversacion_id=:id {$cursorSql}
        ORDER BY creado_en DESC,id DESC LIMIT {$fetchLimit}) recent INNER JOIN messenger_mensajes m ON m.id=recent.id LEFT JOIN usuarios_sistema u ON u.id=m.usuario_id ORDER BY m.creado_en ASC,m.id ASC");
    $stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$hasMore=count($rows)>$limit;if($hasMore)array_shift($rows);$messages=array_map('messenger_message_payload',$rows);
    if($beforeId<=0&&(int)$conversation['no_leidos']>0){$pdo->prepare("UPDATE messenger_conversaciones SET no_leidos=0 WHERE id=:id")->execute([':id'=>$id]);$conversation['no_leidos']=0;}
    $firstHumanSeconds=null;if(!empty($conversation['primer_mensaje_en'])&&!empty($conversation['primera_respuesta_humana_en'])){try{$firstHumanSeconds=max(0,(new DateTimeImmutable((string)$conversation['primera_respuesta_humana_en']))->getTimestamp()-(new DateTimeImmutable((string)$conversation['primer_mensaje_en']))->getTimestamp());}catch(Throwable $e){}}
    $conversation['id']=(int)$conversation['id'];$conversation['requiere_humano']=(int)$conversation['requiere_humano'];$conversation['asignado_a']=$conversation['asignado_a']!==null?(int)$conversation['asignado_a']:null;$conversation['no_leidos']=(int)$conversation['no_leidos'];
    $conversation['total_mensajes']=(int)$conversation['total_mensajes'];$conversation['total_entrantes']=(int)$conversation['total_entrantes'];$conversation['total_salientes']=(int)$conversation['total_salientes'];$conversation['tiempo_primera_respuesta_humana_seg']=$firstHumanSeconds;
    $window=messenger_send_window($pdo,$id);$oldest=$messages[0]??null;
    messenger_json(true,'',['canal'=>'messenger','conversacion'=>$conversation,'history_sync'=>$historySync,'mensajes'=>$messages,'historial'=>['has_more'=>$hasMore,'limit'=>$limit,'cursor'=>$oldest?['id'=>(int)$oldest['id'],'creado_en'=>(string)$oldest['creado_en']]:null],
        'ventana_24h'=>$window,'send_enabled'=>messenger_send_enabled(),'can_send_media'=>messenger_send_enabled()&&!empty($window['active']),'usuarios'=>auth_can_whatsapp('bandeja_gestionar')?messenger_users($pdo):[],
        'can_modify'=>auth_can_whatsapp_any(['bandeja_responder','bandeja_gestionar']),'can_reply'=>auth_can_whatsapp('bandeja_responder'),'can_manage'=>auth_can_whatsapp('bandeja_gestionar')]);
}catch(Throwable $e){error_log('Messenger conversation: '.$e->getMessage());messenger_json(false,'No se pudo abrir la conversación de Messenger.',[],500);}
