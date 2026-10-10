<?php
// kleeja plugin
// developer: Kleeja Team

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

/**
 * a setting of this plugin as it was typed, the configs are saved HTML-encoded
 *
 * @param  string $name
 * @return string
 */
function kjp_setting(string $name): string
{
    global $config;

    return trim(htmlspecialchars_decode((string) ($config[$name] ?? ''), ENT_QUOTES));
}

/**
 * keep a trace of a failed call to a payment provider, the visitor sees only a general message
 *
 * @param string $message never put keys, tokens or e-mail addresses in it
 */
function kjp_log(string $message): void
{
    $line = 'kleeja_payment: ' . trim(preg_replace('/\s+/', ' ', $message));

    //kleeja_log() does nothing without DEV_STAGE, so the log of PHP gets it too
    kleeja_log($line);
    error_log($line);
}

/**
 * show an error and stop, in the control panel or in the site, the payment methods work in both
 *
 * @param string $message
 * @param string $redirect a link to go to after the message
 */
function kjp_error(string $message, string $redirect = ''): void
{
    if (defined('IN_ADMIN')) {
        kleeja_admin_err($message, $redirect === '' ? true : $redirect);
    } else {
        kleeja_err($message, '', true, $redirect === '' ? false : $redirect);
    }

    exit;
}

/**
 * folder of a template of this plugin, a style can have its own copy in its "kj_payment" folder
 *
 * @param  string $template name of the template, without .html
 * @return string
 */
function kjp_template_path(string $template): string
{
    global $THIS_STYLE_PATH_ABS;

    if (file_exists($THIS_STYLE_PATH_ABS . 'kj_payment/' . $template . '.html')) {
        return $THIS_STYLE_PATH_ABS . 'kj_payment/';
    }

    // a style that the plugin has no templates for gets the bootstrap ones, they say that the style is not supported
    return dirname(__DIR__) . '/html/' . (kjp_style_folder() ?: 'bootstrap') . '/';
}

/**
 * folder in "html" of the templates for the style of the site, or for the style it depends on
 *
 * @return string empty when the plugin has no templates for them
 */
function kjp_style_folder(): string
{
    global $config;

    $styles = [empty($config['style']) ? 'bootstrap' : $config['style'], trim((string) ($config['style_depend_on'] ?? ''))];

    foreach ($styles as $style) {
        if (in_array($style, ['bootstrap', 'og_default'], true)) {
            return $style;
        }
    }

    return '';
}

/**
 * file of a payment method of this plugin
 *
 * @param  string       $method
 * @return string|false false when it is not a plain name, it becomes a path and a class name
 */
function kjp_method_file(string $method)
{
    if (! preg_match('/^[a-z0-9_]+$/i', $method)) {
        return false;
    }

    return dirname(__DIR__) . '/method/' . $method . '.php';
}

/**
 * id of the current user, 0 for guests
 *
 * @return int
 */
function kjp_user_id(): int
{
    global $usrcp;

    return $usrcp->name() ? max(0, (int) $usrcp->id()) : 0;
}

/**
 * a permission of this plugin, for the group of the current user or for another group,
 * the guests have no permissions of the plugin, so user_can() of Kleeja warns about them
 *
 * @param  string $acl_name access_bought_files or recaive_profits
 * @param  int    $group_id
 * @return bool
 */
function kjp_can(string $acl_name, int $group_id = 0): bool
{
    global $d_groups, $userinfo;

    $group_id = $group_id ?: (int) ($userinfo['group_id'] ?? 2);

    return ! empty($d_groups[$group_id]['acls'][$acl_name]);
}

/**
 * the founders of the site and the owner of a file download it without paying
 *
 * @param  mixed $owner the user column of the file
 * @return bool
 */
