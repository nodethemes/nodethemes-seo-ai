<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** XML sitemaps (noindex-aware, with images) + IndexNow */
final class NRA_Sitemap {
    const PER = 1000;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'add_rewrites' ) );
        add_filter( 'query_vars', function ( $v ) { $v[] = 'nra_sm'; $v[] = 'nra_sm_page'; $v[] = 'nra_indexnow'; return $v; } );
        add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
        add_action( 'save_post', array( __CLASS__, 'bump' ) );
        add_action( 'deleted_post', array( __CLASS__, 'bump' ) );
        add_action( 'created_term', array( __CLASS__, 'bump' ) );
        add_action( 'delete_term', array( __CLASS__, 'bump' ) );
        add_action( 'transition_post_status', array( __CLASS__, 'indexnow_hook' ), 20, 3 );
        add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );
        if ( NRA_Settings::on( 'mod_sitemap' ) ) { add_filter( 'wp_sitemaps_enabled', '__return_false' ); }
    }
    public static function add_rewrites() {
        add_rewrite_rule( '^sitemap\.xml$', 'index.php?nra_sm=index', 'top' );
        add_rewrite_rule( '^nra-sitemap-([a-z0-9_]+)-([0-9]+)\.xml$', 'index.php?nra_sm=$matches[1]&nra_sm_page=$matches[2]', 'top' );
        add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?nra_indexnow=$matches[1]', 'top' );
    }
    public static function bump() { update_option( 'nra_sm_ver', time(), false ); }
    public static function url() { return home_url( '/sitemap.xml' ); }

    public static function robots( $out, $public ) {
        if ( '0' === (string) $public ) { return $out; }
        if ( NRA_Settings::on( 'mod_sitemap' ) && false === stripos( $out, 'sitemap:' ) ) { $out = rtrim( $out ) . "\n\nSitemap: " . self::url() . "\n"; }
        return $out;
    }

    private static function types() {
        $t = array();
        foreach ( (array) NRA_Settings::get( 'post_types' ) as $pt ) { if ( post_type_exists( $pt ) ) { $t[] = $pt; } }
        return $t;
    }
    private static function excluded_ids() {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_nra_noindex' AND meta_value<>''" ) );
    }
    private static function count_posts( $pt ) {
        global $wpdb;
        $ex = self::excluded_ids();
        $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' AND post_password=''", $pt );
        if ( $ex ) { $sql .= ' AND ID NOT IN (' . implode( ',', $ex ) . ')'; }
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore
    }

    public static function serve() {
        $sm = get_query_var( 'nra_sm' );
        $key = get_query_var( 'nra_indexnow' );
        if ( $key ) {
            if ( hash_equals( (string) get_option( 'nra_indexnow_key' ), (string) $key ) ) { status_header( 200 ); header( 'Content-Type: text/plain; charset=utf-8' ); echo esc_html( $key ); exit; }
            return;
        }
        if ( ! $sm || ! NRA_Settings::on( 'mod_sitemap' ) ) { return; }
        $ver = (string) get_option( 'nra_sm_ver', '0' );
        $page = max( 1, (int) get_query_var( 'nra_sm_page' ) );
        $ck = 'nra_sm_' . md5( $sm . '|' . $page . '|' . $ver );
        $xml = get_transient( $ck );
        if ( false === $xml ) {
            $xml = ( 'index' === $sm ) ? self::build_index() : self::build_part( $sm, $page );
            if ( null === $xml ) { status_header( 404 ); nocache_headers(); return; }
            set_transient( $ck, $xml, HOUR_IN_SECONDS * 6 );
        }
        status_header( 200 );
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow' );
        header( 'Cache-Control: public, max-age=3600' );
        echo $xml; // phpcs:ignore
        exit;
    }

    private static function head( $index ) {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . ( $index ? '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' : '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' ) . "\n";
    }
    private static function build_index() {
        $x = self::head( true );
        foreach ( self::types() as $pt ) {
            $n = self::count_posts( $pt );
            $pages = max( 0, (int) ceil( $n / self::PER ) );
            for ( $i = 1; $i <= $pages; $i++ ) {
                global $wpdb;
                $last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish'", $pt ) );
                $x .= '<sitemap><loc>' . esc_url( home_url( "/nra-sitemap-{$pt}-{$i}.xml" ) ) . '</loc>' . ( $last ? '<lastmod>' . mysql2date( 'c', $last, false ) . '</lastmod>' : '' ) . "</sitemap>\n";
            }
        }
        foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
            if ( 'post_tag' === $tax && NRA_Settings::on( 'noindex_tags' ) ) { continue; }
            if ( in_array( $tax, array( 'post_format', 'product_shipping_class' ), true ) ) { continue; }
            if ( wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) ) > 0 ) { $x .= '<sitemap><loc>' . esc_url( home_url( "/nra-sitemap-tax_{$tax}-1.xml" ) ) . "</loc></sitemap>\n"; }
        }
        return $x . '</sitemapindex>';
    }
    private static function build_part( $sm, $page ) {
        $x = self::head( false );
        if ( 0 === strpos( $sm, 'tax_' ) ) {
            $tax = substr( $sm, 4 );
            if ( ! taxonomy_exists( $tax ) ) { return null; }
            $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => self::PER, 'offset' => ( $page - 1 ) * self::PER ) );
            if ( is_wp_error( $terms ) || ! $terms ) { return null; }
            foreach ( $terms as $t ) {
                if ( get_term_meta( $t->term_id, 'nra_noindex', true ) ) { continue; }
                $l = get_term_link( $t ); if ( is_wp_error( $l ) ) { continue; }
                $x .= '<url><loc>' . esc_url( $l ) . "</loc></url>\n";
            }
            return $x . '</urlset>';
        }
        if ( ! in_array( $sm, self::types(), true ) ) { return null; }
        $ids = get_posts( array( 'post_type' => $sm, 'post_status' => 'publish', 'posts_per_page' => self::PER, 'paged' => $page, 'orderby' => 'modified', 'order' => 'DESC', 'has_password' => false, 'post__not_in' => self::excluded_ids(), 'no_found_rows' => true, 'suppress_filters' => true ) );
        if ( ! $ids ) { return null; }
        if ( 1 === $page && 'page' === $sm ) { $x .= '<url><loc>' . esc_url( trailingslashit( home_url() ) ) . "</loc></url>\n"; }
        foreach ( $ids as $p ) {
            if ( 'page' === $sm && (int) get_option( 'page_on_front' ) === $p->ID ) { continue; }
            $x .= '<url><loc>' . esc_url( get_permalink( $p ) ) . '</loc><lastmod>' . mysql2date( 'c', $p->post_modified_gmt, false ) . '</lastmod>';
            $img = get_the_post_thumbnail_url( $p, 'full' );
            if ( $img ) { $x .= '<image:image><image:loc>' . esc_url( $img ) . '</image:loc></image:image>'; }
            $x .= "</url>\n";
        }
        return $x . '</urlset>';
    }

    // ------------------------------------------------------------ IndexNow
    public static function indexnow_hook( $new, $old, $post ) {
        if ( ! NRA_Pro::active() || ! NRA_Settings::on( 'mod_indexnow' ) || 'publish' !== $new || ! in_array( $post->post_type, (array) NRA_Settings::get( 'post_types' ), true ) ) { return; }
        if ( get_post_meta( $post->ID, '_nra_noindex', true ) ) { return; }
        self::indexnow( array( get_permalink( $post ) ) );
    }
    public static function indexnow( $urls ) {
        $key = get_option( 'nra_indexnow_key' );
        if ( ! $key || ! $urls ) { return false; }
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        $res = wp_remote_post( 'https://api.indexnow.org/indexnow', array( 'timeout' => 8, 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ), 'body' => wp_json_encode( array( 'host' => $host, 'key' => $key, 'keyLocation' => home_url( '/' . $key . '.txt' ), 'urlList' => array_values( $urls ) ) ) ) );
        $ok = ! is_wp_error( $res ) && in_array( (int) wp_remote_retrieve_response_code( $res ), array( 200, 202 ), true );
        update_option( 'nra_indexnow_last', array( 'time' => time(), 'ok' => $ok, 'count' => count( $urls ) ), false );
        return $ok;
    }
}
