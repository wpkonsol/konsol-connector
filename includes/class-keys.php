<?php
if (!defined('ABSPATH')) exit;

/**
 * Kurulum kimliği + Ed25519 anahtar çifti. SITE_IDENTITY_AND_TRANSFER.md
 * §3, adım 3: "WordPress plugin'i ilk kurulumda site_uuid ve kriptografik
 * anahtar çifti üretir." Özel anahtar (secret key) BU SUNUCUDAN HİÇ
 * ÇIKMAZ — yalnızca imza üretmek için kullanılır, hiçbir istekte gönderilmez.
 */
class Konsol_Keys
{
    const OPT_SITE_UUID = 'konsol_site_uuid';
    const OPT_PUBLIC_KEY = 'konsol_public_key';
    const OPT_SECRET_KEY = 'konsol_secret_key';

    public static function ensure_keypair()
    {
        if (get_option(self::OPT_SITE_UUID) && get_option(self::OPT_PUBLIC_KEY) && get_option(self::OPT_SECRET_KEY)) {
            return;
        }

        $keypair = sodium_crypto_sign_keypair();
        $public_key = sodium_crypto_sign_publickey($keypair);
        $secret_key = sodium_crypto_sign_secretkey($keypair);

        update_option(self::OPT_SITE_UUID, wp_generate_uuid4(), true);
        update_option(self::OPT_PUBLIC_KEY, base64_encode($public_key), true);
        // autoload=no — özel anahtar her sayfa yüklemesinde belleğe gelmesin.
        update_option(self::OPT_SECRET_KEY, base64_encode($secret_key), false);
    }

    public static function site_uuid(): string
    {
        return (string) get_option(self::OPT_SITE_UUID, '');
    }

    public static function public_key_base64(): string
    {
        return (string) get_option(self::OPT_PUBLIC_KEY, '');
    }

    /** `$message` üstünde ham (64 bayt) bir Ed25519 imzası, base64. */
    public static function sign(string $message): string
    {
        $secret_key = base64_decode((string) get_option(self::OPT_SECRET_KEY, ''));
        if (!$secret_key) {
            throw new RuntimeException('konsol: özel anahtar yok — eklenti aktivasyonu eksik kalmış olabilir');
        }
        return base64_encode(sodium_crypto_sign_detached($message, $secret_key));
    }
}