function kjp_downloads_free($owner): bool
{
    global $usrcp;

    $user_id = kjp_user_id();

    if (! $user_id) {
        return false;
    }

    //files of guests have no owner
    if ((int) $owner > 0 && (int) $owner === $user_id) {
        return true;
    }

    $data = $usrcp->get_data('founder');

    return ! empty($data['founder']);
}

function getFileInfo($fileID = 0, $getInfo = '*')
{
    global $SQL, $dbprefix;

    $fileID = (int) $fileID;

    if ($fileID <= 0 || ! preg_match('/^[a-z0-9_*, ]+$/i', (string) $getInfo)) {
        return false;
    }

    $result = $SQL->build([
        'SELECT' => $getInfo,
        'FROM' => "{$dbprefix}files",
        'WHERE' => 'id = :id',
        'LIMIT' => '1',
        'BIND' => ['id' => $fileID],
    ]);

    $return = $SQL->fetch_array($result);
    $SQL->freeresult($result);

    if (! $return) {
        return false;
    }

    //the payment methods read the name of what they sell from "name"
    if (! empty($return['real_filename'])) {
        $return['name'] = $return['real_filename'];
    }

    return $return;
}

/**
 * a row of the payments or the payouts table
 *
 * @param  string      $table
 * @param  mixed       $db_id
 * @param  array       $where  more conditions as column => value, a list of values works like IN
 * @param  bool        $mixAll add the parts of payment_more_info to the row
 * @return array|false
 */
function kjp_get_row(string $table, $db_id, array $where, bool $mixAll)
{
    global $SQL, $dbprefix;

    $conditions = ['id = :id'];
    $bind = ['id' => (int) $db_id];

    foreach ($where as $column => $value) {
        //the names of the columns are written in the code, only the values come from the visitors
        if (! preg_match('/^[a-z_]+$/', (string) $column)) {
            return false;
        }

        $conditions[] = is_array($value) ? "{$column} IN (:{$column})" : "{$column} = :{$column}";
        $bind[$column] = $value;
    }

    $result = $SQL->build([
        'SELECT' => '*',
        'FROM' => "{$dbprefix}{$table}",
        'WHERE' => implode(' AND ', $conditions),
        'LIMIT' => '1',
        'BIND' => $bind,
    ]);

    $row = $SQL->fetch_array($result);
    $SQL->freeresult($result);

    if (! $row) {
        return false;
    }

    return $mixAll ? payment_more_info('from_db', $row) : $row;
}

function getPaymentInfo($db_id = 0, array $where = [], $mixAll = false)
{
    return kjp_get_row('payments', $db_id, $where, (bool) $mixAll);
}

function getPayoutInfo($db_id = 0, array $where = [], $mixAll = false)
{
    return kjp_get_row('payments_out', $db_id, $where, (bool) $mixAll);
}

/*
 * $groupData = $args['d_groups'];
 * $getGroup = 'all' or 'the id of group'
 */
function getGroupInfo($groupData, $getGroup = 'all')
{
    $return = [];

    foreach ((array) $groupData as $data) {
        //not the default groups, not the group of the new members, and it has a price
        if (
            $data['data']['group_id'] <= 3 ||
            $data['data']['group_is_default'] != 0 ||
            ($data['configs']['kjp_join_price'] ?? 0) <= 0
        ) {
            continue;
        }

        $group = [
            'id' => $data['data']['group_id'],
            'name' => $data['data']['group_name'],
            'price' => $data['configs']['kjp_join_price'],
            'kjp_min_payout_limit' => $data['configs']['kjp_min_payout_limit'] ?? 0,
        ];

        if ($getGroup === 'all') {
            $return[] = $group;
        } elseif ((int) $getGroup === (int) $group['id']) {
            return $group;
        }
    }

    return count($return) ? $return : false;
}

function is_style_supported()
{
    //the plugin has templates for the bootstrap and the og_default styles,
    //other styles bring their own templates in a "kj_payment" folder
    return kjp_style_folder() !== '';
}

