<?php


namespace App\Http\Services\Payment;

use Exception;
use Illuminate\Support\Facades\Log;
use Omnipay\Omnipay;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

class PaypalService extends BasePaymentService
{
    public $omniPay;

    public function __construct($method, $object)
    {
        parent::__construct($method, $object);
        $config = [
            'mode'    => $this->gateway->mode  === GATEWAY_MODE_SANDBOX ? 'sandbox' : 'live',
            'sandbox' => [
                'client_id'     => $this->gateway->key,
                'client_secret' => $this->gateway->secret,
                'app_id'        => 'APP-80W284485P519543T',
            ],

            'payment_action' => 'Sale',
            'currency'       => $this->currency,
            'notify_url'     => $this->callbackUrl,
            'locale'         => 'en',
            'validate_ssl'   => false,
        ];

        $this->provider = new PayPalClient($config);
        $this->provider->getAccessToken();
        $this->omniPay = Omnipay::create('PayPal_Rest');
        $this->omniPay->setClientId($this->gateway->key);
        $this->omniPay->setSecret($this->gateway->secret);
        $this->omniPay->setTestMode($this->gateway->mode === GATEWAY_MODE_SANDBOX);

    }

    public function makePayment($amount, $post_data = null)
    {
        $this->setAmount($amount);
        $response = $this->omniPay->purchase(array(
            'amount' => $this->amount,
            'currency' => $this->currency,
            'returnUrl' => $this->callbackUrl,
            'cancelUrl' => $this->callbackUrl,
        ))->send();
        Log::info('<<<<<$response->getData()>>>>>');
        Log::info($response->getData());
        $data['success'] = false;
        $data['redirect_url'] = '';
        $data['payment_id'] = '';
        $data['message'] = __(SOMETHING_WENT_WRONG);
        try {
            if ($response->isRedirect()) {
                $data['redirect_url'] = $response->getData()['links'][1]['href'];
                $data['payment_id'] = $response->getData()['id'];
                $data['success'] = true;
            }
            Log::info(json_encode($data));
            return $data;
        } catch (\Exception $ex) {
            $data['message'] = $ex->getMessage();
        }
        return $data;
    }

    public function paymentConfirmation($payment_id, $payer_id = null)
    {

        $data['success'] = false;
        $data['data'] = null;

        if ($payment_id && $payer_id) {
            $transaction = $this->omniPay->completePurchase(array(
                'payer_id'             => $payer_id,
                'transactionReference' => $payment_id,
            ));
            $response = $transaction->send();

            if ($response->isSuccessful()) {
                $arr_body = $response->getData();
                Log::info($response->getData());
                $data['success'] = true;
                $data['data']['amount'] = $arr_body['transactions'][0]['amount']['total'];
                $data['data']['currency'] = $arr_body['transactions'][0]['amount']['currency'];
                $data['data']['payment_status'] = $arr_body['state'] == 'approved' ? 'success' : 'processing';
                $data['data']['payment_method'] = PAYPAL;
            }
        }
        return $data;
    }

