<?php

namespace App\Models\Auth;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Models\System\Role;
use App\Models\Organization\Outlet;
use App\Models\Organization\Department;
use App\Models\Organization\Employee;
use App\Models\Organization\Supplier;
use App\Models\Crm\Customer;
use App\Models\Security\SecurityEvent;
use App\Models\Security\ApiKey;
use App\Models\Security\AuditLog;
use App\Models\Security\ApiClient;
use App\Models\Security\NotificationProvider;
use App\Models\Auth\UserSession;
use App\Models\Auth\RefreshToken;
use App\Models\System\UserRole;
use App\Models\System\Permission;
use App\Models\Organization\TenantUser;
use App\Models\Organization\TenantModule;
use App\Models\Organization\TenantSubscription;
use App\Models\Organization\TenantUserOutlet;
use App\Models\Auth\UserInvitation;
use App\Models\Auth\UserPosPin;
use App\Models\Crm\DigitalVoucher;
use App\Models\Crm\StoreCreditInvoice;
use App\Models\Security\ApiRequestLog;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingRoom;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

class PasskeyCredential extends Model
{
    use HasFactory;

    protected $table = 'passkey_credentials';

    protected $fillable = [
        'user_id',
        'credential_id',
        'user_handle',
        'public_key',
        'sign_count',
        'backup_eligible',
        'backup_state',
        'aaguid',
        'transports',
        'attestation_type',
        'trust_path',
        'name',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'backup_eligible' => 'boolean',
        'backup_state' => 'boolean',
        'sign_count' => 'integer',
        'transports' => 'array',
        'trust_path' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * User owning this passkey.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope only active (non-revoked) passkey credentials.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Convert this model to WebAuthn CredentialRecord for library verification.
     */
    public function toCredentialRecord(): \Webauthn\CredentialRecord
    {
        $aaguid = null;
        if (!empty($this->aaguid)) {
            try {
                $aaguid = Uuid::fromString($this->aaguid);
            } catch (\Throwable) {
                $aaguid = Uuid::v4();
            }
        } else {
            $aaguid = Uuid::fromString('00000000-0000-0000-0000-000000000000');
        }

        return new \Webauthn\CredentialRecord(
            publicKeyCredentialId: base64_decode(strtr($this->credential_id, '-_', '+/')),
            type: 'public-key',
            transports: $this->transports ?? [],
            attestationType: $this->attestation_type ?? 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: $aaguid,
            credentialPublicKey: base64_decode(strtr($this->public_key, '-_', '+/')),
            userHandle: $this->user_handle,
            counter: (int) $this->sign_count,
            otherUI: null,
            backupEligible: (bool) $this->backup_eligible,
            backupStatus: (bool) $this->backup_state,
            uvInitialized: true
        );
    }

    /**
     * Convert this model to WebAuthn PublicKeyCredentialSource for library verification.
     */
    public function toPublicKeyCredentialSource(): PublicKeyCredentialSource
    {
        $aaguid = null;
        if (!empty($this->aaguid)) {
            try {
                $aaguid = Uuid::fromString($this->aaguid);
            } catch (\Throwable) {
                $aaguid = Uuid::v4();
            }
        } else {
            $aaguid = Uuid::fromString('00000000-0000-0000-0000-000000000000');
        }

        return new PublicKeyCredentialSource(
            publicKeyCredentialId: base64_decode(strtr($this->credential_id, '-_', '+/')),
            type: 'public-key',
            transports: $this->transports ?? [],
            attestationType: $this->attestation_type ?? 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: $aaguid,
            credentialPublicKey: base64_decode(strtr($this->public_key, '-_', '+/')),
            userHandle: $this->user_handle,
            counter: (int) $this->sign_count,
            otherUI: null,
            backupEligible: (bool) $this->backup_eligible,
            backupStatus: (bool) $this->backup_state,
            uvInitialized: true
        );
    }

    /**
     * Create or update record from verified WebAuthn PublicKeyCredentialSource.
     */
    public static function fromPublicKeyCredentialSource(
        PublicKeyCredentialSource $source,
        int $userId,
        ?string $name = null
    ): self {
        $encodedId = rtrim(strtr(base64_encode($source->publicKeyCredentialId), '+/', '-_'), '=');
        $encodedKey = rtrim(strtr(base64_encode($source->credentialPublicKey), '+/', '-_'), '=');

        return static::updateOrCreate(
            ['credential_id' => $encodedId],
            [
                'user_id' => $userId,
                'user_handle' => $source->userHandle,
                'public_key' => $encodedKey,
                'sign_count' => $source->counter,
                'backup_eligible' => $source->backupEligible ?? false,
                'backup_state' => $source->backupStatus ?? false,
                'aaguid' => (string) $source->aaguid,
                'transports' => $source->transports,
                'attestation_type' => $source->attestationType,
                'name' => $name ?? 'Passkey ' . now()->format('Y-m-d H:i'),
                'revoked_at' => null,
            ]
        );
    }
}