/*
 * MySql sum function was making some problems with float strings
 * so this is another way to collect the final result for kj_payment control panel
 * this function is used only in kj_payment_options.php
 */
function KJPayFinalData()
{
    global $SQL, $dbprefix;

    $periods = ['all' => 0, 'monthly' => 0, 'daily' => 0];
    $counts = array_fill_keys(array_keys($periods), ['num' => 0, 'amount' => 0]);
    $methods = ['paypal' => $counts, 'balance' => $counts, 'cards' => $counts];

    $result = $SQL->build([
        'SELECT' => 'payment_amount, payment_method, payment_more_info, payment_year, payment_month, payment_day',
        'FROM' => "{$dbprefix}payments",
        'WHERE' => "payment_state = 'approved'",
    ]);

    while ($row = $SQL->fetch($result)) {
        $row = payment_more_info('from_db', $row);
        $in = ['all'];

        if ($row['payment_year'] == date('Y') && $row['payment_month'] == date('m')) {
            $in[] = 'monthly';

            if ($row['payment_day'] == date('d')) {
                $in[] = 'daily';
            }
        }

        $method = $row['payment_method'];
        //the fees of PayPal are known, so its amount is the net profit
        $amount = (float) $row['payment_amount'] - ($method == 'paypal' ? (float) ($row['paypal_payment_fees'] ?? 0) : 0);

        foreach ($in as $period) {
            $periods[$period]++;

            if (isset($methods[$method])) {
                $methods[$method][$period]['num']++;
                $methods[$method][$period]['amount'] = round($methods[$method][$period]['amount'] + $amount, 4);
            }
        }
    }

    $SQL->freeresult($result);

    return ['kj_payments' => $periods] + $methods;
}

/**
 * all pages in kj_payment_option.php need to get user by id from the database ,
 * so we will bring all data only one time by this function .
 */
function UserById()
{
    global $SQL, $dbprefix;

    $return = [];
    $result = $SQL->build(['SELECT' => 'id, name', 'FROM' => "{$dbprefix}users"]);

    while ($user = $SQL->fetch($result)) {
        $return[$user['id']] = $user['name'];
    }

    $SQL->freeresult($result);

    return $return;
}

/**
 * names of some users by their ids, for the pages of the members
 *
 * @param  array $ids
 * @return array
 */
function kjp_user_names(array $ids): array
{
    global $SQL, $dbprefix;

    $return = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));

    if (! $ids) {
        return $return;
    }

    $result = $SQL->build([
        'SELECT' => 'id, name',
        'FROM' => "{$dbprefix}users",
        'WHERE' => 'id IN (:ids)',
        'BIND' => ['ids' => $ids],
    ]);

    while ($user = $SQL->fetch($result)) {
        $return[$user['id']] = $user['name'];
    }

    $SQL->freeresult($result);

    return $return;
}

/**
 * approved payments of a month (mm-yyyy) or a day (dd-mm-yyyy)
 */
function get_archive($date = '30-2-yyyy')
{
    global $SQL, $dbprefix;

    $parts = array_map('intval', explode('-', (string) $date));

    if (count($parts) == 3) {
        $date = ['year' => $parts[2], 'month' => $parts[1], 'day' => $parts[0]];
    } elseif (count($parts) == 2) {
        $date = ['year' => $parts[1], 'month' => $parts[0]];
    } else {
        $date = ['year' => (int) date('Y'), 'month' => (int) date('m')];
    }

    // we dont want to write it again , we will add some change and evrybody is happy .
    $query = [
        'SELECT' => 'payment_action, payment_method, payment_more_info, payment_amount, payment_year, payment_month, payment_day',
        'FROM' => "{$dbprefix}payments",
        'WHERE' =>
            "payment_state = 'approved' AND payment_year = :year AND payment_month = :month" .
            (isset($date['day']) ? ' AND payment_day = :day' : ''),
        'BIND' => $date,
    ];

    $paymentActions = ['all' => []];
    $result = $SQL->build($query);

    while ($row = $SQL->fetch($result)) {
        foreach (['all', $row['payment_action']] as $action) {
            if (! isset($paymentActions[$action][$row['payment_method']])) {
                $paymentActions[$action][$row['payment_method']] = ['num' => 0, 'amount' => 0];
            }

            $counts = &$paymentActions[$action][$row['payment_method']];
            $counts['num']++;
            $counts['amount'] = round($counts['amount'] + (float) $row['payment_amount'], 4);
            unset($counts);
        }
    }

    $SQL->freeresult($result);

    return compact('query', 'date', 'paymentActions');
}

