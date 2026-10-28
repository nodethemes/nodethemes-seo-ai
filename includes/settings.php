<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** options, encrypted API keys and the settings screen */
final class NRA_Settings {

    const OPT = 'nra_settings';
    const KEYS = 'nra_keys';

    public static function init() {
        add_action( 'admin_post_nra_save_settings', array( __CLASS__, 'handle_save' ) );
        add_action( 'wp_ajax_nra_test_ai', array( __CLASS__, 'ajax_test' ) );
        add_action( 'wp_ajax_nra_list_models', array( __CLASS__, 'ajax_models' ) );
        add_action( 'wp_ajax_nra_suggest_summary', array( __CLASS__, 'ajax_summary' ) );
    }

    public static function defaults() {
        return array(
            'provider' => 'openai',
            'model_openai' => 'gpt-4o-mini', 'model_anthropic' => 'claude-sonnet-5-5', 'model_gemini' => 'gemini-2.5-flash',
            'model_fast_openai' => '', 'model_fast_anthropic' => '', 'model_fast_gemini' => '',
            'ai_lang' => 'auto', 'ai_lang_custom' => '',
            'business' => '', 'voice' => '', 'brand_aliases' => '',
            'daily_cap' => 300, 'daily_budget' => 5, 'model_prices' => '',
            'post_types' => array( 'post', 'page', 'product' ),
            'sep' => '-',
            'tpl_home' => '%sitename% %sep% %tagline%', 'tpl_single' => '%title% %sep% %sitename%',
            'tpl_term' => '%term% %sep% %sitename%', 'tpl_archive' => '%archive% %sep% %sitename%',
            'home_desc' => '',
            'mod_meta' => 'yes', 'mod_schema' => 'yes', 'mod_sitemap' => 'yes', 'mod_llms' => 'yes', 'mod_redirects' => 'yes',
            'mod_indexnow' => 'no', 'mod_botlog' => 'yes', 'mod_autoslug' => 'yes', 'force_output' => 'no',
            'append_faq' => 'no', 'append_takeaways' => 'no',
            'noindex_tags' => 'yes', 'noindex_author' => 'yes', 'noindex_search' => 'yes', 'attachment_redirect' => 'yes',
            'org_type' => 'Organization', 'org_name' => '', 'org_logo' => '', 'org_email' => '', 'org_phone' => '', 'org_social' => '',
            'twitter' => '', 'default_og' => '',
            'bots' => array(),
            'llms_summary' => '', 'llms_limit' => 60, 'robots_extra' => '', 'wc_strip_base' => 'no', 'ver_google' => '', 'ver_bing' => '', 'ver_yandex' => '', 'ver_pinterest' => '', 'ga4_id' => '', 'gtm_id' => '', 'ga_skip_users' => 'yes', 'list_columns' => 'yes',
            'track_competitors' => 'yes',
        );
    }

    private static $cache = null;
    public static function all() {
        if ( null === self::$cache ) {
            $s = get_option( self::OPT, array() );
            self::$cache = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
        }
        return self::$cache;
    }
    public static function get( $k ) {
        $a = self::all();
        return isset( $a[ $k ] ) ? $a[ $k ] : null;
    }
    public static function on( $k ) { return 'yes' === self::get( $k ); }
    public static function save( $arr ) {
        update_option( self::OPT, $arr, false );
        self::$cache = null;
    }

