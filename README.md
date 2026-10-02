# Kleeja Payment

A plugin for [Kleeja](https://github.com/kleeja/kleeja), the file upload script. It sells files and paid groups, and it has a subscription system.

- Buyers pay with PayPal, with a card through Stripe, or with the balance of their account.
- The owner of a file gets a share of every sale, and can withdraw it to a PayPal account.
- A subscription opens all the files for a number of days.

Read the [Quick Start Guide](https://github.com/kleeja/kleeja_payment/wiki/), or open **Help** in the control panel: the plugin adds its guide there, in English and Arabic. The words of the guide are in `language/help_en.php` and `language/help_ar.php`.

## Requirements

- Kleeja 4.0.0 or newer.
- PHP 8.0.2 or newer, with the `curl`, `json` and `dom` extensions.
- A PayPal Business account, a Stripe account, or both.

## Install

Download the `kleeja_payment` package of a [release](https://github.com/kleeja/kleeja_payment/releases) and install it from **Admin → Plugins**. The package of a release has the libraries of PayPal and Stripe in its `vendor` folder.

A clone of this repository does not have them. Install them with Composer in the folder of the plugin:

```bash
composer install --no-dev
```

Until the `vendor` folder is there, PayPal and Stripe are not offered to the buyers, and the dashboard of the control panel says why.

## Settings

Set the keys in **Admin → Settings → Kleeja Payment Settings**, and turn the payment methods on or off in **KJPayments Active Methods**.

| Method  | What it needs                                                                                                                            |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| PayPal  | The Client ID and the Secret of a [REST API app](https://developer.paypal.com/dashboard/applications). "Live Mode" off uses the sandbox. |
| Stripe  | The Secret key from the [API keys page](https://dashboard.stripe.com/apikeys). A test key takes test payments, a live key real ones.     |
| Balance | Nothing. Members of the groups that have the "Receive Profits" permission can pay with it.                                               |

Withdrawals are sent with the Payouts API of PayPal, which has to be enabled for the PayPal account.

## How the payments work

The buyer always pays on the page of PayPal or Stripe, so card and account details never pass through the website.

- **PayPal** uses the Orders API (v2). The plugin creates an order, the buyer approves it on PayPal, and the money is captured when the buyer comes back.
- **Stripe** uses Checkout Sessions. The buyer pays on the page of Stripe, and when the buyer comes back the plugin asks Stripe if the session is paid.

In both methods the plugin compares the amount, the currency and its own reference with what the provider reports, before it gives the file, the group or the subscription.

A payment stays in **Pending Payments** until its buyer comes back. There is no webhook, so a buyer who pays on Stripe and closes the browser before coming back stays pending. Check such a payment in the Stripe dashboard.

## Styles

The pages of the members have templates for the two styles of Kleeja, in `html/bootstrap` and `html/default`. A style that depends on one of them gets its templates.

Another style brings its own copies in a `kj_payment` folder, for example `styles/<name>/kj_payment/pay_download.html`. Until it has them, its pages say that the style is not supported.

The templates of the control panel are in `html/admin`.

## Release package

The package of a release is the folder `kleeja_payment` with its `vendor` folder inside:

```bash
composer install --no-dev --prefer-dist --no-interaction
```

`composer.json` sets the platform to PHP 8.0.2, the lowest version that the libraries accept on the PHP 8.0 of Kleeja. So the package works on every supported PHP version, whatever version builds it. `composer.lock` is in the repository, so every build gets the same versions.

Run `composer audit` before a release. The `conflict` rule in `composer.json` keeps `symfony/http-foundation`, which the PayPal library needs, on a branch that still gets security fixes and runs on PHP 8.0.

## Updating from 1.x

- The zipped libraries are gone. Install the package of a release, or run Composer as above.
- The payments by cards are back, and they are off after the update. Turn "Active Stripe" on when the secret key is set. The publishable key is not needed anymore, and its setting is removed.
- Payments that were created with the old PayPal API and never finished stay in the pending list.
- The templates are made for the Bootstrap 5.3 of the `bootstrap` style, the `default` style and the control panel. A style that has its own templates in a `kj_payment` folder has to update them.

## Other packages for this plugin

- [KJPay Account Charger](https://github.com/kleeja/kjp_account_charger)
- [KJP Upload Package](https://github.com/kleeja/kjp_upload_package)

## made with ❤ for kleeja ❣
