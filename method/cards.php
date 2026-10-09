<?php

/*
 * Stripe Method, the buyer pays with his card on the page of Stripe (Checkout), then comes back
 */

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

class kjPayMethod_cards implements KJPaymentMethod
{
    private $stripe;
    private string $currency;
    private $successPayment = false; // its return the payment state after checking it
    private $varsForCreate = []; // some methods will work in kleeja without leaving the website
    private $toGlobal = []; // the list of vars that we want to export it to kleeja
    private $downloadLinkMailer = ''; // the mail that we want to send download link to it

    public function paymentStart()
    {
        global $olang;

        if (! KJP::loadLibraries()) {
            kjp_error($olang['KJP_STRIPE_NO_LIB']);
        }

        try {
            // the library would save an id in the home folder of the server and send details of the server
            \Stripe\Stripe::setEnableTelemetry(false);

            // Stripe asks the plugins to say who they are
            \Stripe\Stripe::setAppInfo(
                'Kleeja Payment',
                Plugins::getInstance()->installed_plugin_info('kleeja_payment')['plugin_version'] ?? null,
                'https://github.com/kleeja/kleeja_payment',
            );

            $this->stripe = new \Stripe\StripeClient([
                'api_key' => kjp_setting('kjp_stripe_secret_key'),
                'max_network_retries' => 2,
            ]);
        } catch (\Throwable $e) {
            $this->fail('start', $e->getMessage());
        }
    }

    public function setCurrency(string $currency)
    {
        $this->currency = strtoupper($currency);
    }

