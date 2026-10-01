<?php
// kleeja plugin
// developer: Kleeja Team

// the payments of a period on the Status Reports page of the plugin kleeja_advanced_stats,
// it is added by the hook status_reports_extra_html of init.php
if (! defined('IN_ADMIN')) {
    exit;
}

/**
 * the payments and the payouts of the period of the report, compared with the previous period
 *
 * @param  array  $args the variables of the page of the report (b_status_reports.php of kleeja_advanced_stats)
 * @return string the HTML of html/admin/kjp_status_report.html
 */
function kjp_status_report(array $args): string
{
    global $SQL, $dbprefix, $config, $olang, $tpl;

    $from = (int) $args['from_ts'];
    $to = (int) $args['to_ts'];
    $prev_from = (int) $args['prev_from_ts'];
    // the points of the charts of the report are days, months or years of the time zone of the site
    $points = array_map('strval', array_keys($args['sr_buckets']));
    $point_format = (string) $args['sr_unit_format'];
    $offset = (int) $args['sr_offset'];
    $currency = strtoupper((string) $config['kjp_iso_currency_code']);
    $page_url = basename(ADMIN_PATH) . '?cp=kj_payment_options';

    // left to right isolate, so a sum is not turned around after an Arabic word
    $ltr = fn(string $text): string => "\u{2066}{$text}\u{2069}";
    $money = fn(float $amount): string => number_format($amount, 2) . ' ' . $currency;
    // the charts draw text, not HTML
    $text = fn(string $html): string => trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

    // [this period, the previous period], like the sums of the report
    $sum = ['sales' => [0, 0], 'amount' => [0.0, 0.0], 'started' => [0, 0], 'buyers' => [[], []]];
    $fees = 0.0;
    $canceled = $unfinished = 0;
    $members = $methods = $actions = $items = $latest = $series = [];

    // the dates of a payment are of GMT (includes/common.php sets it), the days of the report are of the site,
    // so the rows of the days around the period are read, and their times are compared
    $result = $SQL->build([
        'SELECT' =>
            'p.id, p.payment_state, p.payment_method, p.payment_more_info, p.payment_amount, p.payment_action, ' .
            'p.item_id, p.item_name, p.user, p.payment_payer_ip, p.payment_year, p.payment_month, p.payment_day, p.payment_time',
        'FROM' => "{$dbprefix}payments p",
        'WHERE' => '(p.payment_year * 10000 + p.payment_month * 100 + p.payment_day) BETWEEN :from AND :to',
        'ORDER BY' => 'p.id DESC',
        'BIND' => ['from' => (int) gmdate('Ymd', $prev_from), 'to' => (int) gmdate('Ymd', $to)],
    ]);

    while ($row = $SQL->fetch($result)) {
        $time = kjp_payment_time($row);

        if ($time < $prev_from || $time > $to) {
            continue;
        }

        $i = $time >= $from ? 0 : 1;
        $sum['started'][$i]++;

        // created is a payment that the buyer did not finish, or is still paying
        if ($row['payment_state'] != 'approved') {
            if (! $i) {
                $row['payment_state'] == 'canceled' ? $canceled++ : $unfinished++;
            }

            continue;
        }

        $amount = (float) $row['payment_amount'];
        $sum['sales'][$i]++;
        $sum['amount'][$i] += $amount;
        $sum['buyers'][$i][(int) $row['user'] > 0 ? 'u' . (int) $row['user'] : 'g' . $row['payment_payer_ip']] = true;

        if ($i) {
            continue;
        }

        $row = payment_more_info('from_db', $row);
        $method = (string) $row['payment_method'];
        $action = (string) $row['payment_action'];
        $item = $action . ':' . (int) $row['item_id'];

        // only PayPal says its fees
        if ($method == 'paypal') {
            $fees += (float) ($row['paypal_payment_fees'] ?? 0);
        }

        if ((int) $row['user'] > 0) {
            $members[(int) $row['user']] = true;
        }

        $methods[$method] ??= ['count' => 0, 'amount' => 0.0];
        $methods[$method]['count']++;
        $methods[$method]['amount'] += $amount;

        $actions[$action] ??= ['count' => 0, 'amount' => 0.0];
        $actions[$action]['count']++;
        $actions[$action]['amount'] += $amount;

        $items[$item] ??= [
            'action' => $action,
            'item_id' => (int) $row['item_id'],
            'name' => $row['item_name'],
            'count' => 0,
            'amount' => 0.0,
        ];
        $items[$item]['count']++;
        $items[$item]['amount'] += $amount;

        $series[$method] ??= array_fill_keys($points, 0.0);
        $point = gmdate($point_format, $time + $offset);

        if (isset($series[$method][$point])) {
            $series[$method][$point] += $amount;
        }

        $latest[] = $row + ['time' => $time];
    }

    $SQL->freeresult($result);

    // the newest first
    usort($latest, fn(array $a, array $b): int => $b['time'] <=> $a['time']);
    $latest = array_slice($latest, 0, 6);

    //
    // payouts that the members asked for in this period
    //
    $payouts = array_fill_keys(['all', 'recived', 'sent', 'verify', 'cancel'], ['count' => 0, 'amount' => 0.0]);

    $result = $SQL->build([
        'SELECT' => 'o.amount, o.state, o.payout_year, o.payout_month, o.payout_day, o.payout_time',
        'FROM' => "{$dbprefix}payments_out o",
        'WHERE' => '(o.payout_year * 10000 + o.payout_month * 100 + o.payout_day) BETWEEN :from AND :to',
        'BIND' => ['from' => (int) gmdate('Ymd', $from), 'to' => (int) gmdate('Ymd', $to)],
    ]);

    while ($row = $SQL->fetch($result)) {
        $time = kjp_payment_time([
            'payment_time' => $row['payout_time'],
            'payment_year' => $row['payout_year'],
            'payment_month' => $row['payout_month'],
            'payment_day' => $row['payout_day'],
        ]);

        if ($time < $from || $time > $to) {
            continue;
        }

        foreach (array_unique(['all', $row['state']]) as $state) {
            if (isset($payouts[$state])) {
                $payouts[$state]['count']++;
                $payouts[$state]['amount'] += (float) $row['amount'];
            }
        }
    }

    $SQL->freeresult($result);

    //
    // the cards
    //

    // how much a number has changed since the previous period, like the cards of the report
    $trend = function (float $current, float $previous, string $previous_text) use ($olang): array {
        if (round($current, 2) == round($previous, 2)) {
            $trend = ['trend_icon' => 'minus', 'change' => '0%'];
        } elseif ($previous == 0) {
            $trend = ['trend_icon' => 'arrow-up', 'change' => $olang['KJP_SR_NEW']];
        } else {
            $trend = [
                'trend_icon' => $current > $previous ? 'arrow-up' : 'arrow-down',
                'change' => round((abs($current - $previous) / $previous) * 100) . '%',
            ];
        }

        return $trend + ['has_trend' => true, 'previous' => sprintf($olang['KJP_SR_PREVIOUS'], $previous_text)];
    };

    // completed checkouts of all the checkouts that were started
    $rate = fn(int $i): string => $sum['started'][$i] ? round(($sum['sales'][$i] / $sum['started'][$i]) * 100) . '%' : '-';

    $cards = [
        [
            'title' => $olang['KJP_SR_REVENUE'],
            'value' => $money($sum['amount'][0]),
            'meta' => $fees > 0 ? sprintf($olang['KJP_SR_NET'], $ltr($money($sum['amount'][0] - $fees))) : '',
            'icon' => 'sack-dollar',
            'tile' => '',
        ] + $trend($sum['amount'][0], $sum['amount'][1], $ltr($money($sum['amount'][1]))),
        [
            'title' => $olang['KJP_SR_SALES'],
            'value' => $sum['sales'][0],
            'meta' => sprintf(
                $olang['KJP_SR_AVERAGE'],
                $ltr($money($sum['sales'][0] ? $sum['amount'][0] / $sum['sales'][0] : 0)),
            ),
            'icon' => 'cart-shopping',
            'tile' => ' is-navy',
        ] + $trend($sum['sales'][0], $sum['sales'][1], (string) $sum['sales'][1]),
        [
            'title' => $olang['KJP_SR_BUYERS'],
            'value' => count($sum['buyers'][0]),
            'meta' => sprintf($olang['KJP_SR_MEMBERS'], count($members)),
            'icon' => 'users',
            'tile' => '',
        ] + $trend(count($sum['buyers'][0]), count($sum['buyers'][1]), (string) count($sum['buyers'][1])),
        // a change of a rate in percent of the rate says little, the rate of the previous period is shown instead
        [
            'title' => $olang['KJP_SR_COMPLETED'],
            'value' => $rate(0),
            'meta' => sprintf($olang['KJP_SR_NOT_COMPLETED'], $canceled, $unfinished),
            'icon' => 'circle-check',
            'tile' => ' is-navy',
            'has_trend' => false,
            'trend_icon' => '',
            'change' => '',
            'previous' => sprintf($olang['KJP_SR_PREVIOUS'], $ltr($rate(1))),
        ],
    ];

    //
    // methods, types and items, the biggest amounts first
    //
    $by_amount = fn(array $a, array $b): int => $b['amount'] <=> $a['amount'];
    $percent = fn(float $part): float => $sum['amount'][0] > 0 ? round(($part / $sum['amount'][0]) * 100, 1) : 0;

    uasort($methods, $by_amount);
    uasort($actions, $by_amount);
    uasort($items, $by_amount);

    $methods_rows = $types_rows = $items_rows = $chart_methods = $chart_types = [];

    // the colours of the charts, a fourth one for every other method
    foreach (array_keys($methods) as $i => $method) {
        $methods_rows[] = [
            'swatch' => min($i, 3),
            'name' => kjp_method_title($method),
            'count' => $methods[$method]['count'],
            'amount' => $money($methods[$method]['amount']),
            'share' => $percent($methods[$method]['amount']),
        ];

        $chart_methods[] = [
            'name' => $text(kjp_method_title($method)),
            'data' => array_values(array_map(fn(float $amount): float => round($amount, 2), $series[$method])),
        ];
    }

    foreach (array_keys($actions) as $i => $action) {
        $name = $olang['KJP_ACT_ARCH_' . strtoupper($action)] ?? kleeja_html_encode($action);

        $types_rows[] = [
            'swatch' => min($i, 3),
            'name' => $name,
            'count' => $actions[$action]['count'],
            'amount' => $money($actions[$action]['amount']),
            'share' => $percent($actions[$action]['amount']),
        ];

        $chart_types[] = ['name' => $text($name), 'value' => round($actions[$action]['amount'], 2)];
    }

    foreach (array_slice($items, 0, 8) as $item) {
        $items_rows[] = [
            'name' => $item['name'] === '' ? '#' . $item['item_id'] : $item['name'],
            'type' => $olang['KJP_ACT_ARCH_' . strtoupper($item['action'])] ?? kleeja_html_encode($item['action']),
            'link' =>
                $page_url .
                '&amp;smt=all_transactions&amp;action=' .
                preg_replace('/[^a-z0-9_]/i', '', $item['action']) .
                '&amp;item_id=' .
                $item['item_id'],
            'count' => $item['count'],
            'amount' => $money($item['amount']),
        ];
    }

    //
    // the latest payments
    //
    $names = kjp_user_names(array_column($latest, 'user'));
    $latest_rows = [];

    foreach ($latest as $payment) {
        $latest_rows[] = [
            'link' => $page_url . '&amp;smt=view&amp;payment=' . (int) $payment['id'],
            'id' => (int) $payment['id'],
            'title' => kjp_action_title((string) $payment['payment_action'], (string) $payment['item_name']),
            'buyer' => $names[(int) $payment['user']] ?? $olang['KJP_GUEST'],
            'method' => kjp_method_title((string) $payment['payment_method']),
            'amount' => $money((float) $payment['payment_amount']),
            'time' => kleeja_date($payment['time'], human_time: false),
            'time_human' => kleeja_date($payment['time']),
        ];
    }

    //
    // payouts
    //
    $payouts_rows = [];

    foreach (
        [
            'all' => $olang['KJP_SR_REQUESTED'],
            'recived' => $olang['KJP_POUT_ST_RECIVED'],
            'sent' => $olang['KJP_POUT_ST_SENT'],
            'verify' => $olang['KJP_POUT_ST_VERIFY'],
            'cancel' => $olang['KJP_POUT_ST_CANCEL'],
        ]
        as $state => $title
    ) {
        if ($state == 'all' || $payouts[$state]['count']) {
            $payouts_rows[] = [
                'title' => $title,
                'count' => $payouts[$state]['count'],
                'amount' => $money($payouts[$state]['amount']),
            ];
        }
    }

    $tpl->assign('kjp_sr', [
        'has_any' => $sum['started'][0] + $payouts['all']['count'] > 0,
        'has_sales' => $sum['sales'][0] > 0,
        'currency' => $currency,
        'page' => $page_url,
        'payouts_link' => $page_url . '&amp;smt=payouts',
        'transactions_link' => $page_url . '&amp;smt=all_transactions',
    ]);
    $tpl->assign('kjp_sr_cards', $cards);
    $tpl->assign('kjp_sr_methods', $methods_rows);
    $tpl->assign('kjp_sr_types', $types_rows);
    $tpl->assign('kjp_sr_items', $items_rows);
    $tpl->assign('kjp_sr_latest', $latest_rows);
    $tpl->assign('kjp_sr_payouts', $payouts_rows);
    // drawn by assets/status_reports.js
    $tpl->assign(
        'kjp_sr_chart',
        json_encode(
            [
                'unit' => (string) $args['sr_unit'],
                'points' => $points,
                'currency' => $currency,
                'methods' => $chart_methods,
                'types' => $chart_types,
            ],
            JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT |
                JSON_UNESCAPED_UNICODE |
                JSON_INVALID_UTF8_SUBSTITUTE,
        ),
    );

    return $tpl->display('kjp_status_report', dirname(__DIR__) . '/html/admin/');
}
