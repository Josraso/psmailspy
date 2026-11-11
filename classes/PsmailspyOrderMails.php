<?php
/**
 * Mail Spy - reenvío de los correos de un pedido a las direcciones configuradas en el módulo.
 *
 * Dos orígenes:
 *  - correos guardados: lo que la tienda envió de verdad (plantilla, asunto y datos), guardado en el momento;
 *  - correos regenerados: para pedidos anteriores, se vuelven a montar la confirmación del pedido y el correo
 *    de cada cambio de estado con los datos actuales del pedido.
 *
 * Nunca escribe al cliente: cada correo se envía directamente a las direcciones que recibe por parámetro.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PsmailspyOrderMails
{
    const TABLE = 'mailspy_store';

    /** @var Psmailspy */
    protected $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    public static function createTable()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_store` int(11) NOT NULL AUTO_INCREMENT,
            `id_order` int(11) NOT NULL,
            `date_add` datetime NOT NULL,
            `id_lang` int(11) NOT NULL DEFAULT 0,
            `id_shop` int(11) NOT NULL DEFAULT 0,
            `recipient` varchar(255) NOT NULL DEFAULT "",
            `template` varchar(100) NOT NULL,
            `subject` varchar(500) NOT NULL,
            `template_path` varchar(255) NOT NULL DEFAULT "",
            `vars` longtext,
            PRIMARY KEY (`id_store`),
            KEY `id_order` (`id_order`),
            KEY `date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;');
    }

    public static function dropTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /* ------------------------------------------------------------------ */
    /*  Guardar lo que se envía                                            */
    /* ------------------------------------------------------------------ */

    /** Pedido al que pertenece un correo, por sus variables ({id_order} o {order_name}); 0 si no es de un pedido */
    public static function orderIdFromVars(array $vars)
    {
        if (isset($vars['{id_order}']) && (int) $vars['{id_order}'] > 0) {
            return (int) $vars['{id_order}'];
        }
        if (isset($vars['{order_name}']) && is_string($vars['{order_name}']) && preg_match('/^[A-Za-z0-9]{6,32}/', trim($vars['{order_name}']), $m)) {
            return (int) Db::getInstance()->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL($m[0]) . '\' ORDER BY `id_order`');
        }

        return 0;
    }

    /** Guarda un correo de pedido tal como lo va a enviar PrestaShop (parámetros de actionEmailSendBefore) */
    public static function store(array $params, $days)
    {
        $vars = isset($params['templateVars']) && is_array($params['templateVars']) ? $params['templateVars'] : [];
        $idOrder = self::orderIdFromVars($vars);
        if (!$idOrder || empty($params['template'])) {
            return;
        }
        $path = isset($params['templatePath']) ? (string) $params['templatePath'] : '';
        if ($path !== '' && strpos($path, _PS_ROOT_DIR_) === 0) {
            // Relativa a la tienda: sigue valiendo si la tienda cambia de carpeta o de servidor
            $path = ltrim(Tools::substr($path, Tools::strlen(_PS_ROOT_DIR_)), '/\\');
        }
        $flags = defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0;
        $to = implode(', ', array_map('strval', (array) (isset($params['to']) ? $params['to'] : [])));

        Db::getInstance()->insert(self::TABLE, [
            'id_order' => (int) $idOrder,
            'date_add' => date('Y-m-d H:i:s'),
            'id_lang' => (int) (isset($params['idLang']) ? $params['idLang'] : 0),
            'id_shop' => (int) (isset($params['idShop']) ? $params['idShop'] : 0),
            'recipient' => pSQL(Tools::substr($to, 0, 250)),
            'template' => pSQL(Tools::substr((string) $params['template'], 0, 90)),
            'subject' => pSQL(Tools::substr(isset($params['subject']) ? (string) $params['subject'] : '', 0, 490)),
            'template_path' => pSQL(Tools::substr($path, 0, 250)),
            'vars' => pSQL((string) json_encode($vars, $flags), true),
        ]);

        if (mt_rand(1, 50) === 1) {
            Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `date_add` < DATE_SUB(NOW(), INTERVAL ' . max(1, (int) $days) . ' DAY)');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Buscar                                                             */
    /* ------------------------------------------------------------------ */

    /** Pedido por número (ID) o por referencia */
    public function findOrder($query)
    {
        $query = trim((string) $query);
        if ($query === '') {
            return null;
        }
        if (ctype_digit($query)) {
            $order = new Order((int) $query);
            if (Validate::isLoadedObject($order)) {
                return $order;
            }
        }
        $id = (int) Db::getInstance()->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL($query) . '\' ORDER BY `id_order`');
        $order = $id ? new Order($id) : null;

        return $order && Validate::isLoadedObject($order) ? $order : null;
    }

    public function latestOrders($limit = 10)
    {
        return (array) Db::getInstance()->executeS('SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`total_paid`, o.`id_currency`, c.`firstname`, c.`lastname`,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` s WHERE s.`id_order` = o.`id_order`) AS stored
            FROM `' . _DB_PREFIX_ . 'orders` o LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = o.`id_customer`)
            ORDER BY o.`id_order` DESC LIMIT ' . (int) $limit);
    }

    /** Correos guardados de un pedido, del primero al último */
    public function stored($idOrder)
    {
        return (array) Db::getInstance()->executeS('SELECT `id_store`, `date_add`, `recipient`, `template`, `subject` FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `id_order` = ' . (int) $idOrder . ' ORDER BY `id_store`');
    }

    /**
     * Correos que se pueden volver a generar: la confirmación y uno por cada cambio de estado que envía correo.
     *
     * @return array filas [key, date, template, label]
     */
    public function regenerable(Order $order)
    {
        $out = [[
            'key' => 'conf',
            'date' => $order->date_add,
            'template' => 'order_conf',
            'label' => $this->subjectConfirmation($order),
        ]];
        $rows = Db::getInstance()->executeS('SELECT oh.`id_order_history`, oh.`date_add`, osl.`template`, osl.`name`
            FROM `' . _DB_PREFIX_ . 'order_history` oh
            INNER JOIN `' . _DB_PREFIX_ . 'order_state` os ON (os.`id_order_state` = oh.`id_order_state` AND os.`send_email` = 1)
            INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON (osl.`id_order_state` = os.`id_order_state` AND osl.`id_lang` = ' . (int) $order->id_lang . ')
            WHERE oh.`id_order` = ' . (int) $order->id . ' AND osl.`template` <> \'\'
            ORDER BY oh.`date_add`, oh.`id_order_history`');
        foreach ((array) $rows as $r) {
            $out[] = ['key' => 'h' . (int) $r['id_order_history'], 'date' => $r['date_add'], 'template' => $r['template'], 'label' => $r['name']];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Enviar                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $key 's<ID>' guardado, 'conf' confirmación regenerada, 'h<ID>' cambio de estado regenerado
     * @param array $to direcciones del módulo
     *
     * @return true|string true o el motivo del fallo
     */
    public function send(Order $order, $key, array $to)
    {
        try {
            if (preg_match('/^s(\d+)$/', $key, $m)) {
                $mail = $this->mailFromStore((int) $m[1], $order);
            } elseif ($key === 'conf') {
                $mail = $this->mailConfirmation($order);
            } elseif (preg_match('/^h(\d+)$/', $key, $m)) {
                $mail = $this->mailHistory((int) $m[1], $order);
            } else {
                return 'Correo no válido.';
            }
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        if (!is_array($mail)) {
            return (string) $mail;
        }

        $prefix = trim((string) Configuration::get(Psmailspy::CONFIG_PREFIX));
        $subject = ($prefix !== '' ? $prefix . ' ' : '') . $mail['subject'];
        foreach ($to as $email) {
            $ok = Mail::send(
                (int) $mail['id_lang'],
                $mail['template'],
                $subject,
                $mail['vars'],
                $email,
                null,
                null,
                null,
                isset($mail['attachment']) ? $mail['attachment'] : null,
                null,
                $mail['path'],
                false,
                (int) $mail['id_shop']
            );
            if (!$ok) {
                return sprintf('PrestaShop no ha podido enviar «%s» (plantilla %s). Revisa Parámetros avanzados > Email.', $mail['subject'], $mail['template']);
            }
        }

        return true;
    }

    protected function mailFromStore($idStore, Order $order)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_store` = ' . (int) $idStore . ' AND `id_order` = ' . (int) $order->id);
        if (!$row) {
            return 'Ese correo guardado ya no existe.';
        }
        $vars = json_decode((string) $row['vars'], true);
        $path = (string) $row['template_path'];
        if ($path === '') {
            $path = _PS_MAIL_DIR_;
        } elseif ($path[0] !== '/' && !preg_match('/^[A-Za-z]:/', $path)) {
            $path = rtrim(_PS_ROOT_DIR_, '/\\') . '/' . $path;
        }

        return [
            'id_lang' => (int) $row['id_lang'] ?: (int) $order->id_lang,
            'id_shop' => (int) $row['id_shop'] ?: (int) $order->id_shop,
            'template' => $row['template'],
            'subject' => $row['subject'],
            'vars' => is_array($vars) ? $vars : [],
            'path' => $path,
        ];
    }

    /** Correo de un cambio de estado, con los mismos datos que usa PrestaShop (OrderHistory::sendEmail) */
    protected function mailHistory($idHistory, Order $order)
    {
        $r = Db::getInstance()->getRow('SELECT osl.`template`, osl.`name`, os.`module_name`, os.`pdf_invoice`, os.`pdf_delivery`
            FROM `' . _DB_PREFIX_ . 'order_history` oh
            INNER JOIN `' . _DB_PREFIX_ . 'order_state` os ON (os.`id_order_state` = oh.`id_order_state`)
            INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON (osl.`id_order_state` = os.`id_order_state` AND osl.`id_lang` = ' . (int) $order->id_lang . ')
            WHERE oh.`id_order_history` = ' . (int) $idHistory . ' AND oh.`id_order` = ' . (int) $order->id);
        if (!$r || (string) $r['template'] === '') {
            return 'Ese cambio de estado no tiene plantilla de correo.';
        }
        $customer = new Customer((int) $order->id_customer);
        $carrier = new Carrier((int) $order->id_carrier, (int) $order->id_lang);
        $tracking = (string) $this->shippingNumber($order);
        $vars = [
            '{lastname}' => $customer->lastname,
            '{firstname}' => $customer->firstname,
            '{id_order}' => (int) $order->id,
            '{order_name}' => $order->getUniqReference(),
            '{followup}' => Validate::isLoadedObject($carrier) ? str_replace('@', $tracking, (string) $carrier->url) : '',
            '{shipping_number}' => $tracking,
            '{total_paid}' => $this->price($order->total_paid, $order),
        ];
        if ($r['module_name']) {
            $module = Module::getInstanceByName($r['module_name']);
            if (Validate::isLoadedObject($module) && isset($module->extra_mail_vars) && is_array($module->extra_mail_vars)) {
                $vars = array_merge($vars, $module->extra_mail_vars);
            }
        }

        return [
            'id_lang' => (int) $order->id_lang,
            'id_shop' => (int) $order->id_shop,
            'template' => $r['template'],
            'subject' => $r['name'],
            'vars' => $vars,
            'path' => _PS_MAIL_DIR_,
            'attachment' => $this->pdfAttachments($order, (bool) $r['pdf_invoice'], (bool) $r['pdf_delivery']),
        ];
    }

    /** Factura y albarán adjuntos, como hace PrestaShop en los estados que los llevan */
    protected function pdfAttachments(Order $order, $invoice, $delivery)
    {
        if (!$invoice && !$delivery) {
            return null;
        }
        $files = [];
        try {
            $context = Context::getContext();
            $collection = $order->getInvoicesCollection();
            if ($invoice && (int) Configuration::get('PS_INVOICE') && $order->invoice_number) {
                $pdf = new PDF($collection, PDF::TEMPLATE_INVOICE, $context->smarty);
                $files['invoice'] = ['content' => $pdf->render(false), 'name' => $pdf->getFilename(), 'mime' => 'application/pdf'];
            }
            if ($delivery && $order->delivery_number) {
                $pdf = new PDF($collection, PDF::TEMPLATE_DELIVERY_SLIP, $context->smarty);
                $files['delivery'] = ['content' => $pdf->render(false), 'name' => $pdf->getFilename(), 'mime' => 'application/pdf'];
            }
        } catch (Exception $e) {
            // Sin adjuntos antes que sin correo
        } catch (Throwable $e) {
        }

        return $files ?: null;
    }

    /** Confirmación del pedido, montada como en PaymentModule::validateOrder con los datos actuales del pedido */
    protected function mailConfirmation(Order $order)
    {
        $customer = new Customer((int) $order->id_customer);
        $lang = new Language((int) $order->id_lang);
        $delivery = new Address((int) $order->id_address_delivery);
        $invoice = new Address((int) $order->id_address_invoice);
        $taxExcl = Product::getTaxCalculationMethod((int) $order->id_customer) == PS_TAX_EXC;

        $products = [];
        foreach ((array) $order->getProducts() as $p) {
            $unit = $taxExcl ? $p['unit_price_tax_excl'] : $p['unit_price_tax_incl'];
            $total = $taxExcl ? $p['total_price_tax_excl'] : $p['total_price_tax_incl'];
            $products[] = [
                'id_product' => (int) $p['product_id'],
                'id_product_attribute' => (int) $p['product_attribute_id'],
                'reference' => $p['product_reference'],
                'name' => $p['product_name'],
                'price' => $this->price($total, $order),
                'quantity' => (int) $p['product_quantity'],
                'customization' => [],
                'unit_price' => $this->price($unit, $order),
                'unit_price_full' => $this->price($unit, $order),
            ];
        }
        $rules = [];
        foreach ((array) $order->getCartRules() as $rule) {
            $value = $taxExcl ? $rule['value_tax_excl'] : $rule['value'];
            $rules[] = ['voucher_name' => $rule['name'], 'voucher_reduction' => ((float) $value != 0.0 ? '-' : '') . $this->price($value, $order)];
        }

        $bold = ['firstname' => '<span style="font-weight:bold;">%s</span>', 'lastname' => '<span style="font-weight:bold;">%s</span>'];
        $state = function (Address $a) {
            if (!$a->id_state) {
                return '';
            }
            $s = new State((int) $a->id_state);

            return (string) $s->name;
        };
        $carrier = new Carrier((int) $order->id_carrier, (int) $order->id_lang);

        $vars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{email}' => $customer->email,
            '{delivery_block_txt}' => AddressFormat::generateAddress($delivery, ['avoid' => []], "\n", ' ', []),
            '{invoice_block_txt}' => AddressFormat::generateAddress($invoice, ['avoid' => []], "\n", ' ', []),
            '{delivery_block_html}' => AddressFormat::generateAddress($delivery, ['avoid' => []], '<br />', ' ', $bold),
            '{invoice_block_html}' => AddressFormat::generateAddress($invoice, ['avoid' => []], '<br />', ' ', $bold),
            '{delivery_company}' => $delivery->company,
            '{delivery_firstname}' => $delivery->firstname,
            '{delivery_lastname}' => $delivery->lastname,
            '{delivery_address1}' => $delivery->address1,
            '{delivery_address2}' => $delivery->address2,
            '{delivery_city}' => $delivery->city,
            '{delivery_postal_code}' => $delivery->postcode,
            '{delivery_country}' => $delivery->country,
            '{delivery_state}' => $state($delivery),
            '{delivery_phone}' => $delivery->phone ? $delivery->phone : $delivery->phone_mobile,
            '{delivery_other}' => $delivery->other,
            '{invoice_company}' => $invoice->company,
            '{invoice_vat_number}' => $invoice->vat_number,
            '{invoice_firstname}' => $invoice->firstname,
            '{invoice_lastname}' => $invoice->lastname,
            '{invoice_address2}' => $invoice->address2,
            '{invoice_address1}' => $invoice->address1,
            '{invoice_city}' => $invoice->city,
            '{invoice_postal_code}' => $invoice->postcode,
            '{invoice_country}' => $invoice->country,
            '{invoice_state}' => $state($invoice),
            '{invoice_phone}' => $invoice->phone ? $invoice->phone : $invoice->phone_mobile,
            '{invoice_other}' => $invoice->other,
            '{order_name}' => $order->getUniqReference(),
            '{id_order}' => (int) $order->id,
            '{date}' => date('d/m/Y H:i:s', strtotime($order->date_add)),
            '{carrier}' => (!$order->isVirtual() && Validate::isLoadedObject($carrier)) ? $carrier->name : '',
            '{payment}' => Tools::substr($order->payment, 0, 255),
            '{products}' => $this->partial('order_conf_product_list.tpl', $lang, $products),
            '{products_txt}' => $this->partial('order_conf_product_list.txt', $lang, $products),
            '{discounts}' => $this->partial('order_conf_cart_rules.tpl', $lang, $rules),
            '{discounts_txt}' => $this->partial('order_conf_cart_rules.txt', $lang, $rules),
            '{total_paid}' => $this->price($order->total_paid, $order),
            '{total_paid_tax_excl}' => $this->price($order->total_paid_tax_excl, $order),
            '{total_shipping_tax_excl}' => $this->price($order->total_shipping_tax_excl, $order),
            '{total_shipping_tax_incl}' => $this->price($order->total_shipping_tax_incl, $order),
            '{total_tax_paid}' => $this->price($order->total_paid_tax_incl - $order->total_paid_tax_excl, $order),
            '{recycled_packaging_label}' => $order->recyclable ? 'Sí' : 'No',
            '{message}' => (string) $order->getFirstMessage(),
            '{total_products}' => $this->price($taxExcl ? $order->total_products : $order->total_products_wt, $order),
            '{total_discounts}' => $this->price($taxExcl ? $order->total_discounts_tax_excl : $order->total_discounts, $order),
            '{total_shipping}' => $this->price($taxExcl ? $order->total_shipping_tax_excl : $order->total_shipping, $order),
            '{total_wrapping}' => $this->price($taxExcl ? $order->total_wrapping_tax_excl : $order->total_wrapping, $order),
        ];

        return [
            'id_lang' => (int) $order->id_lang,
            'id_shop' => (int) $order->id_shop,
            'template' => 'order_conf',
            'subject' => $this->subjectConfirmation($order),
            'vars' => $vars,
            'path' => _PS_MAIL_DIR_,
        ];
    }

    protected function subjectConfirmation(Order $order)
    {
        try {
            $lang = new Language((int) $order->id_lang);
            $translator = Context::getContext()->getTranslator();
            if ($translator && !empty($lang->locale)) {
                $subject = (string) $translator->trans('Order confirmation', [], 'Emails.Subject', $lang->locale);
                if ($subject !== 'Order confirmation' || $lang->iso_code !== 'es') {
                    return $subject;
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }

        return 'Confirmación de pedido';
    }

    /** Lista de productos o de descuentos con la plantilla parcial del tema o de PrestaShop */
    protected function partial($name, Language $lang, array $list)
    {
        if (!$list) {
            return '';
        }
        $smarty = Context::getContext()->smarty;
        $class = 'PrestaShop\\PrestaShop\\Adapter\\MailTemplate\\MailPartialTemplateRenderer';
        if (class_exists($class)) {
            $renderer = new $class($smarty);

            return (string) $renderer->render($name, $lang, $list);
        }
        $iso = $lang->iso_code;
        foreach ([_PS_THEME_DIR_ . 'mails/' . $iso . '/', _PS_THEME_DIR_ . 'mails/en/', _PS_MAIL_DIR_ . $iso . '/', _PS_MAIL_DIR_ . 'en/', _PS_MAIL_DIR_ . '_partials/'] as $dir) {
            if (is_file($dir . $name)) {
                $smarty->assign('list', $list);

                return (string) $smarty->fetch($dir . $name);
            }
        }

        return '';
    }

    protected function price($value, Order $order)
    {
        $context = Context::getContext();
        $currency = new Currency((int) $order->id_currency);
        if (method_exists('Tools', 'getContextLocale')) {
            return Tools::getContextLocale($context)->formatPrice((float) $value, $currency->iso_code);
        }

        return Tools::displayPrice((float) $value, $currency);
    }

    protected function shippingNumber(Order $order)
    {
        if (method_exists($order, 'getShippingNumber')) {
            return $order->getShippingNumber();
        }
        if (method_exists($order, 'getWsShippingNumber')) {
            return $order->getWsShippingNumber();
        }

        return isset($order->shipping_number) ? $order->shipping_number : '';
    }
}