function create_Archive_Panel($action, $actionInfo, $isForAll = false)
{
    global $olang, $config;

    $action_name = $olang['KJP_ACT_ARCH_' . strtoupper($action)] ?? strtoupper(kleeja_html_encode($action));
    $title = $isForAll ? $olang['KJP_ALL_TRNC'] : sprintf($olang['KJP_ARCH_TBL_NAME'], $action_name);
    $count = 0;
    $rows = '';

    foreach ($actionInfo as $method => $counts) {
        $count += $counts['num'];
        $rows .=
            '<li class="list-group-item kj-kv"><span>' .
            ($olang['KJP_MTHD_NAME_' . strtoupper($method)] ?? kleeja_html_encode($method)) .
            ' <span class="kj-badge is-neutral">' .
            $counts['num'] .
            '</span></span><span dir="ltr">' .
            kjp_price($counts['amount'], strtoupper($config['kjp_iso_currency_code'])) .
            '</span></li>';
    }

    return '<div class="' .
        ($isForAll ? 'col-12' : 'col-md-6') .
        '"><div class="card h-100"><div class="card-header kj-card-header">' .
        '<h2 class="kj-card-title">' .
        $title .
        '</h2><span class="kj-badge is-neutral ms-auto">' .
        $count .
        '</span></div>' .
        ($isForAll ? '<div class="card-body py-2 small text-body-secondary">' . $olang['KJP_TRNC_CP_INFO'] . '</div>' : '') .
        '<ul class="list-group list-group-flush">' .
        $rows .
        '</ul></div></div>';
}

function getPaymentMethods($withoutFilter = false)
{
    global $SQL, $dbprefix;
    static $methods = null;

    if ($methods === null) {
        $methods = [];
        $result = $SQL->build([
            'SELECT' => 'c.name',
            'FROM' => "{$dbprefix}config c",
            'WHERE' => "c.value = '1' AND c.type = 'kj_pay_active_mthd'",
        ]);

        while ($row = $SQL->fetch($result)) {
            $methods[] = str_replace('kjp_active_', '', $row['name']);
        }

        $SQL->freeresult($result);
    }

    $return = $methods;

    if ($withoutFilter) {
        return $return;
    }

    extract(runHook('KJP:get_payment_methods', get_defined_vars()));

    return $return;
}

/**
 * a random token, the download links are protected with it
 */
function createToken($length = 16)
{
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $token = '';

    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $token;
}

/**
 * the details of a payment method (payer name, payer mail ....) are different for every method,
 * so they are saved together in one column as key->value::key->value
 *
 * to_db   = an array to the text of the column, @return string
 * from_db = a row of the table, the parts of its "payment_more_info" are added to it, @return array
 *
 * @param mixed $action
 * @param mixed $data
 */