    // ------------------------------------------------------------ API keys (encrypted at rest)
    public static function providers() {
        return array(
            'openai'    => array( 'label' => 'OpenAI (ChatGPT)', 'const' => 'NRA_OPENAI_KEY', 'hint' => 'platform.openai.com > API keys', 'url' => 'https://platform.openai.com/api-keys', 'billing' => 'https://platform.openai.com/settings/organization/billing/overview', 'steps' => 'Sign in, press "Create new secret key", copy it (it is shown once) and add a few dollars of credit under Billing: the API is prepaid and separate from a ChatGPT subscription.' ),
            'anthropic' => array( 'label' => 'Anthropic (Claude)', 'const' => 'NRA_ANTHROPIC_KEY', 'hint' => 'console.anthropic.com > API keys', 'url' => 'https://console.anthropic.com/settings/keys', 'billing' => 'https://console.anthropic.com/settings/billing', 'steps' => 'Sign in, press "Create Key", copy it and add credit under Billing: the API is prepaid and separate from a Claude subscription.' ),
            'gemini'    => array( 'label' => 'Google Gemini', 'const' => 'NRA_GEMINI_KEY', 'hint' => 'aistudio.google.com > Get API key', 'url' => 'https://aistudio.google.com/apikey', 'billing' => 'https://aistudio.google.com/usage', 'steps' => 'Sign in with a Google account and press "Create API key". There is a free tier with daily limits; enable billing in the same page for more.' ),
        );
    }
    private static function crypt( $v, $decrypt = false ) {
        if ( '' === $v ) { return ''; }
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        if ( ! $decrypt ) {
            if ( function_exists( 'openssl_encrypt' ) ) {
                $iv = random_bytes( 16 );
                return 'enc:' . base64_encode( $iv . openssl_encrypt( $v, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv ) );
            }
            return 'plain:' . base64_encode( $v );
        }
        if ( 0 === strpos( $v, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
            $raw = base64_decode( substr( $v, 4 ) );
            if ( ! $raw || strlen( $raw ) < 17 ) { return ''; }
            $out = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
            return false === $out ? '' : $out;
        }
        if ( 0 === strpos( $v, 'plain:' ) ) { return (string) base64_decode( substr( $v, 6 ) ); }
        return '';
    }
    public static function api_key( $provider ) {
        $p = self::providers();
        if ( ! isset( $p[ $provider ] ) ) { return ''; }
        if ( defined( $p[ $provider ]['const'] ) ) { return trim( (string) constant( $p[ $provider ]['const'] ) ); }
        $k = get_option( self::KEYS, array() );
        return isset( $k[ $provider ] ) ? trim( self::crypt( $k[ $provider ], true ) ) : '';
    }
    public static function has_key( $provider ) { return '' !== self::api_key( $provider ); }
    public static function key_source( $provider ) {
        $p = self::providers();
        return ( isset( $p[ $provider ] ) && defined( $p[ $provider ]['const'] ) ) ? 'constant' : 'db';
    }
    private static function set_key( $provider, $key ) {
        $k = get_option( self::KEYS, array() );
        if ( ! is_array( $k ) ) { $k = array(); }
        if ( '' === $key ) { unset( $k[ $provider ] ); } else { $k[ $provider ] = self::crypt( $key ); }
        update_option( self::KEYS, $k, false );
    }
    public static function mask( $key ) {
        $n = strlen( $key );
        return $n > 10 ? substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 ) : str_repeat( '•', max( 4, $n ) );
    }
    public static function model( $provider = '' ) {
        $provider = $provider ? $provider : self::get( 'provider' );
        $m = trim( (string) self::get( 'model_' . $provider ) );
        $d = self::defaults();
        return $m ? $m : ( isset( $d[ 'model_' . $provider ] ) ? $d[ 'model_' . $provider ] : '' );
    }
    /** model for quick jobs (keywords, titles, descriptions, FAQ, links); falls back to the main model */
    public static function model_fast( $provider = '' ) {
        $provider = $provider ? $provider : self::get( 'provider' );
        $m = trim( (string) self::get( 'model_fast_' . $provider ) );
        return $m ? $m : self::model( $provider );
    }
    public static function suggested_models( $provider ) {
        $m = array(
            'openai' => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-5-mini', 'gpt-5' ),
            'anthropic' => array( 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5' ),
            'gemini' => array( 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' ),
        );
        return isset( $m[ $provider ] ) ? $m[ $provider ] : array();
    }
    public static function known_models( $provider ) {
        $m = get_option( 'nra_models_' . $provider, array() );
        return is_array( $m ) ? $m : array();
    }

    // ------------------------------------------------------------ save
    private static function yes( $v ) { return ( 'yes' === $v ) ? 'yes' : 'no'; }
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nra_save_settings' );
        $P   = wp_unslash( $_POST );
        $s   = self::all();
        $txt = array( 'ai_lang_custom', 'sep', 'tpl_home', 'tpl_single', 'tpl_term', 'tpl_archive', 'org_name', 'org_email', 'org_phone', 'twitter', 'model_openai', 'model_anthropic', 'model_gemini', 'model_fast_openai', 'model_fast_anthropic', 'model_fast_gemini' );
        foreach ( $txt as $k ) { if ( isset( $P[ $k ] ) ) { $s[ $k ] = sanitize_text_field( $P[ $k ] ); } }
        foreach ( array( 'business', 'voice', 'brand_aliases', 'home_desc', 'llms_summary', 'org_social' ) as $k ) { if ( isset( $P[ $k ] ) ) { $s[ $k ] = sanitize_textarea_field( $P[ $k ] ); } }
        foreach ( array( 'org_logo', 'default_og' ) as $k ) { if ( isset( $P[ $k ] ) ) { $s[ $k ] = esc_url_raw( $P[ $k ] ); } }
        foreach ( array( 'mod_meta', 'mod_schema', 'mod_sitemap', 'mod_llms', 'mod_redirects', 'mod_indexnow', 'mod_botlog', 'mod_autoslug', 'wc_strip_base', 'ga_skip_users', 'list_columns', 'force_output', 'append_faq', 'append_takeaways', 'noindex_tags', 'noindex_author', 'noindex_search', 'attachment_redirect', 'track_competitors' ) as $k ) {
            if ( isset( $P[ $k ] ) ) { $s[ $k ] = self::yes( $P[ $k ] ); }
        }
        if ( isset( $P['provider'] ) && isset( self::providers()[ $P['provider'] ] ) ) { $s['provider'] = $P['provider']; }
        if ( isset( $P['ai_lang'] ) ) { $s['ai_lang'] = in_array( $P['ai_lang'], array( 'auto', 'custom' ), true ) ? $P['ai_lang'] : 'auto'; }
        if ( isset( $P['org_type'] ) ) { $s['org_type'] = ( 'Person' === $P['org_type'] ) ? 'Person' : 'Organization'; }
        if ( isset( $P['daily_cap'] ) ) { $s['daily_cap'] = max( 0, min( 5000, (int) $P['daily_cap'] ) ); }
        if ( isset( $P['daily_budget'] ) ) { $s['daily_budget'] = max( 0, min( 1000, round( (float) str_replace( ',', '.', (string) $P['daily_budget'] ), 2 ) ) ); }
        if ( isset( $P['model_prices'] ) ) { $s['model_prices'] = sanitize_textarea_field( $P['model_prices'] ); }
        foreach ( array( 'ver_google', 'ver_bing', 'ver_yandex', 'ver_pinterest' ) as $k ) { if ( isset( $P[ $k ] ) ) { $v = trim( sanitize_text_field( $P[ $k ] ) ); if ( preg_match( '/content=["\']([^"\']+)["\']/', $v, $mm ) ) { $v = $mm[1]; } $s[ $k ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $v ); } }
        if ( isset( $P['ga4_id'] ) ) { $v = strtoupper( trim( sanitize_text_field( $P['ga4_id'] ) ) ); $s['ga4_id'] = preg_match( '/^(G|AW)-[A-Z0-9]{4,14}$/', $v ) ? $v : ''; }
        if ( isset( $P['gtm_id'] ) ) { $v = strtoupper( trim( sanitize_text_field( $P['gtm_id'] ) ) ); $s['gtm_id'] = preg_match( '/^GTM-[A-Z0-9]{4,10}$/', $v ) ? $v : ''; }
        if ( isset( $P['robots_extra'] ) ) { $s['robots_extra'] = NRA_Extras::clean_robots( wp_unslash( $P['robots_extra'] ) ); }
        if ( isset( $P['llms_limit'] ) ) { $s['llms_limit'] = max( 10, min( 300, (int) $P['llms_limit'] ) ); }
        $s['post_types'] = array();
        if ( ! empty( $P['post_types'] ) && is_array( $P['post_types'] ) ) {
            foreach ( $P['post_types'] as $t ) { if ( post_type_exists( $t ) ) { $s['post_types'][] = sanitize_key( $t ); } }
        }
        if ( isset( $P['bots'] ) && is_array( $P['bots'] ) ) {
            $s['bots'] = array();
            foreach ( NRA_Geo::bots() as $id => $b ) {
                $v = isset( $P['bots'][ $id ] ) ? $P['bots'][ $id ] : 'default';
                $s['bots'][ $id ] = in_array( $v, array( 'default', 'allow', 'block' ), true ) ? $v : 'default';
            }
        }
        self::save( $s );
        foreach ( self::providers() as $id => $p ) {
            if ( ! empty( $P[ 'clear_key_' . $id ] ) ) { self::set_key( $id, '' ); continue; }
            if ( ! empty( $P[ 'key_' . $id ] ) ) {
                $k = preg_replace( '/\s+/', '', (string) $P[ 'key_' . $id ] );
                if ( strlen( $k ) > 12 && false === strpos( $k, '•' ) ) { self::set_key( $id, $k ); }
            }
        }
        NRA_Sitemap::bump();
        NRA_Geo::bump();
        flush_rewrite_rules( false );
        $tab = isset( $P['tab'] ) ? sanitize_key( $P['tab'] ) : 'ai';
        wp_safe_redirect( admin_url( 'admin.php?page=nra-settings&saved=1#' . $tab ) );
        exit;
    }

    // ------------------------------------------------------------ AJAX: test + models
    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) { NRA_Util::fail( 'Not allowed.' ); }
        NRA_Util::nonce();
    }
    public static function ajax_test() {
        self::guard();
        $prov = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : self::get( 'provider' );
        if ( ! isset( self::providers()[ $prov ] ) ) { NRA_Util::fail( 'Unknown provider.' ); }
        $key = isset( $_POST['key'] ) ? preg_replace( '/\s+/', '', wp_unslash( $_POST['key'] ) ) : '';
        if ( false !== strpos( $key, '•' ) ) { $key = ''; }
        $model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : self::model( $prov );
        $r = NRA_AI::request( 'You are a connection test.', 'Reply with exactly: OK', array( 'provider' => $prov, 'model' => $model, 'key' => $key, 'max_tokens' => 20, 'count' => false, 'timeout' => 40 ) );
        if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); }
        NRA_Util::ok( array( 'message' => 'Connected. The model answered: ' . esc_html( NRA_Util::cut( trim( $r['text'] ), 60 ) ), 'model' => $model ) );
    }
    public static function ajax_models() {
        self::guard();
        $prov = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : self::get( 'provider' );
        $key  = isset( $_POST['key'] ) ? preg_replace( '/\s+/', '', wp_unslash( $_POST['key'] ) ) : '';
        if ( false !== strpos( $key, '•' ) ) { $key = ''; }
        $r = NRA_AI::list_models( $prov, $key );
        if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); }
        update_option( 'nra_models_' . $prov, $r['models'], false );
        NRA_Util::ok( array( 'models' => $r['models'] ) );
    }
    public static function ajax_summary() {
        self::guard();
        $posts = get_posts( array( 'post_type' => array( 'product', 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 25, 'orderby' => 'modified' ) );
        $list  = array();
        foreach ( $posts as $p ) { $list[] = '- ' . $p->post_title; }
        $sys = NRA_AI::context() . "\nWrite a factual 2-4 sentence description of this website for AI assistants (what it is, who it serves, what it sells, what makes it trustworthy). No hype, no invented facts. Output plain text only.";
        $r = NRA_AI::request( $sys, "Site name: " . get_bloginfo( 'name' ) . "\nTagline: " . get_bloginfo( 'description' ) . "\nURL: " . home_url( '/' ) . "\nSome pages:\n" . implode( "\n", $list ), array( 'max_tokens' => 400 ) );
        if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); }
        NRA_Util::ok( array( 'text' => trim( $r['text'] ) ) );
    }

    // ------------------------------------------------------------ screen
    private static function yn( $name, $val, $labels = array( 'Yes', 'No' ) ) {
        return '<select name="' . esc_attr( $name ) . '"><option value="yes"' . selected( $val, 'yes', false ) . '>' . esc_html( $labels[0] ) . '</option><option value="no"' . selected( $val, 'no', false ) . '>' . esc_html( $labels[1] ) . '</option></select>';
    }
    private static function row( $label, $field, $help = '' ) {
        echo '<div class="nra-row"><div class="nra-row-l"><label>' . wp_kses( $label, array( 'small' => array(), 'b' => array(), 'strong' => array(), 'em' => array(), 'span' => array( 'class' => array(), 'title' => array() ), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'class' => array() ) ) ) . '</label></div><div class="nra-row-f">' . $field . ( $help ? '<p class="nra-help">' . wp_kses_post( $help ) . '</p>' : '' ) . '</div></div>'; // phpcs:ignore
    }
    private static function txt( $name, $val, $ph = '', $cls = '' ) {
        return '<input type="text" class="nra-in ' . esc_attr( $cls ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $ph ) . '">';
    }
    private static function area( $name, $val, $rows = 4, $ph = '' ) {
        return '<textarea class="nra-in" rows="' . (int) $rows . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $ph ) . '">' . esc_textarea( $val ) . '</textarea>';
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
        $s = self::all();
        $tabs = array( 'ai' => 'AI Provider', 'site' => 'Titles & Meta', 'schema' => 'Schema & Brand', 'modules' => 'Modules', 'search' => 'Search & Analytics', 'geo' => 'AI Crawlers & llms.txt', 'tools' => 'Tools', 'license' => 'License' );
        ?>
        <div class="wrap nra-wrap"><h1 class="screen-reader-text">NodeThemes SEO AI Settings</h1>
        <div class="nra-hero"><div><h1>Settings</h1><p>Connect your own AI key, set your brand context and decide what the plugin outputs.</p></div></div>
        <?php if ( isset( $_GET['saved'] ) ) : ?><div class="nra-flash ok">Settings saved.</div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="nra-settings-form" autocomplete="off">
            <input type="hidden" name="action" value="nra_save_settings"><input type="hidden" name="tab" id="nra-tab-field" value="ai">
            <?php wp_nonce_field( 'nra_save_settings' ); ?>
            <div class="nra-tabs" role="tablist"><?php foreach ( $tabs as $k => $l ) { echo '<button type="button" class="nra-tab" data-tab="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</button>'; } ?></div>

            <div class="nra-panel" data-panel="ai">
                <div class="nra-card"><h2>Which AI should do the work?</h2>
                <p class="nra-help">Bring your own API key. Keys are stored encrypted, never shown again in full and never sent to the browser. Only the text of the page you are working on is sent to the provider you choose. Costs are billed by the provider (a typical page costs a fraction of a cent to a few cents).</p>
                <?php
                self::row( 'Default provider', '<select name="provider" id="nra-provider">' . implode( '', array_map( function( $id, $p ) use ( $s ) { return '<option value="' . esc_attr( $id ) . '"' . selected( $s['provider'], $id, false ) . '>' . esc_html( $p['label'] ) . '</option>'; }, array_keys( self::providers() ), self::providers() ) ) . '</select>', 'You can store keys for all three and switch any time.' );
                foreach ( self::providers() as $id => $p ) :
                    $has = self::has_key( $id );
                    $dl  = 'nra-models-' . $id;
                    ?>
                    <div class="nra-prov" data-prov="<?php echo esc_attr( $id ); ?>">
                        <h3><?php echo esc_html( $p['label'] ); ?> <?php echo $has ? '<span class="nra-badge ok">key saved</span>' : '<span class="nra-badge">no key</span>'; ?></h3>
                        <?php
                        if ( 'constant' === self::key_source( $id ) ) {
                            self::row( 'API key', '<code>' . esc_html( $p['const'] ) . '</code> is defined in wp-config.php (used instead of the database).' );
                        } else {
                            self::row( 'API key', '<input type="password" class="nra-in nra-key" name="key_' . esc_attr( $id ) . '" value="" placeholder="' . esc_attr( $has ? self::mask( self::api_key( $id ) ) . '  (leave empty to keep)' : 'Paste your key' ) . '" autocomplete="new-password">' . ( $has ? '<label class="nra-inline"><input type="checkbox" name="clear_key_' . esc_attr( $id ) . '" value="1"> remove saved key</label>' : '' ), '<a class="nra-btn sm primary" href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">Get your ' . esc_html( $p['label'] ) . ' API key &rarr;</a> <a class="nra-btn sm" href="' . esc_url( $p['billing'] ) . '" target="_blank" rel="noopener">Billing / credit</a><br>' . esc_html( $p['steps'] ) );
                        }
                        self::row( 'Writing model <small>(articles, long text, Fix-to-100)</small>', '<input type="text" class="nra-in nra-model" list="' . esc_attr( $dl ) . '" name="model_' . esc_attr( $id ) . '" value="' . esc_attr( self::model( $id ) ) . '"><datalist id="' . esc_attr( $dl ) . '">' . implode( '', array_map( function( $m ) { return '<option value="' . esc_attr( $m ) . '">'; }, self::known_models( $id ) ) ) . '</datalist> <button type="button" class="nra-btn sm nra-fetch-models">Load available models</button> <button type="button" class="nra-btn sm primary nra-test-ai">Test connection</button> <span class="nra-test-out"></span>', 'Click a model below or type one. Model names change often: "Load available models" lists what your key can use. A fast/cheap model is fine for meta data; use a stronger one for long articles.' );
                        self::row( 'Fast model <small>(keywords, titles, descriptions, FAQ, links)</small>', '<input type="text" class="nra-in nra-model-fast" list="' . esc_attr( $dl ) . '" name="model_fast_' . esc_attr( $id ) . '" value="' . esc_attr( self::get( 'model_fast_' . $id ) ) . '" placeholder="' . esc_attr( self::model( $id ) ) . '">', 'Quick jobs run on this model so they finish in seconds. Leave empty to use the writing model. Tip: a mini / flash / haiku model is plenty here. Click a model below to fill the box you used last.' );
                        $chips = array_values( array_unique( array_merge( self::known_models( $id ), self::suggested_models( $id ) ) ) );
                        echo '<div class="nra-chips" data-for="' . esc_attr( $id ) . '"><span class="nra-hint">Models:</span> ' . implode( '', array_map( function( $m ) use ( $id ) { return '<button type="button" class="nra-chip-m' . ( self::model( $id ) === $m ? ' on' : '' ) . '" data-m="' . esc_attr( $m ) . '">' . esc_html( $m ) . '</button>'; }, array_slice( $chips, 0, 40 ) ) ) . '</div>';
                        ?>
                    </div>
                <?php endforeach; ?>
                </div>

                <div class="nra-card"><h2>Context that makes the AI output better</h2>
                <?php
                self::row( 'About your business', self::area( 'business', $s['business'], 5, 'e.g. NodeThemes sells premium WordPress themes and plugins to agencies and store owners. Instant download, lifetime updates, 30-day refund.' ), 'Sent with every request. Real facts only (what you sell, for whom, guarantees). The AI is told not to invent anything beyond this and the page text.' );
                self::row( 'Brand voice', self::area( 'voice', $s['voice'], 3, 'e.g. Clear, confident, practical. No hype. Second person ("you").' ) );
                self::row( 'Brand names / aliases', self::area( 'brand_aliases', $s['brand_aliases'], 2, 'NodeThemes, Node Themes' ), 'One per line. Used by the AI-visibility tracker to detect whether AI assistants mention you.' );
                self::row( 'Output language', '<select name="ai_lang" id="nra-ailang"><option value="auto"' . selected( $s['ai_lang'], 'auto', false ) . '>Same language as the page (recommended)</option><option value="custom"' . selected( $s['ai_lang'], 'custom', false ) . '>Always use:</option></select> ' . self::txt( 'ai_lang_custom', $s['ai_lang_custom'], 'English / Persian / German ...', 'nra-short' ) );
                self::row( 'Daily AI request limit', '<input type="number" class="nra-in nra-short" name="daily_cap" min="0" max="5000" value="' . (int) $s['daily_cap'] . '">', 'Safety cap on spending: after this many requests per day, AI buttons stop until tomorrow. 0 = no limit.' );
                $us = NRA_AI::usage_today(); $bud = (float) $s['daily_budget']; $spent = (float) $us['cost'];
                $chips = ''; foreach ( array( 1, 3, 5, 10, 20 ) as $v ) { $chips .= '<button type="button" class="nra-chip-m" onclick="document.getElementById(\'nra-budget\').value=\'' . $v . '\'">$' . $v . '</button> '; }
                $pct = $bud > 0 ? min( 100, (int) round( $spent / $bud * 100 ) ) : 0;
                $meter = '<div style="margin-top:8px;max-width:360px"><div style="height:8px;border-radius:99px;background:#e5e7eb;overflow:hidden"><div style="height:8px;width:' . $pct . '%;background:' . ( $pct >= 90 ? '#dc2626' : ( $pct >= 60 ? '#d97706' : '#059669' ) ) . '"></div></div><small>Estimated spend today: <b>$' . number_format( $spent, 2 ) . '</b>' . ( $bud > 0 ? ' of $' . number_format( $bud, 2 ) : '' ) . ' &middot; ' . (int) $us['calls'] . ' requests</small></div>';
                self::row( 'Daily budget (USD)', '<input type="number" id="nra-budget" class="nra-in nra-short" name="daily_budget" min="0" max="1000" step="0.5" value="' . esc_attr( $bud ) . '"> ' . $chips . $meter, 'The most you are willing to spend on AI per day. The plugin estimates the cost of every request from the tokens used and stops all AI buttons once the budget is reached. They work again tomorrow, or as soon as you raise it. 0 = no limit. This is an <b>estimate</b> to protect your credit, not your provider&rsquo;s invoice: check the real amount in your provider&rsquo;s billing page. A single request that is already running can finish after the limit is reached.' );
                self::row( 'Custom model prices (optional)', self::area( 'model_prices', $s['model_prices'], 3, "my-model-name: 0.25, 2.00" ), 'Used for the budget estimate when your model is not in the plugin&rsquo;s built-in price list, or when prices change. One model per line: <code>model: input price, output price</code>, in US dollars per 1 million tokens. Without a price, an unknown model is estimated at $3 input / $15 output per million tokens.' );
                ?>
                </div>
            </div>

            <div class="nra-panel" data-panel="site" hidden>
                <div class="nra-card"><h2>Content types</h2>
                <?php
                $opts = '';
                foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
                    if ( 'attachment' === $pt->name ) { continue; }
                    $opts .= '<label class="nra-inline"><input type="checkbox" name="post_types[]" value="' . esc_attr( $pt->name ) . '"' . checked( in_array( $pt->name, (array) $s['post_types'], true ), true, false ) . '> ' . esc_html( $pt->labels->name ) . '</label> ';
                }
                self::row( 'Optimise these types', $opts, 'The SEO box, score, sitemap and bulk tools work on the selected types.' );
                ?></div>
                <div class="nra-card"><h2>Title templates</h2>
                <p class="nra-help">Variables: <code>%title%</code> <code>%sitename%</code> <code>%tagline%</code> <code>%sep%</code> <code>%term%</code> <code>%archive%</code> <code>%category%</code> <code>%focuskw%</code> <code>%author%</code> <code>%year%</code></p>
                <?php
                self::row( 'Separator', self::txt( 'sep', $s['sep'], '-', 'nra-short' ) );
                self::row( 'Home page', self::txt( 'tpl_home', $s['tpl_home'] ) );
                self::row( 'Posts, pages, products', self::txt( 'tpl_single', $s['tpl_single'] ), 'Used when a page has no custom SEO title.' );
                self::row( 'Categories / tags / product categories', self::txt( 'tpl_term', $s['tpl_term'] ) );
                self::row( 'Other archives (e.g. Shop)', self::txt( 'tpl_archive', $s['tpl_archive'] ) );
                self::row( 'Home page meta description', self::area( 'home_desc', $s['home_desc'], 3 ), 'About 120-160 characters. Without this, your home page has no description at all.' );
                self::row( 'Default social image', self::txt( 'default_og', $s['default_og'], 'https://...' ), 'Used when a page has no featured image. 1200x630 works best.' );
                self::row( 'X / Twitter handle', self::txt( 'twitter', $s['twitter'], '@yourbrand', 'nra-short' ) );
                ?></div>
                <div class="nra-card"><h2>Indexing rules</h2>
                <?php
                self::row( 'Noindex tag archives', self::yn( 'noindex_tags', $s['noindex_tags'] ), 'Tag pages are usually thin duplicate content.' );
                self::row( 'Noindex author archives', self::yn( 'noindex_author', $s['noindex_author'] ), 'Recommended for single-author sites.' );
                self::row( 'Noindex internal search results', self::yn( 'noindex_search', $s['noindex_search'] ) );
                self::row( 'Redirect attachment pages to their parent', self::yn( 'attachment_redirect', $s['attachment_redirect'] ), 'Image attachment pages are empty pages that waste crawl budget.' );
                ?></div>
            </div>

            <div class="nra-panel" data-panel="schema" hidden>
                <div class="nra-card"><h2>Who is behind this site?</h2>
                <?php
                self::row( 'Type', '<select name="org_type"><option value="Organization"' . selected( $s['org_type'], 'Organization', false ) . '>Organization / company</option><option value="Person"' . selected( $s['org_type'], 'Person', false ) . '>Person</option></select>' );
                self::row( 'Name', self::txt( 'org_name', $s['org_name'], get_bloginfo( 'name' ) ) );
                self::row( 'Logo URL', self::txt( 'org_logo', $s['org_logo'], 'https://.../logo.png' ), 'Square or wide logo, at least 112x112 px.' );
                self::row( 'Contact email', self::txt( 'org_email', $s['org_email'] ) );
                self::row( 'Phone', self::txt( 'org_phone', $s['org_phone'] ) );
                self::row( 'Profiles (sameAs)', self::area( 'org_social', $s['org_social'], 5, "https://x.com/yourbrand\nhttps://www.linkedin.com/company/yourbrand\nhttps://www.youtube.com/@yourbrand\nhttps://github.com/yourbrand" ), 'One URL per line. These links help search engines and AI models connect your site to the same brand (entity) across the web.' );
                ?></div>
            </div>

            <div class="nra-panel" data-panel="modules" hidden>
                <div class="nra-card"><h2>Modules</h2>
                <?php
                self::row( 'Meta tags, canonical, Open Graph', self::yn( 'mod_meta', $s['mod_meta'] ) );
                self::row( 'Schema (JSON-LD)', self::yn( 'mod_schema', $s['mod_schema'] ), 'Organization, WebSite, WebPage, Breadcrumbs, Article, Product (WooCommerce), FAQ. Replaces WooCommerce\'s own product markup to avoid duplicates.' );
                self::row( 'XML sitemap', self::yn( 'mod_sitemap', $s['mod_sitemap'] ), 'Served at <code>/sitemap.xml</code>, with images and last-modified dates.' );
                self::row( 'llms.txt', self::yn( 'mod_llms', $s['mod_llms'] ), 'Served at <code>/llms.txt</code> and <code>/llms-full.txt</code>.' );
                self::row( 'Redirects + 404 monitor', self::yn( 'mod_redirects', $s['mod_redirects'] ) );
                self::row( 'Auto-redirect when a slug changes', self::yn( 'mod_autoslug', $s['mod_autoslug'] ), 'Creates a 301 from the old URL so you never lose rankings by renaming a page.' );
                self::row( 'IndexNow (instant indexing) ' . NRA_Pro::badge(), NRA_Pro::active() ? self::yn( 'mod_indexnow', $s['mod_indexnow'] ) : NRA_Pro::lock( 'Instant indexing', 'indexnow' ), 'Pings Bing, Yandex, Seznam, Naver when you publish or update. <strong>Google does not use IndexNow.</strong>' );
                self::row( 'Log AI-crawler visits', self::yn( 'mod_botlog', $s['mod_botlog'] ), 'Counts visits from GPTBot, ClaudeBot, PerplexityBot and others. Pages served from a full-page cache are not counted.' );
                self::row( 'Show key takeaways on the page', self::yn( 'append_takeaways', $s['append_takeaways'] ), 'Adds the AI-written "Key takeaways" box (if filled) at the top of the content.' );
                self::row( 'Show FAQ on the page', self::yn( 'append_faq', $s['append_faq'] ), 'Adds the FAQ (if filled) at the end of the content. FAQ schema must match visible text, so this is recommended when you use FAQ schema.' );
                self::row( 'Output even if another SEO plugin is active', self::yn( 'force_output', $s['force_output'] ), 'Leave on "No": two SEO plugins print duplicate tags. Deactivate Yoast / Rank Math after importing.' );
                ?></div>
            </div>

            <div class="nra-panel" data-panel="search" hidden>
                <div class="nra-card"><h2>Tell search engines about your site</h2>
                <p class="nra-help">Step 1: open each tool, add your site, and choose the &ldquo;HTML tag&rdquo; method. Step 2: paste the code it shows below (the whole tag or just the code). Step 3: press Verify there. Your sitemap is <code><?php echo esc_html( home_url( '/sitemap.xml' ) ); ?></code> (submit it in each tool).</p>
                <?php
                self::row( 'Google Search Console', self::txt( 'ver_google', $s['ver_google'], 'verification code' ), '<a href="https://search.google.com/search-console/welcome" target="_blank" rel="noopener">Open Search Console</a>' );
                self::row( 'Bing Webmaster Tools', self::txt( 'ver_bing', $s['ver_bing'], 'verification code' ), '<a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Open Bing Webmaster</a> (you can import the site from Search Console there)' );
                self::row( 'Yandex', self::txt( 'ver_yandex', $s['ver_yandex'], 'verification code' ), '<a href="https://webmaster.yandex.com" target="_blank" rel="noopener">Open Yandex Webmaster</a>' );
                self::row( 'Pinterest', self::txt( 'ver_pinterest', $s['ver_pinterest'], 'verification code' ) );
                ?>
                </div>
                <div class="nra-card"><h2>Google Analytics</h2>
                <?php
                self::row( 'GA4 measurement ID', self::txt( 'ga4_id', $s['ga4_id'], 'G-XXXXXXXXXX' ), 'Adds the tracking code to every page. Create a property at <a href="https://analytics.google.com" target="_blank" rel="noopener">analytics.google.com</a>. Skip this if another plugin or Site Kit already adds it.' );
                self::row( 'Google Tag Manager ID (optional)', self::txt( 'gtm_id', $s['gtm_id'], 'GTM-XXXXXXX' ), 'Use this instead of the GA4 ID if you manage tags (including Google Ads) in Tag Manager.' );
                self::row( 'Do not track logged-in users', self::yn( 'ga_skip_users', $s['ga_skip_users'] ), 'Your own visits will not pollute the numbers. If you need cookie consent, connect a consent plugin: tracking is skipped when the filter <code>nra_tracking_allowed</code> returns false.' );
                ?>
                </div>
                <div class="nra-card"><h2>Post list columns</h2>
                <?php self::row( 'Show link counts', self::yn( 'list_columns', $s['list_columns'] ), 'Adds an &ldquo;Internal links&rdquo; column (links out / links in) and the SEO score to the posts, pages and products list.' ); ?>
                </div>
                <?php do_action( 'nra_settings_search_panel' ); if ( ! NRA_Pro::active() ) { NRA_Pro::upsell_box( 'Connect Google (one click)', 'See Search Console and Analytics inside WordPress, get a daily AI report, automatic sitemap submission, ad-campaign monitoring and AI-ranked fixes for pages that are close to page one.' ); } ?>
            </div>

            <div class="nra-panel" data-panel="geo" hidden>
                <div class="nra-card"><h2>AI crawlers (robots.txt)</h2>
                <p class="nra-help">Decide which AI companies may read your site. <strong>Search/answer</strong> bots decide whether you can be cited and linked in AI answers. <strong>Training</strong> bots only collect data to train future models. Blocking <code>Google-Extended</code> does not affect Google Search ranking or AI Overviews.</p>
                <div class="nra-presets"><button type="button" class="nra-btn sm" data-preset="visible">Be visible in AI answers, no training</button> <button type="button" class="nra-btn sm" data-preset="all">Allow everything</button> <button type="button" class="nra-btn sm" data-preset="none">Block all AI bots</button> <button type="button" class="nra-btn sm" data-preset="default">Reset (don't add rules)</button></div>
                <table class="nra-table"><thead><tr><th>Bot</th><th>Company</th><th>What it does</th><th>Rule</th></tr></thead><tbody>
                <?php foreach ( NRA_Geo::bots() as $id => $b ) :
                    $cur = isset( $s['bots'][ $id ] ) ? $s['bots'][ $id ] : 'default'; ?>
                    <tr><td><code><?php echo esc_html( $id ); ?></code></td><td><?php echo esc_html( $b['co'] ); ?></td><td><span class="nra-chip <?php echo esc_attr( $b['type'] ); ?>"><?php echo esc_html( NRA_Geo::type_label( $b['type'] ) ); ?></span> <?php echo esc_html( $b['desc'] ); ?></td>
                    <td><select name="bots[<?php echo esc_attr( $id ); ?>]" class="nra-bot" data-type="<?php echo esc_attr( $b['type'] ); ?>"><option value="default"<?php selected( $cur, 'default' ); ?>>No rule</option><option value="allow"<?php selected( $cur, 'allow' ); ?>>Allow</option><option value="block"<?php selected( $cur, 'block' ); ?>>Block</option></select></td></tr>
                <?php endforeach; ?></tbody></table>
                <?php if ( file_exists( ABSPATH . 'robots.txt' ) ) : ?><div class="nra-flash warn">A physical <code>robots.txt</code> file exists in your site root, so WordPress cannot add these rules. Delete or edit that file to use this feature.</div><?php endif; ?>
                </div>
                <div class="nra-card"><h2>llms.txt</h2>
                <p class="nra-help">An emerging, <strong>experimental</strong> convention: a short Markdown file that tells AI tools what your site is and which pages matter. Major AI companies have not committed to using it, so treat it as a low-cost extra, not a ranking factor. Mark your key pages as "Cornerstone" in the SEO box to list them first.</p>
                <?php
                self::row( 'Site summary', self::area( 'llms_summary', $s['llms_summary'], 4 ) . '<p><button type="button" class="nra-btn sm" id="nra-suggest-summary">Write it with AI</button> <span id="nra-summary-out"></span></p>', 'Two to four factual sentences. Shown at the top of llms.txt.' );
                self::row( 'Max pages listed', '<input type="number" class="nra-in nra-short" name="llms_limit" min="10" max="300" value="' . (int) $s['llms_limit'] . '">' );
                echo '<p>Preview: <a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">' . esc_html( home_url( '/llms.txt' ) ) . '</a></p>';
                ?></div>
            </div>

            <div class="nra-panel" data-panel="tools" hidden>
                <?php NRA_Import::render_card(); ?>
                <div class="nra-card"><h2>robots.txt and clean URLs</h2>
                <?php
                self::row( 'Extra robots.txt rules', self::area( 'robots_extra', $s['robots_extra'], 6, "User-agent: *\nDisallow: /cart/\nDisallow: /checkout/" ) . '<p class="nra-help">Added to the virtual robots.txt. Only User-agent, Allow, Disallow, Sitemap and Crawl-delay lines are kept, and a rule that blocks the whole site is dropped. Does not apply if a physical robots.txt file exists. <a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">View robots.txt</a></p>' );
                self::row( 'WooCommerce: remove /product/ from URLs', self::yn( 'wc_strip_base', $s['wc_strip_base'] ), 'Product and category links become <code>/my-product/</code> instead of <code>/product/my-product/</code>. Old links redirect with a 301. Needs a WooCommerce store; check a product page after enabling. Use <code>[nra_breadcrumbs]</code> or <code>nra_breadcrumbs()</code> in your theme for visible breadcrumbs.' );
                ?>
                </div>
                <div class="nra-card"><h2>AI tracker options</h2>
                <?php self::row( 'Find competitor names in AI answers ' . NRA_Pro::badge(), NRA_Pro::active() ? self::yn( 'track_competitors', $s['track_competitors'] ) : NRA_Pro::lock( 'Competitor tracking', 'competitors' ), 'Uses one extra AI request per check to list the brands the AI recommended.' ); ?></div>
            </div>

            <p class="nra-savebar"><button type="submit" class="nra-btn primary lg">Save settings</button></p>
        </form>
        <div class="nra-panel" data-panel="license" hidden>
                <?php NRA_Pro::license_card(); ?>
            </div>

            <div class="nt-support" style="margin:16px 0;padding:14px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;max-width:960px;line-height:1.9">
				<strong><?php esc_html_e( 'Need help?', 'nodethemes-seo-ai' ); ?></strong>
				<?php esc_html_e( 'Email', 'nodethemes-seo-ai' ); ?> <a href="mailto:nodethemes.com@gmail.com">nodethemes.com@gmail.com</a>
				&middot; WhatsApp <a href="https://wa.me/447441429969" target="_blank" rel="noopener">+44 7441 429969</a>
				&middot; Telegram <a href="https://t.me/nodethemes" target="_blank" rel="noopener">@nodethemes</a>
			</div>

        </div>
        <?php
    }
}
