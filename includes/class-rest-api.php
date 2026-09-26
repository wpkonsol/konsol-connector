<?php
if (!defined('ABSPATH')) exit;

/**
 * `konsol/v1` — Konsol API'sinin bu siteye ÇAĞIRDIĞI uçlar (yön ters:
 * pairing/heartbeat eklentiden API'ye gider, bunlar API'den eklentiye).
 * Kimlik doğrulama basit bir bearer token — pairing'de üretilip hem API
 * hem eklenti tarafında saklanıyor (bkz. `class-pairing.php`). Ed25519 imza
 * yalnızca eklentiden API'ye giden yönde (pairing/heartbeat) kullanılıyor,
 * çünkü orada "bu isteği gerçekten bu kurulum mu attı" kanıtlanması
 * gerekiyor; burada API zaten pairing'de doğrulanmış bir sır taşıyor.
 */
class Konsol_REST_API
{
    const NAMESPACE = 'konsol/v1';

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

        // `product_cat` bir term — `write_seo`nun post_meta'sı burada işe
        // yaramıyor. Rank Math term'lerde de post'takiyle aynı meta key'leri
        // kullanıyor (yalnızca tablo farklı, `wp_termmeta`), ama Yoast term
        // SEO'yu hiç meta olarak tutmuyor — tek bir `wpseo_taxonomy_meta`
        // option'ında `[taxonomy][term_id] => [wpseo_title, wpseo_desc]`
        // iç içe dizi olarak saklıyor, bkz. `write_category_seo`.
        // WP Konsol blog yazıları için `write_seo`nun karşılığı — aynı meta
        // key'ler (`write_meta_seo`), tek fark `post_type` kontrolü (`product`
        // yerine `post`). WooCommerce kurulu olmayan salt-WP-Konsol
        // sitelerinde de çalışır — bu route WooCommerce'e bağımlı değil.
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/seo', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'write_post_seo'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            'args' => ['id' => ['required' => true, 'validate_callback' => fn($value) => is_numeric($value)]],
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<id>\d+)/seo', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'write_category_seo'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            'args' => ['id' => ['required' => true, 'validate_callback' => fn($value) => is_numeric($value)]],
        ]);

        // 2026-09-06 — "wp-admin'e giriş" butonu (Konsol → Stores). Tek
        // kullanımlık, 60 saniyelik bir token üretir (bkz. `Konsol_Login`);
        // Konsol'un kendi `/auth/exchange-token` desenininin aynısı, yalnızca
        // WordPress tarafında. Hangi WP kullanıcısı olarak giriş yapılacağı
        // pairing'de hiç kaydedilmediği için basitçe siteye ait İLK
        // administrator seçiliyor — tek-adminli (çoğu yerel/test) site için
        // doğru, birden fazla admin'i olan gerçek bir sitede yanlış kişi
        // olabilir (bilinen sınırlama, MVP).
        register_rest_route(self::NAMESPACE, '/login-token', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_login_token'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        // 2026-09-06 — "Check for updates" (Konsol → Sites, WP Konsol tarafı).
        // Salt okunur: hiçbir şey yüklemez/uygulamaz, yalnızca çekirdek/
        // eklenti/tema güncelleme durumunu taze bir kontrolle okur
        // (bkz. `get_updates` yorumu).
        register_rest_route(self::NAMESPACE, '/updates', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_updates'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);
    }

    public static function create_login_token()
    {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
        if (empty($admins)) {
            return new WP_Error('konsol_no_admin_user', 'No administrator user found on this site', ['status' => 404]);
        }

        return ['loginUrl' => Konsol_Login::create_token((int) $admins[0]->ID)];
    }

    /**
     * "Check for updates" — salt okunur, hiçbir şeyi yüklemez/uygulamaz.
     * WordPress'in kendi çekirdek/eklenti/tema güncelleme transient'lerini
     * (`update_core`/`update_plugins`/`update_themes`) normalde günde iki kez
     * cron doldurur; burada bir "şimdi kontrol et" butonu için senkron olarak
     * zorlanıyor (`wp_version_check`/`wp_update_plugins`/`wp_update_themes`).
     * Gerçek arka plan izleme (F9 `worker-uptime`/`worker-update`) hâlâ yok —
     * bu yalnızca istek anındaki durumu okuyor, periyodik değil.
     */
    public static function get_updates(): array
    {
        if (!function_exists('get_plugins') || !function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        require_once ABSPATH . 'wp-admin/includes/update.php';

        wp_version_check();
        wp_update_plugins();
        wp_update_themes();

        $core_updates = get_core_updates(['dismissed' => false]);
        $core_update_available = is_array($core_updates) && isset($core_updates[0]) && $core_updates[0]->response === 'upgrade';

        $plugin_update_data = get_site_transient('update_plugins');
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $new_version = $plugin_update_data->response[$file]->new_version ?? null;
            $plugins[] = [
                'file' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => is_plugin_active($file),
                'updateAvailable' => $new_version !== null,
                'newVersion' => $new_version,
            ];
        }

        $theme_update_data = get_site_transient('update_themes');
        $active_stylesheet = get_stylesheet();
        $themes = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $new_version = $theme_update_data->response[$stylesheet]['new_version'] ?? null;
            $themes[] = [
                // Eklentilerdeki 'file' ile aynı şekil (tek bir okunabilir
                // tanımlayıcı alan) — istemci tarafı ikisini de aynı
                // `SiteUpdateItem` tipiyle işliyor (bkz. `konsol-plugin.ts`).
                'file' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $stylesheet === $active_stylesheet,
                'updateAvailable' => $new_version !== null,
                'newVersion' => $new_version,
            ];
        }

        return [
            'checkedAt' => gmdate('c'),
            'core' => [
                'version' => get_bloginfo('version'),
                'updateAvailable' => $core_update_available,
                'newVersion' => $core_update_available ? ($core_updates[0]->current ?? null) : null,
            ],
            'plugins' => $plugins,
            'themes' => $themes,
        ];
    }

    public static function check_auth(WP_REST_Request $request): bool
    {
        $expected = get_option(Konsol_Pairing::OPT_CONNECTION_TOKEN, '');
        if (!$expected) return false;
        $header = $request->get_header('authorization') ?? '';
        if (!str_starts_with($header, 'Bearer ')) return false;
        return hash_equals($expected, substr($header, 7));
    }

    public static function site_info(): array
    {
        return [
            'siteUuid' => Konsol_Keys::site_uuid(),
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
            'seoFields' => Konsol_Pairing::detect_seo_plugin() !== null,
            'mediaUpload' => true,
            'salesTotals' => class_exists('WooCommerce'),
            'siteOperations' => false,
            'seoPlugin' => Konsol_Pairing::detect_seo_plugin(),
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
            return new WP_Error('konsol_product_not_found', 'Product not found', ['status' => 404]);
        }
        return self::write_meta_seo($product_id, $request);
    }

    /** WP Konsol blog yazıları için `write_seo`nun karşılığı — bkz. route
     * kaydındaki yorum, yalnızca `post_type` kontrolü farklı. */
    public static function write_post_seo(WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        if (!get_post($post_id) || get_post_type($post_id) !== 'post') {
            return new WP_Error('konsol_post_not_found', 'Post not found', ['status' => 404]);
        }
        return self::write_meta_seo($post_id, $request);
    }

    /** `write_seo`/`write_post_seo` ortak gövdesi — WC çekirdek REST'inin
     * yazamadığı tek gerçek şey: Yoast/Rank Math meta title/description.
     * `product_image` yayınlama (F6) bu eklenti OLMADAN da çalışıyor (bkz.
     * packages/generation-core/src/image-storage.ts), o yüzden burada YOK. */
    private static function write_meta_seo(int $post_id, WP_REST_Request $request)
    {
        $seo_title = $request->get_param('seoTitle');
        $meta_description = $request->get_param('metaDescription');
        $plugin = Konsol_Pairing::detect_seo_plugin();

        if (!$plugin) {
            return new WP_Error('konsol_no_seo_plugin', 'Neither Yoast SEO nor Rank Math is active on this site', ['status' => 409]);
        }

        if ($plugin === 'yoast') {
            if ($seo_title !== null) update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($seo_title));
            if ($meta_description !== null) update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description));
        } elseif ($plugin === 'rankmath') {
            if ($seo_title !== null) update_post_meta($post_id, 'rank_math_title', sanitize_text_field($seo_title));
            if ($meta_description !== null) update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_description));
        }

        return ['ok' => true, 'seoPlugin' => $plugin];
    }

    /** `write_seo`nun kategori/term versiyonu — bkz. route yorumu, ikisinin
     * depolama şekli birbirinden tamamen farklı. */
    public static function write_category_seo(WP_REST_Request $request)
    {
        $term_id = (int) $request['id'];
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return new WP_Error('konsol_category_not_found', 'Category not found', ['status' => 404]);
        }

        $seo_title = $request->get_param('seoTitle');
        $meta_description = $request->get_param('metaDescription');
        $plugin = Konsol_Pairing::detect_seo_plugin();

        if (!$plugin) {
            return new WP_Error('konsol_no_seo_plugin', 'Neither Yoast SEO nor Rank Math is active on this site', ['status' => 409]);
        }

        if ($plugin === 'yoast') {
            // Yoast term SEO'yu meta olarak DEĞİL, tek bir `wpseo_taxonomy_meta`
            // option'ında `[taxonomy][term_id] => [...]` iç içe dizi olarak
            // tutuyor (`WPSEO_Taxonomy_Meta`) — post'takinden farklı yol.
            $taxonomy_meta = get_option('wpseo_taxonomy_meta', []);
            if (!is_array($taxonomy_meta)) $taxonomy_meta = [];
            if (!isset($taxonomy_meta['product_cat'][$term_id]) || !is_array($taxonomy_meta['product_cat'][$term_id])) {
                $taxonomy_meta['product_cat'][$term_id] = [];
            }
            if ($seo_title !== null) $taxonomy_meta['product_cat'][$term_id]['wpseo_title'] = sanitize_text_field($seo_title);
            if ($meta_description !== null) $taxonomy_meta['product_cat'][$term_id]['wpseo_desc'] = sanitize_text_field($meta_description);
            update_option('wpseo_taxonomy_meta', $taxonomy_meta);
        } elseif ($plugin === 'rankmath') {
            // Rank Math, post'ta olduğu gibi term'de de gerçek meta kullanıyor
            // (`wp_termmeta`), yalnızca tablo farklı — aynı key isimleri.
            if ($seo_title !== null) update_term_meta($term_id, 'rank_math_title', sanitize_text_field($seo_title));
            if ($meta_description !== null) update_term_meta($term_id, 'rank_math_description', sanitize_text_field($meta_description));
        }

        return ['ok' => true, 'seoPlugin' => $plugin];
    }
}
