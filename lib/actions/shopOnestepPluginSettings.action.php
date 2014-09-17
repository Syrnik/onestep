<?php

class shopOnestepPluginSettingsAction extends waViewAction {

    protected $templates = array(
        'onestep' => array('name' => 'Главный шаблон', 'tpl_path' => 'plugins/onestep/templates/onestep.html'),
        'checkout' => array('name' => 'Шаблон оформления заказа (checkout.html)', 'tpl_path' => 'plugins/onestep/templates/checkout.html'),
    );
    protected $plugin_id = array('shop', 'onestep');

    public function execute() {
        $app_settings_model = new waAppSettingsModel();
        $settings = $app_settings_model->get($this->plugin_id);


        foreach ($this->templates as &$template) {
            $template['full_path'] = wa()->getDataPath($template['tpl_path'], false, 'shop', true);
            if (file_exists($template['full_path'])) {
                $template['change_tpl'] = true;
            } else {
                $template['full_path'] = wa()->getAppPath($template['tpl_path'], 'shop');
                $template['change_tpl'] = false;
            }
            $template['template'] = file_get_contents($template['full_path']);
        }
        unset($template);


        $this->view->assign('settings', $settings);
        $this->view->assign('templates', $this->templates);
    }

}