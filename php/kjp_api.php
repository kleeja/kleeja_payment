<?php

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

class KJP
{
    /**
     * to get kleeja_payment plugin info
     * before using this class, check if(defined('support_kjPay')){ then use it }
     */
    public static function info()
    {
        global $SQL, $dbprefix;

        $KJP_INFO = Plugins::getInstance()->installed_plugin_info('kleeja_payment');

        // get the id from the DB
        $result = $SQL->build([
            'SELECT' => 'p.plg_id',
            'FROM' => "{$dbprefix}plugins p",
            'WHERE' => 'p.plg_name = :name',
            'BIND' => ['name' => 'kleeja_payment'],
        ]);

        $row = $SQL->fetch_array($result);
        $SQL->freeresult($result);

        $KJP_INFO['plugin_id'] = (int) ($row['plg_id'] ?? 0);

        return $KJP_INFO;
    }

    /*
     * if another plugins used this plugin , it need to use some langes for user interface
     * but when the admin don't need that plugin , the plugin will remove the langs from DB
     * and KJP plugin can not display the payment info
     * so give me the langs , and i know when it have to be deleted
     */
    public static function addLang(array $KJP_langs, $language = 'ar')
    {
        global $olang;

        $KJP_ID = self::info()['plugin_id'];

        $new_langs = [];

        // insert every lang that is not exists
        foreach ($KJP_langs as $word => $translate) {
            if (! isset($olang[$word])) {
                $new_langs[$word] = $translate;
            }
        }

        add_olang($new_langs, $language, $KJP_ID);
    }

    /**
     * load the libraries of PayPal and Stripe, they are installed with Composer in the "vendor" folder,
     * and the packages of the releases of this plugin have it inside
     *
     * @return bool false when the folder is not there
     */
    public static function loadLibraries(): bool
    {
        $autoload = self::librariesFile();

        if (! file_exists($autoload)) {
            return false;
        }

        require_once $autoload;

        return true;
    }

    public static function librariesFile(): string
    {
        return dirname(__DIR__) . '/vendor/autoload.php';
    }

    public static function getPayURL(string $action, string $method, int $id = 0)
    {
        global $config;

        return $config['siteurl'] .
            'go.php?go=kj_payment&method=' .
            rawurlencode($method) .
            '&action=' .
            rawurlencode($action) .
            ($id > 0 ? '&id=' . $id : '');
    }
}
