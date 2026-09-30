<?php
// kleeja plugin
// developer: Kleeja Team

// not for directly open
if (! defined('IN_ADMIN')) {
    exit;
}

if (intval($userinfo['founder']) !== 1) {
    kleeja_admin_err($lang['HV_NOT_PRVLG_ACCESS'], basename(ADMIN_PATH));

    exit;
}

$styleePath = dirname(__DIR__) . '/html/admin/';

$UserById = UserById();

$current_smt = preg_replace('/[^a-z0-9_]/i', '', g('smt', 'str', ''));
// for the tabs of the pages
$kjp_is_home = empty($current_smt);
$page_url = basename(ADMIN_PATH) . '?cp=kj_payment_options';
// the guide of the plugin is on the help page of Kleeja, php/help.php adds it there
$kjp_help_url = basename(ADMIN_PATH) . '?cp=s_help&amp;page=kj_payment_options';
$currency = strtoupper($config['kjp_iso_currency_code']);
$page_nums = '';
// the forms of this page change the prices and send money, all of them have this key
$H_FORM_KEYS = kleeja_add_form_key('kj_payment_options');

// the help page that the plugin had before, old links still point to it
if ($current_smt == 'help') {
    redirect($kjp_help_url);
}

if (empty($current_smt)) {
    $FormActions = $page_url;

    $stylee = 'admin_quick_info';

    if (ip('open_payment') && p('payment_number', 'int') > 0) {
        redirect($page_url . '&amp;smt=view&amp;payment=' . p('payment_number', 'int'));
    } elseif (ip('open_archive')) {
        $archive_day = p('archive_day', 'int') > 0 ? p('archive_day', 'int') . '-' : '';

        redirect(
            $page_url .
                '&amp;smt=archive&amp;date=' .
                $archive_day .
                p('archive_month', 'int') .
                '-' .
                p('archive_year', 'int'),
        );
    }

    // add any information u want by this three panels
    // 0 => array(  'methodName' => 'PayPal' , 'htmlContent' => '<h1> display this info </h1>'  )
    $all_trnc_panel = $monthly_trnc_panel = $daily_trnc_panel = [];

    // all Transactions
    // this function getting informations about transactions of the methods of this plugin
    // other method have to calculate they transactions and adding it the CP
    $trncactionsInformation = KJPayFinalData();

    $trnc_count = $trncactionsInformation['kj_payments']['all'];
    //daily Transactions
    $daily_trnc_count = $trncactionsInformation['kj_payments']['daily'];
    // monthly Transactions
    $monthly_trnc_count = $trncactionsInformation['kj_payments']['monthly'];

    foreach (['paypal' => 'PayPal', 'cards' => 'Stripe', 'balance' => 'Balance'] as $method => $methodName) {
        $panels = ['all' => 'all_trnc_panel', 'monthly' => 'monthly_trnc_panel', 'daily' => 'daily_trnc_panel'];

        foreach ($panels as $period => $panel) {
            $totals = $trncactionsInformation[$method][$period];

            ${$panel}[] = [
                'methodName' => $methodName . ' <span class="kj-badge is-neutral">' . $totals['num'] . '</span>',
                // the fees of PayPal are known, other methods show the amount with the fees
                'htmlContent' =>
                    ($method == 'paypal' ? $olang['KJP_NT_PRFIT'] . ' : ' : '') .
                    '<span dir="ltr">' .
                    round($totals['amount'], 2) .
                    ' ' .
                    $currency .
                    '</span>',
            ];
        }
    }

    // add what u want to the panels by this hook using the examples befor
    extract(runHook('kjPay:addToCPanel', get_defined_vars()));

    $viewAll_btn = $page_url . '&amp;smt=all_transactions';
    $viewtoday_btn = $page_url . '&amp;smt=all_transactions&amp;today=1';
    $viewmonth_btn = $page_url . '&amp;smt=all_transactions&amp;thismonth=1';

    // Pending Payments
    $PendPay = [];

    [$result, $page_nums] = kjp_paginate(
        [
            'SELECT' =>
                'p.id, p.payment_payer_ip, p.payment_action, p.payment_method, p.item_name, ' .
                    'p.user, p.payment_year, p.payment_month, p.payment_day, p.payment_time',
            'FROM' => "{$dbprefix}payments p",
            'WHERE' => "p.payment_state = 'created'",
            'ORDER BY' => 'p.id DESC',
        ],
        $page_url,
    );

    while ($result && ($rows = $SQL->fetch($result))) {
        $PendPay[] = [
            'PayID' => $rows['id'],
            'PayUser' => $rows['user'] > 0 ? $UserById[$rows['user']] ?? $rows['user'] : $olang['KJP_GUEST'],
            'PayAction' => kjp_action_title($rows['payment_action'], $rows['item_name']),
            'PayMethod' => kjp_method_title((string) $rows['payment_method']),
            'PayIP' => $rows['payment_payer_ip'],
            'PayDateTime' => "{$rows['payment_year']}-{$rows['payment_month']}-{$rows['payment_day']} / {$rows['payment_time']}",
        ];
    }

    $PendPayNum = (bool) $PendPay;

    $years = [];
    $result = $SQL->build([
        'SELECT' => 'DISTINCT p.payment_year',
        'FROM' => "{$dbprefix}payments p",
        'WHERE' => "p.payment_state = 'approved'",
        'ORDER BY' => 'p.payment_year DESC',
    ]);

    while ($year = $SQL->fetch($result)) {
        $years[]['value'] = $year['payment_year'];
    }

    $SQL->freeresult($result);

    if (count($years) == 0) {
        $years[]['value'] = date('Y');
    }

    // Lazy person !! Ja Ja , Normalerweise bin ich faul .
    $months = $days = [];

    for ($i = 1; $i < 13; $i++) {
        $months[] = ['value' => $i, 'selected' => $i == date('n')];
    }

    for ($i = 1; $i < 32; $i++) {
        $days[]['value'] = $i;
    }

    // show all transactions .
} elseif ($current_smt == 'all_transactions') {
    $stylee = 'all_transactions';

    // get all transactions informations
    $all_trnc_page_title = $olang['KJP_ALL_TRNC'];
    $page_link = $page_url . '&amp;smt=all_transactions';

    $query = [
        'SELECT' =>
            'id, payment_action, payment_method, payment_amount, payment_currency, item_id, ' .
                'item_name, user, payment_year, payment_month, payment_day, payment_time',
        'FROM' => "{$dbprefix}payments",
        'WHERE' => "payment_state = 'approved'",
        'ORDER BY' => 'id DESC',
        'BIND' => [],
    ];

    // show daily transactions
    if (g('today', 'int') == 1) {
        $query['WHERE'] .= ' AND payment_year = :year AND payment_month = :month AND payment_day = :day';
        $query['BIND'] = ['year' => (int) date('Y'), 'month' => (int) date('m'), 'day' => (int) date('d')];
        $page_link .= '&amp;today=1';
        $all_trnc_page_title = $olang['KJP_D_TRNC'];
    }
    // show the transactions of this month
    elseif (g('thismonth', 'int') == 1) {
        $query['WHERE'] .= ' AND payment_year = :year AND payment_month = :month';
        $query['BIND'] = ['year' => (int) date('Y'), 'month' => (int) date('m')];
        $page_link .= '&amp;thismonth=1';
        $all_trnc_page_title = $olang['KJP_M_TRNC'];
    }
    // show all transactions of an item, like buying a file or joining a group
    elseif (ig('action') && ig('item_id')) {
        $trnc_action = preg_replace('/[^a-z0-9_]/i', '', g('action'));
        $trnc_item = g('item_id', 'int');

        $query['WHERE'] .= ' AND payment_action = :action AND item_id = :item_id';
        $query['BIND'] = ['action' => $trnc_action, 'item_id' => $trnc_item];
        $page_link .= '&amp;action=' . $trnc_action . '&amp;item_id=' . $trnc_item;

        if ($trnc_action == 'buy_file') {
            $trnc_file = getFileInfo($trnc_item, 'real_filename');
            $all_trnc_page_title = sprintf(
                $olang['KJP_PAY_OF'],
                sprintf($olang['KJP_ACT_BUY_FILE'], $trnc_file ? $trnc_file['name'] : '#' . $trnc_item),
            );
        } elseif ($trnc_action == 'join_group') {
            // if the group become for free later , it is not a paid group anymore
            $all_trnc_page_title = sprintf(
                $olang['KJP_PAY_OF'],
                sprintf($olang['KJP_ACT_JOIN_GROUP'], $d_groups[$trnc_item]['data']['group_name'] ?? '#' . $trnc_item),
            );
        } else {
            // export this msg only ,,,$all_trnc_page_title
            extract(runHook('KjPay:allTrnc_' . $trnc_action, get_defined_vars()));
        }
    }
    // show all transactions of this user
    elseif (g('user', 'int') > 0) {
        $query['WHERE'] .= ' AND user = :user';
        $query['BIND'] = ['user' => g('user', 'int')];
        $page_link .= '&amp;user=' . g('user', 'int');
        $all_trnc_page_title = $olang['KJP_USR_PAYMNT'] . ' : ' . ($UserById[g('user', 'int')] ?? g('user', 'int'));
    }
    // if the buyer of the file is not member , we can bring his/her payments by IP .
    elseif (ig('ip')) {
        $trnc_ip = preg_replace('/[^0-9a-f.:]/i', '', g('ip'));

        $query['WHERE'] .= ' AND payment_payer_ip = :ip';
        $query['BIND'] = ['ip' => $trnc_ip];
        $page_link .= '&amp;ip=' . $trnc_ip;
        $all_trnc_page_title = $olang['KJP_IP_PAYMNT'] . ' : ' . $trnc_ip;
    }
    // to check the payments that used this method
    elseif (ig('method')) {
        $trnc_method = preg_replace('/[^a-z0-9_]/i', '', g('method'));

        $query['WHERE'] .= ' AND payment_method = :method';
        $query['BIND'] = ['method' => $trnc_method];
        $page_link .= '&amp;method=' . $trnc_method;
        $all_trnc_page_title = $olang['KJP_PAY_BY_MTHD'] . ' : ' . kjp_method_title($trnc_method);
    }

    $transactions = [];

    [$result, $page_nums] = kjp_paginate($query, $page_link);

    while ($result && ($trnc = $SQL->fetch($result))) {
        $transactions[] = [
            'PayID' => $trnc['id'],
            'PayUser' => $trnc['user'] > 0 ? $UserById[$trnc['user']] ?? $trnc['user'] : $olang['KJP_GUEST'],
            'PayAction' => kjp_action_title($trnc['payment_action'], $trnc['item_name']),
            'PayDateTime' => "{$trnc['payment_year']}-{$trnc['payment_month']}-{$trnc['payment_day']} / {$trnc['payment_time']}",
            'PayAmount' => $trnc['payment_amount'] . ' ' . $trnc['payment_currency'],
            'PayMethod' => kjp_method_title((string) $trnc['payment_method']),
            'view_link' => $page_url . '&amp;smt=view&amp;payment=' . $trnc['id'],
        ];
    }

    $have_transaction = (bool) $transactions;

    // view payment details ..
} elseif ($current_smt == 'view' && g('payment', 'int') > 0) {
    $stylee = 'view_payment';
    $id = g('payment', 'int');
    $PayInfo = getPaymentInfo($id, ['payment_state' => ['approved', 'canceled']]);
    $have_payment = (bool) $PayInfo;

    if ($PayInfo) {
        $amount = $PayInfo['payment_amount'] . ' ' . $PayInfo['payment_currency'];
        $token = $PayInfo['payment_token'];
        $payment_method = kjp_method_title((string) $PayInfo['payment_method']);
        $payer_ip = $PayInfo['payment_payer_ip'];

        $item = kjp_action_title($PayInfo['payment_action'], $PayInfo['item_name']);

        $member =
            $PayInfo['user'] > 0
                ? '<a target="_blank" href="' .
                    basename(ADMIN_PATH) .
                    '?cp=g_users&amp;smt=edit_user&amp;uid=' .
                    $PayInfo['user'] .
                    '">' .
                    ($UserById[$PayInfo['user']] ?? $PayInfo['user']) .
                    '</a>'
                : $olang['KJP_GUEST'];

        $date_time =
            $PayInfo['payment_year'] .
            '-' .
            $PayInfo['payment_month'] .
            '-' .
            $PayInfo['payment_day'] .
            ' / ' .
            $PayInfo['payment_time'];

        $file_payments_link =
            $page_url .
            '&amp;smt=all_transactions&amp;action=' .
            preg_replace('/[^a-z0-9_]/i', '', $PayInfo['payment_action']) .
            '&amp;item_id=' .
            (int) $PayInfo['item_id'];
        $file_payments = sprintf($olang['KJP_PAY_OF'], $item);

        if ($PayInfo['user'] > 0) {
            $user_payments_link = $page_url . '&amp;smt=all_transactions&amp;user=' . (int) $PayInfo['user'];
            $user_payments = $olang['KJP_USR_PAYMNT'] . ' : ' . ($UserById[$PayInfo['user']] ?? $PayInfo['user']);
        } else {
            $user_payments_link = $page_url . '&amp;smt=all_transactions&amp;ip=' . $payer_ip;
            $user_payments = $olang['KJP_IP_PAYMNT'] . ' : ' . $payer_ip;
        }

        $method_payments_link =
            $page_url .
            '&amp;smt=all_transactions&amp;method=' .
            preg_replace('/[^a-z0-9_]/i', '', (string) $PayInfo['payment_method']);
        $method_payments = $olang['KJP_PAY_BY_MTHD'] . ' : ' . $payment_method;

        $isCanceledPayment = $PayInfo['payment_state'] == 'canceled';

        // evry method have some informations
        $viewMoreTable = [];

        foreach (payment_more_info('from_db', ['payment_more_info' => $PayInfo['payment_more_info']]) as $key => $value) {
            $viewMoreTable[] = [
                'tableName' => $olang['KJP_VIW_TPL_' . strtoupper($key)] ?? strtoupper(kleeja_html_encode($key)),
                // they come from the payment providers, old ones were saved as they came
                'tableValue' => kleeja_html_encode(htmlspecialchars_decode((string) $value, ENT_QUOTES)),
            ];
        }
    }

    // set a price for file .
} elseif ($current_smt == 'pricing_file') {
    $stylee = 'add_price';
    $FormAction = $page_url . '&amp;smt=pricing_file';
    $show_price_panel = $OpenAlert = false;
    $AlertMsg = $AlertRole = '';

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

        if ($file_info = getFileInfo((int) $select_file_id)) {
            $show_price_panel = true;

            $FileID = $file_info['id'];
            $FileName = $file_info['name'];
            $FileSize = readable_size((int) $file_info['size']);
            $FileUser = $file_info['user'] > 0 ? $UserById[$file_info['user']] ?? $file_info['user'] : $olang['KJP_GUEST'];
            $FilePrice = (float) $file_info['price'];
        } else {
            $OpenAlert = true;
            $AlertMsg = $olang['KJP_NO_FILE_WITH_ID'] . ' ' . (int) $select_file_id;
            $AlertRole = 'danger';
        }
    } elseif (ip('set_price')) {
        if (! kleeja_check_form_key('kj_payment_options', 3600)) {
            kleeja_admin_err($lang['INVALID_FORM_KEY'], $FormAction);
        }

        $FileID = p('price_file_id', 'int');
        // 0 makes the file free again
        $FilePrice = max(0, round((float) p('price_file'), 2));
        $OpenAlert = true;

        if ($file_info = getFileInfo($FileID)) {
            $SQL->build([
                'UPDATE' => "{$dbprefix}files",
                'SET' => 'price = :price',
                'WHERE' => 'id = :id',
                'BIND' => ['price' => $FilePrice, 'id' => $FileID],
            ]);

            $AlertMsg = sprintf($olang['KJP_NO_FILE_NEW_PRICE'], $file_info['name'], $FilePrice, $currency);
            $AlertRole = 'success';
        } else {
            $AlertMsg = $olang['KJP_NO_FILE_WITH_ID'] . ' ' . $FileID;
            $AlertRole = 'danger';
        }
    }
} elseif ($current_smt == 'paid_files') {
    $stylee = 'paid_files';
    $FormAction = $page_url . '&amp;smt=pricing_file';

    $all_paid_file = [];

    [$result, $page_nums] = kjp_paginate(
        [
            'SELECT' => 'f.id, f.real_filename, f.user, f.price',
            'FROM' => "{$dbprefix}files f",
            'WHERE' => 'f.price > 0',
            'ORDER BY' => 'f.id DESC',
        ],
        $page_url . '&amp;smt=paid_files',
    );

    while ($result && ($paid_file = $SQL->fetch($result))) {
        $all_paid_file[] = [
            'id' => $paid_file['id'],
            'name' => $paid_file['real_filename'],
            'user' => $paid_file['user'] > 0 ? $UserById[$paid_file['user']] ?? $paid_file['user'] : $olang['KJP_GUEST'],
            'price' => $paid_file['price'] . ' ' . $currency,
            'link' => $config['siteurl'] . 'do.php?id=' . $paid_file['id'],
        ];
    }

    $have_paid_file = (bool) $all_paid_file;
} elseif ($current_smt == 'archive' && ig('date')) {
    $stylee = 'archive_data';

    $archive_date = preg_replace('/[^0-9-]/', '', g('date'));
    $Archive_data = get_archive($archive_date);
    $archive_link = $page_url . '&amp;smt=archive&amp;date=' . $archive_date;

    $archiveTables = [];

    foreach ($Archive_data['paymentActions'] as $key => $value) {
        $archiveTables[] = ['html' => create_Archive_Panel($key, $value, $key == 'all')];
    }

    extract(runHook('kjPay:addToArchive', get_defined_vars()));

    $query = $Archive_data['query'];

    $query['SELECT'] .= ', id, payment_state, payment_payer_ip, item_id, item_name, user, payment_time';
    $query['ORDER BY'] = 'id DESC';

    $ArchivePay = [];

    [$result, $page_nums] = kjp_paginate($query, $archive_link);

    while ($result && ($rows = $SQL->fetch($result))) {
        $ArchivePay[] = [
            'PayID' => $rows['id'],
            'PayUser' => $rows['user'] > 0 ? $UserById[$rows['user']] ?? $rows['user'] : $olang['KJP_GUEST'],
            'PayAction' => kjp_action_title($rows['payment_action'], $rows['item_name']),
            'PayIP' => $rows['payment_payer_ip'],
            'PayDateTime' => "{$rows['payment_year']}-{$rows['payment_month']}-{$rows['payment_day']} / {$rows['payment_time']}",
            'view_link' => $page_url . '&amp;smt=view&amp;payment=' . $rows['id'],
        ];
    }

    $ArchivePayNum = (bool) $ArchivePay;

    // Archive Payout Table
    $payouts = [];

    [$result, $page_numsPO] = kjp_paginate(
        [
            'SELECT' => '*',
            'FROM' => "{$dbprefix}payments_out",
            'WHERE' =>
                'payout_year = :year AND payout_month = :month' .
                (isset($Archive_data['date']['day']) ? ' AND payout_day = :day' : ''),
            'ORDER BY' => 'id DESC',
            'BIND' => $Archive_data['date'],
        ],
        $archive_link,
    );

    while ($result && ($row = $SQL->fetch_array($result))) {
        $payouts[] = [
            'ID' => $row['id'],
            'METHOD' => kjp_method_title($row['method']),
            'AMOUNT' => $row['amount'] . ' ' . $currency,
            'DATE_TIME' => "{$row['payout_year']}-{$row['payout_month']}-{$row['payout_day']} / {$row['payout_time']}",
            'STATE' => $olang['KJP_POUT_ST_' . strtoupper($row['state'])] ?? $row['state'],
            'PayoutUser' => $UserById[$row['user']] ?? $row['user'],
        ];
    }

    $havePayout = (bool) $payouts;
} elseif ($current_smt == 'payouts') {
    $stylee = 'payouts_list';
    $action = $page_url . '&amp;smt=payouts';
    $case = in_array(g('case'), ['accepted', 'canceled'], true) ? g('case') : 'list';
    $FormAction = $action . '&amp;case=list';

    // lets check if there is post order
    // for sending payout or canceling it
    if ($case == 'list' && (ip('sendPayout') || ip('cancelPayout')) && p('payoutID', 'int') > 0) {
        if (! kleeja_check_form_key('kj_payment_options', 3600)) {
            kleeja_admin_err($lang['INVALID_FORM_KEY'], $FormAction);
        }

        // if we had this payout in db, mix all info to make usfule array
        $pOutInfo = getPayoutInfo(p('payoutID', 'int'), ['state' => 'verify'], true);

        // check if admin want to send or cancel it
        // more secure to do it like this
        // here is for canceling payout
        if ($pOutInfo && ip('cancelPayout') && ! ip('sendPayout')) {
            //let's update the payout state and back the amount to user balance, only once
            $SQL->build([
                'UPDATE' => "{$dbprefix}payments_out",
                'SET' => "state = 'cancel'",
                'WHERE' => "id = :id AND state = 'verify'",
                'BIND' => ['id' => (int) $pOutInfo['id']],
            ]);

            if ($SQL->affected() === 1) {
                kjp_give_balance((int) $pOutInfo['user'], (float) $pOutInfo['amount']);
            }

            kleeja_admin_info(sprintf($olang['KJP_CNCLD_POUT'], $pOutInfo['amount']), $FormAction);
        }
        // the admin accept sending this amount to user
        elseif ($pOutInfo && ip('sendPayout') && ! ip('cancelPayout')) {
            require_once __DIR__ . '/kjPayment.php'; // require the payment interface

            $PaymentMethodClass = kjp_method_file((string) $pOutInfo['method']); // default payment method

            if (! $PaymentMethodClass || ! file_exists($PaymentMethodClass)) {
                $is_err = true;
                extract(runHook('KjPay:set_payout_method', get_defined_vars()));

                if ($is_err) {
                    kleeja_admin_err('The class file of ' . kleeja_html_encode($pOutInfo['method']) . ' payment is not found');
                }
            }

            require_once $PaymentMethodClass;

            $methodClassName = 'kjPayMethod_' . basename($PaymentMethodClass, '.php');

            if (! class_exists($methodClassName, false) || ! $methodClassName::permission('createPayout')) {
                kleeja_admin_err('The method dont support Creating Payouts');
            }

            $PAY = new $methodClassName();
            $PAY->paymentStart();
            $PAY->setCurrency($currency);
            // now let's make a payout
            $PAY->createPayout($pOutInfo); // send all payout data to the class

            if ($PAY->isSuccess()) {
                kleeja_admin_info(sprintf($olang['KJP_SUCS_POUT'], $pOutInfo['amount']), $FormAction);
            }

            // the reason stays on the screen, the request is still in the list
            kleeja_admin_err(
                $olang['KJP_ERR_POUT'] . (method_exists($PAY, 'getError') && $PAY->getError() ? '<br>' . $PAY->getError() : ''),
            );
        }
    }

    $states = ['list' => ['verify'], 'accepted' => ['sent', 'recived'], 'canceled' => ['cancel']];
    $payouts = [];

    [$result, $page_nums] = kjp_paginate(
        [
            'SELECT' => '*',
            'FROM' => "{$dbprefix}payments_out",
            'WHERE' => 'state IN (:states)',
            'ORDER BY' => 'id DESC',
            'BIND' => ['states' => $states[$case]],
        ],
        $action . '&amp;case=' . $case,
    );

    while ($result && ($payout = $SQL->fetch_array($result))) {
        $payout_user = $UserById[$payout['user']] ?? $payout['user'];
        $payout_amount = $payout['amount'] . ' ' . $currency;

        $payouts[] = [
            'ID' => $payout['id'],
            'USER' => $payout_user,
            'METHOD' => kjp_method_title($payout['method']),
            'AMOUNT' => $payout_amount,
            'DATE_TIME' => "{$payout['payout_year']}-{$payout['payout_month']}-{$payout['payout_day']} / {$payout['payout_time']}",
            'STATE' => $olang['KJP_POUT_ST_' . strtoupper($payout['state'])] ?? $payout['state'],
            'MDL_MSG' => sprintf($olang['KJP_POUT_REQ_MDL'], $payout_user, $payout_amount, kjp_method_title($payout['method'])),
            'VIEW_LINK' => $page_url . '&amp;smt=viewPayout&amp;id=' . $payout['id'],
        ];
    }

    $havePayout = (bool) $payouts;
    $no_payout_msg = sprintf(
        $olang['KJP_NO_ITEM'],
        ($case == 'accepted' ? $olang['KJP_ACCEPTED'] . ' ' : ($case == 'canceled' ? $olang['KJP_CANCELED'] . ' ' : '')) .
            $olang['KJP_PAYOUTS'],
    );
} elseif ($current_smt == 'viewPayout' && g('id', 'int') > 0) {
    $stylee = 'view_payout';
    $poutID = g('id', 'int');
    $FormAction = $page_url . '&amp;smt=viewPayout&amp;id=' . $poutID;

    $payoutInfo = getPayoutInfo($poutID);

    // the requests that wait for the admin are in the list of the requests
    if ($payoutInfo && $payoutInfo['state'] == 'verify') {
        $payoutInfo = false;
    }

    $have_payout = (bool) $payoutInfo;

    if ($payoutInfo) {
        if (ip('checkPayout') && p('payoutID', 'int') == $payoutInfo['id']) {
            if (! kleeja_check_form_key('kj_payment_options', 3600)) {
                kleeja_admin_err($lang['INVALID_FORM_KEY'], $FormAction);
            }

            require_once __DIR__ . '/kjPayment.php'; // require the payment interface

            $PaymentMethodClass = kjp_method_file((string) $payoutInfo['method']); // default payment method

            if (! $PaymentMethodClass || ! file_exists($PaymentMethodClass)) {
                $is_err = true;
                extract(runHook('KjPay:check_payout', get_defined_vars()));

                if ($is_err) {
                    kleeja_admin_err('The class file of ' . kleeja_html_encode($payoutInfo['method']) . ' payment is not found');
                }
            }

            require_once $PaymentMethodClass;

            $methodClassName = 'kjPayMethod_' . basename($PaymentMethodClass, '.php');

            if (! class_exists($methodClassName, false) || ! $methodClassName::permission('checkPayouts')) {
                kleeja_admin_err('The method dont support checking Payouts');
            }

            $PAY = new $methodClassName();
            $PAY->paymentStart();
            $PAY->setCurrency($currency);
            $PAY->checkPayout(payment_more_info('from_db', $payoutInfo));

            if ($PAY->isSuccess()) {
                kleeja_admin_info($olang['KJP_POUT_RCV_SUCS'], $FormAction);
            }

            kleeja_admin_err(
                $olang['KJP_POUT_NOT_RCV_SUCS'] .
                    (method_exists($PAY, 'getError') && $PAY->getError() ? '<br>' . $PAY->getError() : ''),
                $FormAction,
                '',
                true,
                $FormAction,
                6,
            );
        }

        $payout_id = $payoutInfo['id'];
        $payout_user = $UserById[$payoutInfo['user']] ?? $payoutInfo['user'];
        $payout_method = kjp_method_title($payoutInfo['method']);
        $payout_amount = $payoutInfo['amount'] . ' ' . $currency;
        $payout_date_time =
            $payoutInfo['payout_year'] .
            '-' .
            $payoutInfo['payout_month'] .
            '-' .
            $payoutInfo['payout_day'] .
            ' / ' .
            $payoutInfo['payout_time'];
        $payout_state = $olang['KJP_POUT_ST_' . strtoupper($payoutInfo['state'])] ?? $payoutInfo['state'];
        // the payout that the admin can ask the payment method about
        $payout_is_sent = $payoutInfo['state'] == 'sent';

        $viewMoreTable = [];

        foreach (payment_more_info('from_db', ['payment_more_info' => $payoutInfo['payment_more_info']]) as $key => $value) {
            $viewMoreTable[] = [
                'tableName' => $olang['KJP_VIW_TPL_' . strtoupper($key)] ?? strtoupper(kleeja_html_encode($key)),
                'tableValue' => kleeja_html_encode(htmlspecialchars_decode((string) $value, ENT_QUOTES)),
            ];
        }
    }
} elseif ($current_smt == 'subscriptions') {
    // dont disply `subscriptions` pages when it's disabled
    if (! $config['kjp_active_subscriptions']) {
        kleeja_admin_err($olang['KJP_SUBSCRIP_NOT_ACTIVE'], $page_url);
    }

    $stylee = 'subscriptions';
    $action = $page_url . '&amp;smt=subscriptions';
    $case = in_array(g('case'), ['create', 'subscription_list', 'view'], true) ? g('case') : 'subscriber';

    switch ($case) {
        case 'create':
            // let's create new subscription
            if (ip('create_subscription')) {
                if (! kleeja_check_form_key('kj_payment_options', 3600)) {
                    kleeja_admin_err($lang['INVALID_FORM_KEY'], $action . '&amp;case=create');
                }

                $subscrip_name = p('subscription_name');
                $subscrip_days = p('subscription_time', 'int');
                $subscrip_price = round((float) p('subscription_price'), 2);

                if ('' == $subscrip_name) {
                    kleeja_admin_err('Empty name', $action . '&amp;case=create');
                } elseif ($subscrip_days <= 0) {
                    kleeja_admin_err('invaled time', $action . '&amp;case=create');
                } elseif ($subscrip_price <= 0) {
                    kleeja_admin_err('invaled Price', $action . '&amp;case=create');
                }

                $SQL->build([
                    'INSERT' => 'name, days, price',
                    'INTO' => "{$dbprefix}subscriptions",
                    'VALUES' => ':name, :days, :price',
                    'BIND' => ['name' => $subscrip_name, 'days' => $subscrip_days, 'price' => $subscrip_price],
                ]);

                if ($SQL->affected()) {
                    kleeja_admin_info($olang['KJP_SUBSCRIBE_CREAT_SUCCESS'], $action . '&amp;case=subscription_list');
                }
            }

            break;

        case 'subscription_list':
            // subs => subscriptions
            $subscriptions_list = [];

            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' => '*',
                    'FROM' => "{$dbprefix}subscriptions",
                    'ORDER BY' => 'id DESC',
                ],
                $action . '&amp;case=subscription_list',
            );

            while ($result && ($subs = $SQL->fetch($result))) {
                $subscriptions_list[] = [
                    'ID' => $subs['id'],
                    'NAME' => $subs['name'],
                    'DAYS' => $subs['days'],
                    'PRICE' => $subs['price'],
                    'LINK' => $action . '&amp;case=view&amp;pack=' . $subs['id'],
                ];
            }

            $have_subscriptions = (bool) $subscriptions_list;

            break;

        case 'view':
            $package = g('pack', 'int');

            // if no package isset in the url , return
            if ($package <= 0) {
                kleeja_admin_err('NO Pack', $action);
            }

            $formAction = $action . '&amp;case=view&amp;pack=' . $package . '&amp;';

            // if try to delete
            if (ip('delete_package')) {
                // let's ckeck the form keys
                if (! kleeja_check_form_key('DELETE_' . $package) || ! kleeja_check_form_key_get('DELETE_' . $package)) {
                    kleeja_admin_err($lang['INVALID_FORM_KEY'], $action . '&amp;case=view&amp;pack=' . $package);
                }

                // delete subscripe
                $SQL->build([
                    'DELETE' => "{$dbprefix}subscriptions",
                    'WHERE' => 'id = :id',
                    'BIND' => ['id' => $package],
                ]);

                if ($SQL->affected()) {
                    // update the package of users who using this package
                    $SQL->build([
                        'UPDATE' => "{$dbprefix}users",
                        'SET' => 'package = 0',
                        'WHERE' => 'package = :package',
                        'BIND' => ['package' => $package],
                    ]);

                    kleeja_admin_info(
                        sprintf($olang['KJP_PACK_DELETE_SUCCESS'], $package),
                        $action . '&amp;case=subscription_list',
                    );
                }
            }

            $kjFormKeyPost = kleeja_add_form_key('DELETE_' . $package);
            $kjFormKeyGet = kleeja_add_form_key_get('DELETE_' . $package);

            $packContent = $subscription->get($package);

            if (! $packContent) {
                kleeja_admin_err('NO PACKAGE FOUND WITH ID ' . $package, $action . '&amp;case=subscription_list');
            }

            $packContent['MembersCount'] = $subscription->getMembersCount($package);

            break;

        case 'subscriber':
            $subscriber = [];

            [$result, $page_nums] = kjp_paginate(
                [
                    'SELECT' => '*',
                    'FROM' => "{$dbprefix}payments",
                    'WHERE' => "payment_state = 'approved' AND payment_action = 'subscripe'",
                    'ORDER BY' => 'id DESC',
                ],
                $action . '&amp;case=subscriber',
            );

            while ($result && ($subs = $SQL->fetch($result))) {
                $subscribed_at = kjp_payment_time($subs);
                $expire = $subscription->expire_at($subs['item_id'], $subscribed_at);

                $subscriber[] = [
                    'PAY_ID' => $subs['id'],
                    'PAY_LINK' => $page_url . '&amp;smt=view&amp;payment=' . $subs['id'],
                    'PAY_METHOD' => kjp_method_title((string) $subs['payment_method']),
                    'SUBSCRIPER' => $UserById[$subs['user']] ?? $subs['user'],
                    'SUBSCRIPER_LINK' => $config['siteurl'] . 'ucp.php?go=fileuser&amp;id=' . $subs['user'],
                    'PACKAGE' => $subs['item_name'],
                    'PACKAGE_LINK' => $action . '&amp;case=view&amp;pack=' . $subs['item_id'],
                    'PRICE' => $subs['payment_amount'] . ' ' . $subs['payment_currency'],
                    'SUBSCRIBE_AT' => date('Y.m.d | H:i', $subscribed_at),
                    'EXPIRE_AT' => $expire ? date('Y.m.d | H:i', $expire) : $olang['KJP_EXPIRED'],
                ];
            }

            $have_subscriber = (bool) $subscriber;

            break;
    }
} elseif ($current_smt == 'canceled_payment') {
    $stylee = 'cancel_payment';

    $cancelPayments = [];

    [$result, $page_nums] = kjp_paginate(
        [
            'SELECT' => '*',
            'FROM' => "{$dbprefix}payments",
            'WHERE' => "payment_state = 'canceled'",
            'ORDER BY' => 'id DESC',
        ],
        $page_url . '&amp;smt=canceled_payment',
    );

    while ($result && ($cancelPayment = $SQL->fetch($result))) {
        $canceled_at = kjp_payment_time($cancelPayment);

        $cancelPayments[] = [
            'PAY_ID' => $cancelPayment['id'],
            'VIEW_LINK' => $page_url . '&amp;smt=view&amp;payment=' . $cancelPayment['id'],
            'Action' => kjp_action_title($cancelPayment['payment_action'], $cancelPayment['item_name']),
            'PAY_METHOD' => kjp_method_title((string) $cancelPayment['payment_method']),
            'USER' =>
                $cancelPayment['user'] > 0
                    ? $UserById[$cancelPayment['user']] ?? $cancelPayment['user']
                    : $olang['KJP_GUEST'],
            'PRICE' => $cancelPayment['payment_amount'] . ' ' . $cancelPayment['payment_currency'],
            'PayDateTime' => kleeja_date($canceled_at),
            'Time' => date('Y.m.d | H:i', $canceled_at),
        ];
    }

    $have_cancel_payment = (bool) $cancelPayments;
}

$go_menu = [
    'all_transactions' => [
        'name' => $olang['KJP_ALL_TRNC'],
        'link' => $page_url . '&amp;smt=all_transactions',
        'goto' => 'all_transactions',
        'current' => $current_smt == 'all_transactions',
    ],
    'payouts' => [
        'name' => $olang['KJP_PAYOUTS'],
        'link' => $page_url . '&amp;smt=payouts',
        'goto' => 'payouts',
        'current' => $current_smt == 'payouts',
    ],
    'pricing_file' => [
        'name' => $olang['KJP_PRC_FILE'],
        'link' => $page_url . '&amp;smt=pricing_file',
        'goto' => 'pricing_file',
        'current' => $current_smt == 'pricing_file',
    ],
    'paid_files' => [
        'name' => $olang['KJP_PAID_FILE'],
        'link' => $page_url . '&amp;smt=paid_files',
        'goto' => 'paid_files',
        'current' => $current_smt == 'paid_files',
    ],
    'help' => [
        'name' => $olang['KJP_HLP'],
        'link' => $kjp_help_url,
        'goto' => 'help',
        'current' => false,
    ],
];
