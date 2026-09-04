# S.I.G.O.I. v1.6.0

## Estabilización multicanal

- Bandeja consolidada para WhatsApp, Instagram y Messenger.
- Permisos de Messenger integrados al flujo principal.
- Eliminación de parches globales sobre `fetch`, `setInterval` y observers de DOM usados durante la integración inicial.
- Polling y renderizado más controlados, conservando selección y scroll.

## Rendimiento

- Paginación SQL de conversaciones.
- Carga progresiva de mensajes antiguos.
- Búsqueda con menos consultas redundantes.
- Sincronización de historial Messenger limitada y controlada.

## Colas

- Procesamiento por cron.
- Reintentos con backoff y estado final de fallo.
- Limpieza de eventos y archivos temporales antiguos.
- Heartbeat operativo de los tres canales.

## Seguridad y diagnóstico

- Estado del sistema para administración.
- Errores internos registrados en servidor sin exponer detalles al navegador.
- Controles de tamaño para multimedia remota.
- Token de Messenger enviado mediante cabecera Authorization.
- Compatibilidad mantenida con PHP 7.4.33.

## Analítica

- WhatsApp + Instagram + Messenger sobre un mismo registro de canales.
- Sin métricas individuales por agente.
- Corrección de tiempos de respuesta en el límite de periodo.
- Menos comprobaciones repetitivas de esquema.
- Timeout de frontend para evitar cargas indefinidas.

## Calidad

- GitHub Actions para lint PHP 7.4, sintaxis JavaScript, Composer y comprobaciones estáticas de seguridad/compatibilidad.
