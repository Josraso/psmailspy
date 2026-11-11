<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.2.0: tabla para guardar los correos de pedidos y poder reenviarlos.
 *
 * @param Psmailspy $module
 */
function upgrade_module_2_2_0($module)
{
    if (Configuration::get(Psmailspy::CONFIG_STORE) === false) {
        Configuration::updateValue(Psmailspy::CONFIG_STORE, 1);
    }
    if (Configuration::get(Psmailspy::CONFIG_STORE_DAYS) === false) {
        Configuration::updateValue(Psmailspy::CONFIG_STORE_DAYS, 90);
    }

    return PsmailspyOrderMails::createTable() && $module->getUpdater()->registerHooks();
}
