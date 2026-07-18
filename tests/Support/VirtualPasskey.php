<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Cose\Algorithms;
use Cose\Key\Ec2Key;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

final class VirtualPasskey
{
    private const AUTHENTICATION_FLAGS = 0x05;

    private const REGISTRATION_FLAGS = 0x45;

    private OpenSSLAsymmetricKey $privateKey;

    private string $credentialId;

    public function __construct()
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to create virtual passkey.');
        }

        $this->privateKey = $privateKey;
        $this->credentialId = random_bytes(32);
    }

    /** @return array<string, mixed> */
    public function registrationCredential(
        string $challenge,
        string $origin = 'http://localhost',
        string $relyingPartyId = 'localhost',
    ): array {
        $clientData = $this->clientData('webauthn.create', $challenge, $origin);
        $authenticatorData = hash('sha256', $relyingPartyId, binary: true)
            .chr(self::REGISTRATION_FLAGS)
            .pack('N', 0)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->cosePublicKey();

        $attestationObject = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

        return $this->credential([
            'clientDataJSON' => $this->encode($clientData),
            'attestationObject' => $this->encode((string) $attestationObject),
            'transports' => ['internal'],
        ]);
    }

    /** @return array<string, mixed> */
    public function authenticationCredential(
        string $challenge,
        string $userHandle,
        int $counter = 1,
        string $origin = 'http://localhost',
        string $relyingPartyId = 'localhost',
    ): array {
        $clientData = $this->clientData('webauthn.get', $challenge, $origin);
        $authenticatorData = hash('sha256', $relyingPartyId, binary: true)
            .chr(self::AUTHENTICATION_FLAGS)
            .pack('N', $counter);
        $signedData = $authenticatorData.hash('sha256', $clientData, binary: true);

        if (! openssl_sign($signedData, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign virtual passkey assertion.');
        }

        return $this->credential([
            'authenticatorData' => $this->encode($authenticatorData),
            'clientDataJSON' => $this->encode($clientData),
            'signature' => $this->encode($signature),
            'userHandle' => $this->encode($userHandle),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function credential(array $response): array
    {
        $id = $this->encode($this->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => $response,
        ];
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }

    private function cosePublicKey(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;

        if (! is_string($x) || ! is_string($y)) {
            throw new RuntimeException('Unable to read virtual passkey public key.');
        }

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(Ec2Key::TYPE), UnsignedIntegerObject::create(Ec2Key::TYPE_EC2))
            ->add(UnsignedIntegerObject::create(Ec2Key::ALG), NegativeIntegerObject::create(Algorithms::COSE_ALGORITHM_ES256))
            ->add(NegativeIntegerObject::create(Ec2Key::DATA_CURVE), UnsignedIntegerObject::create(Ec2Key::CURVE_P256))
            ->add(NegativeIntegerObject::create(Ec2Key::DATA_X), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(Ec2Key::DATA_Y), ByteStringObject::create($y));
    }

    private function encode(string $value): string
    {
        return Base64UrlSafe::encodeUnpadded($value);
    }
}
