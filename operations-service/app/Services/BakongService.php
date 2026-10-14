<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BakongService
{
    protected string $apiUrl;
    protected ?string $apiToken = null;
    protected ?string $merchantName = null;
    protected ?string $merchantCity = null;
    protected ?string $merchantId = null;

    public function __construct()
    {
        $this->apiUrl = (string) (config('services.bakong.url') ?: 'https://api-bakong.nbc.gov.kh/v1');
        $this->apiToken = config('services.bakong.token') ? (string) config('services.bakong.token') : null;
        $this->merchantName = config('services.bakong.merchant_name') ? (string) config('services.bakong.merchant_name') : 'Store POS';
        $this->merchantCity = config('services.bakong.merchant_city') ? (string) config('services.bakong.merchant_city') : 'Phnom Penh';
        $this->merchantId = config('services.bakong.merchant_id') ? (string) config('services.bakong.merchant_id') : 'merchant_pos';
    }

    /**
     * Build standard EMVCo TLV (Tag-Length-Value) chunk
     */
    protected function buildTlv(string $tag, string $value): string
    {
        return $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    /**
     * Calculate 16-bit CRC checksum (CRC-16/CCITT-FALSE) as required by NBC KHQR SDK
     * Polynomial: 0x1021, Initial: 0xFFFF
     */
    protected function calculateCrc16(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Generate authentic Bakong KHQR payload and transaction MD5 reference
     */
    public function generateKhqrPayload(
        float $amount,
        string $currency = 'USD',
        string $billNumber = '',
        ?string $merchantId = null,
        ?string $merchantName = null,
        ?string $merchantCity = null,
        ?string $acquiringBank = null
    ): array {
        $currUpper = strtoupper($currency);
        $currencyCode = $currUpper === 'KHR' ? '116' : '840';
        $formattedAmount = $currUpper === 'KHR' ? (string) round($amount) : sprintf('%.2f', $amount);
        $khrAmount = $currUpper === 'USD' ? round($amount * 4100) : $amount;

        $billNo = $billNumber ?: ('BILL-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4)));
        $activeMerchantId = $merchantId ?: ($this->merchantId ?: 'store@bakong');
        $activeMerchantName = substr($merchantName ?: ($this->merchantName ?: 'Store POS'), 0, 25);
        $activeMerchantCity = substr($merchantCity ?: ($this->merchantCity ?: 'Phnom Penh'), 0, 15);
        $activeBank = $acquiringBank ?: 'Bakong';

        // Tag 00: Payload Format Indicator (01)
        $payload = $this->buildTlv('00', '01');

        // Tag 01: Point of Initiation Method (12 = Dynamic QR with amount)
        $payload .= $this->buildTlv('01', '12');

        // Format Bakong Account ID with domain suffix if not already present
        if (!str_contains($activeMerchantId, '@')) {
            $bankLower = strtolower($activeBank);
            if (str_contains($bankLower, 'aba')) {
                $activeMerchantId .= '@aba';
            } elseif (str_contains($bankLower, 'wing')) {
                $activeMerchantId .= '@wing';
            } elseif (str_contains($bankLower, 'aclb') || str_contains($bankLower, 'acleda')) {
                $activeMerchantId .= '@aclb';
            } elseif (str_contains($bankLower, 'sathapana')) {
                $activeMerchantId .= '@sathapana';
            } elseif (str_contains($bankLower, 'canadia')) {
                $activeMerchantId .= '@canadia';
            } else {
                $activeMerchantId .= '@bakong';
            }
        }

        // Tag 29: Standard Bakong Account Information (Interbank Solo/Merchant standard)
        $tag29Value = $this->buildTlv('00', $activeMerchantId);
        $payload .= $this->buildTlv('29', $tag29Value);

        // Tag 52: Merchant Category Code (5999 = Miscellaneous/General)
        $payload .= $this->buildTlv('52', '5999');

        // Tag 53: Transaction Currency (840 = USD, 116 = KHR)
        $payload .= $this->buildTlv('53', $currencyCode);

        // Tag 54: Transaction Amount
        $payload .= $this->buildTlv('54', $formattedAmount);

        // Tag 58: Country Code (KH)
        $payload .= $this->buildTlv('58', 'KH');

        // Tag 59: Merchant Name
        $payload .= $this->buildTlv('59', $activeMerchantName);

        // Tag 60: Merchant City
        $payload .= $this->buildTlv('60', $activeMerchantCity);

        // Tag 62: Additional Data Field (Sub-tag 01: Bill Number)
        $tag62Value = $this->buildTlv('01', substr($billNo, 0, 25));
        $payload .= $this->buildTlv('62', $tag62Value);

        // Tag 99: Dynamic KHQR Timestamps (Mandatory for Dynamic QR in NBC Bakong SDK)
        $nowMs = (string) (int) (microtime(true) * 1000);
        $expMs = (string) (int) ((microtime(true) + (15 * 60)) * 1000); // 15-minute expiration
        $tag99Value = $this->buildTlv('00', $nowMs) . $this->buildTlv('01', $expMs);
        $payload .= $this->buildTlv('99', $tag99Value);

        // Tag 63: CRC-16 (Compute checksum on entire string including '6304')
        $payloadWithCrcTag = $payload . '6304';
        $crc16 = $this->calculateCrc16($payloadWithCrcTag);
        $khqrString = $payloadWithCrcTag . $crc16;

        // NBC Bakong Open API MD5 hash of the generated KHQR string
        $md5Hash = md5($khqrString);

        return [
            'khqr_string' => $khqrString,
            'md5' => $md5Hash,
            'merchant_name' => $activeMerchantName,
            'merchant_city' => $activeMerchantCity,
            'amount' => $amount,
            'currency' => $currUpper,
            'khr_equivalent' => $khrAmount,
            'bill_number' => $billNo,
        ];
    }

    /**
     * Verify transaction completion using Bakong Open API check_transaction_by_md5
     */
    public function checkTransactionByMd5(string $md5Hash, ?string $token = null): array
    {
        $activeToken = $token ?: $this->apiToken;
        if (empty($activeToken)) {
            Log::warning('Bakong API Token not configured.');
            return [
                'paid' => false,
                'response_code' => 1,
                'message' => 'Bakong API Token missing. Configure token in Settings or .env',
            ];
        }

        try {
            $endpoint = "{$this->apiUrl}/check_transaction_by_md5";
            $response = Http::withToken($activeToken)
                ->acceptJson()
                ->post($endpoint, [
                    'md5' => $md5Hash,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $isPaid = isset($data['responseCode']) && (int) $data['responseCode'] === 0;

                return [
                    'paid' => $isPaid,
                    'response_code' => $data['responseCode'] ?? 1,
                    'response_message' => $data['responseMessage'] ?? 'Unknown status',
                    'data' => $data['data'] ?? null,
                    'raw' => $data,
                ];
            }

            return [
                'paid' => false,
                'response_code' => $response->status(),
                'response_message' => 'Bakong API request failed',
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('Bakong API error: ' . $e->getMessage());
            return [
                'paid' => false,
                'response_code' => 500,
                'response_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify transaction completion using Bakong Open API check_transaction_by_short_hash / check_transaction_by_hash
     */
    public function checkTransactionByHash(
        string $hash,
        ?string $token = null,
        float $amount = 0.0,
        string $currency = 'USD'
    ): array {
        $cleanHash = trim($hash);
        $activeToken = $token ?: $this->apiToken;

        if (empty($activeToken)) {
            return [
                'paid' => false,
                'response_code' => 1,
                'response_message' => 'Bakong API Token missing. Configure Developer Token in Settings.',
            ];
        }

        try {
            $isShort = strlen($cleanHash) <= 16;
            $endpoint = $isShort
                ? "{$this->apiUrl}/check_transaction_by_short_hash"
                : "{$this->apiUrl}/check_transaction_by_hash";

            $body = $isShort
                ? [
                    'hash' => $cleanHash,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                ]
                : ['hash' => $cleanHash];

            $response = Http::withToken($activeToken)
                ->acceptJson()
                ->post($endpoint, $body);

            if ($response->successful()) {
                $data = $response->json();
                $isPaid = isset($data['responseCode']) && (int) $data['responseCode'] === 0;

                return [
                    'paid' => $isPaid,
                    'response_code' => $data['responseCode'] ?? 1,
                    'response_message' => $data['responseMessage'] ?? 'Unknown status',
                    'data' => $data['data'] ?? null,
                    'raw' => $data,
                ];
            }

            $raw = $response->json() ?? [];
            return [
                'paid' => false,
                'response_code' => $response->status(),
                'response_message' => $raw['responseMessage'] ?? $raw['message'] ?? 'Bakong API request failed',
                'raw' => $raw,
            ];
        } catch (\Throwable $e) {
            Log::error('Bakong API hash check error: ' . $e->getMessage());
            return [
                'paid' => false,
                'response_code' => 500,
                'response_message' => $e->getMessage(),
            ];
        }
    }
}
