<?php
/**
 * psmailspy 2.2.3: aviso de licencia: el cartel con el botón sale desde la primera vez.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_2_3($module)
{
    return $module->getUpdater()->registerHooks();
}
