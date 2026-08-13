<?php
/**
 * Cifrado simétrico de secrets en `palyp_configuration`.
 *
 * Algoritmo: libsodium `sodium_crypto_secretbox` (XSalsa20-Poly1305) con clave
 * de 32 bytes derivada de `_COOKIE_KEY_` + `_COOKIE_IV_` (constantes únicas por
 * instalación PrestaShop, autogeneradas en parameters.php — si rotan, el
 * descifrado falla y se marca un flag para que el admin lo vea).
 *
 * Si libsodium no está disponible (PHP < 7.2 o build sin sodium), caemos a
 * openssl AES-256-GCM con la misma clave. Ambos AEAD, nonce aleatorio.
 *
 * Formato almacenado:
 *   "enc:v1:" + base64(nonce || ciphertext)
 *
 * Retrocompatibilidad: si el valor en BD no tiene el prefijo `enc:v1:`, se
 * devuelve tal cual (texto plano legacy). La migración cifra perezosamente
 * en cuanto el admin guarda el valor o se ejecuta `migratePlaintext()`.
 *
 * Integración con `Configuration::get/updateValue` se hace vía wrapper
 * estático: el plugin llama a `TpvSyncSecrets::get()` / `::set()` para
 * estas claves, no se altera el comportamiento global de Configuration.
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) exit;

class TpvSyncSecrets
{
    public const PREFIX = 'enc:v1:';

    /** Claves de configuración que contienen secrets — se cifran al guardar. */
    public const SECRET_KEYS = [
        'TPVSYNC_CLIENT_SECRET',
        'TPVSYNC_WEBHOOK_SECRET',
        'TPVSYNC_SHARED_SECRET',
        'TPVSYNC_BATCH_TOKEN',
        'TPVSYNC_TOKEN_CACHE',
    ];

    /**
     * Deriva una clave de 32 bytes a partir de las constantes únicas por
     * instalación PrestaShop. _COOKIE_KEY_ + _COOKIE_IV_ se generan al
     * instalar PS y NO rotan (a diferencia de WP::AUTH_KEY, que sí puede
     * rotarse). En el peor caso, fallback a una clave persistida en
     * Configuration (menos seguro, pero algo).
     */
    private static function deriveKey(): string
    {
        $material = '';
        foreach (['_COOKIE_KEY_', '_COOKIE_IV_', '_PHP_PASSWD_VERSION_', '_RIJNDAEL_KEY_'] as $c) {
            if (defined($c)) $material .= constant($c);
        }
        if ($material === '') {
            $key = Configuration::getGlobalValue('TPVSYNC_SECRETS_FALLBACK_KEY');
            if (!$key) {
                $key = base64_encode(random_bytes(32));
                Configuration::updateGlobalValue('TPVSYNC_SECRETS_FALLBACK_KEY', $key);
            }
            $material = base64_decode((string) $key);
        }
        $hash = hash('sha256', $material, true);
        // 32 bytes para ambos: secretbox usa SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        // openssl AES-256-GCM usa 32 bytes. Coinciden.
        return substr($hash, 0, 32);
    }

    public static function isEncrypted(string $s): bool
    {
        return strncmp($s, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Cifra un string. Si ya está cifrado o vacío, lo devuelve tal cual
     * (idempotente, evita doble cifrado al guardar el mismo valor).
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || self::isEncrypted($plain)) return $plain;

        $key = self::deriveKey();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return self::PREFIX . base64_encode($nonce . $cipher);
        }

        // Fallback openssl AES-256-GCM. Empaquetamos: nonce(12) || tag(16) || cipher.
        if (function_exists('openssl_encrypt')) {
            $nonce  = random_bytes(12);
            $tag    = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($cipher === false) return $plain;
            return self::PREFIX . base64_encode($nonce . $tag . $cipher);
        }

        // Sin libsodium ni openssl: degradamos silenciosamente. No queremos
        // perder credenciales ni bloquear el plugin si falta una extensión.
        return $plain;
    }

    /**
     * Descifra un string con el prefijo `enc:v1:`. Si no está cifrado, lo
     * devuelve tal cual (legacy plaintext).
     *
     * Si el descifrado falla (clave rotada, restauración desde otra
     * instalación, ciphertext corrupto), devolvemos '' y marcamos un flag
     * en Configuration para que el admin lo vea. Sin esto, el cliente vería
     * "invalid_client" en /auth/token sin pista de la causa.
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !self::isEncrypted($stored)) return $stored;
        $b64 = substr($stored, strlen(self::PREFIX));
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            self::flagDecryptFailure('base64');
            return '';
        }

        $key = self::deriveKey();

        if (function_exists('sodium_crypto_secretbox_open')) {
            $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) < $nonceLen + 16) {
                self::flagDecryptFailure('too_short_sodium');
                return '';
            }
            $nonce = substr($raw, 0, $nonceLen);
            $ct    = substr($raw, $nonceLen);
            $plain = sodium_crypto_secretbox_open($ct, $nonce, $key);
            if ($plain !== false) return $plain;
            // Fallthrough: el ciphertext puede haber sido producido con
            // openssl en un entorno previo sin libsodium.
        }

        if (function_exists('openssl_decrypt')) {
            if (strlen($raw) < 12 + 16) {
                self::flagDecryptFailure('too_short_openssl');
                return '';
            }
            $nonce = substr($raw, 0, 12);
            $tag   = substr($raw, 12, 16);
            $ct    = substr($raw, 28);
            $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($plain !== false) return $plain;
        }

        self::flagDecryptFailure('mac_or_key_mismatch');
        return '';
    }

    /**
     * Wrapper de Configuration::get que descifra automáticamente si la clave
     * pertenece a SECRET_KEYS. El plugin SIEMPRE debería usar este método
     * (no Configuration::get directo) para estas claves.
     */
    public static function get(string $key): string
    {
        $raw = (string) Configuration::get($key);
        if (!in_array($key, self::SECRET_KEYS, true)) return $raw;
        return self::decrypt($raw);
    }

    /**
     * Wrapper de Configuration::updateValue que cifra automáticamente.
     * Devuelve el bool que devuelve PrestaShop (true si guardó, false si no).
     */
    public static function set(string $key, string $value): bool
    {
        $toStore = in_array($key, self::SECRET_KEYS, true)
            ? self::encrypt($value)
            : $value;

        // Limpiamos el flag de fallo de descifrado: el admin acaba de guardar
        // un secret nuevo, démosle la oportunidad de funcionar antes de
        // mostrar banners de error.
        if (in_array($key, self::SECRET_KEYS, true)) {
            Configuration::deleteByName('TPVSYNC_SECRET_DECRYPT_FAILED');
        }

        return (bool) Configuration::updateValue($key, $toStore);
    }

    /**
     * Migra valores legacy en plaintext a ciphertext. Idempotente: omite
     * los que ya están cifrados. Devuelve cuántos migró.
     */
    public static function migratePlaintext(): int
    {
        $migrated = 0;
        foreach (self::SECRET_KEYS as $key) {
            $raw = (string) Configuration::get($key);
            if ($raw === '' || self::isEncrypted($raw)) continue;
            Configuration::updateValue($key, self::encrypt($raw));
            $migrated++;
        }
        return $migrated;
    }

    /**
     * Marca en Configuration que un descifrado ha fallado, para que el
     * admin muestre un banner accionable. Se limpia al guardar un secret
     * nuevo (set()).
     */
    private static function flagDecryptFailure(string $reason): void
    {
        Configuration::updateValue('TPVSYNC_SECRET_DECRYPT_FAILED', json_encode([
            'reason' => $reason,
            'at'     => time(),
        ]));
    }
}
