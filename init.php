<?php
// kleeja plugin
// developer: KLEEJA TEAM

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

// Plugin Functions;
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/kjp_api.php';
require_once __DIR__ . '/php/subscription.php';

// plugin basic information
$kleeja_plugin['kleeja_payment']['information'] = [
    // the casual name of this plugin, anything can a human being understands
    'plugin_title' => [
        'en' => 'Kleeja Payment',
        'ar' => 'نظام كليجة للدفع الإلكتروني',
    ],
    // who wrote this plugin?
    'plugin_developer' => 'Kleeja Team',
    // this plugin version
    'plugin_version' => '2.0.4',
    // explain what is this plugin, why should i use it?
    'plugin_description' => [
        'en' => 'Selling Files and Premium Groups',
        'ar' => 'بيع الملفات والمجموعات المميزة',
    ],

    // min version of kleeja that's required to run this plugin
    'plugin_kleeja_version_min' => '4.0.0',
    // max version of kleeja that support this plugin, use 0 for unlimited
    'plugin_kleeja_version_max' => '4.9',
    // should this plugin run before others?, 0 is normal, and higher number has high priority
    'plugin_priority' => 10, // only for define support_kjPay
    // setting page to display in plugins page
    'settings_page' => 'cp=options&smt=kleeja_payment',
];

//after installation message, you can remove it, it's not requiered
$kleeja_plugin['kleeja_payment']['first_run']['ar'] = "
باستخدام هذا البرنامج المساعد ، يمكنك تسعير الملفات والمجموعات لبيعها ، واستلام الدفعات إلى حساب PayPal أو Stripe الخاص بك تلقائيًا <br>
قم بزيارة صفحة المساعدة للمزيد <br>
<a href='./index.php?cp=s_help&page=kj_payment_options' >المساعدة</a>

";

$kleeja_plugin['kleeja_payment']['first_run']['en'] = "
With this plugin you can sell files and paid groups, and receive the payments to your PayPal or Stripe account automatically
<br>

for more info visit help page <br>
<a href='./index.php?cp=s_help&page=kj_payment_options' >Help</a>

";

// plugin installation function
$kleeja_plugin['kleeja_payment']['install'] = function ($plg_id) {
    global $SQL, $dbprefix, $d_groups;

    require_once __DIR__ . '/php/setup.php';

    foreach (array_keys(kjp_tables()) as $table) {
        kjp_create_table($table);
    }

    foreach (kjp_columns() as [$table, $column, $definition]) {
        $SQL->query("ALTER TABLE `{$dbprefix}{$table}` ADD `{$column}` {$definition}");
    }

    // create group permission to access bought files
    foreach ($d_groups as $group_id => $group_info) {
        // guest & bought files => problems
        // search for "expected_err" on this document Ctrl + F
        // and u will know what i mean
        if ($group_id == 2) {
            continue;
        }

        // access_bought_files, recaive_profits
        foreach (['access_bought_files', 'recaive_profits'] as $acl_name) {
            $SQL->build([
                'INSERT' => 'acl_name, acl_can, group_id',
                'INTO' => "{$dbprefix}groups_acl",
                'VALUES' => ':acl_name, 0, :group_id',
                'BIND' => ['acl_name' => $acl_name, 'group_id' => (int) $group_id],
            ]);
        }
    }

    add_config_r(kjp_config_rows((int) $plg_id));
};

//plugin update function, called if plugin is already installed but version is different than current
$kleeja_plugin['kleeja_payment']['update'] = function ($old_version, $new_version) {
    global $SQL, $dbprefix;

    // the note in php/setup.php says what a step of the update can use
    require_once __DIR__ . '/php/setup.php';

    $plg_id = kjp_update_plugin_id();

    if (version_compare($old_version, '1.2.4', '<')) {
        // create subscription tables
        kjp_create_table('subscriptions');
        kjp_create_table('subscription_point');

        // add package colums to users table
        foreach (['package', 'package_expire', 'subs_point'] as $column) {
            kjp_update_query("ALTER TABLE `{$dbprefix}users` ADD `{$column}` INT NOT NULL DEFAULT '0';");
        }

        // SQLite came to Kleeja after that version
        if ($SQL->driver == 'mysql') {
            kjp_update_query("ALTER TABLE `{$dbprefix}payments` ALTER `payment_more_info` SET DEFAULT NULL;");
        }

        // add prefix to plugins setting
        $renamed = [
            'join_price' => 'kjp_join_price',
            'min_payout_limit' => 'kjp_min_payout_limit',
            'pp_client_id' => 'kjp_paypal_client_id',
            'paypal_client_secret' => 'kjp_paypal_client_secret',
            'stripe_secret_key' => 'kjp_stripe_secret_key',
            'iso_currency_code' => 'kjp_iso_currency_code',
            'down_link_expire' => 'kjp_down_link_expire',
            'file_owner_profits' => 'kjp_file_owner_profits',
            'min_price_limit' => 'kjp_min_price_limit',
            'max_price_limit' => 'kjp_max_price_limit',
            'active_balance' => 'kjp_active_balance',
            'active_paypal' => 'kjp_active_paypal',
            'active_cards' => 'kjp_active_cards',
        ];

        $options = kjp_options();

        foreach ($renamed as $old => $new) {
            kjp_update_rename_config($old, $new, kjp_config_field($new, $options[$new][1]));
        }

        kjp_update_delete_configs(['stripe_publishable_key']);

        // Add new Config for Subscriptions
        kjp_update_add_configs(kjp_config_rows($plg_id, ['kjp_active_subscriptions', 'kjp_active_live_mode']));
    }

    if (version_compare($old_version, '2.0.0', '<')) {
        // the cards are back with the payment page of Stripe, which needs only the secret key,
        // and they stay off until the admin turns them on
        $cards = kjp_config_rows($plg_id, ['kjp_active_cards']);
        $cards['kjp_active_cards']['value'] = '0';

        kjp_update_add_configs($cards);
        kjp_update_delete_configs(['kjp_stripe_publishable_key']);

        // the keys are not shown on the screen
        foreach (['kjp_paypal_client_secret', 'kjp_stripe_secret_key'] as $name) {
            $SQL->build([
                'UPDATE' => "{$dbprefix}config",
                'SET' => '`option` = :option',
                'WHERE' => '`name` = :name',
                'BIND' => ['option' => kjp_config_field($name, 'secret'), 'name' => $name],
            ]);
        }

        // the keys that were never set had "0" in them
        $SQL->build([
            'UPDATE' => "{$dbprefix}config",
            'SET' => "`value` = ''",
            'WHERE' => "`name` IN (:names) AND `value` = '0'",
            'BIND' => ['names' => ['kjp_paypal_client_id', 'kjp_paypal_client_secret', 'kjp_stripe_secret_key']],
        ]);
    }

    kjp_update_clear_cache();
};

