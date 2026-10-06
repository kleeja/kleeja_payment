<?php

/*
 * Balance Method, the members pay with the balance that they earned from their files
 */

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

class kjPayMethod_balance implements KJPaymentMethod
{
    private string $currency;
    private $successPayment = false; // its return the payment state after checking it
    private $varsForCreate = []; // some methods will work in kleeja without leaving the website
    private $toGlobal = []; // the list of vars that we want to export it to kleeja
    private $downloadLinkMailer = ''; // the mail that we want to send download link to it

    public function paymentStart()
    {
        global $lang, $config;

        if (! kjp_can('recaive_profits')) {
            /**
             * this will check for permission
             * and also it will check if user is login or not .
             * anyway , the Guest don't have this permission
             * if the user have this permission , that mean it's able for hem to use the balance
             */
            kleeja_err($lang['USER_PLACE'], '', true, $config['siteurl']);
        } elseif (! in_array('balance', getPaymentMethods())) {
            kleeja_err('it\'s not active method', '', true, $config['siteurl']);
        }
    }

    public function setCurrency(string $currency)
    {
        // it's not important , but ... it's the InterFace
        $this->currency = $currency;
    }

    public function CreatePayment(string $do, array $info)
    {
        global $config;

        $_SESSION['kj_payment'] = [
            'payment_method' => 'balance',
            'payment_action' => $do,
            'item_id' => (int) $info['id'],
            'item_name' => $info['name'],
        ];

        $form_name = $this->formName($_SESSION['kj_payment']);

        $this->varsForCreate['no_request'] = false;
        $this->varsForCreate['titlee'] = 'Pay By Balance';
        $this->varsForCreate['stylee'] = 'pay_balance';
        $this->varsForCreate['styleePath'] = kjp_template_path('pay_balance');
        $this->varsForCreate['FormAction'] =
            $config['siteurl'] .
            'go.php?go=kj_payment&amp;method=balance&amp;action=check&amp;' .
            kleeja_add_form_key_get($form_name);
        $this->varsForCreate['itemName'] = $info['name'];
        $this->varsForCreate['payAction'] = kjp_action_title($do, (string) $info['name']);
        $this->varsForCreate['paymentCurrency'] = $this->currency;
        $this->varsForCreate['itemPrice'] = $info['price'] . ' ' . $this->currency;
        $this->varsForCreate['kjFormKeyPost'] = kleeja_add_form_key($form_name);
    }

    public function varsForCreatePayment(): array
    {
        return $this->varsForCreate;
    }

    public function checkPayment()
    {
        global $config, $usrcp, $d_groups, $lang, $olang, $subscription;

        $session = $_SESSION['kj_payment'] ?? [];

        if (! $usrcp->name()) {
            // to be sure 100% , thats we are on the right way
            kleeja_err($lang['USER_PLACE'], '', true, $config['siteurl']);
        }
        // is he comming from our page
        elseif (empty($session['payment_action']) || ($session['payment_method'] ?? '') != 'balance') {
            kleeja_err($lang['ERROR_NAVIGATATION'], '', true, $config['siteurl']);
        }
        // really from our page
        elseif (
            ! kleeja_check_form_key($this->formName($session)) ||
            ! kleeja_check_form_key_get($this->formName($session))
        ) {
            kleeja_err($lang['INVALID_FORM_KEY'], '', true, $config['siteurl']);
        }

        $itemInfo = false;

        // really really , check if the item is exists
        if ($session['payment_action'] == 'buy_file') {
            if (! ($itemInfo = getFileInfo($session['item_id']))) {
                kleeja_err($olang['KJP_FL_NT_FUND'], '', true, $config['siteurl']);
            }
        } elseif ($session['payment_action'] == 'join_group') {
            if (! ($itemInfo = getGroupInfo($d_groups, (int) $session['item_id']))) {
                kleeja_err($olang['KJP_GP_NT_FUND'], '', true, $config['siteurl'] . 'go.php?go=paid_group');
            }
        } elseif ($session['payment_action'] == 'subscripe') {
            if (! $session['item_id'] || ! ($itemInfo = $subscription->get($session['item_id']))) {
                kleeja_err($lang['ERROR_NAVIGATATION'], '', true, $config['siteurl'] . 'go.php?go=subscription');
            }
        }

        //export here $itemInfo
        extract(runHook('KjPay:itemInfoExport_' . $session['payment_action'], get_defined_vars()));

        // no Error , let's check if the user have this amount in hes balance or not
        $itemPrice = is_array($itemInfo) ? (float) ($itemInfo['price'] ?? 0) : 0;

        if ($itemPrice <= 0) {
            // this is free item
            kleeja_err($olang['KJP_FRE_ITM'], '', true, $config['siteurl']);
        }

        // i will take the money from you , then i will give you the item loooool
        // it is taken only when the balance covers it, in one query
        if (! kjp_take_balance(kjp_user_id(), $itemPrice)) {
            // son , collect some money , then come to buy
            kleeja_err($olang['KJP_NO_BLNC'], '', true, $config['siteurl']);
        }

        // The money is token now , so this item is HALAL for you Now
        $payment = [
            'payment_state' => 'approved',
            'payment_method' => 'balance',
            'payment_amount' => $itemPrice,
            'payment_currency' => $this->currency,
            'payment_token' => createToken(),
            'payment_action' => $session['payment_action'],
            'item_id' => (int) $session['item_id'],
            'item_name' => $session['item_name'],
            'user' => kjp_user_id(),
        ];

        $payment['id'] = kjp_insert_payment($payment);

        $_SESSION['kj_payment']['db_id'] = $payment['id'];
        $_SESSION['kj_payment']['payment_token'] = $payment['payment_token'];

        $this->downloadLinkMailer = (string) $usrcp->mail();
        $this->toGlobal = kjp_apply_payment($payment);

        // now we can say that the payment made successfuly
        $this->successPayment = true;
    }

    public function isSuccess(): bool
    {
        return $this->successPayment;
    }

    public function getGlobalVars(): array
    {
        return $this->toGlobal;
    }

    public function linkMailer(): string
    {
        return (string) $this->downloadLinkMailer;
    }

    public function createPayout(array $itemInfo)
    {
        //
    }

    public function checkPayout(array $payoutInfo)
    {
        //
    }

    public static function permission(string $permission): bool
    {
        return $permission == 'createPayment';
    }

    /**
     * name of the form key of a payment, the confirmation page and its check use the same one
     *
     * @param  array  $payment from the session
     * @return string
     */
    private function formName(array $payment): string
    {
        return 'payFor_' . $payment['payment_action'] . $payment['item_name'] . $payment['item_id'];
    }
}
