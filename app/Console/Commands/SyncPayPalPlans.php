<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\SubscriptionPlan;

class SyncPayPalPlans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paypal:sync-plans';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates products and pricing plans in PayPal and syncs their IDs to the database.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting PayPal Plan Sync...');

        $clientId = env('PAYPAL_CLIENT_ID');
        $clientSecret = env('PAYPAL_CLIENT_SECRET');
        $mode = env('PAYPAL_MODE', 'sandbox');
        
        $baseUrl = $mode === 'live' 
            ? 'https://api-m.paypal.com' 
            : 'https://api-m.sandbox.paypal.com';

        if (!$clientId || !$clientSecret) {
            $this->error('PayPal Client ID or Secret is missing in .env');
            return Command::FAILURE;
        }

        // 1. Get Access Token
        $this->info('Fetching Access Token...');
        $http = config('app.env') === 'local' ? Http::withoutVerifying() : Http::asForm();
        
        $tokenResponse = $http->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post("$baseUrl/v1/oauth2/token", [
                'grant_type' => 'client_credentials'
            ]);

        if (!$tokenResponse->successful()) {
            $this->error('Failed to get access token from PayPal.');
            $this->error($tokenResponse->body());
            return Command::FAILURE;
        }

        $accessToken = $tokenResponse->json('access_token');
        
        // 2. Ensure Product Exists (We will use a fixed Product ID to avoid duplicates)
        $productId = 'BREWSPOT-SAAS-PRODUCT';
        $this->info("Ensuring Product ($productId) exists...");

        $productResponse = (config('app.env') === 'local' ? Http::withoutVerifying() : Http::asJson())
            ->withToken($accessToken)
            ->post("$baseUrl/v1/catalogs/products", [
                'id' => $productId,
                'name' => 'Brewspot SaaS Platform',
                'description' => 'Subscription to Brewspot Cafe Management System',
                'type' => 'SERVICE',
                'category' => 'SOFTWARE'
            ]);

        // 201 means created, 400 with 'RESOURCE_ALREADY_EXISTS' means it's already there (which is fine)
        if (!$productResponse->successful() && $productResponse->json('name') !== 'RESOURCE_ALREADY_EXISTS') {
            $this->error('Failed to create product in PayPal.');
            $this->error($productResponse->body());
            return Command::FAILURE;
        }

        // 3. Sync Plans
        $plans = SubscriptionPlan::where('price', '>', 0)->get();

        if ($plans->isEmpty()) {
            $this->info('No paid plans found in database to sync.');
            return Command::SUCCESS;
        }

        foreach ($plans as $plan) {
            $this->info("Processing {$plan->sub_name}...");

            // Create Monthly Plan in PayPal
            if (!$plan->paypal_plan_id) {
                $monthlyPlanId = $this->createBillingPlan(
                    $baseUrl, 
                    $accessToken, 
                    $productId, 
                    "{$plan->sub_name} - Monthly", 
                    'MONTH', 
                    $plan->price
                );

                if ($monthlyPlanId) {
                    $plan->paypal_plan_id = $monthlyPlanId;
                    $this->line(" - Monthly Plan created: $monthlyPlanId");
                }
            } else {
                $this->line(" - Monthly Plan already exists: {$plan->paypal_plan_id}");
            }

            // Create Yearly Plan in PayPal
            if (!$plan->paypal_yearly_plan_id && $plan->yearly_price > 0) {
                $yearlyPlanId = $this->createBillingPlan(
                    $baseUrl, 
                    $accessToken, 
                    $productId, 
                    "{$plan->sub_name} - Yearly", 
                    'YEAR', 
                    $plan->yearly_price
                );

                if ($yearlyPlanId) {
                    $plan->paypal_yearly_plan_id = $yearlyPlanId;
                    $this->line(" - Yearly Plan created: $yearlyPlanId");
                }
            } else {
                $this->line(" - Yearly Plan already exists: {$plan->paypal_yearly_plan_id}");
            }

            $plan->save();
        }

        $this->info('PayPal Plan Sync Complete!');
        return Command::SUCCESS;
    }

    /**
     * Helper to create a billing plan via PayPal API
     */
    private function createBillingPlan($baseUrl, $accessToken, $productId, $name, $interval, $price)
    {
        $response = (config('app.env') === 'local' ? Http::withoutVerifying() : Http::asJson())
            ->withToken($accessToken)
            ->post("$baseUrl/v1/billing/plans", [
                'product_id' => $productId,
                'name' => $name,
                'status' => 'ACTIVE',
                'billing_cycles' => [
                    [
                        'frequency' => [
                            'interval_unit' => $interval,
                            'interval_count' => 1
                        ],
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0, // 0 = infinite (recurring)
                        'pricing_scheme' => [
                            'fixed_price' => [
                                'value' => (string) number_format($price, 2, '.', ''),
                                'currency_code' => 'PHP' // Explicitly set to PHP
                            ]
                        ]
                    ]
                ],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'setup_fee_failure_action' => 'CONTINUE',
                    'payment_failure_threshold' => 3
                ]
            ]);

        if ($response->successful()) {
            return $response->json('id');
        }

        $this->error("Failed to create billing plan: $name");
        $this->error($response->body());
        return null;
    }
}