    /**
     * the buyer goes to the page of Stripe, the payment waits here until he comes back
     * @param mixed $do
     * @param mixed $info
     */
    public function CreatePayment(string $do, array $info)
    {
        global $config, $usrcp;

        $amount = $this->amount($info['price']);

        if ($amount <= 0) {
            $this->fail('create session', 'the price is not a valid amount');
        }

        // it goes to Stripe with the session and comes back with the paid one
        $payment_token = createToken();
        $label = kjp_item_label($do, (string) $info['name']);
        $check_url = $config['siteurl'] . 'go.php?go=kj_payment&method=cards&action=check&state=';

        $params = [
            'mode' => 'payment',
            // cards are paid at once, so the result is known when the buyer comes back
            'payment_method_types' => ['card'],
            'client_reference_id' => $payment_token,
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($this->currency),
                        'unit_amount' => $amount,
                        'product_data' => ['name' => $label],
                    ],
                ],
            ],
            // the amount and the currency stay as they are asked for, they are compared when the buyer comes back
            'adaptive_pricing' => ['enabled' => false],
            'payment_intent_data' => ['description' => $label],
            // Stripe puts the id of the session in the link
            'success_url' => $check_url . 'success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $check_url . 'cancel',
        ];

        if (filter_var((string) $usrcp->mail(), FILTER_VALIDATE_EMAIL)) {
            $params['customer_email'] = $usrcp->mail();
        }

        try {
            $checkout = $this->stripe->checkout->sessions->create($params);
        } catch (\Throwable $e) {
            $this->fail('create session', $e->getMessage());
        }

        if (empty($checkout->id) || empty($checkout->url)) {
            $this->fail('create session', 'no link in the response');
        }

        $db_id = kjp_insert_payment([
            'payment_method' => 'cards',
            'payment_more_info' => ['stripe_session_id' => $checkout->id],
            'payment_amount' => $info['price'],
            'payment_currency' => $this->currency,
            'payment_token' => $payment_token,
            'payment_action' => $do,
            'item_id' => $info['id'],
            'item_name' => $info['name'],
        ]);

        $_SESSION['kj_payment'] = [
            'db_id' => $db_id,
            'payment_method' => 'cards',
            'payment_token' => $payment_token,
            'payment_action' => $do,
            'item_id' => (int) $info['id'],
            'item_name' => $info['name'],
        ];

        redirect($checkout->url);
    }

    public function varsForCreatePayment(): array
    {
        return $this->varsForCreate;
    }

    public function checkPayment()
    {
        global $lang, $olang;

        $session = $_SESSION['kj_payment'] ?? [];

        if (empty($session['db_id']) || ($session['payment_method'] ?? '') != 'cards') {
            kjp_error($lang['ERROR_NAVIGATATION']);
        }

        // the payment that waits for the buyer
        $payment = getPaymentInfo($session['db_id'], ['payment_method' => 'cards', 'payment_state' => 'created'], true);
        $checkout_id = $payment ? (string) ($payment['stripe_session_id'] ?? '') : '';
        $canceled = g('state') != 'success';

        if ($checkout_id === '' || (! $canceled && ! hash_equals($checkout_id, (string) g('session_id')))) {
            kjp_error($lang['ERROR_NAVIGATATION']);
        }

        if ($canceled) {
            try {
                // the page of Stripe stays open for a day, close it so it can not be paid after it was canceled here
                $this->stripe->checkout->sessions->expire($checkout_id);
                kjp_payment_canceled($payment);
            } catch (\Throwable $e) {
                // it is not open anymore, it may be paid from another tab, that is checked below
            }
        }

        try {
            // the money is taken on the page of Stripe, here we only ask Stripe about it
            $checkout = $this->stripe->checkout->sessions
                ->retrieve($checkout_id, ['expand' => ['payment_intent.latest_charge']])
                ->toArray();
        } catch (\Throwable $e) {
            $this->fail('check session ' . $checkout_id, $e->getMessage(), $olang['KJP_PAY_FAILED']);
        }

        if (($checkout['status'] ?? '') != 'complete' || ($checkout['payment_status'] ?? '') != 'paid') {
            if ($canceled) {
                kjp_payment_canceled($payment);
            }

            $this->fail(
                'check session ' . $checkout_id,
                'the session is ' . ($checkout['status'] ?? 'unknown') . ', ' . ($checkout['payment_status'] ?? 'unknown'),
                $olang['KJP_PAY_FAILED'],
            );
        }

        // it has to be the payment of this session, with the amount that was asked for
        if (
            ($checkout['client_reference_id'] ?? '') !== $payment['payment_token'] ||
            strtoupper((string) ($checkout['currency'] ?? '')) !== strtoupper($payment['payment_currency']) ||
            (int) ($checkout['amount_total'] ?? -1) !== $this->amount($payment['payment_amount'], $payment['payment_currency'])
        ) {
            $this->fail('check session ' . $checkout_id, 'the session does not match the payment ' . $payment['id']);
        }

        $intent = is_array($checkout['payment_intent'] ?? null) ? $checkout['payment_intent'] : [];
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $card = $charge['payment_method_details']['card'] ?? [];
        $buyer_mail = (string) ($checkout['customer_details']['email'] ?? '');

        // information from Stripe
        $more_info = [
            'stripe_transaction_id' => $intent['id'] ?? $checkout_id, // the transaction id
            'stripe_buyer_mail' => $buyer_mail,
            'stripe_card_type' => $card['brand'] ?? '', // visa or master or ...
            'stripe_card_funding' => $card['funding'] ?? '', // credit card or prepaid card
            'stripe_card_country' => $card['country'] ?? '', // the card country
            'stripe_card_expire_date' => isset($card['exp_month']) ? $card['exp_month'] . ' / ' . $card['exp_year'] : '',
            'stripe_card_last_4nums' => $card['last4'] ?? '',
            'stripe_card_fingerprint' => $card['fingerprint'] ?? '', // its like uniq id of the card
        ];

        // the buyer got the item already, with a request that came before this one
        if (! kjp_approve_payment((int) $payment['id'], $more_info)) {
            kjp_error($lang['ERROR_NAVIGATATION']);
        }

        $this->downloadLinkMailer = $buyer_mail;
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
    }

    public function checkPayout(array $payoutInfo)
    {
    }

    public static function permission(string $permission): bool
    {
        return $permission == 'createPayment';
    }

    /**
     * an amount in the smallest unit of its currency, as Stripe takes it, so 12.95 USD is 1295
     *
     * @param  mixed       $amount
     * @param  string|null $currency the currency of the site by default
     * @return int
     */
    private function amount($amount, ?string $currency = null): int
    {
        $currency = strtoupper($currency ?? $this->currency);
        $amount = (float) $amount;

        // currencies without decimals
        $zero = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        if (in_array($currency, $zero, true)) {
            return (int) round($amount);
        }

        // currencies without decimals that Stripe counts with two
        if (in_array($currency, ['ISK', 'UGX'], true)) {
            return (int) round($amount) * 100;
        }

        // currencies with three decimals, Stripe takes them when the last one is 0
        if (in_array($currency, ['BHD', 'JOD', 'KWD', 'OMR', 'TND'], true)) {
            return (int) round($amount * 100) * 10;
        }

        return (int) round($amount * 100);
    }

    /**
     * a call failed, the details are kept in the log and the visitor sees a general message
     *
     * @param string $step
     * @param string $details
     * @param string $message what the visitor sees, the payment method is not available by default
     */
    private function fail(string $step, string $details, string $message = ''): void
    {
        global $olang;

        kjp_log('Stripe ' . $step . ' failed: ' . $details);
        kjp_error($message !== '' ? $message : $olang['KJP_PAY_METHOD_ERR']);
    }
}
