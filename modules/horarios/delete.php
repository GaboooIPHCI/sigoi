<?php
require_once __DIR__ . '/_helpers.php';
auth_require_module_modify('horarios'); auth_validate_csrf();
try {
    $data=horarios_input(); $id=(int)($data['id']??0); if($id<=0) horarios_json(false,'Horario inválido.',[],422);
    $stmt=$pdo->prepare('DELETE FROM horarios_medicos WHERE id=:id'); $stmt->execute([':id'=>$id]);
    if(!$stmt->rowCount()) horarios_json(false,'El horario ya no existe.',[],404);
    auth_audit($pdo,'horario_eliminado',auth_user_id(),auth_username(),'ID '.$id); horarios_json(true,'Horario eliminado.');
} catch(Throwable $e){ horarios_json(false,'No se pudo eliminar el horario.',[],500); }