function payment_more_info($action, $data = [])
{
    if ($action == 'to_db') {
        $return = [];

        foreach ($data as $key => $value) {
            //values come from the payment providers, they are saved encoded like other texts,
            //a value that is read from the table and saved again is not encoded twice
            $value = kleeja_html_encode(htmlspecialchars_decode(trim((string) $value), ENT_QUOTES));

            //the separator can not be a part of a value ("->" is encoded already)
            if ($value !== '') {
                $return[] = $key . '->' . str_replace('::', ':', $value);
            }
        }

        return implode('::', $return);
    }

    $info = (string) ($data['payment_more_info'] ?? '');
    // we dont need it anymore
    unset($data['payment_more_info']);

    foreach (explode('::', $info) as $part) {
        $part = explode('->', $part, 2);

        //a detail never replaces a column of the row
        if (count($part) == 2 && $part[0] !== '' && ! array_key_exists($part[0], $data)) {
            $data[$part[0]] = $part[1];
        }
    }

    return $data;
}

/**
 * name of what is sold as a plain text, it is shown on the pages of the payment provider
 *
 * @param  string $action buy_file, join_group, subscripe or an action of another plugin
 * @param  string $name
 * @return string
 */
function kjp_item_label(string $action, string $name): string
{
    global $olang;

    $label = sprintf($olang['KJP_ACT_' . strtoupper($action)] ?? '%s', $name);
    $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES, 'UTF-8')));

    //the providers limit it to 127 characters
    return preg_match('/^.{1,120}/us', $label, $match) ? $match[0] : 'Kleeja';
}

/**
 * what a payment is for, as it is shown in the lists of the payments
 *
 * @param  string $action
 * @param  string $item_name
 * @return string
 */
function kjp_action_title(string $action, string $item_name): string
{
    global $olang;

    //the actions of other plugins have their own words, which are gone with their plugins
    return sprintf($olang['KJP_ACT_' . strtoupper($action)] ?? '%s', $item_name);
}

function kjp_method_title(string $method): string
{
    global $olang;

    return $olang['KJP_MTHD_NAME_' . strtoupper($method)] ?? kleeja_html_encode($method);
}

/**
 * a price, an amount or a balance as it is shown, always with two decimals (9 is 9.00),
 * the FLOAT columns give 9 or 9.9899997711182, and it goes in the inputs of the prices too
 *
 * @param  mixed  $amount
 * @param  string $currency added after the amount when it is given
 * @return string
 */
function kjp_price($amount, string $currency = ''): string
{
    $price = number_format((float) $amount, 2, '.', '');

    return $currency === '' ? $price : $price . ' ' . $currency;
}

/**
 * seconds that a download link lives, 0 when it never expires
 *
 * @return int
 */
function kjp_link_lifetime(): int
{
    global $config;

    return max(0, (int) ($config['kjp_down_link_expire'] ?? 0)) * 86400;
}

/**
 * link that downloads a bought file, the begin_download_page hook checks it
 *
 * @param  array  $payment the row of the payment
 * @return string
 */
function kjp_down_link(array $payment): string
{
    global $config;

    return $config['siteurl'] .
        'do.php?downPaidFile=' .
        (int) $payment['item_id'] .
        '_' .
        (int) $payment['id'] .
        '_' .
        $payment['payment_token'];
}

/**
 * send the download link of a bought file to the buyer, as an HTML e-mail of Kleeja
 *
 * @param  string $to         e-mail address of the buyer
 * @param  array  $payment    the row of the payment
 * @param  string $linkExpire when the link expires, as the page of the payment shows it
 * @return bool
 */
function kjp_mail_download_link(string $to, array $payment, string $linkExpire): bool
{
    global $config, $olang;

    // the texts are encoded by send_mail(), and the name of the file is HTML encoded already in the database
    $blocks = [
        ['type' => 'text', 'content' => $olang['KJP_MAIL_THANKS']],
        [
            'type' => 'text',
            'content' => implode("\n", [
                $olang['KJP_FILE_NAME'] . ': ' . $payment['item_name'],
                $olang['KJP_PAY_AMNT'] . ': ' . kjp_price($payment['payment_amount'], $payment['payment_currency']),
                $olang['KJP_PAY_MTHD'] . ': ' . kjp_method_title((string) $payment['payment_method']),
                $olang['KJP_PAY_ID'] . ': ' . (int) $payment['id'],
            ]),
        ],
        ['type' => 'button', 'link' => kjp_down_link($payment), 'label' => $olang['KJP_MAIL_DOWNLOAD']],
        // a link that expires works only with the cookie of the browser that paid, see kjp_has_download_access()
        [
            'type' => 'alert',
            'content' => kjp_link_lifetime()
                ? sprintf($olang['KJP_MAIL_EXPIRE'], $linkExpire)
                : $olang['KJP_MAIL_NO_EXPIRE'],
        ],
    ];

    $subject = sprintf($olang['KJP_MAIL_SUBJECT'], html_entity_decode($payment['item_name'], ENT_QUOTES, 'UTF-8'));

    return send_mail($to, $blocks, $subject, $config['sitemail'], $config['sitename']);
}

