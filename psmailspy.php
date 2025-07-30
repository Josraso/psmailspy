<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Psmailspy extends Module
{
    public function __construct()
    {
        $this->name = 'psmailspy';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'ChatGPT';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Mail Spy');
        $this->description = $this->l('Reenvía todos los correos salientes a una dirección específica.');
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue('PSMAILSPY_EMAIL', '')
            && $this->registerHook('actionEmailSendBefore');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('PSMAILSPY_EMAIL');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit_psmailspy')) {
            $email = Tools::getValue('PSMAILSPY_EMAIL');
            if (!Validate::isEmail($email)) {
                $output .= $this->displayError($this->l('Dirección de correo no válida.'));
            } else {
                Configuration::updateValue('PSMAILSPY_EMAIL', $email);
                $output .= $this->displayConfirmation($this->l('Configuración guardada.'));
            }
        }

        $emailValue = Configuration::get('PSMAILSPY_EMAIL');

        $output .= '<form method="post">
            <label for="PSMAILSPY_EMAIL">' . $this->l('Correo de destino') . '</label><br>
            <input type="email" name="PSMAILSPY_EMAIL" value="' . htmlspecialchars($emailValue) . '" style="width:300px;" required><br><br>
            <button type="submit" name="submit_psmailspy" class="btn btn-primary">' . $this->l('Guardar') . '</button>
        </form>';

        return $output;
    }

    public function hookActionEmailSendBefore($params)
    {
        $spyEmail = Configuration::get('PSMAILSPY_EMAIL');
        if (Validate::isEmail($spyEmail)) {
            // Enviar copia al correo configurado
            $mailParams = $params['params'];
            Mail::Send(
                (int) $mailParams['id_lang'],
                $mailParams['template'],
                '[COPIA] ' . $mailParams['subject'],
                $mailParams['templateVars'],
                $spyEmail,
                null,
                null,
                null,
                null,
                null,
                _PS_MAIL_DIR_,
                false,
                (int) $mailParams['id_shop']
            );
        }
    }
}
