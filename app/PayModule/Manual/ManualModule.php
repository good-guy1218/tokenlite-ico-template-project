<?php

namespace App\PayModule\Manual;

/**
 * Manual Module
 * @version v1.4.1
 * @since v1.0.2
 */
use Auth;
use Route;
use Validator;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Setting;
use App\Models\IcoStage;
use App\Helpers\IcoHandler;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use App\PayModule\ModuleHelper;
use App\PayModule\PmInterface;
use App\Notifications\TnxStatus;
use App\Helpers\TokenCalculate as TC;

class ManualModule implements PmInterface
{
    const SLUG = 'manual';
    const SUPPORT_CURRENCY = ['ETH', 'BTC', 'LTC', 'XRP', 'XLM', 'BCH', 'BNB', 'USDT', 'TRX', 'USDC', 'DASH', 'WAVES', 'XMR', 'BUSD', 'ADA', 'DOGE', 'SOL', 'UNI', 'LINK', 'CAKE', 'AVAX', 'LUNA', 'MATIC', 'DOT', 'SHIB', 'CRO'];
    const VERSION = '1.4.2';
    const APP_VERSION = '^1.6.0';

    public function routes()
    {
        Route::post('/manual/action', 'Manual\ManualController@update_transaction')->name('manual.update');
        Route::post('/manual/notify', 'Manual\ManualController@email_notify')->name('manual.notify');
    }

    public function currencies()
    {
        $support_cur = self::SUPPORT_CURRENCY;
        $currencies = array_map('strtolower', $support_cur);
        return $currencies;
    }

    public function networks($code=null, $output=null)
    {
        $code = strtolower($code);
        $networks = [
            'eth' => [
                'default' => 'Default',
                'bep2' => 'BC Chain (BEP2)',
                'bep20' => 'BSC Chain (BEP20)',
            ],
            'btc' => [
                'default' => 'Default',
                'bep2' => 'BC Chain (BEP2)',
                'bep20' => 'BSC Chain (BEP20)',
            ],
            'ltc' => [
                'default' => 'Default',
                'bep2' => 'BC Chain (BEP2)',
                'bep20' => 'BSC Chain (BEP20)',
            ],
            'xrp' => [
                'default' => 'Default',
                'bep2' => 'BC Chain (BEP2)',
            ],
            'bch' => [
                'default' => 'Default',
                'bep2' => 'BC Chain (BEP2)',
                'bep20' => 'BSC Chain (BEP20)',
            ],
            'bnb' => [
                'default' => 'Mainnet',
                'bsc' => 'BSC Chain (BSC)',
                'erc20' => 'ERC20',
            ],
            'usdt' => [
                'default' => 'Omni Layer',
                'bep2' => 'BC Chain (BEP2)',
                'bep20' => 'BSC Chain (BEP20)',
                'erc20' => 'ERC20',
                'trc20' => 'Tron (TRC20)',
            ],
            'usdc' => [
                'default' => 'Default',
                'bep20' => 'BSC Chain (BEP20)',
                'trc20' => 'Tron (TRC20)',
            ],
            'busd' => [
                'default' => 'ERC20',
                'bep2' => 'BC Chain (BEP2)',
                'bep20' => 'BSC Chain (BEP20)',
            ],
            'ada' => [
                'default' => 'Default',
                'bep2' => 'BC Chain (BEP2)',
            ],
            'doge' => [
                'default' => 'Default',
                'bep20' => 'BSC Chain (BEP20)',
            ],
            'link' => [
                'default' => 'Default',
                'bep20' => 'BSC Chain (BEP20)',
            ]
        ];

        if (isset($networks[$code])) {
            return $networks[$code];
        }
        return $networks;
    }

    public function admin_views()
    {
        $pmData = get_pm(self::SLUG, true);
        $name = self::SLUG;
        $currencies = $this->currencies();
        return ModuleHelper::view('Manual.views.card', compact('pmData', 'name', 'currencies'));
    }

    public function admin_views_details()
    {
        $pmData = get_pm(self::SLUG, true);
        $currencies = $this->currencies();
        $networks = $this->networks();
        return ModuleHelper::view('Manual.views.admin', compact('pmData', 'currencies', 'networks'));
    }

    public function show_action()
    {
        $pmData = get_pm(self::SLUG, true);
        $html = '<li class="pay-item"><div class="input-wrap">
                    <input type="radio" class="pay-check" Value="'.self::SLUG.'" name="pay_option" required="required" id="pay-'.self::SLUG.'" data-msg-required="'.__('Select your payment method.').'">
                    <label class="pay-check-label" for="pay-'.self::SLUG.'"><span class="pay-check-text" title="'.$pmData->details.'">'.$pmData->title.'</span><img class="pay-check-img" src="'.asset('assets/images/pay-manual.png').'" alt="'.ucfirst(self::SLUG).'"></label>
                </div></li>';
        return [
            'currency' => $this->check_currency(),
            'html' => ModuleHelper::str2html($html)
        ];
    }

