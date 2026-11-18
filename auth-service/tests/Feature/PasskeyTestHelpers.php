<?php

namespace Tests\Feature;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

trait PasskeyTestHelpers
{
    /**
     * Helper to generate an EC P-256 key pair and return the private key PEM,
     * raw public key coordinates (x, y), and CBOR-encoded COSE public key bytes.
     */
    protected function generateEcKeyPair(): array
    {
        $keyPair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($keyPair, $privKeyPem);
        $details = openssl_pkey_get_details($keyPair);
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)) // kty = EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)) // alg = ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)) // crv = P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        return [
            'private_key' => $privKeyPem,
            'x' => $x,
            'y' => $y,
            'cose_bytes' => (string) $cose,
        ];
    }

    /**
     * Helper to create a WebAuthn attestation object (fmt = none).
     */
    protected function craftAttestationObject(string $credIdRaw, string $coseBytes, bool $userVerified = true): string
    {
        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = 0x41; // UP (0x01) + AT (0x40)
        if ($userVerified) {
            $flags |= 0x04; // UV (0x04)
        }

        $authData = $rpIdHash
            .chr($flags)
            .pack('N', 0) // counter = 0
            .str_repeat("\0", 16) // aaguid
            .pack('n', strlen($credIdRaw))
            .$credIdRaw
            .$coseBytes;

        $attMap = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return (string) $attMap;
    }

    /**
     * Helper to craft a WebAuthn assertion authenticatorData.
     */
    protected function craftAssertionAuthData(int $counter = 1, bool $userVerified = true): string
    {
        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = 0x01; // UP
        if ($userVerified) {
            $flags |= 0x04; // UV
        }

        return $rpIdHash.chr($flags).pack('N', $counter);
    }
}
