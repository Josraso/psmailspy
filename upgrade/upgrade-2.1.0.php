<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.1.0: solo el hook actionEmailSendBefore, opciones nuevas (qué se copia y cómo)
 * y servidor de actualizaciones de josra.
 *
 * @param Psmailspy $module
 */
function upgrade_module_2_1_0($module)
{
    return $module->migrateFrom20();
}
