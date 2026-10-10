<?php
// kleeja plugin
// developer: Kleeja Team
// what the installation, the update and the removal of the plugin need

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

/**
 * tables of the plugin, in the MySQL dialect, Kleeja converts them for SQLite
 *
 * @return array
 */
function kjp_tables(): array
{
    global $dbprefix;

    return [
        'payments' => "CREATE TABLE IF NOT EXISTS `{$dbprefix}payments` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `payment_state` text COLLATE utf8_bin NOT NULL,
            `payment_method` VARCHAR(100) NULL DEFAULT NULL,
            `payment_more_info` LONGTEXT DEFAULT NULL,
            `payment_amount` float NOT NULL,
            `payment_currency` VARCHAR(10) NOT NULL,
            `payment_token` text COLLATE utf8_bin NOT NULL,
            `payment_payer_ip` text COLLATE utf8_bin NOT NULL,
            `payment_action` text COLLATE utf8_bin NOT NULL,
            `item_id` int(11) NOT NULL,
            `item_name` text COLLATE utf8_bin NOT NULL,
            `user` int(11) NOT NULL,
            `payment_year` int(11) NOT NULL,
            `payment_month` int(11) NOT NULL,
            `payment_day` int(11) NOT NULL,
            `payment_time` text COLLATE utf8_bin NOT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;",

        'payments_out' => "CREATE TABLE IF NOT EXISTS `{$dbprefix}payments_out` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `user` int(11) NOT NULL,
            `method` text COLLATE utf8_bin NOT NULL,
            `amount` float NOT NULL,
            `payment_more_info` text COLLATE utf8_bin NOT NULL,
            `payout_year` int(11) NOT NULL,
            `payout_month` int(11) NOT NULL,
            `payout_day` int(11) NOT NULL,
            `payout_time` text COLLATE utf8_bin NOT NULL,
            `state` text COLLATE utf8_bin NOT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;",

        'subscriptions' => "CREATE TABLE IF NOT EXISTS `{$dbprefix}subscriptions` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `name` text COLLATE utf8_bin NOT NULL,
            `days` int(11) NOT NULL,
            `price` float NOT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;",

        'subscription_point' => "CREATE TABLE IF NOT EXISTS `{$dbprefix}subscription_point` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user` int(11) NOT NULL,
            `file_id` int(11) NOT NULL,
            `subscription_id` int(11) NOT NULL,
            `subscripe_hash` text COLLATE utf8_bin NOT NULL,
            `time` int(11) NOT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;",
    ];
}

/**
 * create a table of the plugin if it is not there
 *
 * @param string $name a key of kjp_tables()
 */
function kjp_create_table(string $name): void
{
    global $SQL, $dbprefix;

    $query = kjp_tables()[$name];

    // for SQLite, Kleeja changes every "int" of the query to the integer type, the one in "subscription_point" too,
    // so that table is made with another name then it takes its own
    if ($SQL->driver === 'sqlite' && stripos($name, 'int') !== false) {
        $result = $SQL->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name", [
            'name' => $dbprefix . $name,
        ]);

        $exists = $SQL->fetch($result);
        $SQL->freeresult($result);

        if (! $exists) {
            kjp_update_query(str_replace("`{$dbprefix}{$name}`", "`{$dbprefix}kjp_new_table`", $query));
            kjp_update_query("ALTER TABLE `{$dbprefix}kjp_new_table` RENAME TO `{$dbprefix}{$name}`");
        }

        return;
    }

    kjp_update_query($query);
}

/**
 * columns that the plugin adds to the tables of Kleeja, as [table, column, definition]
 *
 * @return array
 */
function kjp_columns(): array
{
    return [
        ['files', 'price', "FLOAT NOT NULL DEFAULT '0'"],
        ['users', 'package', "INT NOT NULL DEFAULT '0'"],
        ['users', 'balance', "FLOAT NOT NULL DEFAULT '0.00'"],
        ['users', 'subs_point', "INT NOT NULL DEFAULT '0'"],
        ['users', 'package_expire', "INT NOT NULL DEFAULT '0'"],
    ];
}

/**
 * settings of the plugin, as name => [default value, field, where it is shown, order]
 *
 * a payment method is a setting named "kjp_active_{method}" of the type "kj_pay_active_mthd",
 * check getPaymentMethods() function for more informations
 *
 * @return array
 */
function kjp_options(): array
{
    return [
        'kjp_join_price' => ['0', 'text', 'groups', 0],
        'kjp_min_payout_limit' => ['0', 'text', 'groups', 1],
        'kjp_active_subscriptions' => ['0', 'yesno', 'kleeja_payment', 0],
        'kjp_active_live_mode' => ['0', 'yesno', 'kleeja_payment', 0],
        'kjp_paypal_client_id' => ['', 'text', 'kleeja_payment', 1],
        'kjp_paypal_client_secret' => ['', 'secret', 'kleeja_payment', 2],
        'kjp_stripe_secret_key' => ['', 'secret', 'kleeja_payment', 4],
        'kjp_iso_currency_code' => ['USD', 'text', 'kleeja_payment', 5],
        'kjp_down_link_expire' => ['1', 'text', 'kleeja_payment', 6],
        'kjp_file_owner_profits' => ['50', 'text', 'kleeja_payment', 7],
        'kjp_max_price_limit' => ['5', 'text', 'kleeja_payment', 8],
        'kjp_min_price_limit' => ['1', 'text', 'kleeja_payment', 9],
        'kjp_active_balance' => ['1', 'yesno', 'kj_pay_active_mthd', 0],
        'kjp_active_paypal' => ['1', 'yesno', 'kj_pay_active_mthd', 1],
        'kjp_active_cards' => ['1', 'yesno', 'kj_pay_active_mthd', 2],
    ];
}