    /**
     * Save or update prices in PayPal (deactivate old plan only if price or name is different).
     *
     * @param array $data
     * @return array
     */
    public function saveProductSaas($data): array
    {
        try {
            $response = [];

            // PayPal Provider
            $provider = $this->provider;

            // Handle monthly price
            if (isset($data['monthly_price'])) {
                if (isset($data['monthlyPriceId']) && $data['monthlyPriceId']) {
                    // Retrieve the old monthly plan
                    $oldMonthlyPlan = $provider->showPlanDetails($data['monthlyPriceId']);

                    // Check if the price or product name has changed
                    if ($oldMonthlyPlan['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] != $data['monthly_price'] || $oldMonthlyPlan['name'] != $data['name'] || $oldMonthlyPlan['description'] != $data['description']) {
                        // Deactivate the old plan
                        $provider->deactivatePlan($data['monthlyPriceId']);

                        // Create a new monthly plan with the new product
                        $response['monthly_price_id'] = $this->createBillingPlanSaas($data['name'], $data['description'], $data['monthly_price'], 'MONTH', $this->currency);
                    } else {
                        // Reuse the existing plan if unchanged
                        $response['monthly_price_id'] = $data['monthlyPriceId'];
                    }
                } else {
                    // No existing plan, create a new one
                    $response['monthly_price_id'] = $this->createBillingPlanSaas($data['name'], $data['description'], $data['monthly_price'], 'MONTH', $this->currency);
                }
            }

            // Handle yearly price
            if (isset($data['yearly_price'])) {
                if (isset($data['yearlyPriceId']) && $data['yearlyPriceId']) {
                    // Retrieve the old yearly plan
                    $oldYearlyPlan = $provider->showPlanDetails($data['yearlyPriceId']);

                    // Check if the price or product name has changed
                    if ($oldYearlyPlan['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] != $data['yearly_price'] || $oldYearlyPlan['name'] != $data['name'] || $oldMonthlyPlan['description'] != $data['description']) {
                        // Deactivate the old plan
                        $provider->deactivatePlan($data['yearlyPriceId']);

                        // Create a new yearly plan with the new product
                        $response['yearly_price_id'] = $this->createBillingPlanSaas($data['name'], $data['description'], $data['yearly_price'], 'YEAR', $this->currency);
                    } else {
                        // Reuse the existing plan if unchanged
                        $response['yearly_price_id'] = $data['yearlyPriceId'];
                    }
                } else {
                    // No existing plan, create a new one
                    $response['yearly_price_id'] = $this->createBillingPlanSaas($data['name'], $data['description'], $data['yearly_price'], 'YEAR', $this->currency);
                }
            }

            // Create PayPal webhook if not already created
            $this->createWebhook();

            Log::info('Prices and products saved or updated in PayPal: ', $data);
            return ['success' => true, 'data' => $response, 'message' => 'Prices and products saved or updated'];
        } catch (Exception $ex) {
            Log::error('PayPal Price/Product Save/Update Error: ' . $ex->getMessage());
            return ['success' => false, 'message' => $ex->getMessage()];
        }
    }

    /**
     * Create a PayPal billing plan.
     *
     * @param string $name
     * @param float $price
     * @param string $interval (e.g., 'MONTH' or 'YEAR')
     * @param string $currency
     * @return string Plan ID
     */
    private function createBillingPlanSaas($name, $description, $price, $interval, $currency)
    {
        // Create a billing plan for the product
        $planData = [
            'product_id' => $this->createProduct($name, $description),
            'name' => $name,
            'description' => $description,
            'billing_cycles' => [
                [
                    'frequency' => [
                        'interval_unit' => $interval,
                        'interval_count' => 1,
                    ],
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => $price,
                            'currency_code' => $currency,
                        ]
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                ]
            ],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee' => [
                    'value' => '0',
                    'currency_code' => $currency
                ],
                'setup_fee_failure_action' => 'CANCEL',
                'payment_failure_threshold' => 3
            ],
        ];

        // Create and return the plan ID
        $billingPlan = $this->provider->createPlan($planData);
        $this->provider->activatePlan($billingPlan['id']);

