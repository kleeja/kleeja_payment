<?php
//
// kleeja payment, its guide on the help page of the control panel
// English
//
// The guide is built in php/help.php, the texts here can have HTML.
//  - a list is numbered: KJP_HELP_TIP_1, KJP_HELP_TIP_2 ... it ends at the first missing number,
//    so an item can be added or removed without touching the code
//  - KJP_HELP_{LIST}_TITLE is the title of the list, a list without it takes the title that Kleeja gives its type
//  - questions and answers are KJP_HELP_FAQ_Q_1 and KJP_HELP_FAQ_A_1
//  - names in <strong> are written as the control panel shows them, check language/en.php when one changes
//

return [
    'KJP_HELP_TITLE' => 'Kleeja Payment',
    'KJP_HELP_INTRO' =>
        'Sell files, places in a group and subscriptions, and take the payments with PayPal, with cards through Stripe, or from the balance of a member. This guide covers the setup, the daily work in Payments Control, and the questions that buyers and file owners ask most.',

    //
    // what the plugin does
    //
    'KJP_HELP_FEATURE_1' =>
        'Put a <strong>price on a file</strong>. Visitors pay before they can download it, and guests can buy without an account.',
    'KJP_HELP_FEATURE_2' =>
        'Give a group a <strong>join price</strong>. Members pay to move into the group, and get its upload limits and permissions.',
    'KJP_HELP_FEATURE_3' =>
        'Offer <strong>subscriptions</strong>. A subscriber can download every file of the site for a number of days.',
    'KJP_HELP_FEATURE_4' =>
        'Take payments with <strong>PayPal</strong>, with cards through <strong>Stripe</strong>, and from the <strong>balance</strong> of a member. Buyers always pay on the page of PayPal or Stripe, so card details never reach your site.',
    'KJP_HELP_FEATURE_5' =>
        '<strong>Share the income</strong> with the people who upload. The owner of a file earns a percentage of each sale, and can withdraw it to a PayPal account.',
    'KJP_HELP_FEATURE_6' =>
        'Follow every payment in <strong>Payments Control</strong>: the totals of today, of this month and of all time, an archive for any day or month, and the pending and canceled payments.',

    //
    // the first setup
    //
    'KJP_HELP_SETUP_TITLE' => 'Set up the payment methods',
    'KJP_HELP_SETUP_1' =>
        'For PayPal, create a REST API app in the <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener">PayPal Developer Dashboard</a> and copy its <strong>Client ID</strong> and <strong>Secret</strong>. Every app has two pairs: one for the sandbox, and one for live payments.',
    'KJP_HELP_SETUP_2' =>
        'For Stripe, copy the <strong>Secret key</strong> from the <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">API keys page</a> of your Stripe dashboard. A key that starts with <code>sk_test_</code> takes test payments, and one that starts with <code>sk_live_</code> takes real ones.',
    'KJP_HELP_SETUP_3' =>
        'Open <strong>Settings → Kleeja Payment Settings</strong> and enter the keys. Set <strong>ISO Currency Code</strong> to a three-letter code that your payment provider supports, such as <code>USD</code> or <code>EUR</code>.',
    'KJP_HELP_SETUP_4' =>
        'Open <strong>Settings → KJPayments Active Methods</strong> and turn on the methods you want to offer. Buyers see a method only when it is on and its keys are set.',
    'KJP_HELP_SETUP_5' =>
        'Open <strong>Settings → Upload settings</strong> and set <strong>Files URLs form</strong> and <strong>Images URLs form</strong> to <strong>Row ID in database</strong>.',
    'KJP_HELP_SETUP_6' =>
        'Make a test purchase. Keep <strong>Activate Live Mode for PayPal</strong> off and use the sandbox keys, or use a Stripe test key, then buy a paid file as a guest in a private browser window.',
    'KJP_HELP_SETUP_7' =>
        'When the test works, enter the live keys and turn <strong>Activate Live Mode for PayPal</strong> on.',

    //
    // the settings, the ones of a group included
    //
    'KJP_HELP_SETTING_TITLE' => 'What the settings mean',
    'KJP_HELP_SETTING_1' =>
        '<strong>Activate Live Mode for PayPal</strong>: when it is off, PayPal works in its sandbox and no real money moves. Stripe does not use this setting, it follows the key you entered.',
    'KJP_HELP_SETTING_2' =>
        '<strong>Download Link will expire after x days</strong>: how long a buyer can use the download link, in the browser they paid with. Use <code>0</code> for links that never expire and work in any browser.',
    'KJP_HELP_SETTING_3' =>
        '<strong>Percentage of owner\'s earnings</strong>: the share of each sale that goes to the balance of the file owner. The rest stays with the site.',
    'KJP_HELP_SETTING_4' =>
        '<strong>Min price limit</strong> and <strong>Max price limit</strong>: the lowest and the highest price that members can give their own files. They do not limit the prices you set in the control panel.',
    'KJP_HELP_SETTING_5' =>
        '<strong>Join Price</strong> and <strong>Min Withdrawal Limit</strong> belong to a group: open <strong>Users &amp; Groups</strong> and select <strong>Edit data</strong> on the group. The limit is the smallest amount its members can withdraw, and <code>0</code> means any amount.',
    'KJP_HELP_SETTING_6' =>
        'The <strong>Receive Profits</strong> permission, in <strong>Edit Permissions</strong> of a group, lets its members price their own files, earn from their sales, pay with their balance and ask for withdrawals.',
    'KJP_HELP_SETTING_7' =>
        'The <strong>Access "purchases" Page</strong> permission gives the members of a group a page with every file they bought, where they can download it again at any time.',

    //
    // selling
    //
    'KJP_HELP_FILE_TITLE' => 'Sell a file',
    'KJP_HELP_FILE_1' => 'Open <strong>Payments Control → Pricing a file</strong>.',
    'KJP_HELP_FILE_2' => 'Type the ID of the file, or paste its link, and select <strong>Open file</strong>.',
    'KJP_HELP_FILE_3' =>
        'Enter the price and select <strong>Set a price</strong>. A price of <code>0</code> makes the file free again.',
    'KJP_HELP_FILE_4' =>
        'To change a price later, open <strong>Paid Files</strong>, which lists every file that has a price.',

    'KJP_HELP_GROUP_TITLE' => 'Sell a place in a group',
    'KJP_HELP_GROUP_1' =>
        'Open <strong>Users &amp; Groups</strong> and add a group for the paying members, with the upload limits and permissions you want to sell. The Admins, Guests and Users groups, and the default group of new members, cannot be sold.',
    'KJP_HELP_GROUP_2' => 'Select <strong>Edit data</strong> on the group and set its <strong>Join Price</strong>.',
    'KJP_HELP_GROUP_3' =>
        'A <strong>Paid Groups</strong> link then appears in the menu of your site. A signed-in member chooses the group and pays, and moves into it at once.',

    'KJP_HELP_SUBSCRIPTION_TITLE' => 'Offer subscriptions',
    'KJP_HELP_SUBSCRIPTION_1' =>
        'Open <strong>Settings → Kleeja Payment Settings</strong> and turn on <strong>Activate Subscriptions</strong>. A <strong>Subscriptions</strong> tab appears in Payments Control.',
    'KJP_HELP_SUBSCRIPTION_2' =>
        'Open that tab, select <strong>Create Subscriptions</strong>, and enter a name, the number of days and the price.',
    'KJP_HELP_SUBSCRIPTION_3' =>
        'Members subscribe on the <strong>Subscriptions</strong> page of your site. A member holds one subscription at a time, and can buy the next one when it ends.',
    'KJP_HELP_SUBSCRIPTION_4' =>
        'The <strong>Subscriber</strong> list shows who subscribed, how they paid and when each subscription ends.',

    //
    // withdrawals of the file owners
    //
    'KJP_HELP_PAYOUT_TITLE' => 'Pay a withdrawal request',
    'KJP_HELP_PAYOUT_1' =>
        'Open <strong>Payments Control → Payouts</strong>. <strong>Requests List</strong> shows the requests that wait for you. The amount of a request is already taken from the balance of the member.',
    'KJP_HELP_PAYOUT_2' =>
        'Select <strong>VIEW</strong> on a request. <strong>Send Payout</strong> sends the amount with PayPal to the email address of the member\'s account, and <strong>Cancel Payout</strong> returns it to their balance.',
    'KJP_HELP_PAYOUT_3' =>
        'Some time later, open the payout from <strong>Accepted</strong> and select <strong>Check Payout</strong>. Kleeja asks PayPal whether the money arrived. If PayPal could not deliver it, the payout is canceled and the amount returns to the balance of the member.',

    //
    // questions
    //
    'KJP_HELP_FAQ_Q_1' => 'Who can download a paid file without paying?',
    'KJP_HELP_FAQ_A_1' =>
        'The owner of the file and the founders of the site. When subscriptions are on, every member with a running subscription can too. A founder is not the same as a member of the Admins group: it is a role that a founder gives to an account.',
    'KJP_HELP_FAQ_Q_2' => 'PayPal or Stripe is not offered to buyers. Why?',
    'KJP_HELP_FAQ_A_2' =>
        'A method is offered when three things are true: it is turned on in <strong>KJPayments Active Methods</strong>, its keys are set in <strong>Kleeja Payment Settings</strong>, and the libraries of the plugin are installed. The Dashboard shows a notification when the keys or the libraries are missing. Installing the plugin from its release package brings the libraries with it.',
    'KJP_HELP_FAQ_Q_3' => 'A member cannot pay with their balance.',
    'KJP_HELP_FAQ_A_3' =>
        'The balance is offered only to signed-in members of a group that has the <strong>Receive Profits</strong> permission, and only while <strong>Active Balance</strong> is on. The balance also has to cover the whole price.',
    'KJP_HELP_FAQ_Q_4' => 'A buyer says they paid, but the payment is still under Pending Payments.',
    'KJP_HELP_FAQ_A_4' =>
        'A payment is approved when the buyer comes back from PayPal or Stripe to your site. If they close the page before that, it stays pending. With PayPal, no money was taken, because PayPal takes it only when the buyer comes back. With Stripe, the card may have been charged: look for the payment in your Stripe dashboard, then refund it there or send the file to the buyer yourself.',
    'KJP_HELP_FAQ_Q_5' => 'A buyer cannot download with the link from the email.',
    'KJP_HELP_FAQ_A_5' =>
        'The link works in the browser that made the payment, and until it expires. To let buyers download on any device, set the expiry of the link to <code>0</code>. Members of a group with the <strong>Access "purchases" Page</strong> permission don\'t depend on the link: they find their files on that page at any time.',
    'KJP_HELP_FAQ_Q_6' => 'How are the earnings of a file owner worked out?',
    'KJP_HELP_FAQ_A_6' =>
        'For a sale, the owner gets the price multiplied by <strong>Percentage of owner\'s earnings</strong>, added to their balance at once. With subscriptions, each paid file that a subscriber downloads gives its owner one point. When the subscription ends, the owners\' share of its price is divided between those points. An owner earns only if their group has the <strong>Receive Profits</strong> permission, and files uploaded by guests have no owner.',
    'KJP_HELP_FAQ_Q_7' => 'Why is the PayPal amount in Payments Control lower than the price?',
    'KJP_HELP_FAQ_A_7' =>
        'For PayPal, the totals show the net profit, after the fee of PayPal. The amounts of Stripe and Balance are shown in full, and the Stripe fee appears in your Stripe dashboard.',
    'KJP_HELP_FAQ_Q_8' => 'Where do members find all of this on the site?',
    'KJP_HELP_FAQ_A_8' =>
        'In their user panel. <strong>My Payments</strong> lists what they paid, and <strong>purchases</strong> lists the files they bought. Members of a group with the <strong>Receive Profits</strong> permission also have <strong>Payments Control</strong>, where they price their files, see their balance and ask for a withdrawal.',

    //
    // advice
    //
    'KJP_HELP_TIP_1' =>
        'Test the whole purchase as a guest in a private browser window. Founders and file owners are never asked to pay, so you won\'t see the payment page with your own account.',
    'KJP_HELP_TIP_2' =>
        'Give the group of your members the <strong>Access "purchases" Page</strong> permission, so they always find what they bought.',
    'KJP_HELP_TIP_3' =>
        'Set a <strong>Min Withdrawal Limit</strong> for the groups that receive profits, to avoid many small payouts.',
    'KJP_HELP_TIP_4' =>
        'Look at <strong>Pending Payments</strong> on the Home tab from time to time. A payment that stays there usually belongs to a buyer who left before finishing.',
    'KJP_HELP_TIP_5' =>
        'The earnings from subscriptions reach the balance of the file owners when the queue of Kleeja runs. Add the queue link from <strong>Maintenance</strong> as a cron job, so they are not late on a quiet site.',
    'KJP_HELP_TIP_6' =>
        'Keep the secret keys to yourself. If one leaks, create a new one in PayPal or Stripe and replace it in the settings.',

    'KJP_HELP_WARNING_1' =>
        'Don\'t set <strong>Files URLs form</strong> or <strong>Images URLs form</strong> to <strong>Direct</strong>. A direct link opens the file without any payment.',
    'KJP_HELP_WARNING_2' =>
        'When subscriptions are on, free files need a subscription too. Only the owner of a file and the founders download without one.',
    'KJP_HELP_WARNING_3' =>
        '<strong>Delete Package</strong> ends the subscription of every member who bought that package, without a refund.',
    'KJP_HELP_WARNING_4' =>
        'Refunds are made in your PayPal or Stripe account. Kleeja is not told about them, so the buyer keeps access and the file owner keeps their share.',
    'KJP_HELP_WARNING_5' =>
        '<strong>Send Payout</strong> moves real money at once and cannot be undone from Kleeja. Payouts have to be enabled for your PayPal account first. If the email address of the member has no PayPal account, PayPal returns the money to you after 30 days.',
    'KJP_HELP_WARNING_6' => 'Only a founder can open Payments Control.',

    //
    // the end of the guide
    //
    // what the other plugins of Kleeja Payment add with the KjPay:KLJ_HELP hook
    'KJP_HELP_ADDON_TITLE' => 'Add-ons of Kleeja Payment',
    'KJP_HELP_MORE_TITLE' => 'More help',
    'KJP_HELP_MORE' =>
        'Ask the Kleeja community on <a href="https://discord.gg/Mp3XVKP" target="_blank" rel="noopener">Discord</a>. The source code of the plugin and its issue tracker are on <a href="https://github.com/kleeja/kleeja_payment" target="_blank" rel="noopener">GitHub</a>.',
];