/**
 * time of a payment from its date columns
 *
 * @param  array $payment
 * @return int
 */
function kjp_payment_time(array $payment): int
{
    $time = array_map('intval', explode(':', (string) $payment['payment_time'])) + [0, 0, 0];

    return (int) mktime(
        $time[0],
        $time[1],
        $time[2],
        (int) $payment['payment_month'],
        (int) $payment['payment_day'],
        (int) $payment['payment_year'],
    );
}

/**
 * the visitor bought this file, the link he opened has the payment id and its token
 *
 * @param  mixed $file_id
 * @return bool
 */
function kjp_has_download_access($file_id): bool
{
    global $usrcp;

    if (! ig('downToken') || ! ig('db')) {
        return false;
    }

    $payment = getPaymentInfo(g('db', 'int'), [
        'item_id' => (int) $file_id,
        'payment_action' => 'buy_file',
        'payment_state' => 'approved',
        'payment_token' => g('downToken'),
    ]);

    if (! $payment) {
        return false;
    }

    $lifetime = kjp_link_lifetime();

    //links that never expire
    if (! $lifetime) {
        return true;
    }

    //members who have the purchases page keep what they bought
    if ((int) $payment['user'] > 0 && (int) $payment['user'] === kjp_user_id() && kjp_can('access_bought_files')) {
        return true;
    }

    //others need the cookie of the browser they paid with, until the link expires
    $cookie = explode('_', (string) $usrcp->kleeja_get_cookie('downloadFile_' . (int) $file_id));

    if (
        count($cookie) != 3 ||
        (int) $cookie[0] !== (int) $file_id ||
        (int) $cookie[1] !== (int) $payment['id'] ||
        ! hash_equals((string) $payment['payment_token'], $cookie[2])
    ) {
        return false;
    }

    return kjp_payment_time($payment) + $lifetime >= time();
}

/**
 * add a payment, the time, the IP and the buyer are filled here
 *
 * @param  array $payment columns of the payments table, payment_more_info as an array
 * @return int   id of the payment
 */
function kjp_insert_payment(array $payment): int
{
    global $SQL, $dbprefix;

    $payment += [
        'payment_state' => 'created',
        'payment_more_info' => [],
        'payment_payer_ip' => get_ip(),
        'user' => kjp_user_id(),
        'payment_year' => (int) date('Y'),
        'payment_month' => (int) date('m'),
        'payment_day' => (int) date('d'),
        'payment_time' => date('H:i:s'),
    ];

    $payment['payment_more_info'] = payment_more_info('to_db', $payment['payment_more_info']);
    $payment['item_id'] = (int) $payment['item_id'];

    $columns = [
        'payment_state',
        'payment_method',
        'payment_more_info',
        'payment_amount',
        'payment_currency',
        'payment_token',
        'payment_payer_ip',
        'payment_action',
        'item_id',
        'item_name',
        'user',
        'payment_year',
        'payment_month',
        'payment_day',
        'payment_time',
    ];

    $SQL->build([
        'INSERT' => implode(', ', $columns),
        'INTO' => "{$dbprefix}payments",
        'VALUES' => ':' . implode(', :', $columns),
        'BIND' => array_intersect_key($payment, array_flip($columns)),
    ]);

    return (int) $SQL->insert_id();
}