/**
 * the input of a setting on the settings page
 *
 * @param  string $name
 * @param  string $field text, yesno or secret
 * @return string
 */
function kjp_config_field(string $name, string $field): string
{
    //the keys of the payment providers are not shown on the screen
    if ($field === 'secret') {
        return '<input type="password" id="' .
            $name .
            '" name="' .
            $name .
            '" value="{con.' .
            $name .
            '}" size="50" autocomplete="new-password" />';
    }

    return configField($name, $field);
}

/**
 * the settings as add_config_r() takes them
 *
 * @param  int   $plg_id
 * @param  array $only   names of the wanted settings, all of them by default
 * @return array
 */
function kjp_config_rows(int $plg_id, array $only = []): array
{
    $rows = [];

    foreach (kjp_options() as $name => [$value, $field, $type, $order]) {
        if ($only && ! in_array($name, $only, true)) {
            continue;
        }

        $rows[$name] = [
            'value' => $value,
            'html' => kjp_config_field($name, $field),
            'plg_id' => $plg_id,
            'type' => $type,
            'order' => $order,
        ];
    }

    return $rows;
}

/*
 * The update of a plugin runs while Kleeja is still loading the plugins, before the groups and the current user
 * are known, and the config functions of Kleeja need them. They also run hooks, which loads the plugins again.
 * So the update changes the tables with the functions below.
 */

/**
 * run a query that may fail, like adding a column that is there from a try before
 *
 * @param string $query
 */
function kjp_update_query(string $query): void
{
    global $SQL;

    $show_errors = $SQL->show_errors;
    $SQL->show_errors = false;
    $SQL->query($query);
    $SQL->show_errors = $show_errors;
}

function kjp_update_config_exists(string $name): bool
{
    global $SQL, $dbprefix;

    $result = $SQL->build([
        'SELECT' => 'c.name',
        'FROM' => "{$dbprefix}config c",
        'WHERE' => 'c.name = :name',
        'BIND' => ['name' => $name],
    ]);

    $row = $SQL->fetch($result);
    $SQL->freeresult($result);

    return (bool) $row;
}

/**
 * add settings that are not there, they are not settings of the groups
 *
 * @param array $rows from kjp_config_rows()
 */
function kjp_update_add_configs(array $rows): void
{
    global $SQL, $dbprefix;

    foreach ($rows as $name => $row) {
        if (kjp_update_config_exists($name)) {
            continue;
        }

        $SQL->build([
            'INSERT' => '`name`, `value`, `option`, `display_order`, `type`, `plg_id`, `dynamic`',
            'INTO' => "{$dbprefix}config",
            'VALUES' => ':name, :value, :option, :display_order, :type, :plg_id, 0',
            'BIND' => [
                'name' => $name,
                'value' => $row['value'],
                'option' => $row['html'],
                'display_order' => (int) $row['order'],
                'type' => $row['type'],
                'plg_id' => (int) $row['plg_id'],
            ],
        ]);
    }
}

/**
 * give a setting a new name and a new input, its values stay, the ones of the groups too
 *
 * @param string $old
 * @param string $new
 * @param string $html
 */
function kjp_update_rename_config(string $old, string $new, string $html): void
{
    global $SQL, $dbprefix;

    if (kjp_update_config_exists($new)) {
        kjp_update_delete_configs([$old]);

        return;
    }

    $SQL->build([
        'UPDATE' => "{$dbprefix}config",
        'SET' => '`name` = :new, `option` = :option',
        'WHERE' => '`name` = :old',
        'BIND' => ['new' => $new, 'option' => $html, 'old' => $old],
    ]);

    $SQL->build([
        'UPDATE' => "{$dbprefix}groups_data",
        'SET' => '`name` = :new',
        'WHERE' => '`name` = :old',
        'BIND' => ['new' => $new, 'old' => $old],
    ]);
}

function kjp_update_delete_configs(array $names): void
{
    global $SQL, $dbprefix;

    foreach (['config', 'groups_data'] as $table) {
        $SQL->build([
            'DELETE' => "{$dbprefix}{$table}",
            'WHERE' => '`name` IN (:names)',
            'BIND' => ['names' => $names],
        ]);
    }
}

/**
 * id of this plugin in the plugins table, 0 if it is not there
 *
 * @return int
 */
function kjp_update_plugin_id(): int
{
    global $SQL, $dbprefix;

    $result = $SQL->build([
        'SELECT' => 'p.plg_id',
        'FROM' => "{$dbprefix}plugins p",
        'WHERE' => 'p.plg_name = :name',
        'BIND' => ['name' => 'kleeja_payment'],
    ]);

    $row = $SQL->fetch($result);
    $SQL->freeresult($result);

    return (int) ($row['plg_id'] ?? 0);
}

/**
 * the cached settings and the compiled templates of the old version are made again
 */
function kjp_update_clear_cache(): void
{
    $files = array_merge(
        [PATH . 'cache/data_config.php', PATH . 'cache/data_groups.php'],
        glob(PATH . 'cache/tpl_*.php') ?: [],
    );

    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
