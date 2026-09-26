<?php
if (!defined('ABSPATH')) exit;

/**
 * "Single Click Login" — Konsol'un Stores sayfasındaki "Log into WordPress"
 * butonu. Konsol'un kendi `/auth/exchange-token` akışıyla (staff → admin
 * geçişi) AYNI desen, burada WordPress tarafında: kısa ömürlü, tek
 * kullanımlık bir token → bir kez kullanılınca gerçek bir wp-admin oturumu
 * açıp düşer.
 *
 * Şifre saklamıyoruz (bkz. `class-rest-api.php`'deki `create_login_token`
 * yorumu — bilerek: gerçek wp-admin şifresi saklamanın risk kapsamı
 * WooCommerce API anahtarından çok daha büyük). Token'ın kendisi bir WP
 * geçici seçeneği (`set_transient`) olarak saklanıyor — veritabanında,
 * ama süresi dolduğunda WP'nin kendisi temizliyor, bizim ayrıca bir
 * temizlik işi yok.
 */
class WCKonsol_Login
{
    const TOKEN_TTL = 60; // saniye — Konsol'un kendi exchange-token'ıyla aynı süre
    const QUERY_ARG = 'wckonsol_login';

    public static function init()
    {
        // `init` — çok erken bir hook (`template_redirect` bile değil) ki
        // hiçbir tema/eklenti bu isteği başka bir şeye çevirmeden önce
        // yakalayalım. Yalnızca query arg VARSA çalışıyor, yoksa normal
        // WordPress akışına hiç dokunmuyor.
        add_action('init', [__CLASS__, 'maybe_redeem']);
    }

    private static function transient_key(string $token): string
    {
        return 'wckonsol_login_' . $token;
    }

    /** `class-rest-api.php`'nin `/login-token` ucu — hangi WP kullanıcısı
     * olarak giriş yapılacağı zaten orada seçildi (siteye ait ilk admin),
     * burası yalnızca token üretip saklıyor. */
    public static function create_token(int $user_id): string
    {
        $token = wp_generate_password(48, false, false);
        set_transient(self::transient_key($token), $user_id, self::TOKEN_TTL);
        return add_query_arg(self::QUERY_ARG, $token, home_url('/'));
    }

    /** Tek kullanımlık — okunur okunmaz siliniyor, aynı token ikinci kez
     * hiç işe yaramıyor (replay koruması). Geçersiz/süresi dolmuş bir
     * token'da normal giriş sayfasına düşülüyor — sessizce hata değil,
     * kullanıcı en azından manuel giriş yapabilsin. */
    public static function maybe_redeem()
    {
        if (empty($_GET[self::QUERY_ARG])) return;

        $token = sanitize_text_field(wp_unslash($_GET[self::QUERY_ARG]));
        $key = self::transient_key($token);
        $user_id = get_transient($key);
        delete_transient($key);

        if (!$user_id || !get_userdata((int) $user_id)) {
            wp_safe_redirect(wp_login_url(admin_url()));
            exit;
        }

        $user_id = (int) $user_id;
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false);
        do_action('wp_login', get_userdata($user_id)->user_login, get_userdata($user_id));

        wp_safe_redirect(admin_url());
        exit;
    }
}