/**
 * a created payment becomes approved, only once,
 * so opening the return link again or two requests at the same time can not give the item twice
 *
 * @param  int   $id
 * @param  array $more_info details of the payment method
 * @return bool  false when the payment is not waiting anymore
 */
function kjp_approve_payment(int $id, array $more_info = []): bool
{
    global $SQL, $dbprefix;

    $SQL->build([
        'UPDATE' => "{$dbprefix}payments",
        'SET' => "payment_state = 'approved', payment_more_info = :info",
        'WHERE' => "id = :id AND payment_state = 'created'",
        'BIND' => ['info' => payment_more_info('to_db', $more_info), 'id' => $id],
    ]);

    return $SQL->affected() === 1;
}

/**
 * the buyer went back without paying
 *
 * @param  int  $id
 * @return bool
 */
function kjp_cancel_payment(int $id): bool
{
    global $SQL, $dbprefix;

    $SQL->build([
        'UPDATE' => "{$dbprefix}payments",
        'SET' => "payment_state = 'canceled'",
        'WHERE' => "id = :id AND payment_state = 'created'",
        'BIND' => ['id' => $id],
    ]);

    return $SQL->affected() === 1;
}

/**
 * the buyer left the page of the payment provider without paying, he goes back to what he wanted to buy
 *
 * @param array $payment_info the row of the payment
 */
function kjp_payment_canceled(array $payment_info): void
{
    global $config;

    kjp_cancel_payment((int) $payment_info['id']);

    unset($_SESSION['kj_payment']);

    if ($payment_info['payment_action'] == 'buy_file') {
        redirect($config['siteurl'] . 'do.php?file=' . (int) $payment_info['item_id']);
    } elseif ($payment_info['payment_action'] == 'join_group') {
        redirect($config['siteurl'] . 'go.php?go=paid_group');
    } elseif ($payment_info['payment_action'] == 'subscripe') {
        redirect($config['siteurl'] . 'go.php?go=subscription');
    }

    extract(runHook('KjPay:PayCancel_' . $payment_info['payment_action'], get_defined_vars()));

    redirect($config['siteurl']);
}

/**
 * take an amount from the balance of a user, only when the balance covers it,
 * so two requests at the same time can not spend the same balance twice
 *
 * @param  int   $user_id
 * @param  float $amount
 * @return bool
 */
function kjp_take_balance(int $user_id, float $amount): bool
{
    global $SQL, $dbprefix;

    if ($user_id <= 0 || $amount <= 0) {
        return false;
    }

    $SQL->build([
        'UPDATE' => "{$dbprefix}users",
        //the balance is a FLOAT column, the rounding and the small margin are for its inexact values
        'SET' => 'balance = ROUND(balance - :amount, 4)',
        'WHERE' => 'id = :id AND balance >= :amount - 0.0001',
        'BIND' => ['amount' => $amount, 'id' => $user_id],
    ]);

    return $SQL->affected() === 1;
}

/**
 * add an amount to the balance of a user
 *
 * @param int   $user_id
 * @param float $amount
 */
function kjp_give_balance(int $user_id, float $amount): void
{
    global $SQL, $dbprefix;

    if ($user_id <= 0 || $amount <= 0) {
        return;
    }

    $SQL->build([
        'UPDATE' => "{$dbprefix}users",
        'SET' => 'balance = ROUND(balance + :amount, 4)',
        'WHERE' => 'id = :id',
        'BIND' => ['amount' => $amount, 'id' => $user_id],
    ]);
}

/**
 * give the buyer what an approved payment is for, all the payment methods end here
 *
 * @param  array $payment the row of the payment
 * @return array variables for the page of the successful payment
 */
