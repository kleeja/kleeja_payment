<?php

/*
 * Th Default Method of Kleeja Payment
 * PayPal Method, the payments are orders of the Orders API (v2), and the payouts go with the Payouts API
 */

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\ItemRequestBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletExperienceContextBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\ItemCategory;
use PaypalServerSdkLib\Models\PaypalExperienceUserAction;
use PaypalServerSdkLib\Models\PaypalWalletContextShippingPreference;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

class kjPayMethod_paypal implements KJPaymentMethod
{
    private $client; // client id and cliend secret , we need it to create payment and checking it
    private $accessToken = null; // for the calls that the library of PayPal does not have
    private $currency;
    private $successPayment = false; // its return the payment state after checking it
    private $varsForCreate = []; // some methods will work in kleeja without leaving the website
    private $toGlobal = []; // the list of vars that we want to export it to kleeja
    private $downloadLinkMailer = ''; // the mail that we want to send download link to it
    private $error = ''; // why the last payout failed, for the admin

    public function paymentStart()
    {
        global $config, $olang;

        if (! KJP::loadLibraries()) {
            kjp_error($olang['KJP_PAYPAL_NO_LIB']);
        }

        $this->client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init(
                    kjp_setting('kjp_paypal_client_id'),
                    kjp_setting('kjp_paypal_client_secret'),
                ),
            )
            ->environment($config['kjp_active_live_mode'] ? Environment::PRODUCTION : Environment::SANDBOX)
            ->timeout(30)
            ->build();
    }

    public function setCurrency(string $currency)
    {
        $this->currency = strtoupper($currency);
    }

    // do = buy_file OR join_group OR subscripe
    // info = is an array about File infrmations or group informations
    // check getFileInfo function and getGroupInfo function
    public function CreatePayment(string $do, array $info = [])
    {
        global $config;

        $amount = $this->amount($info['price']);

        if ((float) $amount <= 0) {
            $this->fail('create order', 'the price is not a valid amount');
        }

        // it goes to PayPal with the order and comes back with the captured payment
        $payment_token = createToken();
        $label = kjp_item_label($do, (string) $info['name']);
        $money = MoneyBuilder::init($this->currency, $amount)->build();
        $check_url = $config['siteurl'] . 'go.php?go=kj_payment&method=paypal&action=check&state=';

        $order = OrderRequestBuilder::init(CheckoutPaymentIntent::CAPTURE, [
            PurchaseUnitRequestBuilder::init(
                AmountWithBreakdownBuilder::init($this->currency, $amount)
                    ->breakdown(AmountBreakdownBuilder::init()->itemTotal($money)->build())
                    ->build(),
            )
                ->customId($payment_token)
                ->description($label)
                ->items([
                    ItemRequestBuilder::init($label, $money, '1')
                        ->sku((string) $info['id']) // its like ItemNumber .
                        ->category(ItemCategory::DIGITAL_GOODS)
                        ->build(),
                ])
                ->build(),
        ])
            ->paymentSource(
                PaymentSourceBuilder::init()
                    ->paypal(
                        PaypalWalletBuilder::init()
                            ->experienceContext(
                                PaypalWalletExperienceContextBuilder::init()
                                    ->brandName(kjp_item_label('', (string) $config['sitename']))
                                    ->shippingPreference(PaypalWalletContextShippingPreference::NO_SHIPPING)
                                    ->userAction(PaypalExperienceUserAction::PAY_NOW)
                                    //no payments that take days to arrive, the buyer gets the item when he comes back
                                    ->paymentMethodPreference('IMMEDIATE_PAYMENT_REQUIRED')
                                    ->returnUrl($check_url . 'success')
                                    ->cancelUrl($check_url . 'cancel')
                                    ->build(),
                            )
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

        try {
            $response = $this->client->getOrdersController()->createOrder([
                'body' => $order,
                'paypalRequestId' => 'kjp-order-' . $payment_token,
            ]);
        } catch (\Throwable $e) {
            $this->fail('create order', $e->getMessage());
        }

        $created = $this->body($response);
        $approve_link = '';

        // the page of PayPal where the buyer approves the payment
        foreach ($created['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $approve_link = (string) ($link['href'] ?? '');
            }
        }

        if (! $response->isSuccess() || empty($created['id']) || $approve_link === '') {
            $this->fail('create order', $this->summary($created));
        }

        $db_id = kjp_insert_payment([
            'payment_method' => 'paypal',
            'payment_more_info' => ['paypal_payment_id' => $created['id']],
            'payment_amount' => $amount,
            'payment_currency' => $this->currency,
            'payment_token' => $payment_token,
            'payment_action' => $do,
            'item_id' => $info['id'],
            'item_name' => $info['name'],
        ]);

        $_SESSION['kj_payment'] = [
            'db_id' => $db_id,
            'payment_method' => 'paypal',
            'payment_token' => $payment_token,
            'payment_action' => $do,
            'item_id' => (int) $info['id'],
            'item_name' => $info['name'],
        ];

        redirect($approve_link);
    }

    public function varsForCreatePayment(): array
    {
        return $this->varsForCreate;
    }

    public function isSuccess(): bool
    {
        return $this->successPayment;
    }

    public function getGlobalVars(): array
    {
        return $this->toGlobal;
    }

    public function checkPayment()
    {
        global $lang, $olang;

        $session = $_SESSION['kj_payment'] ?? [];

        if (empty($session['db_id']) || ($session['payment_method'] ?? '') != 'paypal') {
            kjp_error($lang['ERROR_NAVIGATATION']);
        }

        // the payment that waits for the buyer, PayPal sends the id of its order back as the token
        $payment = getPaymentInfo($session['db_id'], ['payment_method' => 'paypal', 'payment_state' => 'created'], true);
        $order_id = $payment ? (string) ($payment['paypal_payment_id'] ?? '') : '';

        if ($order_id === '' || ! hash_equals($order_id, (string) g('token'))) {
            kjp_error($lang['ERROR_NAVIGATATION']);
        }

        // nothing was taken from the buyer, the money moves only with the capture below
        if (g('state') != 'success') {
            kjp_payment_canceled($payment);
        }

        try {
            $response = $this->client->getOrdersController()->captureOrder([
                'id' => $order_id,
                // PayPal gives the same result if this request is sent again, it does not take the money twice
                'paypalRequestId' => 'kjp-capture-' . $payment['payment_token'],
                'prefer' => 'return=representation',
            ]);
        } catch (\Throwable $e) {
            $this->fail('capture order ' . $order_id, $e->getMessage(), $olang['KJP_PAY_FAILED']);
        }

        $order = $this->body($response);
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? [];

        if (! $response->isSuccess() || ($order['status'] ?? '') != 'COMPLETED' || ! $capture) {
            $this->fail('capture order ' . $order_id, $this->summary($order), $olang['KJP_PAY_FAILED']);
        }

        // the money did not arrive yet, like a payment that PayPal is reviewing
        if (($capture['status'] ?? '') != 'COMPLETED') {
            $this->fail(
                'capture order ' . $order_id,
                'the capture is ' . ($capture['status'] ?? 'unknown'),
                sprintf($olang['KJP_PAY_PENDING'], $payment['id']),
            );
        }

        // it has to be the payment of this order, with the amount that was asked for
        if (
            ($capture['custom_id'] ?? ($unit['custom_id'] ?? '')) !== $payment['payment_token'] ||
            ($capture['amount']['currency_code'] ?? '') !== $payment['payment_currency'] ||
            ($capture['amount']['value'] ?? '') !== $this->amount($payment['payment_amount'], $payment['payment_currency'])
        ) {
            $this->fail('capture order ' . $order_id, 'the capture does not match the payment ' . $payment['id']);
        }

        $payer = $order['payment_source']['paypal'] ?? [];
        $payer += ($order['payer'] ?? []) + ['name' => [], 'email_address' => ''];

        $approved = kjp_approve_payment((int) $payment['id'], [
            'paypal_payment_id' => $order_id,
            'paypal_capture_id' => $capture['id'] ?? '',
            'paypal_payment_fees' => $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0,
            'paypal_payer_name' => trim(($payer['name']['given_name'] ?? '') . ' ' . ($payer['name']['surname'] ?? '')),
            'paypal_payer_mail' => $payer['email_address'],
            'paypal_payer_id' => $payer['account_id'] ?? ($payer['payer_id'] ?? ''),
        ]);

        // the buyer got the item already, with a request that came before this one
        if (! $approved) {
            kjp_error($lang['ERROR_NAVIGATATION']);
        }

        $this->downloadLinkMailer = (string) $payer['email_address'];
        $this->toGlobal = kjp_apply_payment($payment);

        // now we can say that the payment made successfuly
        $this->successPayment = true;
    }

    // return the e-mail adress that kleeja have to send download link to it
    // if you are working with a method that dont have an e-mail adress , return an empty text;
    // then kleeja will display a form for user to enter the e-mail adress to recive the download link
    // called if checking payment is successful only
    public function linkMailer(): string
    {
        return (string) $this->downloadLinkMailer;
    }

    public function createPayout(array $itemInfo = [])
    {
        global $config, $SQL, $dbprefix;

        $id = (int) $itemInfo['id'];
        // PayPal refuses a batch id that it got before, so the same id for the same request
        // makes sure that a payout is never sent twice
        $sender_id = 'kjp_' . $id . '_' . substr(sha1($config['h_key'] . 'payout' . $id), 0, 12);

        $created = $this->api('POST', '/v1/payments/payouts', [
            'sender_batch_header' => [
                'sender_batch_id' => $sender_id,
                'email_subject' => kjp_item_label('', (string) $config['sitename']),
            ],
            'items' => [
                [
                    'recipient_type' => 'EMAIL',
                    'receiver' => htmlspecialchars_decode((string) ($itemInfo['SENDTO'] ?? ''), ENT_QUOTES),
                    'note' => 'Thank you.',
                    'sender_item_id' => $sender_id,
                    'amount' => [
                        'value' => $this->amount($itemInfo['amount']),
                        'currency' => $this->currency,
                    ],
                ],
            ],
        ]);

        $batch_id = (string) ($created['body']['batch_header']['payout_batch_id'] ?? '');

        if (! $created['ok'] || $batch_id === '') {
            $this->error = $this->summary($created['body']);
            kjp_log('PayPal payout ' . $id . ' failed: ' . $this->error);

            return;
        }

        // PayPal works on the batch after the request, so its item may not be ready yet,
        // then the payout stays "sent" until the admin checks it
        $batch = $this->api('GET', '/v1/payments/payouts/' . rawurlencode($batch_id));
        $item = $batch['body']['items'][0] ?? [];
        $state = ($item['transaction_status'] ?? '') == 'SUCCESS' ? 'recived' : 'sent';

        $SQL->build([
            'UPDATE' => "{$dbprefix}payments_out",
            'SET' => 'state = :state, payment_more_info = :info',
            'WHERE' => "id = :id AND state = 'verify'",
            'BIND' => ['state' => $state, 'info' => $this->payoutInfo($batch_id, $item, $itemInfo), 'id' => $id],
        ]);

        if ($SQL->affected()) {
            $this->successPayment = true;
        }
    }

    public function checkPayout(array $payoutInfo = [])
    {
        global $olang, $SQL, $dbprefix;

        $id = (int) $payoutInfo['id'];
        $batch_id = (string) ($payoutInfo['payout_batch_id'] ?? '');

        if ($batch_id === '') {
            return;
        }

        $batch = $this->api('GET', '/v1/payments/payouts/' . rawurlencode($batch_id));
        $item = $batch['body']['items'][0] ?? [];
        $status = (string) ($item['transaction_status'] ?? '');

        if (! $batch['ok']) {
            $this->error = $this->summary($batch['body']);
            kjp_log('PayPal payout ' . $id . ' check failed: ' . $this->error);
        } elseif ($status == 'SUCCESS') {
            $SQL->build([
                'UPDATE' => "{$dbprefix}payments_out",
                'SET' => "state = 'recived', payment_more_info = :info",
                'WHERE' => "id = :id AND state = 'sent'",
                'BIND' => ['info' => $this->payoutInfo($batch_id, $item, $payoutInfo), 'id' => $id],
            ]);

            $this->successPayment = true;
        } elseif (in_array($status, ['FAILED', 'RETURNED', 'BLOCKED', 'REFUNDED', 'REVERSED'], true)) {
            // the money did not reach the member, or it came back, so the amount goes back to his balance
            $SQL->build([
                'UPDATE' => "{$dbprefix}payments_out",
                'SET' => "state = 'cancel'",
                'WHERE' => "id = :id AND state = 'sent'",
                'BIND' => ['id' => $id],
            ]);

            if ($SQL->affected() === 1) {
                kjp_give_balance((int) $payoutInfo['user'], (float) $payoutInfo['amount']);
            }

            $this->error = sprintf($olang['KJP_POUT_FAILED_BACK'], $status);
        }
    }

    /**
     * why the last payout failed, the admin sees it
     *
     * @return string
     */
    public function getError(): string
    {
        return kleeja_html_encode($this->error);
    }

    /**
     * what is this method support
     * @param mixed $permission
     */
    public static function permission(string $permission): bool
    {
        // createPayout = sending money to users
        return in_array($permission, ['createPayment', 'createPayout', 'checkPayouts'], true);
    }

    /**
     * an amount as PayPal takes it, some currencies have no decimals
     *
     * @param  mixed       $amount
     * @param  string|null $currency the currency of the site by default
     * @return string
     */
    private function amount($amount, ?string $currency = null): string
    {
        $no_decimals = in_array(strtoupper($currency ?? $this->currency), ['HUF', 'JPY', 'TWD'], true);

        return number_format((float) $amount, $no_decimals ? 0 : 2, '.', '');
    }

    /**
     * the details of a payout that are saved with it
     *
     * @param  string $batch_id
     * @param  array  $item     the item of the batch from PayPal, empty if it is not ready
     * @param  array  $payout   the row of the payout
     * @return string
     */
    private function payoutInfo(string $batch_id, array $item, array $payout): string
    {
        return payment_more_info('to_db', [
            'payout_item_id' => $item['payout_item_id'] ?? ($payout['payout_item_id'] ?? ''),
            'payout_batch_id' => $batch_id,
            'transaction_fees' => $item['payout_item_fee']['value'] ?? ($payout['transaction_fees'] ?? 0),
            'receiver' => $item['payout_item']['receiver'] ?? ($payout['receiver'] ?? ($payout['SENDTO'] ?? '')),
        ]);
    }

    /**
     * the JSON of a response of the library as an array
     *
     * @param  mixed $response
     * @return array
     */
    private function body($response): array
    {
        $body = $response->getBody();
        $body = is_string($body) ? json_decode($body, true) : $body;

        return is_array($body) ? $body : [];
    }

    /**
     * the error of a response in one line, PayPal support asks for the debug id
     *
     * @param  array  $body
     * @return string
     */
    private function summary(array $body): string
    {
        return trim(
            ($body['name'] ?? ($body['status'] ?? 'unknown')) .
                ' ' .
                ($body['details'][0]['issue'] ?? ($body['message'] ?? '')) .
                (empty($body['debug_id']) ? '' : ' (debug id ' . $body['debug_id'] . ')'),
        );
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

        kjp_log('PayPal ' . $step . ' failed: ' . $details);
        kjp_error($message !== '' ? $message : $olang['KJP_PAY_METHOD_ERR']);
    }

    /**
     * call an API of PayPal that its library does not have, which is the payouts
     *
     * @param  string     $method GET or POST
     * @param  string     $path
     * @param  array|null $data   sent as JSON
     * @return array      [ok => it succeeded, body => the JSON of the response as an array]
     */
    private function api(string $method, string $path, ?array $data = null): array
    {
        try {
            if ($this->accessToken === null) {
                $this->accessToken = $this->client->getClientCredentialsAuth()->fetchToken()->getAccessToken();
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'body' => ['name' => 'AUTHENTICATION_FAILURE', 'message' => $e->getMessage()]];
        }

        $curl = curl_init($this->client->getBaseUri() . $path);

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($response === false) {
            return ['ok' => false, 'body' => ['name' => 'CONNECTION_FAILURE', 'message' => curl_error($curl)]];
        }

        $body = json_decode((string) $response, true);

        return ['ok' => $status >= 200 && $status < 300, 'body' => is_array($body) ? $body : []];
    }
}
