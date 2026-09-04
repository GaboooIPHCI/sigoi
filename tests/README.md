# Pruebas S.I.G.O.I. v1.6.0

La CI valida la base que S.I.G.O.I. necesita mantener estable:

- sintaxis de todos los PHP usando PHP 7.4;
- sintaxis de todos los JavaScript;
- `composer.json` / `composer.lock`;
- ausencia de funciones básicas exclusivas de PHP 8 en el código PHP;
- ausencia de archivos privados de configuración en Git;
- ausencia de parches globales sobre `window.fetch`, `window.setInterval` o `MutationObserver`;
- versión estable `1.6.0`.

Estas pruebas son estáticas. Los webhooks y envíos reales de WhatsApp, Instagram y Messenger se validan en hosting porque dependen de credenciales privadas y de Meta/YCloud.
