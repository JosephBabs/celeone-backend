<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Order;
use App\Models\Marketplace\Payment;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class FedaPayService
{
    private function boot(): void
    {
        $key = (string) config('marketplace.fedapay.secret_key');
        if ($key === '') throw new RuntimeException('FedaPay secret key is not configured.');
        if (!class_exists(\FedaPay\FedaPay::class)) {
            throw new RuntimeException('FedaPay PHP SDK is missing. Run: composer require fedapay/fedapay-php');
        }

        \FedaPay\FedaPay::setApiKey($key);
        \FedaPay\FedaPay::setEnvironment((string) config('marketplace.fedapay.environment', 'live'));
    }

    public function createCheckout(Order $order, User $buyer): Payment
    {
        $this->boot();

        $name = trim((string) $buyer->name);
        $parts = preg_split('/\s+/', $name ?: 'Cele One User', 2);
        $first = $parts[0] ?? 'Cele';
        $last = $parts[1] ?? 'One';

        $transaction = \FedaPay\Transaction::create([
            'description' => 'Cèlè Market order ' . $order->public_id,
            'amount' => (int) $order->total,
            'currency' => ['iso' => $order->currency],
            'callback_url' => (string) config('marketplace.fedapay.callback_url'),
            'customer' => [
                'firstname' => $first,
                'lastname' => $last,
                'email' => (string) $buyer->email,
            ],
            'metadata' => [
                'kind' => 'marketplace',
                'marketplace_order_id' => $order->public_id,
                'buyer_user_id' => (string) $buyer->id,
            ],
        ]);

        $token = $transaction->generateToken();
        $providerId = (string) ($transaction->id ?? '');
        $checkoutUrl = (string) ($token->url ?? '');

        if ($providerId === '' || $checkoutUrl === '') {
            throw new RuntimeException('FedaPay did not return a transaction id/payment URL.');
        }

        return Payment::create([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'provider' => 'fedapay',
            'provider_transaction_id' => $providerId,
            'checkout_url' => $checkoutUrl,
            'amount' => (int) $order->total,
            'currency' => $order->currency,
            'status' => 'pending',
            'provider_payload' => ['transaction_id' => $providerId],
        ]);
    }

    public function retrieve(string $providerTransactionId): array
    {
        $this->boot();
        $transaction = \FedaPay\Transaction::retrieve($providerTransactionId);

        $array = method_exists($transaction, 'toArray')
            ? $transaction->toArray()
            : json_decode(json_encode($transaction), true);

        return is_array($array) ? $array : [];
    }

    public function statusFromPayload(array $payload): string
    {
        return strtolower((string) (
            data_get($payload, 'status')
            ?? data_get($payload, 'transaction.status')
            ?? data_get($payload, 'entity.status')
            ?? 'pending'
        ));
    }

    public function isApproved(string $status): bool
    {
        return in_array(strtolower($status), ['approved', 'transferred'], true);
    }
}