// plugin uninstalling, function to be called at uninstalling
$kleeja_plugin['kleeja_payment']['uninstall'] = function ($plg_id) {
    global $SQL, $dbprefix;

    require_once __DIR__ . '/php/setup.php';

    // the payments, the payouts and the subscriptions stay in the database
    $SQL->query("DROP TABLE `{$dbprefix}subscription_point`");

    foreach (kjp_columns() as [$table, $column]) {
        $SQL->query("ALTER TABLE `{$dbprefix}{$table}` DROP `{$column}`");
    }

    delete_config(array_merge(array_keys(kjp_options()), ['kjp_payment_method', 'kjp_stripe_publishable_key']));

    // DELETE ACCESS BOUGHT FILES PERMISSIONS AND recaive profits
    $SQL->build([
        'DELETE' => "{$dbprefix}groups_acl",
        'WHERE' => 'acl_name IN (:acls)',
        'BIND' => ['acls' => ['access_bought_files', 'recaive_profits']],
    ]);

    // in the end , let's delete the olang , not our one , for other packages of this plugin
    delete_olang(null, null, (int) $plg_id);
};

// plugin functions
$kleeja_plugin['kleeja_payment']['functions'] = [
    // the page of a file before its download
    'qr_download_id_filename' => function ($args) {
        global $SQL, $config, $usrcp, $subscription, $olang;

        $query = $args['query'];
        $query['SELECT'] .= ', f.price, f.user';

        $result = $SQL->build($query);
        $row = $SQL->fetch_array($result);
        $SQL->freeresult($result);

        // file not found -> kleeja will display an error msg
        // wibsite founders and file Owner can download without pay
        if (! $row || kjp_downloads_free($row['user'])) {
            return;
        }

        // if the code arrive here , that mean the user have valid subscripe , he is free to do what he want
        if ($config['kjp_active_subscriptions'] && $subscription->is_valid($usrcp->id())) {
            return;
        }

        if ($row['price'] > 0) {
            // if paid , send hem to buy page, it displays subscripes packs too
            redirect($config['siteurl'] . 'do.php?file=' . $row['id']);
        } elseif ($config['kjp_active_subscriptions']) {
            // display an msg to say that we are using subscripe system and redirect to subscripes page
            kleeja_err($olang['KJP_WE_USE_SUBSCRIPE_SYS'], '', true, $config['siteurl'] . 'go.php?go=subscription');
        }

        // subscription is not active and the file is free => do nothing , it's not our job
    },

    // the page to buy a file, do.php?file=1
    'err_navig_download_page' => function ($args) {
        global $config, $SQL, $dbprefix, $olang, $lang, $tpl, $usrcp, $subscription;

        $file_id = g('file', 'int');

        if ($file_id <= 0) {
            return;
        }

        // if we are using subscription system, and the user trying to buy a file, redirect hem to normal download page
        if ($config['kjp_active_subscriptions'] && $subscription->is_valid($usrcp->id())) {
            redirect($config['siteurl'] . 'do.php?id=' . $file_id);
        }

        // avilable Payment methods
        $payment_methods = [];

        foreach (getPaymentMethods() as $value) {
            $payment_methods[$value] = ['name' => kjp_method_title($value), 'method' => $value];
        }

        if (ip('buy_file')) {
            if (! isset($payment_methods[p('method')])) {
                kleeja_err($lang['ERROR_NAVIGATATION']);
            }

            redirect(KJP::getPayURL('buy_file', p('method'), $file_id));
        }

        require_once __DIR__ . '/php/down_ui.php';

        // add Vars to $GLOBAL
        foreach (
            compact(
                'id',
                'name',
                'real_filename',
                'type',
                'time',
                'uploads',
                'price',
                'fusername',
                'REPORT',
                'userfolder',
                'size',
                'FormAction',
                'payment_methods',
                'is_style_supported',
            )
            as $var => $value
        ) {
            $tpl->assign($var, $value);
        }

        Saaheader($title);
        echo $tpl->display($sty, $styPath);
        Saafooter();

        $error = false;

        return compact('error');
    },

    'default_go_page' => function ($args) {
        global $lang, $olang, $usrcp, $config, $subscription;

        $go = g('go');

        // request Example : domain.io/kleeja/go.php?go=kj_payment&method=paypal&action=buy_file&id=1
        // action = buy_file OR join_group OR subscripe OR check
        // id = the id of file or the id of group

        // checking request Example : domain.io/kleeja/go.php?go=kj_payment&method=paypal&action=check&blablabla
        // blablabla = what the payment method put in its return link

        if ($go == 'kj_payment' && g('method') != '' && g('action') != '') {
            require_once __DIR__ . '/php/kjPayment.php'; // require the payment interface

            $method = g('method');
            $action = g('action');
            $PaymentMethodClass = kjp_method_file($method); // default payment method

            if (! $PaymentMethodClass) {
                kleeja_err($lang['ERROR_NAVIGATATION']);
            }

            if (! file_exists($PaymentMethodClass)) {
                $is_err = true;
                // a method of another plugin, it gives the file of its class here
                extract(runHook('KjPay:set_payment_method', get_defined_vars()));

                if ($is_err) {
                    kleeja_err('The class file of ' . $method . ' payment in not found');
                }
            }

            // a new payment needs an active method, the buyer can still come back from a payment that he started
            if ($action != 'check' && ! in_array($method, getPaymentMethods(), true)) {
                kleeja_err($lang['ERROR_NAVIGATATION']);
            }

            require_once $PaymentMethodClass;

            $PaymentMethod = 'kjPayMethod_' . basename($PaymentMethodClass, '.php');

            // to be sure
            if ($PaymentMethod !== 'kjPayMethod_' . $method || ! class_exists($PaymentMethod, false)) {
                kleeja_err('Its not your method');
            }
            // check if the current payment class is implemented our interface or not
            elseif (! is_subclass_of($PaymentMethod, 'KJPaymentMethod')) {
                kleeja_err('<strong>' . $PaymentMethod . '</strong> class is not implementing (KJPaymentMethod) interface');
            } elseif (! $PaymentMethod::permission('createPayment')) {
                kleeja_err('This Method Dont support Creating Payments');
            }

            $PAY = new $PaymentMethod();

            $PAY->paymentStart(); // Play some song to enjoy ;

            $PAY->setCurrency(strtoupper($config['kjp_iso_currency_code']));

            switch ($action) {
                case 'buy_file':
                    // user can't buy another file before receive the link of first file
                    // if the user do it , he will lost access to first bought file
                    if ($usrcp->kleeja_get_cookie('mailForDownFile')) {
                        // the user didn't download the file , becuse he did n't set his e-mail
                        redirect($config['siteurl'] . 'go.php?go=KJPaymentMailer');
                    }

                    $fileInfo = getFileInfo(g('id', 'int')); // get file information

                    if (! $fileInfo) {
                        kleeja_err($lang['FILE_NO_FOUNDED']);
                    } elseif ($fileInfo['price'] <= 0) {
                        kleeja_err($olang['KJP_FRE_ITM']);
                    }

                    $PAY->CreatePayment('buy_file', $fileInfo);

                    // get some vars for kleeja # compact(':)')
                    return $PAY->varsForCreatePayment();

                case 'join_group':
                    // Joining Group Steps
                    // the Guests have to signup befor join ..
                    if (! $usrcp->name()) {
                        kleeja_err($lang['USER_PLACE']);
                    }

                    $group_id = g('id', 'int');
                    // the cookie keeps the group that the user had at login, so it is read from the database
                    $userIs = $usrcp->get_data('group_id');

                    if (! $userIs || $userIs['group_id'] == $group_id) {
                        kleeja_err($olang['KJP_CNT_JOIN']);
                    } elseif ($userIs['group_id'] == 1 && ! defined('DEV_STAGE')) {
                        kleeja_err('YOU ARE ADMIN');
                    }

                    $groupInfo = getGroupInfo($args['d_groups'], $group_id);

                    if (! $groupInfo) {
                        kleeja_err($olang['KJP_CANT_JOIN_GRP']);
                    }

                    $PAY->CreatePayment('join_group', $groupInfo);

                    return $PAY->varsForCreatePayment();

                case 'subscripe':
                    if (! $usrcp->name()) {
                        kleeja_err($lang['USER_PLACE'], '', true, $config['siteurl'] . 'go.php?go=subscription');
                    } elseif ($usrcp->group_id() == 1 && ! defined('DEV_STAGE')) {
                        kleeja_err('YOU ARE ADMIN');
                    } elseif ($subscription->is_valid($usrcp->id())) {
                        kleeja_err($olang['KJP_U_H_VALID_SUBSCRIPE']);
                    }

                    $subscripe_info = g('id', 'int') > 0 ? $subscription->get(g('id', 'int')) : false;

                    if (! $subscripe_info) {
                        kleeja_err($lang['ERROR_NAVIGATATION'], '', true, $config['siteurl'] . 'go.php?go=subscription');
                    }

                    $PAY->CreatePayment('subscripe', $subscripe_info);

                    return $PAY->varsForCreatePayment();

                case 'check':
                    // Checking Payments steps

                    // i don't want the user reset the cookie expire date
                    if ($usrcp->kleeja_get_cookie('mailForDownFile')) {
                        // the user didn't download the file , becuse he did n't set his e-mail
                        redirect($config['siteurl'] . 'go.php?go=KJPaymentMailer');
                    }

                    // Every method shows its error and stops if the payment is not done
                    $PAY->checkPayment();

                    if (! $PAY->isSuccess()) {
                        unset($_SESSION['kj_payment']);

                        return;
                    }

                    $payment = $_SESSION['kj_payment'];
                    unset($_SESSION['kj_payment']);

                    $vars = $PAY->getGlobalVars() + [
                        'no_request' => false,
                        'titlee' => $olang['KJP_SCES_PAY'],
                        'stylee' => 'pay_success',
                        // to allow the developers to including 'pay_success.html' with their styles .
                        'styleePath' => kjp_template_path('pay_success'),
                        'FormAction' => $config['siteurl'] . 'go.php?go=KJPaymentMailer',
                        'kjFormKeyPost' => kleeja_add_form_key('kjp_mailer'),
                        'showMailForm' => false,
                        'down_link' => '',
                        'file_name' => '',
                        'groupName' => '',
                    ];

                    // we send e-mail only when the user buying files , no e-mail for joining group
                    if ($payment['payment_action'] != 'buy_file') {
                        return $vars;
                    }

                    $lifetime = kjp_link_lifetime();
                    $linkExpire = $lifetime ? date('Y-m-d / H:i:s', time() + $lifetime) : $olang['KJP_NO_EXPIRE'];
                    $downCookie = $payment['item_id'] . '_' . $payment['db_id'] . '_' . $payment['payment_token'];
                    $mail = $PAY->linkMailer();
                    // the user can find the file on bought files , don't need to send the download link
                    $has_page = $usrcp->name() && kjp_can('access_bought_files');
                    $mailer = false;

                    // "expected_err"
                    if ($has_page) {
                        $olang['KJP_DOWN_INFO_2'] = $olang['KJP_DOWN_INFO_3'];
                    } elseif ($mail !== '') {
                        // the method support email, the mail shows the amount and the method, which are in the row only
                        $row = getPaymentInfo($payment['db_id']);
                        $mailer = $row && kjp_mail_download_link($mail, $row, $linkExpire);
                    }

                    if ($mailer) {
                        // mail is sent , don't need mail form & dispaly success msg
                        $olang['KJP_DOWN_INFO_2'] = str_replace(
                            ['@mail', '@time'],
                            [kleeja_html_encode($mail), $linkExpire],
                            $olang['KJP_DOWN_INFO_2'],
                        );
                    } elseif (! $has_page) {
                        // method don't support email, or we have to send mail again , i hope we never arrive to this part :(
                        // -> display email form & hide msg & set coockie to use mailform page
                        $vars['showMailForm'] = true;
                        $olang['KJP_DOWN_INFO_2'] = ''; // dont show this msg , we didn't send it yet
                        $usrcp->kleeja_set_cookie('mailForDownFile', $downCookie, time() + 86400);
                    }

                    // the browser that paid can download until the link expires
                    if (! $vars['showMailForm'] && $lifetime) {
                        $usrcp->kleeja_set_cookie('downloadFile_' . $payment['item_id'], $downCookie, time() + $lifetime);
                    }

                    return $vars;

                default:
                    $request = false; // maybe we will need it later;

                    extract(runHook('KjPay:default_action', get_defined_vars()));

                    if (! $request) {
                        kleeja_err($lang['ERROR_NAVIGATATION']);
                    }

                    return;
            }
        } elseif ($go == 'paid_group') {
            $methods = getPaymentMethods();

            // to be sure that no one playing with html file
            if (ip('join_grp') && in_array(p('method'), $methods, true)) {
                redirect(KJP::getPayURL('join_group', p('method'), p('group_id', 'int')));
            }

            $MethodOption = '';

            foreach ($methods as $value) {
                // loop inside loop doesn't work in kleeja styles
                $MethodOption .= '<option value="' . $value . '">' . kjp_method_title($value) . "</option>\n";
            }

            $no_request = false;
            $stylee = 'paid_group';
            $titlee = $olang['KJP_PID_GRP'];
            // to allow the developers to including 'paid_group.html' with their styles .
            $styleePath = kjp_template_path('paid_group');
            $FormAction = $config['siteurl'] . 'go.php?go=paid_group';
            $PaidGroups = getGroupInfo($args['d_groups']) ?: [];

            foreach ($PaidGroups as &$group) {
                $group['price'] = kjp_price($group['price']);
            }

            unset($group);

            return compact('no_request', 'titlee', 'stylee', 'styleePath', 'PaidGroups', 'MethodOption', 'FormAction');
        }

        // Send Download Link
        elseif ($go == 'KJPaymentMailer') {
            $FormAction = $config['siteurl'] . 'go.php?go=KJPaymentMailer';
            $payCookieInfo = (string) $usrcp->kleeja_get_cookie('mailForDownFile');
            $payCookieInfoExplode = explode('_', $payCookieInfo);

            // the cookie can be written by anybody, so it has to be a real payment of a file
            $payment =
                count($payCookieInfoExplode) == 3
                    ? getPaymentInfo($payCookieInfoExplode[1], [
                        'item_id' => (int) $payCookieInfoExplode[0],
                        'payment_token' => $payCookieInfoExplode[2],
                        'payment_state' => 'approved',
                        'payment_action' => 'buy_file',
                    ])
                    : false;

            if (! $payment) {
                // ! from check payment page or the mail is sent
                if ($payCookieInfo !== '') {
                    $usrcp->kleeja_set_cookie('mailForDownFile', '', time() - 86400);
                }

                kleeja_err($lang['ERROR_NAVIGATATION']);
            }

            $fileName = $payment['item_name'];
            $lifetime = kjp_link_lifetime();
            $linkExpire = $lifetime ? date('Y-m-d / H:i:s', time() + $lifetime) : $olang['KJP_NO_EXPIRE'];

            if (ip('sendMail')) {
                if (! kleeja_check_form_key('kjp_mailer', 3600)) {
                    kleeja_err($lang['INVALID_FORM_KEY'], '', true, $FormAction, 2);
                }

                // p() encodes the text, and the address goes to the mail server as it is
                $mailAdress = htmlspecialchars_decode(p('buyerMail'), ENT_QUOTES);

                if (! filter_var($mailAdress, FILTER_VALIDATE_EMAIL)) {
                    kleeja_err($lang['WRONG_EMAIL'], '', true, $FormAction, 2);
                }

                if (! kjp_mail_download_link($mailAdress, $payment, $linkExpire)) {
                    kleeja_err($olang['KJP_ERR_SND_MIL'], '', true, $FormAction, 3);
                }

                // set cookie for download file
                if ($lifetime) {
                    $usrcp->kleeja_set_cookie('downloadFile_' . (int) $payment['item_id'], $payCookieInfo, time() + $lifetime);
                }

                // delete cookie
                $usrcp->kleeja_set_cookie('mailForDownFile', '', time() - 86400);

                // dispaly success msg || I HOPE WE DONE
                kleeja_info(
                    str_replace(
                        ['@mail', '@time'],
                        [kleeja_html_encode($mailAdress), $linkExpire],
                        $olang['KJP_DOWN_INFO_2'],
                    ),
                );
            }

            $titlee = $olang['KJP_MAIL_INFO_1'];
            $no_request = false;
            $stylee = 'kjpayment_mailer';
            $styleePath = kjp_template_path('kjpayment_mailer');
            $kjFormKeyPost = kleeja_add_form_key('kjp_mailer');

            return compact('titlee', 'stylee', 'styleePath', 'fileName', 'no_request', 'FormAction', 'kjFormKeyPost');
        }

        // Subscription list
        // the page to buy a subscripe
        elseif ($go == 'subscription') {
            $methods = getPaymentMethods();
            $FormAction = $config['siteurl'] . 'go.php?go=subscription';

            // if submit
            if (ip('subscripe_now') && kleeja_check_form_key('subscription', 3600)) {
                if (! $usrcp->name()) {
                    kleeja_err($lang['USER_PLACE'], '', true, $FormAction);
                }

                if (in_array(p('Pay_method'), $methods, true) && p('subscripe_id', 'int') > 0) {
                    redirect(KJP::getPayURL('subscripe', p('Pay_method'), p('subscripe_id', 'int')));
                }
            }

            $titlee = $olang['KJP_SUBSCRIPTIONS'];
            $no_request = false;
            $stylee = 'subscripe';
            $styleePath = kjp_template_path('subscripe');
            $subscripe_list = [];

            foreach ($subscription->get() as $package) {
                $subscripe_list[] = ['price' => kjp_price($package['price'])] + $package;
            }
            $MethodOption = '';
            $form_key = kleeja_add_form_key('subscription');

            foreach ($methods as $value) {
                // loop inside loop doesn't work in kleeja styles
                $MethodOption .= '<option value="' . $value . '">' . kjp_method_title($value) . "</option>\n";
            }

            return compact(
                'titlee',
                'stylee',
                'styleePath',
                'no_request',
                'subscripe_list',
                'MethodOption',
                'form_key',
                'FormAction',
            );
        }
    },

    // the download itself
    'qr_down_go_page_filename' => function ($args) {
        global $SQL, $usrcp, $config, $subscription;

        $query = $args['query'];
        $query['SELECT'] .= ', f.price, f.user';

        $result = $SQL->build($query);
        $row = $SQL->fetch_array($result);
        $SQL->freeresult($result);

        if (! $row) {
            return;
        }

        $is_paid = $row['price'] > 0;

        // the subscription is not active and free file, or the user is founder or file owner
        if ((! $is_paid && ! $config['kjp_active_subscriptions']) || kjp_downloads_free($row['user'])) {
            return;
        }

        // subscriptions is active and the user have a valid subscription
        if ($config['kjp_active_subscriptions'] && $subscription->is_valid($usrcp->id())) {
            if ($is_paid) {
                // add a uniq point to file owner
                $subscription->addPoint($row['id']);
            }

            return;
        }

        // let's check if he bought the file or not
        if ($is_paid && kjp_has_download_access($row['id'])) {
            return;
        }

        // i hate this part
        redirect($config['siteurl'] . 'do.php?file=' . $row['id']);
    },

    'begin_admin_page' => function ($args) {
        $adm_extensions = $args['adm_extensions'];
        $ext_icons = $args['ext_icons'];
        $adm_extensions[] = 'kj_payment_options';
        $ext_icons['kj_payment_options'] = 'money';

        return compact('adm_extensions', 'ext_icons');
    },

    'not_exists_kj_payment_options' => function ($args) {
        $include_alternative = __DIR__ . '/php/kj_payment_options.php';

        return compact('include_alternative');
    },

    // the guide of the plugin on the help page of Kleeja, its words are in language/help_{language}.php
    'admin_help_guides' => function ($args) {
        require_once __DIR__ . '/php/help.php';

        $help_guides = $args['help_guides'];
        // the name of the plugin is the key, so Kleeja knows that the plugin has its guide
        $help_guides['kleeja_payment'] = kjp_help_guide();

        return compact('help_guides');
    },

    'Saaheader_links_func' => function ($args) {
        global $d_groups, $config, $olang, $usrcp, $subscription;

        $top_menu = $args['top_menu'];
        $side_menu = $args['side_menu'];
        $user_is = $args['user_is'];
        $has_subscriptions = ! empty($config['kjp_active_subscriptions']);
        $package = $has_subscriptions && $subscription->is_valid($usrcp->id()) ? $subscription->user_subscripe($usrcp->id()) : '';
        // if subscription is active , add user package next to hes name in the header
        $username = $package ? $args['username'] . ' | ' . $package['name'] : $args['username'];

        $side_menu[] = [
            'name' => 'my_kj_payment',
            'title' => $olang['R_KJ_PAYMENT_OPTIONS'],
            'url' => $config['siteurl'] . 'ucp.php?go=my_kj_payment',
            'icon' => 'wallet',
            'show' => $user_is && kjp_can('recaive_profits'),
        ];
        $side_menu[] = [
            'name' => 'my_payments',
            'title' => $olang['KJP_MY_PAYS'],
            'url' => $config['siteurl'] . 'ucp.php?go=my_payments',
            'icon' => 'receipt',
            'show' => (bool) $user_is,
        ];
        $side_menu[] = [
            'name' => 'bought_files',
            'title' => $olang['KJP_BOUGHT_FILES'],
            'url' => $config['siteurl'] . 'ucp.php?go=bought_files',
            'icon' => 'bag-shopping',
            'show' => $user_is && kjp_can('access_bought_files'),
        ];
        $top_menu[] = [
            'name' => 'paid_group',
            'title' => $olang['KJP_PID_GRP'],
            'url' => $config['siteurl'] . 'go.php?go=paid_group',
            'show' => (bool) getGroupInfo($d_groups),
        ];
        $top_menu[] = [
            'name' => 'subscription',
            'title' => $olang['KJP_SUBSCRIPTIONS'],
            'url' => $config['siteurl'] . 'go.php?go=subscription',
            'show' => $has_subscriptions && $subscription->get(),
        ];

        // i want to put logout to the end if menu always
        if ($user_is) {
            $side_menu['logout'] = $side_menu[3];
            unset($side_menu[3]);
        }

        return compact('top_menu', 'side_menu', 'username');
    },

    'begin_download_page' => function ($args) {
        global $config;

        if (! ig('downPaidFile')) {
            return;
        }

        // the mailed link to Buyer mail
        // EX: domain.io/kleeja/do.php?downPaidFile=fileID_dbID_payToken
        $downToken = explode('_', g('downPaidFile')) + ['', '', ''];
        $fileID = (int) $downToken[0];

        $paymentInfo = getPaymentInfo($downToken[1], [
            'item_id' => $fileID,
            'payment_token' => $downToken[2],
            'payment_state' => 'approved',
            'payment_action' => 'buy_file',
        ]);

        if (! $paymentInfo) {
            redirect($config['siteurl']); //OR kleeja_err();
        }

        // for this session i made this page
        $_SESSION['HTTP_REFERER'] = $fileID;

        redirect(
            $config['siteurl'] .
                'do.php?down=' .
                $fileID .
                '&db=' .
                (int) $paymentInfo['id'] .
                '&downToken=' .
                rawurlencode($paymentInfo['payment_token']),
        );
    },

    'default_usrcp_page' => function ($args) {
        global $SQL, $dbprefix, $usrcp, $config, $lang, $olang;

        $go = g('go');
        $user_id = kjp_user_id();

        // these pages are for members only
        if (! $user_id || ! in_array($go, ['bought_files', 'my_payments', 'my_kj_payment'], true)) {
            return;
        }

        $no_request = false;
        $page_nums = '';
        $is_style_supported = is_style_supported();

        // all user bought file
        if ($go == 'bought_files') {
            if (! kjp_can('access_bought_files')) {
                return;
            }

            $titlee = $olang['KJP_BOUGHT_FILES'];
            $stylee = 'bought_files';
            $styleePath = kjp_template_path('bought_files');
            $myPayments = [];

            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' =>
                        'p.id, p.payment_token, p.item_name, p.item_id, p.payment_currency, ' .
                            'p.payment_amount, p.payment_year, p.payment_month, p.payment_day, p.payment_time',
                    'FROM' => "{$dbprefix}payments p",
                    'WHERE' => "p.payment_state = 'approved' AND p.user = :user AND p.payment_action = 'buy_file'",
                    'ORDER BY' => 'p.id DESC',
                    'BIND' => ['user' => $user_id],
                ],
                $config['siteurl'] . 'ucp.php?go=bought_files',
            );

            while ($result && ($pay = $SQL->fetch($result))) {
                $myPayments[] = [
                    'ID' => $pay['id'],
                    'FILE' => $pay['item_name'],
                    'AMOUNT' => kjp_price($pay['payment_amount'], $pay['payment_currency']),
                    'DATE_TIME' => "{$pay['payment_year']}-{$pay['payment_month']}-{$pay['payment_day']} / {$pay['payment_time']}",
                    'DOWN_LINK' => kjp_down_link($pay),
                ];
            }

            $havePayments = (bool) $myPayments;

            return compact(
                'titlee',
                'no_request',
                'stylee',
                'page_nums',
                'styleePath',
                'myPayments',
                'havePayments',
                'is_style_supported',
            );
        } elseif ($go == 'my_payments') {
            $titlee = $olang['KJP_MY_PAYS'];
            $stylee = 'my_payments';
            $styleePath = kjp_template_path('my_payments');
            $payments = [];

            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' =>
                        'p.id, p.payment_method, p.payment_amount, p.payment_currency, p.payment_action, ' .
                            'p.item_name, p.payment_year, p.payment_month, p.payment_day, p.payment_time',
                    'FROM' => "{$dbprefix}payments p",
                    'WHERE' => "p.payment_state = 'approved' AND p.user = :user",
                    'ORDER BY' => 'p.id DESC',
                    'BIND' => ['user' => $user_id],
                ],
                $config['siteurl'] . 'ucp.php?go=my_payments',
            );

            while ($result && ($row = $SQL->fetch_array($result))) {
                $payments[] = [
                    'ID' => $row['id'],
                    'METHOD' => kjp_method_title($row['payment_method']),
                    'FILE_NAME' => $row['item_name'],
                    'AMOUNT' => kjp_price($row['payment_amount'], $row['payment_currency']),
                    'ACTION' => kjp_action_title($row['payment_action'], $row['item_name']),
                    'DATE_TIME' => "{$row['payment_year']}-{$row['payment_month']}-{$row['payment_day']} / {$row['payment_time']}",
                ];
            }

            $havePayments = (bool) $payments;

            return compact(
                'titlee',
                'stylee',
                'styleePath',
                'page_nums',
                'payments',
                'havePayments',
                'no_request',
                'is_style_supported',
            );
        }

        // Payment UCP
        if (! kjp_can('recaive_profits')) {
            return;
        }

        $action = $config['siteurl'] . 'ucp.php?go=my_kj_payment'; // for withdraw form
        $case = in_array(g('case'), ['withdrawals', 'files_payments', 'pricing_file', 'my_paid_files'], true) ? g('case') : 'cp';
        $currency = strtoupper($config['kjp_iso_currency_code']);
        $userData = $usrcp->get_data('subs_point, balance, password, password_salt');
        $titlee = $olang['R_KJ_PAYMENT_OPTIONS'];
        $stylee = 'my_kj_payment';
        $styleePath = kjp_template_path('my_kj_payment');

        $OpenAlert = $show_price_panel = $havePayout = $havePayments = $have_paid_file = false;
        $AlertMsg = $AlertRole = $FileID = $FileName = $FileUser = $FilePrice = $FileSize = '';
        $payouts = $payments = $all_paid_file = [];

        // request your money
        if (ip('requestAmount')) {
            if (! kleeja_check_form_key('kjp_withdraw', 3600)) {
                kleeja_err($lang['INVALID_FORM_KEY'], '', true, $action, 2);
            }

            require_once __DIR__ . '/php/kjPayment.php'; // require the payment interface

            $PaymentMethodClass = kjp_method_file(p('PayoutMethod')); // default payment method

            if (! $PaymentMethodClass) {
                kleeja_err($lang['ERROR_NAVIGATATION']);
            }

            if (! file_exists($PaymentMethodClass)) {
                extract(runHook('KjPay:createPayout', get_defined_vars()));

                if (! file_exists($PaymentMethodClass)) {
                    kleeja_err('The class file of ' . p('PayoutMethod') . ' payment is not found');
                }
            }

            require_once $PaymentMethodClass;

            $method = basename($PaymentMethodClass, '.php');
            $methodClassName = 'kjPayMethod_' . $method;

            if (! class_exists($methodClassName, false) || ! $methodClassName::permission('createPayout')) {
                kleeja_err('The method dont support Creating Payouts');
            }

            $requestAmount = round((float) p('AmountNumber'), 2);
            $min_limit = (float) $config['kjp_min_payout_limit'];

            // if erro password stop
            if (
                p('userPass') == '' ||
                ! $usrcp->kleeja_hash_password(p('userPass') . $userData['password_salt'], $userData['password'])
            ) {
                kleeja_err($olang['KJP_WRONG_PASS'], '', true, $action, 3);
            } elseif ($min_limit > 0 && $requestAmount < $min_limit) {
                kleeja_err(sprintf($olang['KJP_MIN_POUT_LMT'], kjp_price($min_limit), $currency), '', true, $action, 3);
            }
            // the password was correct , is he really have this amount in hes balance
            // it is taken only when the balance covers it, in one query
            elseif ($requestAmount <= 0 || ! kjp_take_balance($user_id, $requestAmount)) {
                // no -> he don't have it
                kleeja_err($olang['KJP_NOT_VAILED_AMNT'], '', true, $action, 3);
            }

            $SQL->build([
                'INSERT' => 'user, payment_more_info, method, amount, state, payout_year, payout_month, payout_day, payout_time',
                'INTO' => "{$dbprefix}payments_out",
                'VALUES' => ':user, :info, :method, :amount, :state, :year, :month, :day, :time',
                'BIND' => [
                    'user' => $user_id,
                    'info' => payment_more_info('to_db', ['SENDTO' => $usrcp->mail()]),
                    'method' => $method,
                    'amount' => $requestAmount,
                    'state' => 'verify',
                    'year' => (int) date('Y'),
                    'month' => (int) date('m'),
                    'day' => (int) date('d'),
                    'time' => date('H:i:s'),
                ],
            ]);

            if (! $SQL->affected()) {
                // no request was saved, so the amount goes back
                kjp_give_balance($user_id, $requestAmount);
                kleeja_err($lang['ERROR_NAVIGATATION'], '', true, $action, 3);
            }

            $new_balance = $usrcp->get_data('balance');

            kleeja_info(
                sprintf(
                    $olang['KJP_SUCES_SND_WTHD'],
                    kjp_price($requestAmount, $currency),
                    kjp_price($new_balance['balance'], $currency),
                ),
                '',
                true,
                $action . '&amp;case=withdrawals',
            );
        }

        if ($case == 'withdrawals') {
            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' => 'o.id, o.method, o.amount, o.state, o.payout_year, o.payout_month, o.payout_day, o.payout_time',
                    'FROM' => "{$dbprefix}payments_out o",
                    'WHERE' => 'o.user = :user',
                    'ORDER BY' => 'o.id DESC',
                    'BIND' => ['user' => $user_id],
                ],
                $action . '&amp;case=withdrawals',
            );

            while ($result && ($row = $SQL->fetch_array($result))) {
                $payouts[] = [
                    'ID' => $row['id'],
                    'METHOD' => kjp_method_title($row['method']),
                    'AMOUNT' => kjp_price($row['amount'], $currency),
                    'DATE_TIME' => "{$row['payout_year']}-{$row['payout_month']}-{$row['payout_day']} / {$row['payout_time']}",
                    'STATE_LANG' => $olang['KJP_POUT_ST_' . strtoupper($row['state'])] ?? $row['state'],
                    'STATE' => $row['state'],
                ];
            }

            $havePayout = (bool) $payouts;
        } elseif ($case == 'files_payments') {
            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' =>
                        'p.id, p.payment_method, p.item_name, p.item_id, ' .
                            'p.user, p.payment_year, p.payment_month, p.payment_day, p.payment_time',
                    'FROM' => "{$dbprefix}payments p",
                    'JOINS' => [
                        [
                            'INNER JOIN' => "{$dbprefix}files f",
                            'ON' => 'p.item_id = f.id',
                        ],
                    ],
                    'WHERE' => "f.user = :user AND p.payment_action = 'buy_file' AND p.payment_state = 'approved'",
                    'ORDER BY' => 'p.id DESC',
                    'BIND' => ['user' => $user_id],
                ],
                $action . '&amp;case=files_payments',
            );

            $rows = [];

            while ($result && ($row = $SQL->fetch_array($result))) {
                $rows[] = $row;
            }

            $UserById = kjp_user_names(array_column($rows, 'user'));

            foreach ($rows as $row) {
                $payments[] = [
                    'ID' => $row['id'],
                    'METHOD' => kjp_method_title($row['payment_method']),
                    'FILE_NAME' =>
                        '<a href="' .
                        $config['siteurl'] .
                        'do.php?id=' .
                        $row['item_id'] .
                        '" target="_blank">' .
                        $row['item_name'] .
                        '</a>',
                    'BUYER' => $UserById[$row['user']] ?? $olang['KJP_GUEST'],
                    'DATE_TIME' => "{$row['payment_year']}-{$row['payment_month']}-{$row['payment_day']} / {$row['payment_time']}",
                ];
            }

            $havePayments = (bool) $payments;
        } elseif ($case == 'pricing_file') {
            if (ip('open_file')) {
                $select_file_id = p('select_file_id');

                // the link of the file instead of its id
                if (! (int) $select_file_id) {
                    $select_file_id = str_replace(
                        [$config['siteurl'] . 'do.php?id=', $config['siteurl'] . 'do.php?img='],
                        '',
                        $select_file_id,
                    );
                }

                $file_info = getFileInfo((int) $select_file_id);

                // to be sure that every user will change hes files only
                if ($file_info && (int) $file_info['user'] === $user_id) {
                    $show_price_panel = true;
                    $FileID = $file_info['id'];
                    $FileName = $file_info['name'];
                    $FileSize = readable_size((int) $file_info['size']);
                    $FileUser = $usrcp->name();
                    $FilePrice = kjp_price($file_info['price']);
                } else {
                    $OpenAlert = true;
                    $AlertMsg = $olang['KJP_NO_FILE_WITH_ID'] . ' ' . (int) $select_file_id;
                    $AlertRole = 'danger';
                }
            } elseif (ip('set_price')) {
                if (! kleeja_check_form_key('kjp_price', 3600)) {
                    kleeja_err($lang['INVALID_FORM_KEY'], '', true, $action . '&amp;case=pricing_file', 2);
                }

                $FileID = p('price_file_id', 'int');
                $FilePrice = round((float) p('price_file'), 2);

                if ($FilePrice < $config['kjp_min_price_limit'] || $FilePrice > $config['kjp_max_price_limit']) {
                    kleeja_err(
                        sprintf(
                            $olang['KJP_PRC_LMT'],
                            kjp_price($config['kjp_min_price_limit']),
                            kjp_price($config['kjp_max_price_limit']),
                            $currency,
                        ),
                        '',
                        true,
                        $action . '&amp;case=pricing_file',
                        3,
                    );
                }

                $file_info = getFileInfo($FileID);
                $OpenAlert = true;

                // every user changes the price of hes files only
                if ($file_info && (int) $file_info['user'] === $user_id) {
                    $SQL->build([
                        'UPDATE' => "{$dbprefix}files",
                        'SET' => 'price = :price',
                        'WHERE' => 'id = :id AND user = :user',
                        'BIND' => ['price' => $FilePrice, 'id' => $FileID, 'user' => $user_id],
                    ]);

                    $AlertMsg = sprintf(
                        $olang['KJP_NO_FILE_NEW_PRICE'],
                        $file_info['name'],
                        kjp_price($FilePrice),
                        $currency,
                    );
                    $AlertRole = 'success';
                } else {
                    $AlertMsg = $olang['KJP_NO_FILE_WITH_ID'] . ' ' . $FileID;
                    $AlertRole = 'danger';
                }
            }
        } elseif ($case == 'my_paid_files') {
            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' => 'f.id, f.real_filename, f.price',
                    'FROM' => "{$dbprefix}files f",
                    'WHERE' => 'f.price > 0 AND f.user = :user',
                    'ORDER BY' => 'f.id DESC',
                    'BIND' => ['user' => $user_id],
                ],
                $action . '&amp;case=my_paid_files',
            );

            while ($result && ($paid_file = $SQL->fetch($result))) {
                $all_paid_file[] = [
                    'id' => $paid_file['id'],
                    'name' => $paid_file['real_filename'],
                    'price' => kjp_price($paid_file['price'], $currency),
                    'link' => $config['siteurl'] . 'do.php?id=' . $paid_file['id'],
                ];
            }

            $have_paid_file = (bool) $all_paid_file;
        }

        // to have it fresh
        $user_balance = kjp_price($userData['balance'], $currency);
        $user_subs_points = $userData['subs_point'];
        $min_price = kjp_price($config['kjp_min_price_limit']);
        $max_price = kjp_price($config['kjp_max_price_limit']);
        $withdraw_form_key = kleeja_add_form_key('kjp_withdraw');
        $price_form_key = kleeja_add_form_key('kjp_price');

        return compact(
            'have_paid_file',
            'all_paid_file',
            'user_subs_points',
            'AlertRole',
            'AlertMsg',
            'OpenAlert',
            'show_price_panel',
            'FileID',
            'FileName',
            'FileUser',
            'FilePrice',
            'FileSize',
            'havePayments',
            'payments',
            'page_nums',
            'havePayout',
            'payouts',
            'case',
            'action',
            'titlee',
            'no_request',
            'stylee',
            'styleePath',
            'user_balance',
            'currency',
            'min_price',
            'max_price',
            'withdraw_form_key',
            'price_form_key',
            'is_style_supported',
        );
    },

    'begin_logout' => function ($args) {
        global $config, $usrcp;

        // delete the cookies of bought files, so they do not stay in a shared browser
        $prefix = $config['cookie_name'] . '_downloadFile_';

        foreach (array_keys($_COOKIE) as $cookie) {
            if (strpos((string) $cookie, $prefix) === 0) {
                $usrcp->kleeja_set_cookie(substr($cookie, strlen($config['cookie_name']) + 1), '', time() - 86400 * 31);
            }
        }
    },

    'boot_common' => function ($args) {
        global $olang, $config;

        // to check if the plugin is installed and enabled
        // if (defined('support_kjPay')) { a payment without salt please }
        define('support_kjPay', true);

        // the words of the plugin, in English when the language of the site is not there
        $language = preg_replace('/[^a-z0-9_-]/i', '', (string) ($config['language'] ?? ''));
        $langFiles = __DIR__ . "/language/{$language}.php";

        if ($language === '' || ! file_exists($langFiles)) {
            $langFiles = __DIR__ . '/language/en.php';
        }

        foreach (require $langFiles as $key => $value) {
            $olang[$key] = $value;
        }

        $subscription = new Subscription();
        $is_style_supported = is_style_supported();

        return compact('olang', 'subscription', 'is_style_supported');
    },

    'go_queue' => function ($args) {
        global $subscription;

        $subscription->convertPoints();
    },

    'KJP:get_payment_methods' => function ($args) {
        $return = $args['return']; // all methods in DB

        /**
         * in the next lines, we will check all methods in db
         * for each method, there is some conditions that have to be applied to use the method
         * and any method that don't apply the condition we will add it to this array, to remove it later in one time
         */
        $filter = [];
        $has_libraries = file_exists(KJP::librariesFile());
        // the keys were "0" when they were not set, in the versions before 2.0
        $has_paypal_keys = ! empty(kjp_setting('kjp_paypal_client_id')) && ! empty(kjp_setting('kjp_paypal_client_secret'));

        // check if the user can use the Balance method or not
        if (! kjp_can('recaive_profits')) {
            $filter[] = 'balance';
        }

        // check if PayPal method is ready to use or not
        if (! $has_libraries || ! $has_paypal_keys) {
            $filter[] = 'paypal';
        }

        // check if Stripe method is ready to use or not
        if (! $has_libraries || empty(kjp_setting('kjp_stripe_secret_key'))) {
            $filter[] = 'cards';
        }

        $return = array_values(array_diff($return, $filter));

        return compact('return');
    },

    'default_admin_page' => function ($args) {
        global $olang;

        $ADM_NOTIFICATIONS = $args['ADM_NOTIFICATIONS'];

        $payment_methods = getPaymentMethods(true);
        $has_libraries = file_exists(KJP::librariesFile());
        $has_paypal_keys = ! empty(kjp_setting('kjp_paypal_client_id')) && ! empty(kjp_setting('kjp_paypal_client_secret'));

        // check if PayPal method is ready to use or not
        // let's check the configs of paypal in DB
        if (in_array('paypal', $payment_methods) && ! $has_paypal_keys) {
            $ADM_NOTIFICATIONS[] = [
                'id' => 'EmptyPaypalParms',
                'msg_type' => 'error',
                'title' => $olang['KJP_PAYPAL_PRMS_EMPTY_TITLE'],
                'msg' => $olang['KJP_PAYPAL_PRMS_EMPTY'],
            ];
        }

        if (in_array('paypal', $payment_methods) && ! $has_libraries) {
            $ADM_NOTIFICATIONS[] = [
                'id' => 'NoPaypalLib',
                'msg_type' => 'error',
                'title' => $olang['KJP_PAYPAL_NO_LIB_TITLE'],
                'msg' => $olang['KJP_PAYPAL_NO_LIB'],
            ];
        }

        // check if Stripe method is ready to use or not
        if (in_array('cards', $payment_methods) && empty(kjp_setting('kjp_stripe_secret_key'))) {
            $ADM_NOTIFICATIONS[] = [
                'id' => 'EmptyStripeParms',
                'msg_type' => 'error',
                'title' => $olang['KJP_STRIPE_PRMS_EMPTY_TITLE'],
                'msg' => $olang['KJP_STRIPE_PRMS_EMPTY'],
            ];
        }

        if (in_array('cards', $payment_methods) && ! $has_libraries) {
            $ADM_NOTIFICATIONS[] = [
                'id' => 'NoStripeLib',
                'msg_type' => 'error',
                'title' => $olang['KJP_STRIPE_NO_LIB_TITLE'],
                'msg' => $olang['KJP_STRIPE_NO_LIB'],
            ];
        }

        return compact('ADM_NOTIFICATIONS');
    },

    // the payments of a period in a tab of the Status Reports page of the plugin kleeja_advanced_stats,
    // only the founders see them, like the payments control (php/kj_payment_options.php)
    'status_reports_extra_html' => function ($args) {
        global $olang;

        if (intval($args['userinfo']['founder'] ?? 0) !== 1) {
            return [];
        }

        require_once __DIR__ . '/php/status_reports.php';

        $sr_extra_html = $args['sr_extra_html'];
        $sr_extra_html['kleeja_payment'] = [
            'title' => $olang['KJP_SR_TITLE'],
            'icon' => 'sack-dollar',
            'html' => kjp_status_report($args),
        ];

        return compact('sr_extra_html');
    },

    // the style and the charts of those payments, with the version so browsers load them again after an update
    'status_reports_extra_files' => function ($args) {
        if (intval($args['userinfo']['founder'] ?? 0) !== 1) {
            return [];
        }

        $info = Plugins::getInstance()->installed_plugin_info('kleeja_payment');
        $folder = $args['config']['siteurl'] . KLEEJA_PLUGINS_FOLDER . '/kleeja_payment/assets/';
        $version = '?v=' . rawurlencode($info['plugin_version'] ?? '1');

        $sr_extra_css = $args['sr_extra_css'];
        $sr_extra_js = $args['sr_extra_js'];
        $sr_extra_css[] = $folder . 'status_reports.css' . $version;
        $sr_extra_js[] = $folder . 'status_reports.js' . $version;

        return compact('sr_extra_css', 'sr_extra_js');
    },

    /*
    //Example
    'kjPay:addToCPanel' => function ($args) {
        $all_trnc_panel = $args['all_trnc_panel'];
        $all_trnc_panel[] = array( 'methodName' => 'PayPal' , 'htmlContent' => 'Hello From PayPal Method' );
        return compact('all_trnc_panel');
    }
    */
];
