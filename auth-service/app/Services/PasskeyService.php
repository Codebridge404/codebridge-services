<?php

namespace App\Services;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Serializer\Serializer;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Enterprise WebAuthn Level 3 Passkey Authentication & Registration Service.
 * Implements FIDO2 Passkeys for Web Admin, Android Credential Manager, and iOS AuthenticationServices.
 */
class PasskeyService
{
    protected Serializer $serializer;
    protected AuthenticatorAttestationResponseValidator $attestationValidator;
    protected AuthenticatorAssertionResponseValidator $assertionValidator;

    public function __construct()
    {
        $asm = AttestationStatementSupportManager::create();
        $asm->add(NoneAttestationStatementSupport::create());

        $serializerFactory = new WebauthnSerializerFactory($asm);
        $this->serializer = $serializerFactory->create();

        $ceremonyFactory = new CeremonyStepManagerFactory();
        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $ceremonyFactory->creationCeremony()
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $ceremonyFactory->requestCeremony()
        );
    }

    /**
     * Generate WebAuthn passkey registration options for an authenticated user.
     */
    public function generateRegistrationOptions(User $user): array
    {
        $rpId = config('webauthn.rp.id', 'localhost');
        $rpName = config('webauthn.rp.name', 'CodeBridges SmartPOS');

        $rpEntity = PublicKeyCredentialRpEntity::create(
            name: $rpName,
            id: $rpId
        );

        $userHandle = (string) ($user->uuid ?? $user->id);
        $userEntity = PublicKeyCredentialUserEntity::create(
            name: $user->email,
            id: $userHandle,
            displayName: $user->name ?? $user->email
        );

        $challenge = random_bytes(config('webauthn.challenge_length', 32));
        $encodedChallenge = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');

        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', -7),   // ES256
            PublicKeyCredentialParameters::create('public-key', -257), // RS256
            PublicKeyCredentialParameters::create('public-key', -8),   // Ed25519
        ];

        // Exclude user's existing credentials to prevent duplicate enrollments on same device
        $excludeCredentials = [];
        $existing = PasskeyCredential::where('user_id', $user->id)->active()->get();
        foreach ($existing as $cred) {
            $excludeCredentials[] = [
                'type' => 'public-key',
                'id' => $cred->credential_id,
                'transports' => $cred->transports ?? [],
            ];
        }

        // Authenticator selection: require user verification (biometric / PIN)
        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
        );

        $timeout = config('webauthn.timeout', 60000);

        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: $rpEntity,
            user: $userEntity,
            challenge: $challenge,
            pubKeyCredParams: $pubKeyCredParams,
            authenticatorSelection: $authenticatorSelection,
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: [],
            timeout: $timeout
        );

        // Store ceremony options in Redis for 5 minutes bound to user
        $challengeId = Str::random(40);
        Cache::put("auth:passkey_reg_challenge:{$challengeId}", [
            'user_id' => $user->id,
            'challenge' => $encodedChallenge,
            'user_handle' => $userHandle,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(5));

        return [
            'challenge_id' => $challengeId,
            'options' => [
                'rp' => [
                    'name' => $rpName,
                    'id' => $rpId,
                ],
                'user' => [
                    'id' => rtrim(strtr(base64_encode($userHandle), '+/', '-_'), '='),
                    'name' => $user->email,
                    'displayName' => $user->name ?? $user->email,
                ],
                'challenge' => $encodedChallenge,
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],   // ES256
                    ['type' => 'public-key', 'alg' => -257], // RS256
                    ['type' => 'public-key', 'alg' => -8],   // Ed25519
                ],
                'authenticatorSelection' => [
                    'userVerification' => 'required',
                    'residentKey' => 'preferred',
                    'requireResidentKey' => false,
                ],
                'timeout' => $timeout,
                'attestation' => 'none',
                'excludeCredentials' => $excludeCredentials,
            ],
        ];
    }

    /**
     * Verify WebAuthn attestation response and store the new passkey credential.
     */
    public function verifyRegistration(User $user, array $data, string $origin): PasskeyCredential
    {
        $challengeId = $data['challenge_id'] ?? null;
        if (!$challengeId) {
            throw new \InvalidArgumentException('Challenge ID is required for registration verification.');
        }

        // Atomically consume registration challenge to prevent replay
        $saved = Cache::pull("auth:passkey_reg_challenge:{$challengeId}");
        if (!$saved || (int) $saved['user_id'] !== (int) $user->id) {
            throw new \RuntimeException('Registration challenge is invalid, expired, or bound to another account.');
        }

        $credentialPayload = $data['credential'] ?? $data;
        $credentialId = $credentialPayload['id'] ?? null;
        $response = $credentialPayload['response'] ?? null;

        if (!$credentialId || !$response || !isset($response['clientDataJSON'], $response['attestationObject'])) {
            throw new \InvalidArgumentException('Malformed passkey registration response.');
        }

        // Validate clientDataJSON
        $clientDataJson = base64_decode(strtr($response['clientDataJSON'], '-_', '+/'));
        $clientData = json_decode($clientDataJson, true);

        if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new \RuntimeException('Invalid WebAuthn ceremony type in clientDataJSON.');
        }

        // Validate challenge
        $receivedChallenge = rtrim(strtr($clientData['challenge'] ?? '', '+/', '-_'), '=');
        if (!hash_equals($saved['challenge'], $receivedChallenge)) {
            throw new \RuntimeException('WebAuthn challenge mismatch.');
        }

        // Validate origin
        $clientOrigin = rtrim($clientData['origin'] ?? '', '/');
        $this->validateAllowedOrigin($clientOrigin, $origin);

        // Check duplicate credential ID across all accounts
        if (PasskeyCredential::where('credential_id', $credentialId)->active()->exists()) {
            throw new \RuntimeException('Passkey credential already registered in the system.');
        }

        // Parse and extract public key and authenticator data from attestationObject
        $attestationRaw = base64_decode(strtr($response['attestationObject'], '-_', '+/'));
        $attestationDecoded = \CBOR\Decoder::create()->decode(
            new \CBOR\StringStream($attestationRaw)
        )->normalize();

        $authData = $attestationDecoded['authData'] ?? null;
        if (!$authData) {
            throw new \RuntimeException('Missing authData in attestationObject.');
        }

        $authDataBytes = is_string($authData) ? $authData : (string) $authData;
        $flags = ord($authDataBytes[32]);
        $up = ($flags & 0x01) !== 0; // User Present
        $uv = ($flags & 0x04) !== 0; // User Verified
        $at = ($flags & 0x40) !== 0; // Attested Credential Data present

        if (!$up) {
            throw new \RuntimeException('User presence flag was not set during registration.');
        }
        if (!$uv) {
            throw new \RuntimeException('User verification (biometric or PIN) is strictly required.');
        }
        if (!$at) {
            throw new \RuntimeException('Attested credential data missing from authenticator.');
        }

        // Parse attested credential data: AAGUID (16 bytes) + Credential ID Length (2 bytes) + Credential ID + COSE Key
        $aaguid = bin2hex(substr($authDataBytes, 37, 16));
        $credIdLen = unpack('n', substr($authDataBytes, 53, 2))[1];
        $extractedCredId = substr($authDataBytes, 55, $credIdLen);
        $encodedExtractedId = rtrim(strtr(base64_encode($extractedCredId), '+/', '-_'), '=');

        if (!hash_equals($credentialId, $encodedExtractedId)) {
            throw new \RuntimeException('Credential ID does not match authenticator data payload.');
        }

        // Extract COSE public key bytes
        $coseKeyBytes = substr($authDataBytes, 55 + $credIdLen);
        $encodedPublicKey = rtrim(strtr(base64_encode($coseKeyBytes), '+/', '-_'), '=');

        $name = !empty($data['name']) ? trim($data['name']) : 'Passkey ' . now()->format('Y-m-d H:i');

        return PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credentialId,
            'user_handle' => $saved['user_handle'],
            'public_key' => $encodedPublicKey,
            'sign_count' => 0,
            'backup_eligible' => ($flags & 0x08) !== 0,
            'backup_state' => ($flags & 0x10) !== 0,
            'aaguid' => $aaguid,
            'transports' => $response['transports'] ?? ($credentialPayload['transports'] ?? []),
            'attestation_type' => 'none',
            'name' => $name,
            'last_used_at' => now(),
        ]);
    }

    /**
     * Generate WebAuthn authentication options for discoverable or identified login.
     */
    public function generateAuthenticationOptions(?string $email = null): array
    {
        $rpId = config('webauthn.rp.id', 'localhost');
        $challenge = random_bytes(config('webauthn.challenge_length', 32));
        $encodedChallenge = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');

        $allowCredentials = [];
        $userId = null;

        if ($email) {
            $user = User::where('email', strtolower(trim($email)))->first();
            if ($user) {
                $userId = $user->id;
                $credentials = PasskeyCredential::where('user_id', $user->id)->active()->get();
                foreach ($credentials as $cred) {
                    $allowCredentials[] = [
                        'type' => 'public-key',
                        'id' => $cred->credential_id,
                        'transports' => $cred->transports ?? [],
                    ];
                }
            }
        }

        $challengeId = Str::random(40);
        Cache::put("auth:passkey_auth_challenge:{$challengeId}", [
            'challenge' => $encodedChallenge,
            'user_id' => $userId,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(5));

        return [
            'challenge_id' => $challengeId,
            'options' => [
                'challenge' => $encodedChallenge,
                'rpId' => $rpId,
                'timeout' => config('webauthn.timeout', 60000),
                'userVerification' => 'required',
                'allowCredentials' => $allowCredentials,
            ],
        ];
    }

    /**
     * Verify WebAuthn assertion and return authenticated User.
     */
    public function verifyAuthentication(array $data, string $origin): User
    {
        $challengeId = $data['challenge_id'] ?? null;
        if (!$challengeId) {
            throw new \InvalidArgumentException('Challenge ID is required for authentication verification.');
        }

        // Atomically consume authentication challenge to prevent replay
        $saved = Cache::pull("auth:passkey_auth_challenge:{$challengeId}");
        if (!$saved) {
            throw new \RuntimeException('Authentication challenge is invalid or has expired.');
        }

        $credentialPayload = $data['credential'] ?? $data;
        $credentialId = $credentialPayload['id'] ?? null;
        $response = $credentialPayload['response'] ?? null;

        if (!$credentialId || !$response || !isset($response['clientDataJSON'], $response['authenticatorData'], $response['signature'])) {
            throw new \InvalidArgumentException('Malformed passkey authentication response.');
        }

        // Find registered credential
        $passkey = PasskeyCredential::where('credential_id', $credentialId)->active()->first();
        if (!$passkey) {
            throw new \RuntimeException('Unrecognized or revoked passkey credential.');
        }

        $user = $passkey->user;
        if (!$user || (isset($user->is_active) && !(bool) $user->is_active)) {
            throw new \RuntimeException('User account associated with this passkey is inactive or not found.');
        }

        // Validate clientDataJSON
        $clientDataJson = base64_decode(strtr($response['clientDataJSON'], '-_', '+/'));
        $clientData = json_decode($clientDataJson, true);

        if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new \RuntimeException('Invalid WebAuthn ceremony type in clientDataJSON.');
        }

        // Validate challenge
        $receivedChallenge = rtrim(strtr($clientData['challenge'] ?? '', '+/', '-_'), '=');
        if (!hash_equals($saved['challenge'], $receivedChallenge)) {
            throw new \RuntimeException('WebAuthn challenge mismatch.');
        }

        // Validate origin
        $clientOrigin = rtrim($clientData['origin'] ?? '', '/');
        $this->validateAllowedOrigin($clientOrigin, $origin);

        // Verify authenticatorData
        $authDataBytes = base64_decode(strtr($response['authenticatorData'], '-_', '+/'));
        $flags = ord($authDataBytes[32]);
        $up = ($flags & 0x01) !== 0; // User Present
        $uv = ($flags & 0x04) !== 0; // User Verified

        if (!$up) {
            throw new \RuntimeException('User presence flag missing during authentication.');
        }
        if (!$uv) {
            throw new \RuntimeException('User verification (biometric or PIN) is required.');
        }

        // Verify RP ID hash matches config('webauthn.rp.id')
        $expectedRpId = config('webauthn.rp.id', 'localhost');
        $expectedRpIdHash = hash('sha256', $expectedRpId, true);
        $authDataRpIdHash = substr($authDataBytes, 0, 32);

        // Allow matching if RP ID matches configured host or request origin host
        if (!hash_equals($expectedRpIdHash, $authDataRpIdHash)) {
            $parsedHost = parse_url($origin, PHP_URL_HOST);
            if (!$parsedHost || !hash_equals(hash('sha256', $parsedHost, true), $authDataRpIdHash)) {
                throw new \RuntimeException('RP ID mismatch in authenticator data.');
            }
        }

        // Verify cryptographic assertion signature
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authDataBytes . $clientDataHash;
        $signature = base64_decode(strtr($response['signature'], '-_', '+/'));
        $coseKeyBytes = base64_decode(strtr($passkey->public_key, '-_', '+/'));

        $verified = $this->verifySignatureWithCoseKey($signedData, $signature, $coseKeyBytes);
        if (!$verified) {
            throw new \RuntimeException('Cryptographic assertion signature verification failed.');
        }

        // Signature counter validation & cloned authenticator detection (WebAuthn Level 3)
        $counter = unpack('N', substr($authDataBytes, 33, 4))[1];
        if ($counter > 0 && $passkey->sign_count > 0 && $counter <= $passkey->sign_count) {
            $passkey->update(['revoked_at' => now()]);
            Log::emergency("Cloned passkey authenticator detected for user {$user->id}, credential {$passkey->id}. Revoking credential immediately.", [
                'user_id' => $user->id,
                'credential_id' => $passkey->credential_id,
                'stored_counter' => $passkey->sign_count,
                'received_counter' => $counter,
            ]);
            throw new \RuntimeException('Cloned passkey authenticator detected. Credential has been revoked for security.');
        }

        if ($counter > $passkey->sign_count) {
            $passkey->sign_count = $counter;
        }
        $passkey->last_used_at = now();
        $passkey->save();

        return $user;
    }

    /**
     * Issue a short-lived step-up reauthentication grant (10 minutes).
     */
    public function createReauthGrant(User $user, string $purpose = 'security_action', int $ttlSeconds = 600): string
    {
        $token = Str::random(40);
        Cache::put("auth:reauth_grant:{$user->id}:{$purpose}", [
            'token' => $token,
            'granted_at' => now()->timestamp,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ], now()->addSeconds($ttlSeconds));

        return $token;
    }

    /**
     * Check if user has an active, valid step-up grant for a specific sensitive purpose.
     */
    public function hasRecentReauth(User $user, string $purpose = 'security_action'): bool
    {
        return Cache::has("auth:reauth_grant:{$user->id}:{$purpose}");
    }

    /**
     * Consume step-up grant after sensitive operation.
     */
    public function consumeReauthGrant(User $user, string $purpose = 'security_action'): void
    {
        Cache::forget("auth:reauth_grant:{$user->id}:{$purpose}");
    }

    /**
     * Validate client origin against allowlist and platform association conventions.
     */
    protected function validateAllowedOrigin(string $clientOrigin, string $requestOrigin): void
    {
        $allowed = config('webauthn.allowed_origins', []);
        $cleanClient = rtrim($clientOrigin, '/');

        // Check exact match in configured allowed origins
        foreach ($allowed as $originPattern) {
            if (rtrim($originPattern, '/') === $cleanClient) {
                return;
            }
        }

        // Allow Android native origins: android:apk-key-hash:<sha256>
        if (str_starts_with($cleanClient, 'android:apk-key-hash:')) {
            $allowedHashes = config('webauthn.android_key_hashes', []);
            $apkHash = substr($cleanClient, strlen('android:apk-key-hash:'));
            if (in_array($apkHash, $allowedHashes, true) || app()->environment('local', 'testing')) {
                return;
            }
        }

        // Allow matching request origin in development/testing
        if (app()->environment('local', 'testing') && $cleanClient === rtrim($requestOrigin, '/')) {
            return;
        }

        throw new \RuntimeException("WebAuthn origin '{$clientOrigin}' is not permitted.");
    }

    /**
     * Cryptographically verify signature using COSE key data via OpenSSL.
     */
    protected function verifySignatureWithCoseKey(string $signedData, string $signature, string $coseBytes): bool
    {
        try {
            $cose = \CBOR\Decoder::create()->decode(
                new \CBOR\StringStream($coseBytes)
            )->normalize();

            if (!is_array($cose)) {
                return false;
            }

            $kty = isset($cose[1]) ? (int) $cose[1] : null;  // 2 = EC2, 3 = RSA, 1 = OKP
            $alg = isset($cose[3]) ? (int) $cose[3] : null;  // -7 = ES256, -257 = RS256, -8 = EdDSA

            if ($kty === 2 && $alg === -7) {
                // ES256: curve P-256 (crv = 1), x = -2, y = -3
                $x = $cose[-2] ?? '';
                $y = $cose[-3] ?? '';

                $derPublicKey = $this->ecPointsToDer($x, $y);
                $opensslKey = openssl_pkey_get_public($derPublicKey);

                if (!$opensslKey) {
                    return false;
                }

                $derSignature = $this->rawSignatureToDer($signature);
                $result = openssl_verify($signedData, $derSignature, $opensslKey, OPENSSL_ALGO_SHA256);

                return $result === 1;
            } elseif ($kty === 3 && $alg === -257) {
                // RS256: n = -1, e = -2
                $n = $cose[-1] ?? '';
                $e = $cose[-2] ?? '';

                $rsaPublicKey = $this->rsaComponentsToDer($n, $e);
                $opensslKey = openssl_pkey_get_public($rsaPublicKey);

                if (!$opensslKey) {
                    return false;
                }

                $result = openssl_verify($signedData, $signature, $opensslKey, OPENSSL_ALGO_SHA256);

                return $result === 1;
            }
        } catch (\Throwable $e) {
            Log::warning('Passkey signature verification error: ' . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Format EC P-256 (x, y) coordinates into SubjectPublicKeyInfo DER/PEM.
     */
    protected function ecPointsToDer(string $x, string $y): string
    {
        $x = str_pad($x, 32, "\0", STR_PAD_LEFT);
        $y = str_pad($y, 32, "\0", STR_PAD_LEFT);
        $point = "\x04" . $x . $y;

        // X.509 SubjectPublicKeyInfo for prime256v1
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $point;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Format RSA (n, e) components into SubjectPublicKeyInfo PEM.
     */
    protected function rsaComponentsToDer(string $n, string $e): string
    {
        $modulus = $this->encodeDerInteger($n);
        $exponent = $this->encodeDerInteger($e);
        $rsaSequence = "\x30" . $this->encodeDerLength(strlen($modulus . $exponent)) . $modulus . $exponent;

        $bitString = "\x03" . $this->encodeDerLength(strlen($rsaSequence) + 1) . "\x00" . $rsaSequence;
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $spki = "\x30" . $this->encodeDerLength(strlen($algorithmIdentifier . $bitString)) . $algorithmIdentifier . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    protected function encodeDerInteger(string $data): string
    {
        $data = ltrim($data, "\x00");
        if (ord($data[0]) & 0x80) {
            $data = "\x00" . $data;
        }
        return "\x02" . $this->encodeDerLength(strlen($data)) . $data;
    }

    protected function encodeDerLength(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Convert WebAuthn IEEE P1363 (r || s) or raw signature to ASN.1 DER if required.
     */
    protected function rawSignatureToDer(string $sig): string
    {
        // If already ASN.1 DER (starts with 0x30)
        if (ord($sig[0]) === 0x30) {
            return $sig;
        }

        // IEEE P1363 (64 bytes: 32 bytes r + 32 bytes s)
        if (strlen($sig) === 64) {
            $r = substr($sig, 0, 32);
            $s = substr($sig, 32, 32);

            $r = ltrim($r, "\x00");
            $s = ltrim($s, "\x00");

            if (ord($r[0]) & 0x80) {
                $r = "\x00" . $r;
            }
            if (ord($s[0]) & 0x80) {
                $s = "\x00" . $s;
            }

            $rDer = "\x02" . chr(strlen($r)) . $r;
            $sDer = "\x02" . chr(strlen($s)) . $s;

            return "\x30" . chr(strlen($rDer . $sDer)) . $rDer . $sDer;
        }

        return $sig;
    }
}
