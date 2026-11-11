# Changelog - psmailspy

## 2.1.0 - 2026-11-11
- Nueva opción «Qué correos se copian»: todos, solo los de pedidos (confirmación, pago, envío, cambios de estado,
  devoluciones…) o solo las plantillas de una lista.
- Nueva opción «Cómo se envía la copia»: copia oculta (CCO) del mismo correo que recibe el cliente, idéntico y con
  sus adjuntos, o reenvío aparte con prefijo en el asunto. La copia oculta pasa a ser la forma por defecto.
- El reenvío aparte usa ahora la plantilla y el idioma reales del correo, también en correos de módulos. Antes los
  correos de módulos llegaban con la plantilla de contacto.
- Botón «Enviar un correo de prueba» y tabla con los últimos 50 correos copiados (fecha, destinatario, asunto,
  plantilla y a quién fue la copia). El registro se limpia solo a los días que se configuren.
- Se quitan las opciones «Auto-detectar método», «Método interceptación SMTP» y «Modo fallback»: dependían de hooks
  que PrestaShop no tiene y no hacían nada. El módulo usa solo `actionEmailSendBefore`.
- No se manda copia a una dirección que ya es destinataria del correo.
- Conectado al servidor de actualizaciones de josra.

## 2.0.0
- Versión anterior.
