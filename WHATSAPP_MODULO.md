# Módulo WhatsApp - Fase 1

Esta versión incorpora el módulo interno para administrar un asistente de WhatsApp basado 100% en reglas, sin IA.

## Incluido

- Módulo `WhatsApp` dentro de **Gestión**.
- Acceso total para Admin y permiso ver/modificar para Marketing.
- Configuración de horario humano por día.
- Interruptor general del motor automático (apagado por defecto).
- Mensaje de bienvenida fuera de horario.
- Respuesta de respaldo cuando ninguna regla coincide.
- CRUD de reglas con prioridad, palabras/frases y respuesta exacta.
- Activar/desactivar reglas.
- Simulador de mensajes que permite probar coincidencias sin Meta.
- Tablas preparadas para conversaciones y mensajes de la futura integración.
- Indicadores de actividad preparados para la siguiente fase.

## Aún no incluido (Fase 2)

- Webhook de Meta.
- Envío real de mensajes por WhatsApp Cloud API.
- Coexistence / vinculación del número actual.

## Seguridad

El motor automático se instala APAGADO. No existe conexión con Meta en esta fase, por lo que ningún cliente puede recibir mensajes todavía.

## Instalación

Al subir el proyecto, `config/db.php` ejecuta `whatsapp_ensure_schema()` automáticamente. Si el hosting bloquea CREATE TABLE/ALTER, puede ejecutarse `database/whatsapp.sql` manualmente y luego recargar el sistema.
