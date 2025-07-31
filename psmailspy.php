<?php
/**
 * Mail Spy Module for PrestaShop 1.7+ and 8.x
 * Advanced email interceptor with professional interface
 * 
 * @author DevTeam
 * @version 2.0.0
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Psmailspy extends Module
{
    const CONFIG_EMAIL = 'PSMAILSPY_EMAIL';
    const CONFIG_ENABLED = 'PSMAILSPY_ENABLED';
    const CONFIG_PREFIX = 'PSMAILSPY_PREFIX';
    const CONFIG_LOG_ENABLED = 'PSMAILSPY_LOG_ENABLED';
    const CONFIG_MULTIPLE_EMAILS = 'PSMAILSPY_MULTIPLE_EMAILS';
    const CONFIG_FILTER_TEMPLATES = 'PSMAILSPY_FILTER_TEMPLATES';
    const CONFIG_SMTP_METHOD = 'PSMAILSPY_SMTP_METHOD';
    const CONFIG_DETECT_METHOD = 'PSMAILSPY_DETECT_METHOD';
    const CONFIG_FALLBACK_MODE = 'PSMAILSPY_FALLBACK_MODE';

    public function __construct()
    {
        $this->name = 'psmailspy';
        $this->tab = 'administration';
        $this->version = '2.0.0';
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
        $this->description = $this->l('Intercepta y reenvía emails salientes con configuración avanzada y logging.');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar Mail Spy Pro?');
    }

    public function install()
    {
        return parent::install()
            && $this->installConfiguration()
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('actionEmailSendAfter')
            && $this->registerHook('actionObjectMailMessageSendBefore')
            && $this->registerHook('actionObjectMailMessageSendAfter')
            && $this->registerHook('actionMailSend')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->createLogTable();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->uninstallConfiguration()
            && $this->dropLogTable();
    }

    private function installConfiguration()
    {
        return Configuration::updateValue(self::CONFIG_EMAIL, '')
            && Configuration::updateValue(self::CONFIG_ENABLED, true)
            && Configuration::updateValue(self::CONFIG_PREFIX, '[MAIL SPY]')
            && Configuration::updateValue(self::CONFIG_LOG_ENABLED, true)
            && Configuration::updateValue(self::CONFIG_MULTIPLE_EMAILS, '')
            && Configuration::updateValue(self::CONFIG_FILTER_TEMPLATES, '')
            && Configuration::updateValue(self::CONFIG_SMTP_METHOD, 'both')
            && Configuration::updateValue(self::CONFIG_DETECT_METHOD, true)
            && Configuration::updateValue(self::CONFIG_FALLBACK_MODE, true);
    }

    private function uninstallConfiguration()
    {
        return Configuration::deleteByName(self::CONFIG_EMAIL)
            && Configuration::deleteByName(self::CONFIG_ENABLED)
            && Configuration::deleteByName(self::CONFIG_PREFIX)
            && Configuration::deleteByName(self::CONFIG_LOG_ENABLED)
            && Configuration::deleteByName(self::CONFIG_MULTIPLE_EMAILS)
            && Configuration::deleteByName(self::CONFIG_FILTER_TEMPLATES)
            && Configuration::deleteByName(self::CONFIG_SMTP_METHOD)
            && Configuration::deleteByName(self::CONFIG_DETECT_METHOD)
            && Configuration::deleteByName(self::CONFIG_FALLBACK_MODE);
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

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitMailSpyConfig')) {
            if (!Tools::getToken(false)) {
                $output .= $this->displayError($this->l('Token de seguridad inválido.'));
            } else {
                $output .= $this->processForm();
            }
        }

        $output .= $this->displayStats();
        $output .= $this->displayForm();

        return $output;
    }

    private function processForm()
    {
        $errors = [];
        
        $email = Tools::getValue(self::CONFIG_EMAIL);
        if (!empty($email) && !Validate::isEmail($email)) {
            $errors[] = $this->l('Email principal no válido.');
        }

        $multipleEmails = Tools::getValue(self::CONFIG_MULTIPLE_EMAILS);
        if (!empty($multipleEmails)) {
            $emails = array_map('trim', explode(',', $multipleEmails));
            foreach ($emails as $emailItem) {
                if (!empty($emailItem) && !Validate::isEmail($emailItem)) {
                    $errors[] = sprintf($this->l('Email no válido: %s'), $emailItem);
                }
            }
        }

        $prefix = Tools::getValue(self::CONFIG_PREFIX);
        if (strlen($prefix) > 50) {
            $errors[] = $this->l('El prefijo no puede tener más de 50 caracteres.');
        }

        if (!empty($errors)) {
            return $this->displayError(implode('<br>', $errors));
        }

        $success = Configuration::updateValue(self::CONFIG_EMAIL, $email)
            && Configuration::updateValue(self::CONFIG_ENABLED, (bool)Tools::getValue(self::CONFIG_ENABLED))
            && Configuration::updateValue(self::CONFIG_PREFIX, $prefix)
            && Configuration::updateValue(self::CONFIG_LOG_ENABLED, (bool)Tools::getValue(self::CONFIG_LOG_ENABLED))
            && Configuration::updateValue(self::CONFIG_MULTIPLE_EMAILS, $multipleEmails)
            && Configuration::updateValue(self::CONFIG_FILTER_TEMPLATES, Tools::getValue(self::CONFIG_FILTER_TEMPLATES))
            && Configuration::updateValue(self::CONFIG_SMTP_METHOD, Tools::getValue(self::CONFIG_SMTP_METHOD))
            && Configuration::updateValue(self::CONFIG_DETECT_METHOD, (bool)Tools::getValue(self::CONFIG_DETECT_METHOD))
            && Configuration::updateValue(self::CONFIG_FALLBACK_MODE, (bool)Tools::getValue(self::CONFIG_FALLBACK_MODE));

        if ($success) {
            return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
        } else {
            return $this->displayError($this->l('Error al guardar la configuración.'));
        }
    }

    private function displayStats()
    {
        if (!Configuration::get(self::CONFIG_LOG_ENABLED)) {
            return '';
        }

        $stats = $this->getMailStats();
        
        $html = '<div class="panel">
            <div class="panel-heading">
                <i class="icon-bar-chart"></i> ' . $this->l('Estadísticas de Emails') . '
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="alert alert-info">
                            <h4>' . (int)$stats['total_today'] . '</h4>
                            <p>' . $this->l('Emails hoy') . '</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-success">
                            <h4>' . (int)$stats['total_week'] . '</h4>
                            <p>' . $this->l('Esta semana') . '</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-warning">
                            <h4>' . (int)$stats['total_month'] . '</h4>
                            <p>' . $this->l('Este mes') . '</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-danger">
                            <h4>' . (int)$stats['failed'] . '</h4>
                            <p>' . $this->l('Fallos recientes') . '</p>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <h5><i class="icon-gear"></i> ' . $this->l('Configuración detectada') . '</h5>
                            <p><strong>' . $this->l('Método de email') . ':</strong> ' . $this->getDetectedEmailMethod() . '</p>
                            <p><strong>' . $this->l('Hooks activos') . ':</strong> ' . $this->getActiveHooksInfo() . '</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>';

        return $html;
    }

    private function getMailStats()
    {
        $sql = 'SELECT 
                    SUM(CASE WHEN DATE(date_add) = CURDATE() THEN 1 ELSE 0 END) as total_today,
                    SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 1 WEEK) THEN 1 ELSE 0 END) as total_week,
                    SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) as total_month,
                    SUM(CASE WHEN status = "failed" AND date_add >= DATE_SUB(NOW(), INTERVAL 1 WEEK) THEN 1 ELSE 0 END) as failed
                FROM `' . _DB_PREFIX_ . 'mailspy_log`';
        
        $result = Db::getInstance()->getRow($sql);
        return $result ?: ['total_today' => 0, 'total_week' => 0, 'total_month' => 0, 'failed' => 0];
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
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activar Mail Spy'),
                        'name' => self::CONFIG_ENABLED,
                        'is_bool' => true,
                        'desc' => $this->l('Activar o desactivar el interceptor de emails.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Activado')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Desactivado')
                            ]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Email principal'),
                        'name' => self::CONFIG_EMAIL,
                        'size' => 50,
                        'desc' => $this->l('Email principal donde se enviarán todas las copias.'),
                        'required' => false,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Emails adicionales'),
                        'name' => self::CONFIG_MULTIPLE_EMAILS,
                        'rows' => 3,
                        'cols' => 50,
                        'desc' => $this->l('Emails adicionales separados por comas. Ejemplo: admin@example.com, dev@example.com'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Prefijo del asunto'),
                        'name' => self::CONFIG_PREFIX,
                        'size' => 30,
                        'desc' => $this->l('Prefijo que se añadirá al asunto de los emails interceptados.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Filtrar plantillas'),
                        'name' => self::CONFIG_FILTER_TEMPLATES,
                        'rows' => 3,
                        'cols' => 50,
                        'desc' => $this->l('Plantillas de email a interceptar separadas por comas. Dejar vacío para todas. Usar "custom" para emails sin plantilla. Ejemplo: order_conf, payment, new_order, custom'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Auto-detectar método'),
                        'name' => self::CONFIG_DETECT_METHOD,
                        'is_bool' => true,
                        'desc' => $this->l('Detectar automáticamente el método de envío de emails configurado en PrestaShop.'),
                        'values' => [
                            [
                                'id' => 'detect_on',
                                'value' => true,
                                'label' => $this->l('Activado')
                            ],
                            [
                                'id' => 'detect_off',
                                'value' => false,
                                'label' => $this->l('Desactivado')
                            ]
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Método interceptación SMTP'),
                        'name' => self::CONFIG_SMTP_METHOD,
                        'desc' => $this->l('Cuándo interceptar emails enviados vía SMTP. "Ambos" es la opción más compatible.'),
                        'options' => [
                            'query' => [
                                ['id' => 'before', 'name' => $this->l('Antes del envío SMTP')],
                                ['id' => 'after', 'name' => $this->l('Después del envío SMTP (solo exitosos)')],
                                ['id' => 'both', 'name' => $this->l('Ambos (máxima compatibilidad)')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Modo fallback'),
                        'name' => self::CONFIG_FALLBACK_MODE,
                        'is_bool' => true,
                        'desc' => $this->l('Activar hooks adicionales para máxima compatibilidad con módulos terceros.'),
                        'values' => [
                            [
                                'id' => 'fallback_on',
                                'value' => true,
                                'label' => $this->l('Activado (recomendado)')
                            ],
                            [
                                'id' => 'fallback_off',
                                'value' => false,
                                'label' => $this->l('Desactivado')
                            ]
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activar logging'),
                        'name' => self::CONFIG_LOG_ENABLED,
                        'is_bool' => true,
                        'desc' => $this->l('Registrar todos los emails interceptados en la base de datos.'),
                        'values' => [
                            [
                                'id' => 'log_on',
                                'value' => true,
                                'label' => $this->l('Activado')
                            ],
                            [
                                'id' => 'log_off',
                                'value' => false,
                                'label' => $this->l('Desactivado')
                            ]
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar configuración'),
                    'class' => 'btn btn-default pull-right',
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

        $helper->fields_value = $this->getConfigFormValues();

        return $helper->generateForm([$fieldsForm]);
    }

    private function getConfigFormValues()
    {
        return [
            self::CONFIG_EMAIL => Configuration::get(self::CONFIG_EMAIL),
            self::CONFIG_ENABLED => Configuration::get(self::CONFIG_ENABLED),
            self::CONFIG_PREFIX => Configuration::get(self::CONFIG_PREFIX),
            self::CONFIG_LOG_ENABLED => Configuration::get(self::CONFIG_LOG_ENABLED),
            self::CONFIG_MULTIPLE_EMAILS => Configuration::get(self::CONFIG_MULTIPLE_EMAILS),
            self::CONFIG_FILTER_TEMPLATES => Configuration::get(self::CONFIG_FILTER_TEMPLATES),
            self::CONFIG_SMTP_METHOD => Configuration::get(self::CONFIG_SMTP_METHOD),
            self::CONFIG_DETECT_METHOD => Configuration::get(self::CONFIG_DETECT_METHOD),
            self::CONFIG_FALLBACK_MODE => Configuration::get(self::CONFIG_FALLBACK_MODE),
        ];
    }

    public function hookActionEmailSendBefore($params)
    {
        // CRÍTICO: Prevenir bucle infinito - NO interceptar emails del propio Mail Spy
        if (!Configuration::get(self::CONFIG_ENABLED) || $this->isMailSpyEmail($params)) {
            return true;
        }

        try {
            $this->interceptEmail($params, 'before');
        } catch (Exception $e) {
            $this->logError('Error en hookActionEmailSendBefore: ' . $e->getMessage(), $params);
        }
        
        return true;
    }

    public function hookActionEmailSendAfter($params)
    {
        if (!Configuration::get(self::CONFIG_ENABLED) || $this->isMailSpyEmail($params)) {
            return true;
        }

        try {
            if (Configuration::get(self::CONFIG_LOG_ENABLED)) {
                $this->logEmailConfirmation($params);
            }
        } catch (Exception $e) {
            $this->logError('Error en hookActionEmailSendAfter: ' . $e->getMessage(), $params);
        }
        
        return true;
    }

    public function hookActionObjectMailMessageSendBefore($params)
    {
        if (!Configuration::get(self::CONFIG_ENABLED) || $this->isMailSpyEmail($params)) {
            return true;
        }

        $smtpMethod = Configuration::get(self::CONFIG_SMTP_METHOD);
        if ($smtpMethod === 'after') {
            return true;
        }

        try {
            $this->interceptSMTPEmail($params, 'before');
        } catch (Exception $e) {
            $this->logError('Error en hookActionObjectMailMessageSendBefore: ' . $e->getMessage(), $params);
        }
        
        return true;
    }

    public function hookActionObjectMailMessageSendAfter($params)
    {
        if (!Configuration::get(self::CONFIG_ENABLED) || $this->isMailSpyEmail($params)) {
            return true;
        }

        $smtpMethod = Configuration::get(self::CONFIG_SMTP_METHOD);
        if ($smtpMethod === 'before') {
            return true;
        }

        try {
            $this->interceptSMTPEmail($params, 'after');
        } catch (Exception $e) {
            $this->logError('Error en hookActionObjectMailMessageSendAfter: ' . $e->getMessage(), $params);
        }
        
        return true;
    }

    public function hookActionMailSend($params)
    {
        if (!Configuration::get(self::CONFIG_ENABLED) || !Configuration::get(self::CONFIG_FALLBACK_MODE) || $this->isMailSpyEmail($params)) {
            return true;
        }

        try {
            $params['_mailspy_source'] = 'actionMailSend';
            $this->interceptEmail($params, 'fallback');
        } catch (Exception $e) {
            $this->logError('Error en hookActionMailSend: ' . $e->getMessage(), $params);
        }
        
        return true;
    }

    public function hookDisplayBackOfficeHeader($params)
    {
        if (Tools::getValue('configure') !== $this->name) {
            return '';
        }

        return '<style>
            .mailspy-info { background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0; }
            .mailspy-status { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
            .mailspy-status.active { background: #28a745; color: white; }
            .mailspy-status.inactive { background: #dc3545; color: white; }
        </style>';
    }

    private function getDetectedEmailMethod()
    {
        $mailMethod = Configuration::get('PS_MAIL_METHOD');
        $smtpServer = Configuration::get('PS_MAIL_SERVER');
        
        switch ($mailMethod) {
            case '1':
                return $this->l('PHP mail() function');
            case '2':
                return $this->l('SMTP') . ($smtpServer ? ' (' . $smtpServer . ')' : '');
            case '3':
                return $this->l('Sendmail');
            default:
                return $this->l('Deshabilitado o no configurado');
        }
    }

    private function getActiveHooksInfo()
    {
        $hooks = [
            'actionEmailSendBefore' => 'Estándar',
            'actionEmailSendAfter' => 'Post-envío',
            'actionObjectMailMessageSendBefore' => 'SMTP Pre',
            'actionObjectMailMessageSendAfter' => 'SMTP Post',
            'actionMailSend' => 'Fallback'
        ];

        $activeHooks = [];
        foreach ($hooks as $hook => $name) {
            if ($this->isRegisteredInHook($hook)) {
                $activeHooks[] = $name;
            }
        }

        return implode(', ', $activeHooks);
    }

    private function isMailSpyEmail($params)
    {
        // Detectar SOLO si este email tiene nuestro prefijo en el asunto
        $mailParams = $this->normalizeEmailParams($params);
        
        if (empty($mailParams)) {
            return false;
        }

        // SOLO verificar asunto - no verificar destinatario porque puede ser legítimo
        $subject = isset($mailParams['subject']) ? $mailParams['subject'] : '';
        $prefix = Configuration::get(self::CONFIG_PREFIX) ?: '[MAIL SPY]';
        
        // Si ya tiene nuestro prefijo, es un email spy
        return strpos($subject, $prefix) !== false;
    }

    private function interceptEmail($params, $hookType = 'before')
    {
        try {
            static $processedEmails = [];
            
            $mailParams = $this->normalizeEmailParams($params);
            
            if (empty($mailParams)) {
                return;
            }

            // Verificar que hay contenido para interceptar
            if (!isset($mailParams['subject']) && !isset($mailParams['message']) && !isset($mailParams['template'])) {
                return;
            }

            // Hash MÁS SIMPLE para evitar duplicados solo del mismo email
            $subject = isset($mailParams['subject']) ? $mailParams['subject'] : 'no-subject';
            $recipient = isset($mailParams['to']) ? $mailParams['to'] : (isset($mailParams['toEmail']) ? $mailParams['toEmail'] : 'no-recipient');
            
            $emailHash = md5($subject . '|' . $recipient . '|' . time());

            // Solo evitar duplicados en la misma ejecución
            if (isset($processedEmails[$emailHash])) {
                return;
            }
            $processedEmails[$emailHash] = true;

            // Aplicar filtros
            $filterTemplates = Configuration::get(self::CONFIG_FILTER_TEMPLATES);
            if (!empty($filterTemplates)) {
                $allowedTemplates = array_map('trim', explode(',', $filterTemplates));
                $currentTemplate = isset($mailParams['template']) ? $mailParams['template'] : 'custom';
                
                if (empty($currentTemplate)) {
                    $currentTemplate = 'custom';
                }
                
                if (!in_array($currentTemplate, $allowedTemplates) && !in_array('custom', $allowedTemplates)) {
                    return;
                }
            }

            $spyEmails = $this->getSpyEmails();
            if (empty($spyEmails)) {
                return;
            }

            $prefix = Configuration::get(self::CONFIG_PREFIX) ?: '[MAIL SPY]';
            $originalSubject = isset($mailParams['subject']) ? $mailParams['subject'] : 'Sin asunto';
            $newSubject = $prefix . ' ' . $originalSubject;
            
            $originalRecipient = isset($mailParams['to']) ? $mailParams['to'] : 
                               (isset($mailParams['toEmail']) ? $mailParams['toEmail'] : 'Desconocido');
            
            // Enviar copias con el CONTENIDO ORIGINAL
            foreach ($spyEmails as $spyEmail) {
                $this->sendSpyEmailSafe($mailParams, $spyEmail, $newSubject, $originalRecipient);
            }

            if (Configuration::get(self::CONFIG_LOG_ENABLED)) {
                $this->logEmailSafe($mailParams, $spyEmails, 'sent');
            }

        } catch (Exception $e) {
            error_log('Mail Spy Intercept Error (non-critical): ' . $e->getMessage());
        }
    }

    private function normalizeEmailParams($params)
    {
        if (isset($params['params'])) {
            return $params['params'];
        }
        
        if (isset($params['subject']) || isset($params['to']) || isset($params['toEmail'])) {
            return $params;
        }
        
        foreach ($params as $key => $value) {
            if (is_array($value) && (isset($value['subject']) || isset($value['to']))) {
                return $value;
            }
        }
        
        return $params;
    }



    private function getSpyEmails()
    {
        $emails = [];
        
        $mainEmail = Configuration::get(self::CONFIG_EMAIL);
        if (!empty($mainEmail) && Validate::isEmail($mainEmail)) {
            $emails[] = $mainEmail;
        }

        $multipleEmails = Configuration::get(self::CONFIG_MULTIPLE_EMAILS);
        if (!empty($multipleEmails)) {
            $additionalEmails = array_map('trim', explode(',', $multipleEmails));
            foreach ($additionalEmails as $email) {
                if (!empty($email) && Validate::isEmail($email) && !in_array($email, $emails)) {
                    $emails[] = $email;
                }
            }
        }

        return $emails;
    }

    private function sendSpyEmailSafe($originalParams, $spyEmail, $subject, $originalRecipient)
    {
        try {
            // Flag simple para prevenir bucle inmediato
            static $sending = false;
            
            if ($sending) {
                return;
            }
            
            $sending = true;

            // PRESERVAR el contenido original exacto
            $templateVars = isset($originalParams['templateVars']) ? $originalParams['templateVars'] : [];
            
            // Añadir información del espía SIN sobreescribir variables originales
            $templateVars['{spy_original_recipient}'] = $originalRecipient;
            $templateVars['{spy_date}'] = date('Y-m-d H:i:s');
            
            // Si no hay templateVars, crear contenido básico
            if (empty($templateVars)) {
                $templateVars['{message}'] = "Email interceptado\nDestinatario original: " . $originalRecipient . "\nFecha: " . date('Y-m-d H:i:s');
                $templateVars['{email}'] = $spyEmail;
            }

            // Usar configuración del email original para preservar formato
            $langId = isset($originalParams['id_lang']) ? (int)$originalParams['id_lang'] : (int)Configuration::get('PS_LANG_DEFAULT');
            if ($langId <= 0) $langId = 1;
            
            $shopId = isset($originalParams['id_shop']) ? (int)$originalParams['id_shop'] : (int)Context::getContext()->shop->id;
            if ($shopId <= 0) $shopId = 1;

            // USAR EL MISMO TEMPLATE que el email original para preservar formato
            $template = isset($originalParams['template']) ? $originalParams['template'] : 'contact';
            
            // Verificar que el template existe
            $templatePath = _PS_MAIL_DIR_ . Language::getIsoById($langId) . '/' . $template;
            if (!file_exists($templatePath . '.html') && !file_exists($templatePath . '.txt')) {
                $template = 'contact'; // Fallback seguro
            }

            // Enviar con la misma configuración que el original
            $result = Mail::Send(
                $langId,
                $template,
                $subject,
                $templateVars,
                $spyEmail,
                null,
                isset($originalParams['from']) ? $originalParams['from'] : null,
                isset($originalParams['fromName']) ? $originalParams['fromName'] : null,
                isset($originalParams['fileAttachment']) ? $originalParams['fileAttachment'] : null,
                null,
                _PS_MAIL_DIR_,
                false,
                $shopId
            );

            $sending = false;

            if (!$result) {
                error_log('Mail Spy: Failed to send spy email to ' . $spyEmail);
            }

        } catch (Exception $e) {
            $sending = false;
            error_log('Mail Spy Send Error (non-critical): ' . $e->getMessage());
        }
    }

    private function logEmailSafe($params, $spyEmails, $status = 'sent', $errorMessage = null)
    {
        try {
            $recipient = isset($params['to']) ? $params['to'] : 
                        (isset($params['toEmail']) ? $params['toEmail'] : 'Desconocido');
            $subject = isset($params['subject']) ? $params['subject'] : 'Sin asunto';
            $template = isset($params['template']) ? $params['template'] : 'desconocido';

            // Truncar datos largos para evitar errores de DB
            $recipient = substr($recipient, 0, 250);
            $subject = substr($subject, 0, 490);
            $template = substr($template, 0, 90);

            Db::getInstance()->insert('mailspy_log', [
                'date_add' => date('Y-m-d H:i:s'),
                'recipient' => pSQL($recipient),
                'subject' => pSQL($subject), 
                'template' => pSQL($template),
                'status' => pSQL($status),
                'error_message' => $errorMessage ? pSQL(substr($errorMessage, 0, 500)) : null,
                'spy_recipients' => pSQL(substr(implode(', ', $spyEmails), 0, 500)),
            ]);
        } catch (Exception $e) {
            // Solo log, nunca interrumpir
            error_log('Mail Spy Log Error (non-critical): ' . $e->getMessage());
        }
    }

    private function interceptSMTPEmail($params, $timing = 'before')
    {
        try {
            $mailMessage = isset($params['mailMessage']) ? $params['mailMessage'] : null;
            
            if (!$mailMessage) {
                return; // Salir silenciosamente
            }

            // Extraer datos de forma segura
            $emailData = [
                'subject' => method_exists($mailMessage, 'getSubject') ? $mailMessage->getSubject() : 'Sin asunto SMTP',
                'to' => 'SMTP Recipient',
                'template' => 'smtp_email',
                'id_lang' => (int)Configuration::get('PS_LANG_DEFAULT') ?: 1,
                'id_shop' => (int)Context::getContext()->shop->id ?: 1,
            ];

            // Intentar obtener destinatarios de forma segura
            if (method_exists($mailMessage, 'getTo')) {
                try {
                    $toArray = $mailMessage->getTo();
                    if (is_array($toArray) && !empty($toArray)) {
                        $emailData['to'] = implode(', ', array_keys($toArray));
                    }
                } catch (Exception $e) {
                    // Mantener valor por defecto
                }
            }

            // Filtros (no críticos)
            $filterTemplates = Configuration::get(self::CONFIG_FILTER_TEMPLATES);
            if (!empty($filterTemplates)) {
                $allowedTemplates = array_map('trim', explode(',', $filterTemplates));
                if (!in_array('smtp_email', $allowedTemplates) && !in_array('custom', $allowedTemplates)) {
                    return;
                }
            }

            $spyEmails = $this->getSpyEmails();
            if (empty($spyEmails)) {
                return;
            }

            $prefix = Configuration::get(self::CONFIG_PREFIX) ?: '[MAIL SPY]';
            $newSubject = $prefix . ' [SMTP-' . strtoupper($timing) . '] ' . $emailData['subject'];

            // Envío seguro
            foreach ($spyEmails as $spyEmail) {
                $this->sendSMTPSpyEmailSafe($emailData, $spyEmail, $newSubject, $mailMessage);
            }

            // Logging seguro
            if (Configuration::get(self::CONFIG_LOG_ENABLED)) {
                $this->logEmailSafe($emailData, $spyEmails, 'sent');
            }

        } catch (Exception $e) {
            // Solo log, nunca interrumpir
            error_log('Mail Spy SMTP Intercept Error (non-critical): ' . $e->getMessage());
        }
    }

    private function sendSMTPSpyEmailSafe($emailData, $spyEmail, $subject, $originalMessage = null)
    {
        try {
            $message = "Email interceptado vía SMTP\n\n";
            $message .= "Destinatario original: " . $emailData['to'] . "\n";
            $message .= "Asunto original: " . str_replace(Configuration::get(self::CONFIG_PREFIX) ?: '[MAIL SPY]', '', $emailData['subject']) . "\n";
            $message .= "Método: SMTP\n";
            $message .= "Fecha: " . date('Y-m-d H:i:s') . "\n\n";
            
            // Contenido original (opcional y seguro)
            if ($originalMessage && method_exists($originalMessage, 'getBody')) {
                try {
                    $body = $originalMessage->getBody();
                    if (!empty($body)) {
                        $message .= "Contenido original:\n" . substr($body, 0, 1000) . (strlen($body) > 1000 ? '...' : '');
                    }
                } catch (Exception $e) {
                    $message .= "Contenido original: No disponible";
                }
            }

            // Envío con configuración mínima
            $result = Mail::Send(
                $emailData['id_lang'],
                'contact', // Template seguro
                $subject,
                [
                    '{email}' => $spyEmail,
                    '{message}' => $message,
                ],
                $spyEmail,
                null,
                null,
                null,
                null,
                null,
                _PS_MAIL_DIR_,
                false,
                $emailData['id_shop']
            );

            if (!$result) {
                error_log('Mail Spy: Failed to send SMTP spy email to ' . $spyEmail);
            }

        } catch (Exception $e) {
            error_log('Mail Spy SMTP Send Error (non-critical): ' . $e->getMessage());
        }
    }

    private function logEmailConfirmation($params)
    {
        try {
            $mailParams = $this->normalizeEmailParams($params);
            
            if (empty($mailParams)) {
                return;
            }

            $recipient = isset($mailParams['to']) ? $mailParams['to'] : 
                        (isset($mailParams['toEmail']) ? $mailParams['toEmail'] : 'Desconocido');
            
            $this->logEmailSafe($mailParams, [], 'confirmed', 'Email original confirmado: ' . $recipient);
        } catch (Exception $e) {
            error_log('Mail Spy Confirmation Log Error (non-critical): ' . $e->getMessage());
        }
    }

    private function logError($message, $params = [])
    {
        try {
            if (Configuration::get(self::CONFIG_LOG_ENABLED)) {
                $this->logEmailSafe($params, [], 'failed', $message);
            }
        } catch (Exception $e) {
            // Evitar bucles de errores
        }
        
        error_log('Mail Spy Error (non-critical): ' . $message);
    }
}