    public function check_currency()
    {
        $currency = self::SUPPORT_CURRENCY;
        $active = [];
        $pm = get_pm(self::SLUG);
        foreach ($currency as $cur) {
            $cur = strtolower($cur);
            if (isset($pm->$cur) && $pm->$cur->address != null && $pm->$cur->status == 'active') {
                $active[] = strtoupper($cur);
            }
        }
        return $active;
    }

    private function check_address($wallet, $type='')
    {
        if (empty($wallet)) {
            return false;
        }
        return IcoHandler::validate_address($wallet, $type);
    }

    public function transaction_details($transaction)
    {
        return ModuleHelper::view('Manual.views.tnx_details', compact('transaction'));
    }

    public function email_details($transaction)
    {
        $mnl = get_pm(self::SLUG);
        $currency = strtolower($transaction->currency);
        $address = isset($mnl->$currency->address) ? $mnl->$currency->address : '~';
        $text = '<tr><td>Payment to Address</td><td>:</td><td><strong>'.$address.' ('.strtoupper($currency).')</strong></td></tr>';
        return $text; //html_string($text);
    }

    public function create_transaction(Request $request)
    {
        if (version_compare(phpversion(), '7.1', '>=')) {
            ini_set('precision', get_setting('token_decimal_max', 8));
            ini_set('serialize_precision', -1);
        }
        $ret['msg'] = 'info';
        $ret['message'] = __('messages.nothing');
        $validator = Validator::make($request->all(), [
            'agree' => 'required',
            'pp_token' => 'required|integer|min:1',
            'pp_currency' => 'required',
        ], [
            'agree.required' => __('messages.agree'),
            'pp_currency.required' => __('messages.trnx.require_currency'),
            'pp_token.required' => __('messages.trnx.require_token'),
            'pp_token.min' => __('messages.trnx.minimum_token'),
            'pp_token.integer' => __('messages.trnx.minimum_token'),
        ]);

        if ($validator->fails()) {
            if ($validator->errors()->has('agree')) {
                $msg = $validator->errors()->first();
            } elseif ($validator->errors()->has('pp_token')) {
                $msg = $validator->errors()->first();
            } elseif ($validator->errors()->has('pp_currency')) {
                $msg = $validator->errors()->first();
            } else {
                $msg = __('messages.form.wrong');
            }

            $ret['msg'] = 'warning';
            $ret['message'] = $msg;
        } else {
            $tc = new TC();
            $token = $request->input('pp_token');
            $calc_token = $tc->calc_token($token, 'array');
            $current_price = $tc->get_current_price();
            $exrate = Setting::exchange_rate($current_price, 'array');

            $currency = strtolower($request->input('pp_currency'));
            $currency_rate = isset($exrate['fx'][$currency]) ? $exrate['fx'][$currency] : 0;
            $all_currency_rate = isset($exrate['except']) ? json_encode($exrate['except']) : json_encode([]);
            $base_currency = strtolower(base_currency());
            $base_currency_rate = isset($exrate['base']) ? $exrate['base'] : 0;

            $trnx_data = [
                'token' => round($token, min_decimal()),
                'bonus_on_base' => round($calc_token['bonus-base'], min_decimal()),
                'bonus_on_token' => round($calc_token['bonus-token'], min_decimal()),
                'total_bonus' => round($calc_token['bonus'], min_decimal()),
                'total_tokens' => round($calc_token['total'], min_decimal()),
                'base_price' => round($calc_token['price']->base, max_decimal()),
                'amount' => round($calc_token['price']->$currency, max_decimal()),
            ];
            $address = isset(get_pm(self::SLUG)->$currency) ? get_pm(self::SLUG)->$currency->address : '';
            $network = (isset(get_pm(self::SLUG)->$currency) && isset(get_pm(self::SLUG)->$currency->network)) ? get_pm(self::SLUG)->$currency->network : '';
            $save_data = [
                'created_at' => Carbon::now()->toDateTimeString(),
                'tnx_id' => set_id(rand(100, 999), 'trnx'),
                'tnx_type' => 'purchase',
                'tnx_time' => Carbon::now()->toDateTimeString(),
                'tokens' => $trnx_data['token'],
                'bonus_on_base' => $trnx_data['bonus_on_base'],
                'bonus_on_token' => $trnx_data['bonus_on_token'],
                'total_bonus' => $trnx_data['total_bonus'],
                'total_tokens' => $trnx_data['total_tokens'],
                'stage' => active_stage()->id,
                'user' => Auth::id(),
                'amount' => $trnx_data['amount'],
                'base_amount' => $trnx_data['base_price'],
                'base_currency' => $base_currency,
                'base_currency_rate' => $base_currency_rate,
                'currency' => $currency,
                'currency_rate' => $currency_rate,
                'receive_currency' => $currency,
                'all_currency_rate' => $all_currency_rate,
                'payment_method' => self::SLUG,
                'payment_to' => $address,
                'added_by' => set_added_by('00'),
                'details' => __('messages.trnx.purchase_token'),
                'extra' => $network ? json_encode(['network' => $network]) : '',
                'status' => 'pending',
            ];
            $iid = Transaction::insertGetId($save_data);

            if ($iid != null) {
                $ret['trnx'] = 'true';
                $ret['msg'] = 'info';
                $ret['message'] = __('messages.trnx.manual.success');
                $transaction = Transaction::where('id', $iid)->first();
                $transaction->tnx_id = set_id($iid, 'trnx');
                $transaction->save();

                IcoStage::token_add_to_account($transaction, 'add');
                $mailed = ['notify' => 'order-placed', 'user' => 'submit-user', 'system' => 'placed-admin'];
                $ret['modal'] = ModuleHelper::view('Manual.views.payment', compact('transaction', 'mailed'), false);
            } else {
                $ret['msg'] = 'error';
                $ret['message'] = __('messages.trnx.manual.failed');
                Transaction::where('id', $iid)->delete();
            }
        }

        if ($request->ajax()) {
            return response()->json($ret);
        }
        return back()->with([$ret['msg'] => $ret['message']]);
    }

