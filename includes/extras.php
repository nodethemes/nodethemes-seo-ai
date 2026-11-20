<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Classic SEO-plugin extras: WooCommerce clean URLs, visible breadcrumbs, robots.txt additions */
final class NTSEOAI_Extras {

    public static function init() {
        add_shortcode( 'ntseoai_breadcrumbs', array( __CLASS__, 'breadcrumbs_html' ) );
        add_filter( 'robots_txt', array( __CLASS__, 'robots_extra' ), 20, 2 );
        add_action( 'wp_head', array( __CLASS__, 'head_tags' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'tracking_scripts' ), 1 );
        add_action( 'wp_body_open', array( __CLASS__, 'gtm_body' ), 1 );
        add_filter( 'the_content', array( __CLASS__, 'toc' ), 9 );
        if ( is_admin() && NTSEOAI_Settings::on( 'list_columns' ) ) {
            add_action( 'admin_enqueue_scripts', function ( $hook ) {
                if ( 'edit.php' !== $hook ) { return; }
                wp_register_style( 'ntseoai-list-columns', false, array(), NTSEOAI_VERSION );
                wp_enqueue_style( 'ntseoai-list-columns' );
                wp_add_inline_style( 'ntseoai-list-columns', '.wp-list-table th.column-ntseoai_links,.wp-list-table td.column-ntseoai_links{width:72px;white-space:nowrap}.wp-list-table th.column-ntseoai,.wp-list-table td.column-ntseoai{width:120px;white-space:nowrap}' );
            } );
            foreach ( array( 'post', 'page', 'product' ) as $pt ) {
                add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'col_add' ) );
                add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'col_show' ), 10, 2 );
            }
        }
        if ( NTSEOAI_Settings::on( 'wc_strip_base' ) ) {
            add_filter( 'post_type_link', array( __CLASS__, 'product_link' ), 99, 2 );
            add_filter( 'term_link', array( __CLASS__, 'term_link' ), 99, 3 );
            add_filter( 'request', array( __CLASS__, 'resolve' ), 1 );
            add_action( 'template_redirect', array( __CLASS__, 'old_to_new' ), 1 );
        }
    }

    // ---------------------------------------------------------------- breadcrumbs ([ntseoai_breadcrumbs] or ntseoai_breadcrumbs())
    public static function breadcrumbs_html() {
        if ( is_front_page() ) { return ''; }
        $c = NTSEOAI_Schema::breadcrumbs();
        if ( count( $c ) < 2 ) { return ''; }
        $sep = wp_kses_post( apply_filters( 'ntseoai_breadcrumb_separator', ' &rsaquo; ' ) );
        $out = array(); $n = count( $c );
        foreach ( $c as $i => $b ) { $out[] = ( $i < $n - 1 ) ? '<a href="' . esc_url( $b[1] ) . '">' . esc_html( $b[0] ) . '</a>' : '<span aria-current="page">' . esc_html( $b[0] ) . '</span>'; }
        return '<nav class="ntseoai-breadcrumbs" aria-label="Breadcrumb">' . implode( $sep, $out ) . '</nav>';
    }

    // ---------------------------------------------------------------- site verification + Google tracking
    private static function tracking_ok() {
        if ( NTSEOAI_Settings::on( 'ga_skip_users' ) && is_user_logged_in() && current_user_can( 'edit_posts' ) ) { return false; }
        return (bool) apply_filters( 'ntseoai_tracking_allowed', true );
    }
    public static function head_tags() {
        $map = array( 'ver_google' => 'google-site-verification', 'ver_bing' => 'msvalidate.01', 'ver_yandex' => 'yandex-verification', 'ver_pinterest' => 'p:domain_verify' );
        if ( is_front_page() || is_home() ) { foreach ( $map as $k => $name ) { $v = NTSEOAI_Settings::get( $k ); if ( $v ) { echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $v ) . '">' . "\n"; } } }
    }
    /** Google Tag Manager or GA4, loaded with the WordPress script API (only when an ID is set in Settings) */
    public static function tracking_scripts() {
        if ( ! self::tracking_ok() ) { return; }
        $gtm = trim( (string) NTSEOAI_Settings::get( 'gtm_id' ) ); $ga = trim( (string) NTSEOAI_Settings::get( 'ga4_id' ) );
        if ( $gtm ) {
            wp_register_script( 'ntseoai-gtm', false, array(), NTSEOAI_VERSION, false );
            wp_enqueue_script( 'ntseoai-gtm' );
            wp_add_inline_script( 'ntseoai-gtm', "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . wp_json_encode( $gtm ) . ');' );
        } elseif ( $ga ) {
            wp_enqueue_script( 'ntseoai-gtag', 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga ), array(), null, array( 'in_footer' => false, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the Google script is versioned by Google.
            wp_add_inline_script( 'ntseoai-gtag', "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config'," . wp_json_encode( $ga ) . ');', 'before' );
        }
    }
    public static function gtm_body() {
        $gtm = NTSEOAI_Settings::get( 'gtm_id' );
        if ( $gtm && self::tracking_ok() ) { echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n"; }
    }

    // ---------------------------------------------------------------- [ntseoai_toc]: table of contents built from the headings
    public static function toc( $content ) {
        if ( false === strpos( $content, '[ntseoai_toc]' ) ) { return $content; }
        $items = array(); $used = array();
        $content = preg_replace_callback( '#<h([23])([^>]*)>(.*?)</h\1>#is', function ( $m ) use ( &$items, &$used ) {
            $label = trim( wp_strip_all_tags( $m[3] ) );
            if ( '' === $label ) { return $m[0]; }
            if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $m[2], $im ) ) { $id = $im[1]; $attrs = $m[2]; }
            else { $id = sanitize_title( $label ); if ( '' === $id ) { $id = 'section'; } $base = $id; $n = 2; while ( isset( $used[ $id ] ) ) { $id = $base . '-' . $n++; } $attrs = $m[2] . ' id="' . esc_attr( $id ) . '"'; }
            $used[ $id ] = 1; $items[] = array( (int) $m[1], $id, $label );
            return '<h' . $m[1] . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
        }, $content );
        $html = '';
        if ( count( $items ) >= 3 ) {
            $html = '<nav class="ntseoai-toc" aria-label="Table of contents"><p class="ntseoai-toc-title"><strong>' . esc_html( apply_filters( 'ntseoai_toc_title', 'Contents' ) ) . '</strong></p><ul>';
            foreach ( $items as $i ) { $html .= '<li' . ( 3 === $i[0] ? ' style="margin-left:1.2em"' : '' ) . '><a href="#' . esc_attr( $i[1] ) . '">' . esc_html( $i[2] ) . '</a></li>'; }
            $html .= '</ul></nav>';
        }
        return str_replace( '[ntseoai_toc]', $html, $content );
    }

    // ---------------------------------------------------------------- post list columns
    public static function col_add( $cols ) { $cols['ntseoai_links'] = '<span title="Internal links: going out / coming in">Links</span>'; return $cols; }
    public static function col_show( $col, $id ) {
        static $c = null;
        if ( 'ntseoai_links' === $col ) {
            if ( null === $c ) { $c = NTSEOAI_Links::counts(); }
            echo (int) ( $c[1][ $id ] ?? 0 ) . ' / ' . ( ( $c[0][ $id ] ?? 0 ) ? (int) $c[0][ $id ] : '<span style="color:#b32d2e" title="No other page links here">0</span>' );
        }
    }

    // ---------------------------------------------------------------- robots.txt additions (virtual file only)
    public static function robots_extra( $out, $public ) {
        if ( '0' === (string) $public ) { return $out; }
        $x = trim( (string) NTSEOAI_Settings::get( 'robots_extra' ) );
        return '' === $x ? $out : rtrim( $out ) . "\n\n# NodeThemes SEO AI: custom rules\n" . $x . "\n";
    }
    /** refuse rules that would hide the whole site from every crawler */
    public static function clean_robots( $t ) {
        $t = str_replace( array( "\r\n", "\r" ), "\n", wp_strip_all_tags( (string) $t ) );
        $lines = array_filter( array_map( 'trim', explode( "\n", $t ) ), function ( $l ) { return '' === $l || preg_match( '/^(#|user-agent:|disallow:|allow:|sitemap:|crawl-delay:)/i', $l ); } );
        $t = implode( "\n", $lines );
        if ( preg_match( '/user-agent:\s*\*\s*\n\s*disallow:\s*\/\s*(\n|$)/i', $t ) ) { $t = preg_replace( '/user-agent:\s*\*\s*\n\s*disallow:\s*\/\s*(\n|$)/i', '', $t ); }
        return trim( $t );
    }

    // ---------------------------------------------------------------- WooCommerce: drop /product/ and /product-category/
    private static function bases() {
        $p = (array) get_option( 'woocommerce_permalinks', array() );
        return array(
            'product' => trim( ! empty( $p['product_base'] ) ? $p['product_base'] : 'product', '/' ),
            'cat'     => trim( ! empty( $p['category_base'] ) ? $p['category_base'] : 'product-category', '/' ),
        );
    }
    private static function strip( $url, $base ) {
        if ( '' === $base ) { return $url; }
        $home = trailingslashit( home_url() );
        return 0 === strpos( $url, $home . $base . '/' ) ? $home . substr( $url, strlen( $home . $base . '/' ) ) : $url;
    }
    public static function product_link( $link, $post ) {
        if ( 'product' !== $post->post_type || 'publish' !== $post->post_status ) { return $link; }
        return self::strip( $link, self::bases()['product'] );
    }
    public static function term_link( $link, $term, $tax ) { return 'product_cat' === $tax ? self::strip( $link, self::bases()['cat'] ) : $link; }

    /** a request for /my-product/ that is not a page, post or term is looked up among products and categories */
    public static function resolve( $qv ) {
        if ( is_admin() || ( empty( $qv['pagename'] ) && empty( $qv['name'] ) ) || ! post_type_exists( 'product' ) ) { return $qv; }
        $path = ! empty( $qv['pagename'] ) ? $qv['pagename'] : $qv['name'];
        if ( get_page_by_path( $path, OBJECT, array( 'page', 'post' ) ) ) { return $qv; }
        $slug = basename( $path );
        $ids = get_posts( array( 'post_type' => 'product', 'name' => $slug, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
        if ( $ids ) { return array( 'post_type' => 'product', 'product' => $slug, 'name' => $slug ); }
        if ( taxonomy_exists( 'product_cat' ) && get_term_by( 'slug', $slug, 'product_cat' ) ) { return array( 'product_cat' => $slug, 'post_type' => 'product' ); }
        return $qv;
    }
    /** old /product/slug/ addresses keep working and lead to the new one with a 301 */
    public static function old_to_new() {
        if ( is_admin() || ! ( is_singular( 'product' ) || is_tax( 'product_cat' ) ) ) { return; }
        $req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
        $b = self::bases();
        $target = is_singular( 'product' ) ? get_permalink() : get_term_link( get_queried_object() );
        if ( is_wp_error( $target ) ) { return; }
        $path = wp_parse_url( $req, PHP_URL_PATH );
        $tp = wp_parse_url( $target, PHP_URL_PATH );
        if ( $path && $tp && untrailingslashit( $path ) !== untrailingslashit( $tp ) && false !== strpos( $path, '/' . ( is_singular( 'product' ) ? $b['product'] : $b['cat'] ) . '/' ) ) { wp_safe_redirect( $target, 301 ); exit; }
    }
}

/** template helper: <?php ntseoai_breadcrumbs(); ?> */
function ntseoai_breadcrumbs() { echo wp_kses_post( NTSEOAI_Extras::breadcrumbs_html() ); }
