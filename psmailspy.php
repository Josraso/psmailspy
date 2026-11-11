<?php
/**
 * Mail Spy Pro - copia de los correos que envía la tienda
 *
 * Envía a los correos que configures una copia de lo que la tienda manda a los clientes
 * (todos los correos, solo los de pedidos o solo unas plantillas) para comprobar cómo llegan.
 *
 * Dos formas de copiar:
 *  - copia oculta (CCO): el mismo correo que recibe el cliente, idéntico, con sus adjuntos;
 *  - reenvío aparte: un segundo correo con un prefijo en el asunto.
 *
 * Usa solo el hook actionEmailSendBefore de PrestaShop (sin overrides). PrestaShop 1.7 a 9.
 *
 * @author DevTeam / josra
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/PsmailspyUpdater.php';

class Psmailspy extends Module
{
    const CONFIG_EMAIL = 'PSMAILSPY_EMAIL';
    const CONFIG_ENABLED = 'PSMAILSPY_ENABLED';
    const CONFIG_PREFIX = 'PSMAILSPY_PREFIX';
    const CONFIG_LOG_ENABLED = 'PSMAILSPY_LOG_ENABLED';
    const CONFIG_MULTIPLE_EMAILS = 'PSMAILSPY_MULTIPLE_EMAILS';
    const CONFIG_FILTER_TEMPLATES = 'PSMAILSPY_FILTER_TEMPLATES';
    /** Qué correos se copian: all | orders | list */
    const CONFIG_SCOPE = 'PSMAILSPY_SCOPE';
    /** Cómo se copian: bcc | resend */
    const CONFIG_MODE = 'PSMAILSPY_MODE';
    /** Días que se guarda el registro */
    const CONFIG_LOG_DAYS = 'PSMAILSPY_LOG_DAYS';

    /** Opciones de versiones anteriores que ya no se usan */
    const OLD_KEYS = 'PSMAILSPY_SMTP_METHOD,PSMAILSPY_DETECT_METHOD,PSMAILSPY_FALLBACK_MODE';

    /** Hooks que registraba la 2.0.0 y que PrestaShop no tiene */
    const OLD_HOOKS = 'actionEmailSendAfter,actionObjectMailMessageSendBefore,actionObjectMailMessageSendAfter,actionMailSend,displayBackOfficeHeader';

    /** Para no copiar la copia cuando se reenvía aparte */
    private static $sending = false;

    public function __construct()
    {
        $this->name = 'psmailspy';
        $this->tab = 'administration';
        $this->version = '2.1.0';
        $this->author = 'DevTeam';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;
        $this->module_key = '';

        parent::__construct();

        $this->displayName = $this->l('Mail Spy Pro');
        $this->description = $this->l('Envía a tu correo una copia de los emails que manda la tienda (todos, solo los de pedidos o las plantillas que elijas) para comprobar cómo llegan.');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar Mail Spy Pro?');
    }

    /* ------------------------------------------------------------------ */
    /*  Instalación                                                        */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        return parent::install()
            && $this->installConfiguration()
            && $this->registerHook('actionEmailSendBefore')
            && $this->getUpdater()->registerHooks()
            && $this->createLogTable();
    }

    public function uninstall()
    {
        // Aviso al servidor de actualizaciones (no impide desinstalar si no responde)
        $this->getUpdater()->notifyUninstall();

        return parent::uninstall()
            && $this->uninstallConfiguration()
            && $this->dropLogTable();
    }

    public static function defaults()
    {
        return [
            self::CONFIG_EMAIL => '',
            self::CONFIG_ENABLED => 1,
            self::CONFIG_PREFIX => '[MAIL SPY]',
            self::CONFIG_LOG_ENABLED => 1,
            self::CONFIG_MULTIPLE_EMAILS => '',
            self::CONFIG_FILTER_TEMPLATES => '',
            self::CONFIG_SCOPE => 'all',
            self::CONFIG_MODE => 'bcc',
            self::CONFIG_LOG_DAYS => 30,
        ];
    }

    private function installConfiguration()
    {
        foreach (self::defaults() as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        return true;
    }

    private function uninstallConfiguration()
    {
        foreach (array_merge(array_keys(self::defaults()), explode(',', self::OLD_KEYS), ['PSMAILSPY_UPD_TS', 'PSMAILSPY_UPD_INFO']) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    private function createLogTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mailspy_log` (
            `id_log` int(11) NOT NULL AUTO_INCREMENT,
            `date_add` datetime NOT NULL,
            `recipient` varchar(255) NOT NULL,
            `subject` varchar(500) NOT NULL,
            `template` varchar(100) NOT NULL,
            `status` enum("sent", "failed", "confirmed") NOT NULL DEFAULT "sent",
            `error_message` text,
            `spy_recipients` text,
            PRIMARY KEY (`id_log`),
            KEY `date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    private function dropLogTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mailspy_log`');
    }

    /**
     * De la 2.0.0 a la 2.1.0: quita los hooks que PrestaShop no tiene y las opciones que no hacían nada,
     * y deja el alcance como estaba (todas las plantillas, o las de la lista si había filtro).
     */
    public function migrateFrom20()
    {
        foreach (explode(',', self::OLD_HOOKS) as $hook) {
            if ($this->isRegisteredInHook($hook)) {
                $this->unregisterHook($hook);
            }
        }
        foreach (explode(',', self::OLD_KEYS) as $key) {
            Configuration::deleteByName($key);
        }
        if (Configuration::get(self::CONFIG_SCOPE) === false) {
            Configuration::updateValue(self::CONFIG_SCOPE, trim((string) Configuration::get(self::CONFIG_FILTER_TEMPLATES)) !== '' ? 'list' : 'all');
        }
        if (Configuration::get(self::CONFIG_MODE) === false) {
            Configuration::updateValue(self::CONFIG_MODE, 'bcc');
        }
        if (Configuration::get(self::CONFIG_LOG_DAYS) === false) {
            Configuration::updateValue(self::CONFIG_LOG_DAYS, 30);
        }
        // El registro anterior guardaba filas «confirmed» y «failed» de hooks que nunca se ejecutaban
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'mailspy_log` WHERE `status` <> "sent"');

        return $this->createLogTable()
            && ($this->isRegisteredInHook('actionEmailSendBefore') || $this->registerHook('actionEmailSendBefore'))
            && $this->getUpdater()->registerHooks();
    }

    /* ------------------------------------------------------------------ */
    /*  Actualizaciones                                                    */
    /* ------------------------------------------------------------------ */

    public function getUpdater()
    {
        return new PsmailspyUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    /* ------------------------------------------------------------------ */
    /*  Copia de los correos                                               */
    /* ------------------------------------------------------------------ */

    /**
     * PrestaShop llama aquí antes de enviar cada correo. Los parámetros van por referencia.
     * Siempre devuelve true: si devolviera false, PrestaShop no enviaría el correo.
     */
    public function hookActionEmailSendBefore($params)
    {
        try {
            if (Configuration::get(self::CONFIG_ENABLED) && !self::$sending) {
                $this->copyEmail($params);
            }
        } catch (Exception $e) {
            error_log('Mail Spy: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('Mail Spy: ' . $e->getMessage());
        }

        return true;
    }

    private function copyEmail(&$params)
    {
        $template = isset($params['template']) ? (string) $params['template'] : '';
        $vars = isset($params['templateVars']) && is_array($params['templateVars']) ? $params['templateVars'] : [];
        if (!$this->inScope($template, $vars)) {
            return;
        }

        // No se manda copia a quien ya recibe el correo
        $recipients = [];
        foreach ((array) (isset($params['to']) ? $params['to'] : []) as $to) {
            $recipients[] = Tools::strtolower(trim((string) $to));
        }
        $spies = [];
        foreach ($this->getSpyEmails() as $email) {
            if (!in_array(Tools::strtolower($email), $recipients, true)) {
                $spies[] = $email;
            }
        }
        if (!$spies) {
            return;
        }

        $mode = Configuration::get(self::CONFIG_MODE) === 'resend' || !array_key_exists('bcc', $params) ? 'resend' : 'bcc';
        if ($mode === 'bcc') {
            $bcc = [];
            foreach ((array) $params['bcc'] as $addr) {
                if (is_string($addr) && trim($addr) !== '') {
                    $bcc[] = trim($addr);
                }
            }
            foreach ($spies as $email) {
                if (!in_array($email, $bcc, true)) {
                    $bcc[] = $email;
                }
            }
            $params['bcc'] = $bcc;
        } else {
            $this->resend($params, $spies);
        }

        if (Configuration::get(self::CONFIG_LOG_ENABLED)) {
            $this->logEmail($params, $spies, $mode);
        }
    }

    /** Plantillas de pedidos de PrestaShop y de los módulos de pago y avisos habituales */
    public static function orderTemplates()
    {
        return ['order_conf', 'payment', 'payment_error', 'bankwire', 'cheque', 'cashondelivery', 'preparation', 'shipped', 'in_transit',
            'order_canceled', 'refund', 'outofstock', 'order_changed', 'order_merchandise_return', 'order_return_state', 'credit_slip',
            'download_product', 'order_customer_comment', 'order_merchant_comment', 'new_order', 'return_slip', 'backoffice_order'];
    }

    private function inScope($template, array $vars)
    {
        $scope = (string) Configuration::get(self::CONFIG_SCOPE);
        if ($scope === 'orders') {
            // Por plantilla o porque el correo lleva el número o la referencia de un pedido
            return in_array($template, self::orderTemplates(), true)
                || isset($vars['{order_name}']) || isset($vars['{id_order}']);
        }
        if ($scope === 'list') {
            $list = array_filter(array_map('trim', explode(',', (string) Configuration::get(self::CONFIG_FILTER_TEMPLATES))));

            return !$list || in_array($template, $list, true);
        }

        return true;
    }

    private function getSpyEmails()
    {
        $emails = [];
        $all = Configuration::get(self::CONFIG_EMAIL) . ',' . Configuration::get(self::CONFIG_MULTIPLE_EMAILS);
        foreach (preg_split('/[,;\s]+/', (string) $all) as $email) {
            $email = trim($email);
            if ($email !== '' && Validate::isEmail($email) && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /** Reenvío aparte: el mismo correo otra vez, a cada dirección, con el prefijo en el asunto */
    private function resend(array $params, array $spies)
    {
        $prefix = trim((string) Configuration::get(self::CONFIG_PREFIX));
        $subject = ($prefix !== '' ? $prefix . ' ' : '') . (isset($params['subject']) ? $params['subject'] : '');
        $get = function ($key, $default = null) use ($params) {
            return isset($params[$key]) ? $params[$key] : $default;
        };

        self::$sending = true;
        try {
            foreach ($spies as $email) {
                Mail::send(
                    (int) $get('idLang', Configuration::get('PS_LANG_DEFAULT')),
                    $get('template'),
                    $subject,
                    $get('templateVars', []),
                    $email,
                    null,
                    $get('from'),
                    $get('fromName'),
                    $get('fileAttachment'),
                    $get('mode_smtp'),
                    $get('templatePath', _PS_MAIL_DIR_),
                    false,
                    $get('idShop')
                );
            }
        } catch (Exception $e) {
            error_log('Mail Spy: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('Mail Spy: ' . $e->getMessage());
        }
        self::$sending = false;
    }

    private function logEmail(array $params, array $spies, $mode)
    {
        try {
            $to = implode(', ', array_map('strval', (array) (isset($params['to']) ? $params['to'] : [])));
            Db::getInstance()->insert('mailspy_log', [
                'date_add' => date('Y-m-d H:i:s'),
                'recipient' => pSQL(Tools::substr($to, 0, 250)),
                'subject' => pSQL(Tools::substr(isset($params['subject']) ? (string) $params['subject'] : '', 0, 490)),
                'template' => pSQL(Tools::substr(isset($params['template']) ? (string) $params['template'] : '', 0, 90)),
                'status' => 'sent',
                'error_message' => pSQL($mode === 'bcc' ? 'cco' : 'reenvio'),
                'spy_recipients' => pSQL(Tools::substr(implode(', ', $spies), 0, 500)),
            ]);
            // Limpieza de vez en cuando
            if (mt_rand(1, 50) === 1) {
                $days = max(1, (int) Configuration::get(self::CONFIG_LOG_DAYS));
                Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'mailspy_log` WHERE `date_add` < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
            }
        } catch (Exception $e) {
            error_log('Mail Spy: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Configuración                                                      */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $updater = $this->getUpdater();
        $output = $updater->processRequest();

        if (Tools::isSubmit('submitMailSpyConfig')) {
            $output .= $this->processForm();
        }
        if (Tools::isSubmit('submitMailSpyTest')) {
            $output .= $this->sendTest();
        }
        if (Tools::isSubmit('submitMailSpyClear')) {
            Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'mailspy_log`');
            $output .= $this->displayConfirmation($this->l('Registro vaciado.'));
        }

        if (Configuration::get(self::CONFIG_ENABLED) && !$this->getSpyEmails()) {
            $output .= $this->displayWarning($this->l('El módulo está activado pero no hay ningún correo configurado: no se envía ninguna copia.'));
        }

        $info = $updater->getInfo();
        $panel = $updater->renderPanel();
        $forms = $this->displayStats() . $this->displayForm() . $this->displayLog();

        return !empty($info['update_available'])
            ? $output . $panel . $forms
            : $output . $forms . $panel;
    }

    private function processForm()
    {
        $errors = [];

        $email = trim((string) Tools::getValue(self::CONFIG_EMAIL));
        if ($email !== '' && !Validate::isEmail($email)) {
            $errors[] = $this->l('Email principal no válido.');
        }

        $multipleEmails = trim((string) Tools::getValue(self::CONFIG_MULTIPLE_EMAILS));
        foreach (preg_split('/[,;\s]+/', $multipleEmails) as $emailItem) {
            if ($emailItem !== '' && !Validate::isEmail($emailItem)) {
                $errors[] = sprintf($this->l('Email no válido: %s'), Tools::safeOutput($emailItem));
            }
        }

        $prefix = (string) Tools::getValue(self::CONFIG_PREFIX);
        if (Tools::strlen($prefix) > 50) {
            $errors[] = $this->l('El prefijo no puede tener más de 50 caracteres.');
        }

        if (!empty($errors)) {
            return $this->displayError(implode('<br>', $errors));
        }

        $scope = (string) Tools::getValue(self::CONFIG_SCOPE);
        $mode = (string) Tools::getValue(self::CONFIG_MODE);
        Configuration::updateValue(self::CONFIG_EMAIL, $email);
        Configuration::updateValue(self::CONFIG_ENABLED, (int) (bool) Tools::getValue(self::CONFIG_ENABLED));
        Configuration::updateValue(self::CONFIG_PREFIX, $prefix);
        Configuration::updateValue(self::CONFIG_LOG_ENABLED, (int) (bool) Tools::getValue(self::CONFIG_LOG_ENABLED));
        Configuration::updateValue(self::CONFIG_MULTIPLE_EMAILS, $multipleEmails);
        Configuration::updateValue(self::CONFIG_FILTER_TEMPLATES, trim((string) Tools::getValue(self::CONFIG_FILTER_TEMPLATES)));
        Configuration::updateValue(self::CONFIG_SCOPE, in_array($scope, ['all', 'orders', 'list'], true) ? $scope : 'all');
        Configuration::updateValue(self::CONFIG_MODE, $mode === 'resend' ? 'resend' : 'bcc');
        Configuration::updateValue(self::CONFIG_LOG_DAYS, max(1, min(365, (int) Tools::getValue(self::CONFIG_LOG_DAYS))));

        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    /** Correo de prueba a un cliente ficticio: la copia tiene que llegar a los correos configurados */
    private function sendTest()
    {
        $spies = $this->getSpyEmails();
        if (!Configuration::get(self::CONFIG_ENABLED) || !$spies) {
            return $this->displayError($this->l('Activa el módulo y guarda al menos un correo antes de probar.'));
        }
        $shopEmail = (string) Configuration::get('PS_SHOP_EMAIL');
        if (in_array(Tools::strtolower($shopEmail), array_map('strtolower', $spies), true)) {
            return $this->displayWarning($this->l('El correo de la tienda es uno de los configurados para recibir copias, y no se manda copia a quien ya recibe el correo. Para esta prueba, pon otra dirección.'));
        }
        // Se envía al correo de la tienda con una plantilla de pedido, para que valga con cualquier alcance
        $ok = Mail::send(
            (int) $this->context->language->id,
            'order_customer_comment',
            $this->l('Prueba de Mail Spy'),
            ['{firstname}' => 'Mail', '{lastname}' => 'Spy', '{email}' => $shopEmail, '{id_order}' => 0, '{order_name}' => 'PRUEBA',
                '{message}' => $this->l('Este es un correo de prueba de Mail Spy. Si lo lees en tu correo de copias, el módulo funciona.')],
            $shopEmail,
            null,
            null,
            null,
            null,
            null,
            _PS_MAIL_DIR_,
            false,
            (int) $this->context->shop->id
        );

        return $ok
            ? $this->displayConfirmation(sprintf($this->l('Correo de prueba enviado a %1$s. La copia tiene que llegar a: %2$s.'), Tools::safeOutput($shopEmail), Tools::safeOutput(implode(', ', $spies))))
            : $this->displayError($this->l('PrestaShop no ha podido enviar el correo de prueba. Revisa Parámetros avanzados > Email.'));
    }

    private function displayStats()
    {
        if (!Configuration::get(self::CONFIG_LOG_ENABLED)) {
            return '';
        }

        $stats = $this->getMailStats();
        $box = function ($class, $value, $label) {
            return '<div class="col-md-3"><div class="alert alert-' . $class . '"><h4>' . (int) $value . '</h4><p>' . $label . '</p></div></div>';
        };

        return '<div class="panel">
            <div class="panel-heading"><i class="icon-bar-chart"></i> ' . $this->l('Correos copiados') . '</div>
            <div class="row">'
                . $box('info', $stats['total_today'], $this->l('Hoy'))
                . $box('success', $stats['total_week'], $this->l('Últimos 7 días'))
                . $box('warning', $stats['total_month'], $this->l('Últimos 30 días'))
                . $box('info', $stats['orders_week'], $this->l('De pedidos, últimos 7 días'))
            . '</div>
            <p class="help-block">' . $this->l('Método de envío de la tienda') . ': <strong>' . $this->getDetectedEmailMethod() . '</strong></p>
        </div>';
    }

    private function getMailStats()
    {
        $in = '"' . implode('","', array_map('pSQL', self::orderTemplates())) . '"';
        $sql = 'SELECT
                    SUM(CASE WHEN DATE(date_add) = CURDATE() THEN 1 ELSE 0 END) as total_today,
                    SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 1 WEEK) THEN 1 ELSE 0 END) as total_week,
                    SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) as total_month,
                    SUM(CASE WHEN template IN (' . $in . ') AND date_add >= DATE_SUB(NOW(), INTERVAL 1 WEEK) THEN 1 ELSE 0 END) as orders_week
                FROM `' . _DB_PREFIX_ . 'mailspy_log` WHERE `status` = "sent"';

        $result = Db::getInstance()->getRow($sql);

        return $result ?: ['total_today' => 0, 'total_week' => 0, 'total_month' => 0, 'orders_week' => 0];
    }

    private function getDetectedEmailMethod()
    {
        switch ((string) Configuration::get('PS_MAIL_METHOD')) {
            case '1':
                return $this->l('función mail() de PHP / sendmail');
            case '2':
                $server = (string) Configuration::get('PS_MAIL_SERVER');

                return $this->l('SMTP') . ($server !== '' ? ' (' . Tools::safeOutput($server) . ')' : '');
            default:
                return $this->l('desactivado: la tienda no envía correos');
        }
    }

    private function switchField($name, $label, $desc)
    {
        return [
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'is_bool' => true,
            'desc' => $desc,
            'values' => [
                ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Sí')],
                ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
            ],
        ];
    }

    private function displayForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración Mail Spy'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    $this->switchField(self::CONFIG_ENABLED, $this->l('Activar Mail Spy'), $this->l('Enviar copia de los correos de la tienda.')),
                    [
                        'type' => 'text',
                        'label' => $this->l('Email principal'),
                        'name' => self::CONFIG_EMAIL,
                        'desc' => $this->l('Correo donde llegarán las copias.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Emails adicionales'),
                        'name' => self::CONFIG_MULTIPLE_EMAILS,
                        'rows' => 2,
                        'cols' => 50,
                        'desc' => $this->l('Separados por comas. Ejemplo: admin@example.com, dev@example.com'),
                    ],
                    [
                        'type' => 'radio',
                        'label' => $this->l('Qué correos se copian'),
                        'name' => self::CONFIG_SCOPE,
                        'values' => [
                            ['id' => 'scope_all', 'value' => 'all', 'label' => $this->l('Todos los que envía la tienda')],
                            ['id' => 'scope_orders', 'value' => 'orders', 'label' => $this->l('Solo los de pedidos (confirmación, pago, envío, cambios de estado, devoluciones…)')],
                            ['id' => 'scope_list', 'value' => 'list', 'label' => $this->l('Solo las plantillas de la lista de abajo')],
                        ],
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Plantillas'),
                        'name' => self::CONFIG_FILTER_TEMPLATES,
                        'rows' => 2,
                        'cols' => 50,
                        'desc' => $this->l('Solo se usa con la tercera opción. Nombres de plantilla separados por comas. Ejemplo: order_conf, payment, shipped. El nombre de cada plantilla sale en el registro de abajo.'),
                    ],
                    [
                        'type' => 'radio',
                        'label' => $this->l('Cómo se envía la copia'),
                        'name' => self::CONFIG_MODE,
                        'desc' => $this->l('La copia oculta es exactamente el correo del cliente: mismo asunto, mismo contenido y mismos adjuntos, y en el «Para» ves a quién iba. El reenvío aparte es un segundo correo solo para ti, con el prefijo en el asunto para distinguirlo.'),
                        'values' => [
                            ['id' => 'mode_bcc', 'value' => 'bcc', 'label' => $this->l('Copia oculta (CCO) del mismo correo (recomendado)')],
                            ['id' => 'mode_resend', 'value' => 'resend', 'label' => $this->l('Reenvío aparte con prefijo en el asunto')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Prefijo del asunto'),
                        'name' => self::CONFIG_PREFIX,
                        'class' => 'fixed-width-xl',
                        'desc' => $this->l('Solo para el reenvío aparte.'),
                    ],
                    $this->switchField(self::CONFIG_LOG_ENABLED, $this->l('Guardar registro'), $this->l('Anota cada correo copiado: fecha, destinatario, asunto y plantilla.')),
                    [
                        'type' => 'text',
                        'label' => $this->l('Conservar el registro'),
                        'name' => self::CONFIG_LOG_DAYS,
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('días'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar configuración'),
                    'name' => 'submitMailSpyConfig',
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'name' => 'submitMailSpyTest',
                        'title' => $this->l('Enviar un correo de prueba'),
                        'icon' => 'process-icon-envelope',
                        'class' => 'btn btn-default pull-right',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMailSpyConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $values = [];
        foreach (self::defaults() as $key => $default) {
            $value = Configuration::get($key);
            $values[$key] = $value === false ? $default : $value;
        }
        $helper->fields_value = $values;

        return $helper->generateForm([$fieldsForm]);
    }

    private function displayLog()
    {
        if (!Configuration::get(self::CONFIG_LOG_ENABLED)) {
            return '';
        }
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'mailspy_log` WHERE `status` = "sent" ORDER BY `id_log` DESC LIMIT 50');
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-list"></i> ' . $this->l('Últimos correos copiados') . '</div>';
        if (!$rows) {
            return $html . '<p class="text-muted">' . $this->l('Todavía no se ha copiado ningún correo.') . '</p></div>';
        }
        $html .= '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>' . $this->l('Fecha') . '</th><th>' . $this->l('Para') . '</th><th>'
            . $this->l('Asunto') . '</th><th>' . $this->l('Plantilla') . '</th><th>' . $this->l('Copia a') . '</th><th>' . $this->l('Modo') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr><td style="white-space:nowrap">' . Tools::safeOutput(date('d/m/Y H:i', strtotime($r['date_add']))) . '</td><td>' . Tools::safeOutput($r['recipient']) . '</td><td>'
                . Tools::safeOutput($r['subject']) . '</td><td><code>' . Tools::safeOutput($r['template']) . '</code></td><td>' . Tools::safeOutput($r['spy_recipients']) . '</td><td>'
                . ($r['error_message'] === 'reenvio' ? $this->l('Reenvío') : $this->l('CCO')) . '</td></tr>';
        }
        $url = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name;

        return $html . '</tbody></table></div>'
            . '<form method="post" action="' . Tools::safeOutput($url) . '" class="panel-footer"><button type="submit" name="submitMailSpyClear" class="btn btn-default" onclick="return confirm(\''
            . addslashes($this->l('¿Vaciar el registro?')) . '\');"><i class="icon-trash"></i> ' . $this->l('Vaciar el registro') . '</button></form></div>';
    }
}