    public function save_data(Request $request)
    {
        $response['msg'] = 'info';
        $response['message'] = __('messages.nothing');
        $mnl_status = isset($request->mnl_status) ? 'active' : 'inactive';

        $currencies = $this->currencies();
        $check_valid =true;
        $gateway_data = [];
        foreach ($currencies as $cur) {
            $cur_status = $request->input($cur.'-status', 'inactive');

            $gateway_data[$cur] = $request->input($cur);
            $gateway_data[$cur]['status'] = $cur_status;

            // Check wallet
            if ($cur_status=='active') {
                $wallet = $request->input($cur.'.address');
                $check_valid = (!empty($wallet)) ? $this->check_address($wallet, $cur) : false;
                // if ($cur=='xrp') dd($wallet, $this->check_address($wallet, $cur));
                if ($check_valid==false) {
                    $which = strtolower(short_to_full($cur));
                    $response['msg'] = 'warning';
                    $response['message'] = __('messages.invalid.address_is', ['is' => $which]);
                    return $response;
                }
            }
        }

        if ($check_valid) {
            // if address is valid then do it
            $mnl = PaymentMethod::where('payment_method', self::SLUG)->first();
            if (!$mnl) {
                $mnl = new PaymentMethod();
                $mnl->payment_method = self::SLUG;
            }
            $mnl->title = $request->input('mnl_title');
            $mnl->description = $request->input('mnl_details');
            $mnl->status = $mnl_status;
            $mnl->data = json_encode($gateway_data);

            if ($mnl->save()) {
                $response['msg'] = 'success';
                $response['message'] = __('messages.update.success', ['what' => 'Manual wallet payment info']);
            } else {
                $response['msg'] = 'error';
                $response['message'] = __('messages.update.failed', ['what' => 'Manual wallet payment info']);
            }
        }
        return $response;
    }

    public function demo_data()
    {
        $old = PaymentMethod::get_data('manual', true);
        $manual = [
            'eth' => [
                'status' => ($old ? $old->secret->eth->status : 'inactive'),
                'address' => ($old ? $old->secret->eth->address : null),
                'limit' => ($old ? $old->secret->eth->limit : null),
                'price' => ($old ? $old->secret->eth->price : null),
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'btc' => [
                'status' => ($old ? $old->secret->btc->status : 'inactive'),
                'address' => ($old ? $old->secret->btc->address : null),
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'ltc' => [
                'status' => ($old ? $old->secret->ltc->status : 'inactive'),
                'address' => ($old ? $old->secret->ltc->address : null),
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'bch' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'bnb' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'trx' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'xlm' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'xrp' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'usdt' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'usdc' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'dash' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'waves' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'xmr' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'busd' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'ada' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'doge' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'sol' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'uni' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
            'link' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
                'network' => 'default',
            ],
            'cake' => [
                'status' => 'inactive',
                'address' => null,
                'num' => 3,
                'req' => 'no',
            ],
        ];

        if (PaymentMethod::check(self::SLUG)) {
            $man = new PaymentMethod();
            $man->payment_method = self::SLUG;
            $man->title = ($old ? $old->title : 'Pay via Crypto');
            $man->description = ($old ? $old->details : 'You can send payment direct to our wallets. We will manually verify.');
            $man->data = json_encode($manual);
            $man->status = ($old ? $old->status : 'inactive');
            $man->save();
        }
    }
}