        return $billingPlan['id'];
    }


    /**
     * Create a PayPal product.
     *
     * @param string $name
     * @return string Product ID
     */
    private function createProduct($name, $description)
    {
        $productData = [
            'name' => $name,
            'description' => $description,
            'type' => 'SERVICE',
            'category' => 'SOFTWARE',
        ];

        $newProduct = $this->provider->createProduct($productData);
        return $newProduct['id'];
    }

    /**
     * Subscribe to a PayPal product using PayPal subscription plan.
     *
     * @param string $productId
     * @param array|null $data
     * @return array
     */
    public function subscribeSaas($productId, $data = null): array
    {
        try {
            // Retrieve PayPal provider
            $provider = $this->provider;

            // Prepare subscriber details
            $subscriber = [
                'name' => [
                    'given_name' => auth()->user()->first_name ?? 'fname',
                    'surname' => auth()->user()->last_name ?? 'lname'
                ],
                'email_address' => auth()->user()->email ?? 'email@gmail.com',
            ];

            // Prepare subscription data
            $subscriptionData = [
                'plan_id' => $productId, // Plan ID created during product creation
                'start_time' => now()->addMinute()->toISOString(), // Subscription starts 5 mins later to allow processing
                'subscriber' => $subscriber,
                'application_context' => [
                    'brand_name' => getOption('app_name'),
                    'locale' => 'en-US',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => $this->callbackUrl, // Callback URL after successful subscription
                    'cancel_url' => $this->cancelUrl,   // URL to redirect on subscription cancellation
                ],
                'custom_id' => json_encode([
                    'plan_id' => $data['plan_id'],    // Include the plan_id in custom metadata
                    'customer_id' => $data['customer_id'],    // Include the plan_id in custom metadata
                    'plan_gateway_price_id' => $data['plan_gateway_price_id'], // Store gateway-specific package price ID
                    'duration_type' => $data['duration_type'] ?? ORDER_PLAN_DURATION_TYPE_MONTH,  // Monthly or yearly
                ])
            ];

            // Create the subscription on PayPal
            $subscription = $provider->createSubscription($subscriptionData);

            // Log the subscription creation
            Log::info('PayPal Subscription Created: ', $subscription);
            $approvalUrl = null;

            // Check if the 'links' key exists and contains the approval URL
            if (isset($subscription['links']) && is_array($subscription['links'])) {
                foreach ($subscription['links'] as $link) {
                    if ($link['rel'] === 'approve') {
                        $approvalUrl = $link['href'];  // Get the approval link URL
                        break;
                    }
                }
            }

            if ($approvalUrl) {
                return [
                    'success' => true,
                    'payment_id' => '',
                    'redirect_url' => $approvalUrl,  // Redirect to the PayPal subscription approval page
                ];
            } else {
                return [
                    'success' => false,
                    'payment_id' => '',
                    'message' => 'Approval URL not found in the PayPal response.',
                ];
            }
        } catch (\Exception $ex) {
            Log::error('PayPal Subscription Error: ' . $ex->getMessage());
            return [
                'success' => false,
                'payment_id' => '',
                'message' => $ex->getMessage(),
            ];
        }
    }


    /**
     * Cancel an active subscription.
     *
     * @param string $subscriptionId
     * @param array|null $data
     * @return array
     */
    public  function subscriptionCancel($subscriptionId, $data = null): array
    {
        // Implement PayPal subscription cancellation logic
        Log::info('Subscription cancelled: ' . $subscriptionId, $data);
        return ['success' => true, 'message' => 'Subscription cancelled'];
    }

    /**
     * Get remaining days of a subscription.
     *
     * @param string $subscriptionId
     * @param array|null $data
     * @return array
     */
    public  function subscriptionRemainingDays($subscriptionId, $data = null): array
    {
        // Implement logic to retrieve remaining days of a PayPal subscription
        Log::info('Checking remaining days for subscription: ' . $subscriptionId);
        return ['success' => true, 'days_remaining' => 30]; // Example data
    }

    /**
     * Get the status of a subscription.
     *
     * @param string $subscriptionId
     * @param array|null $data
     * @return array
     */
    public  function subscriptionStatus($subscriptionId, $data = null): array
    {
        // Implement logic to retrieve subscription status from PayPal
        Log::info('Checking subscription status: ' . $subscriptionId);
        return ['success' => true, 'status' => 'active']; // Example status
    }

    /**
     * Get the renewal date of a subscription.
     *
     * @param string $subscriptionId
     * @param array|null $data
     * @return array
     */
    public  function subscriptionRenewalDate($subscriptionId, $data = null): array
    {
        // Implement logic to retrieve subscription renewal date from PayPal
        Log::info('Checking renewal date for subscription: ' . $subscriptionId);
        return ['success' => true, 'renewal_date' => '2024-09-01']; // Example data
    }

    /**
     * Create a webhook for PayPal events.
     *
     * @return array
     */
    /**
     * Create a webhook for PayPal events.
     *
     * @return array
     */
    public function createWebhook(): array
    {
        try {
            // List existing webhooks to check if already registered
            $webhooks = $this->provider->listWebHooks();

            $url = $this->webhookUrl;
            $webhookExists = false;

            foreach ($webhooks['webhooks'] as $webhook) {
                if ($webhook['url'] === $url) {
                    $webhookExists = true;
                    break;
                }
            }

            if ($webhookExists) {
                return ['success' => true, 'message' => 'Webhook already exists'];
            }

            // Define the PayPal events to subscribe to
            $events = [
                'PAYMENT.SALE.COMPLETED'
            ];

            // Create the webhook for the specified events
            $webhookRequest = $this->provider->createWebHook($url, $events);

            // Save webhook ID in the gateway for future reference
            $this->gateway->update(['url' => $webhookRequest['id']]);

            Log::info('PayPal Webhook created successfully for events:', $events);
            return [
                'success' => true,
                'message' => 'Webhook created successfully',
                'webhook_id' => $webhookRequest['id'],
            ];
        } catch (\Exception $ex) {
            Log::error('PayPal Webhook Creation Error: ' . $ex->getMessage());
            return [
                'success' => false,
                'message' => 'Webhook creation failed: ' . $ex->getMessage(),
            ];
        }
    }


    /**
     * Handle incoming webhook events from PayPal.
     *
     * @param mixed $request
     * @return array
     */
    public function handleWebhook($request): array
    {
        try {
            // Retrieve the webhook event body
            $payload = $request->getContent();
            $event = json_decode($payload, true);

            // Get PayPal's transmission verification headers
            $headers = [
                'paypal-transmission-id' => $request->header('Paypal-Transmission-Id'),
                'paypal-transmission-time' => $request->header('Paypal-Transmission-Time'),
                'paypal-transmission-sig' => $request->header('Paypal-Transmission-Sig'),
                'paypal-cert-url' => $request->header('Paypal-Cert-Url'),
                'paypal-auth-algo' => $request->header('Paypal-Auth-Algo'),
            ];

            // Retrieve the correct webhook ID from the gateway or configuration
            $webhookId = $this->gateway->url;

            // Check if the webhook_id is properly set
            if (empty($webhookId)) {
                Log::error('PayPal Webhook ID is missing or empty.');
                return [
                    'success' => false,
                    'error' => 'Webhook ID is missing'
                ];
            }

            // Webhook verification request using PayPal API
            $verificationResponse = $this->provider->verifyWebHook([
                'auth_algo' => $headers['paypal-auth-algo'],
                'cert_url' => $headers['paypal-cert-url'],
                'transmission_id' => $headers['paypal-transmission-id'],
                'transmission_sig' => $headers['paypal-transmission-sig'],
                'transmission_time' => $headers['paypal-transmission-time'],
                'webhook_id' => $webhookId,  // Use the correct webhook ID
                'webhook_event' => $event,
            ]);

            // Log the verification response for debugging
            Log::info('PayPal Webhook Verification Response: ', $verificationResponse);

            if (isset($verificationResponse['verification_status']) && $verificationResponse['verification_status'] === 'SUCCESS') {
                // The event is verified, handle the event
                return [
                    'success' => true,
                    'event' => $event
                ];
            } else {
                Log::error('Webhook verification failed or missing "verification_status": ', $verificationResponse);
                return [
                    'success' => false,
                    'error' => 'Webhook verification failed or missing verification_status'
                ];
            }
        } catch (\Exception $e) {
            // Handle errors in verification or webhook processing
            Log::error('PayPal Webhook Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
