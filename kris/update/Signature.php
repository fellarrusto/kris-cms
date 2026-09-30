<?php
declare(strict_types=1);

namespace Kris\Update;

/**
 * Firme Ed25519 dei pacchetti e del canale delle release.
 *
 * Le chiavi pubbliche stanno in kris/update/keys.php, cioe nel codice: nessun
 * campo di configurazione puo cambiarle. La chiave privata non entra mai
 * nella repository e non passa dalla CI.
 */
final class Signature
{
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /** Chiavi pubbliche fidate, codificate in base64. */
    public static function trustedKeys(): array
    {
        $keys = require KRIS_DIR . '/update/keys.php';
        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * Vero se $signature (base64) e una firma valida di $data per almeno una
     * delle chiavi. Una firma o una chiave malformata vale come non valida.
     */
    public static function verify(string $data, string $signature, array $publicKeys): bool
    {
        if (!self::available()) {
            throw new UpdateException(
                "Su questo hosting manca l'estensione PHP sodium: senza, non si possono verificare i pacchetti. "
                . "Chiedi all'hosting di attivarla."
            );
        }
        $sig = base64_decode(trim($signature), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ($publicKeys as $key) {
            $pk = base64_decode(trim((string) $key), true);
            if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            if (sodium_crypto_sign_verify_detached($sig, $data, $pk)) {
                return true;
            }
        }
        return false;
    }

    /** Firma $data con una chiave segreta in base64. Serve agli strumenti di release e ai test. */
    public static function sign(string $data, string $secretKey): string
    {
        $sk = base64_decode(trim($secretKey), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new UpdateException('Chiave segreta non valida.');
        }
        return base64_encode(sodium_crypto_sign_detached($data, $sk));
    }

    /** Nuova coppia di chiavi: ['public' => base64, 'secret' => base64]. */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
