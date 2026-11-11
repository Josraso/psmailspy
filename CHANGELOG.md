# Changelog - psmailspy

## 2.2.0 - 2026-11-11
- Nuevo bloque «Reenviarme los correos de un pedido»: buscas el pedido por número o referencia (o lo eliges entre
  los últimos) y te reenvías sus correos, de uno en uno o todos. Van solo a los correos configurados en el módulo,
  nunca al cliente.
  - Correos guardados: desde esta versión el módulo guarda cada correo de pedido que envía la tienda (confirmación,
    pago, envío, los de módulos…) y te lo reenvía tal como salió. Sin adjuntos.
  - Volver a generar: para pedidos anteriores, monta de nuevo la confirmación del pedido y el correo de cada cambio
    de estado con los datos actuales del pedido.
- Opción para guardar o no los correos de pedidos y días que se conservan (90 por defecto).
- El correo de prueba va ahora directamente y solo a los correos configurados. En la 2.1.0 se enviaba al correo de
  la tienda con copia a los configurados.

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
