<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** one-click import from Yoast SEO, Rank Math and All in One SEO */
final class NRA_Import {

    public static function init() {
        add_action( 'wp_ajax_nra_import_run', array( __CLASS__, 'ajax_run' ) );
    }
    private static function map() {
        return array(
            'yoast' => array( 'label' => 'Yoast SEO', 'keys' => array( 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc', 'focuskw' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical', 'og_title' => '_yoast_wpseo_opengraph-title', 'og_desc' => '_yoast_wpseo_opengraph-description', 'og_img' => '_yoast_wpseo_opengraph-image', 'noindex' => '_yoast_wpseo_meta-robots-noindex' ) ),
            'rankmath' => array( 'label' => 'Rank Math', 'keys' => array( 'title' => 'rank_math_title', 'desc' => 'rank_math_description', 'focuskw' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url', 'og_title' => 'rank_math_facebook_title', 'og_desc' => 'rank_math_facebook_description', 'og_img' => 'rank_math_facebook_image', 'robots' => 'rank_math_robots' ) ),
            'aioseo' => array( 'label' => 'All in One SEO', 'keys' => array( 'title' => '_aioseo_title', 'desc' => '_aioseo_description' ) ),
        );
    }
    public static function detect() {
        global $wpdb;
        $out = array();
        foreach ( self::map() as $id => $m ) {
            $key = reset( $m['keys'] );
            $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value<>''", $m['keys']['title'], $m['keys']['desc'] ) ); // phpcs:ignore
            if ( $n ) { $out[ $id ] = $n; }
        }
        return $out;
    }
    private static function vars( $s ) {
        $s = (string) $s;
        $map = array( 'title' => 'title', 'sitename' => 'sitename', 'sep' => 'sep', 'sitedesc' => 'tagline', 'primary_category' => 'category', 'category' => 'category', 'currentyear' => 'year', 'name' => 'author', 'term_title' => 'term', 'focuskw' => 'focuskw', 'tagline' => 'tagline', 'year' => 'year', 'author' => 'author', 'term' => 'term' );
        $s = preg_replace_callback( '/%%?([a-z_]+)%%?/i', function ( $m ) use ( $map ) { $k = strtolower( $m[1] ); return isset( $map[ $k ] ) ? '%' . $map[ $k ] . '%' : ''; }, $s );
        return trim( preg_replace( '/\s+/', ' ', $s ) );
    }
    public static function render_card() {
        $d = self::detect();
        echo '<div class="nra-card"><h2>Import from another SEO plugin</h2>';
        if ( ! $d ) { echo '<p class="nra-help">No Yoast, Rank Math or All in One SEO data found in this site.</p></div>'; return; }
        echo '<p class="nra-help">Copies titles, descriptions, focus keywords, canonical, social and noindex settings into NodeThemes SEO AI. Values you already set here are not overwritten. Your old plugin data stays untouched. Deactivate the old SEO plugin afterwards so tags are not output twice.</p>';
        foreach ( $d as $id => $n ) {
            $m = self::map()[ $id ];
            echo '<p><button type="button" class="nra-btn nra-import" data-src="' . esc_attr( $id ) . '">Import from ' . esc_html( $m['label'] ) . ' (' . (int) $n . ' pages)</button> <span class="nra-import-out" data-for="' . esc_attr( $id ) . '"></span></p>';
        }
        echo '</div>';
    }
    public static function ajax_run() {
        check_ajax_referer( 'nra_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { NRA_Util::fail( 'Not allowed.' ); }
        global $wpdb;
        $src = isset( $_POST['src'] ) ? sanitize_key( $_POST['src'] ) : '';
        $off = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $maps = self::map();
        if ( ! isset( $maps[ $src ] ) ) { NRA_Util::fail( 'Unknown source.' ); }
        $k = $maps[ $src ]['keys'];
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value<>'' ORDER BY post_id LIMIT 50 OFFSET %d", $k['title'], $k['desc'], $off ) ); // phpcs:ignore
        $n = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            $put = function ( $key, $val ) use ( $id ) { if ( '' !== trim( (string) $val ) && '' === (string) get_post_meta( $id, '_nra_' . $key, true ) ) { update_post_meta( $id, '_nra_' . $key, $val ); } };
            $g = function ( $name ) use ( $id, $k ) { return isset( $k[ $name ] ) ? get_post_meta( $id, $k[ $name ], true ) : ''; };
            $put( 'title', self::vars( $g( 'title' ) ) );
            $put( 'desc', self::vars( $g( 'desc' ) ) );
            $kw = (string) $g( 'focuskw' ); if ( 'rankmath' === $src ) { $kw = trim( explode( ',', $kw )[0] ); }
            $put( 'focuskw', $kw );
            $put( 'canonical', esc_url_raw( (string) $g( 'canonical' ) ) );
            $put( 'og_title', self::vars( $g( 'og_title' ) ) );
            $put( 'og_desc', self::vars( $g( 'og_desc' ) ) );
            $put( 'og_img', esc_url_raw( (string) $g( 'og_img' ) ) );
            if ( 'yoast' === $src && '1' === (string) $g( 'noindex' ) ) { $put( 'noindex', '1' ); }
            if ( 'rankmath' === $src ) { $rb = $g( 'robots' ); if ( is_array( $rb ) && in_array( 'noindex', $rb, true ) ) { $put( 'noindex', '1' ); } }
            $n++;
        }
        $done = count( $ids ) < 50;
        if ( $done ) { NRA_Sitemap::bump(); NRA_Geo::bump(); }
        NRA_Util::ok( array( 'done' => $done, 'next' => $off + 50, 'imported' => $n ) );
    }
}
