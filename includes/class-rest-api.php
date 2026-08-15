<?php
if (!defined('ABSPATH')) exit;

/**
 * `wckonsol/v1` — WC Konsol API'sinin bu siteye ÇAĞIRDIĞI uçlar (yön ters:
 * pairing/heartbeat eklentiden API'ye gider, bunlar API'den eklentiye).
 * Kimlik doğrulama basit bir bearer token — pairing'de üretilip hem API
 * hem eklenti tarafında saklanıyor (bkz. `class-pairing.php`). Ed25519 imza
 * yalnızca eklentiden API'ye giden yönde (pairing/heartbeat) kullanılıyor,
 * çünkü orada "bu isteği gerçekten bu kurulum mu attı" kanıtlanması
 * gerekiyor; burada API zaten pairing'de doğrulanmış bir sır taşıyor.
 */
class WCKonsol_REST_API
{
    const NAMESPACE = 'wckonsol/v1';

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes()
    {
        register_rest_route(self::NAMESPACE, '/site-info', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'site_info'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route(self::NAMESPACE, '/capabilities', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'capabilities'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route(self::NAMESPACE, '/test-connection', [
            'methods' => 'POST',
            'callback' => fn() => ['ok' => true],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)/seo', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'write_seo'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            // WP_REST_Server, validate_callback'i (value, request, param)
            // ÜÇ argümanla çağırıyor — PHP'nin çekirdek `is_numeric()`i
            // yalnızca bir argüman alıyor, doğrudan verilince
            // ArgumentCountError'la 500 patlıyordu (canlıda yakalandı).
            'args' => ['id' => ['required' => true, 'validate_callback' => fn($value) => is_numeric($value)]],
        ]);
    }

    public static function check_auth(WP_REST_Request $request): bool
    {
        $expected = get_option(WCKonsol_Pairing::OPT_CONNECTION_TOKEN, '');
        if (!$expected) return false;
        $header = $request->get_header('authorization') ?? '';
        if (!str_starts_with($header, 'Bearer ')) return false;
        return hash_equals($expected, substr($header, 7));
    }

    public static function site_info(): array
    {
        return [
            'siteUuid' => WCKonsol_Keys::site_uuid(),
            'url' => home_url(),
            'siteName' => get_bloginfo('name'),
            'wpVersion' => get_bloginfo('version'),
            'wcVersion' => defined('WC_VERSION') ? WC_VERSION : null,
            'phpVersion' => PHP_VERSION,
        ];
    }

    public static function capabilities(): array
    {
        return [
            'catalogue' => true,
            'seoFields' => WCKonsol_Pairing::detect_seo_plugin() !== null,
            'mediaUpload' => true,
            'salesTotals' => class_exists('WooCommerce'),
            'siteOperations' => false,
            'seoPlugin' => WCKonsol_Pairing::detect_seo_plugin(),
            'multilingualPlugin' => defined('ICL_SITEPRESS_VERSION') ? 'wpml' : (defined('POLYLANG_VERSION') ? 'polylang' : null),
        ];
    }

    /**
     * WC çekirdek REST'inin yazamadığı tek gerçek şey — Yoast/Rank Math
     * meta title/description. `product_image` yayınlama (F6) bu eklenti
     * OLMADAN da çalışıyor (bkz. packages/generation-core/src/
     * image-storage.ts), o yüzden burada YOK.
     */
    public static function write_seo(WP_REST_Request $request)
    {
        $product_id = (int) $request['id'];
        if (!get_post($product_id) || get_post_type($product_id) !== 'product') {
            return new WP_Error('wckonsol_product_not_found', 'Product not found', ['status' => 404]);
        }

        $seo_title = $request->get_param('seoTitle');
        $meta_description = $request->get_param('metaDescription');
        $plugin = WCKonsol_Pairing::detect_seo_plugin();

        if (!$plugin) {
            return new WP_Error('wckonsol_no_seo_plugin', 'Neither Yoast SEO nor Rank Math is active on this site', ['status' => 409]);
        }

        if ($plugin === 'yoast') {
            if ($seo_title !== null) update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($seo_title));
            if ($meta_description !== null) update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description));
        } elseif ($plugin === 'rankmath') {
            if ($seo_title !== null) update_post_meta($product_id, 'rank_math_title', sanitize_text_field($seo_title));
            if ($meta_description !== null) update_post_meta($product_id, 'rank_math_description', sanitize_text_field($meta_description));
        }

        return ['ok' => true, 'seoPlugin' => $plugin];
    }
}