function kjp_apply_payment(array $payment): array
{
    global $SQL, $dbprefix, $config, $olang, $usrcp, $subscription;

    $toGlobal = [];
    $action = $payment['payment_action'];
    $buyer = (int) $payment['user'];
    $item_id = (int) $payment['item_id'];

    if ($action == 'join_group' && $buyer > 0) {
        $SQL->build([
            'UPDATE' => "{$dbprefix}users",
            'SET' => 'group_id = :group_id',
            'WHERE' => 'id = :id',
            'BIND' => ['group_id' => $item_id, 'id' => $buyer],
        ]);

        $toGlobal['groupName'] = $payment['item_name'];
    } elseif ($action == 'buy_file') {
        $toGlobal['file_name'] = $payment['item_name'];
        $toGlobal['down_link'] = kjp_down_link($payment);

        // becuse the payment is successfuly , let's give some profits to the file owner
        $file = getFileInfo($item_id, 'user');
        $owner = $file ? (int) $file['user'] : 0;

        //files of guests have no owner
        if ($owner > 0) {
            $owner_data = $usrcp->get_data('group_id', $owner);

            if ($owner_data && kjp_can('recaive_profits', (int) $owner_data['group_id'])) {
                kjp_give_balance(
                    $owner,
                    ((float) $payment['payment_amount'] * (float) $config['kjp_file_owner_profits']) / 100,
                );
            }
        }
    } elseif ($action == 'subscripe' && $buyer > 0) {
        $package_expire = (int) $subscription->expire_at($item_id);

        $SQL->build([
            'UPDATE' => "{$dbprefix}users",
            'SET' => 'package = :package, package_expire = :expire',
            'WHERE' => 'id = :id',
            'BIND' => ['package' => $item_id, 'expire' => $package_expire, 'id' => $buyer],
        ]);

        $subscription->forget($buyer);

        $olang['KJP_JUIN_SUCCESS'] = sprintf(
            $olang['KJP_SUCCESS_SUBSCRIPE'],
            $payment['item_name'],
            date('Y/m/d', $package_expire),
        );
        $toGlobal['olang'] = $olang;
    } else {
        //export here $toGlobal and do what u want
        extract(runHook('KjPay:notFoundedAction_' . $action, get_defined_vars()));
    }

    return is_array($toGlobal) ? $toGlobal : [];
}

/**
 * page numbers of the pages of this plugin
 *
 * @param  Pagination $pager
 * @param  string     $link
 * @return string
 */
function kjp_page_nums(Pagination $pager, string $link): string
{
    global $config;

    //these pages have no rewrite rules, so their page numbers are always in the query string
    $mod_writer = $config['mod_writer'];
    $config['mod_writer'] = 0;
    $html = $pager->print_nums($link);
    $config['mod_writer'] = $mod_writer;

    return $html;
}

/**
 * run a query page by page, the page comes from ?page=
 *
 * @param  array  $query   a query for $SQL->build()
 * @param  string $link    link of the page, the page number is added to it
 * @param  int    $perpage
 * @return array  [result of the current page or false when there are no rows, html of the page numbers]
 */
function kjp_paginate(array $query, string $link, int $perpage = 21): array
{
    global $SQL;

    $count = ['SELECT' => 'COUNT(*) AS total_rows'] + $query;
    unset($count['ORDER BY']);

    $result = $SQL->build($count);
    $row = $SQL->fetch_array($result);
    $SQL->freeresult($result);

    $total = (int) ($row['total_rows'] ?? 0);

    if (! $total) {
        return [false, ''];
    }

    $pager = new Pagination($perpage, $total, ig('page') ? g('page', 'int') : 1);

    $query['LIMIT'] = ':kjp_start, :kjp_perpage';
    $query['BIND'] = ($query['BIND'] ?? []) + ['kjp_start' => $pager->getStartRow(), 'kjp_perpage' => $perpage];

    return [$SQL->build($query), kjp_page_nums($pager, $link)];
}
