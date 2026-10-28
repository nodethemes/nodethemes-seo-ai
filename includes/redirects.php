<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** redirect manager + 404 monitor + automatic redirect when a slug changes */
final class NRA_Redirects {

    public static function init() {
        if ( ! NRA_Settings::on( 'mod_redirects' ) ) { return; }
        add_action( 'template_redirect', array( __CLASS__, 'handle' ), 2 );
        add_action( 'post_updated', array( __CLASS__, 'slug_changed' ), 10, 3 );
        add_action( 'admin_post_nra_redirect_add', array( __CLASS__, 'post_add' ) );
        add_action( 'admin_post_nra_redirect_del', array( __CLASS__, 'post_del' ) );
        add_action( 'admin_post_nra_404_clear', array( __CLASS__, 'post_clear' ) );
    }

    private static function table() { global $wpdb; return $wpdb->prefix . 'nra_redirects'; }
    private static function norm_path( $p ) { $p = '/' . ltrim( (string) $p, '/' ); return strlen( $p ) > 1 ? untrailingslashit( $p ) : $p; }

    public static function handle() {
        global $wpdb;
        $req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
        $path = self::norm_path( rawurldecode( (string) wp_parse_url( $req, PHP_URL_PATH ) ) );
        $home = self::norm_path( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
        if ( '/' !== $home && 0 === strpos( $path, $home ) ) { $path = self::norm_path( substr( $path, strlen( $home ) ) ); }
        $t = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE is_regex=0 AND source=%s LIMIT 1", $path ) ); // phpcs:ignore
        if ( ! $row ) {
            $regex = $wpdb->get_results( "SELECT * FROM {$t} WHERE is_regex=1 LIMIT 100" ); // phpcs:ignore
            foreach ( (array) $regex as $r ) {
                if ( @preg_match( '#' . str_replace( '#', '\#', $r->source ) . '#i', $path, $mm ) ) { // phpcs:ignore
                    $row = $r; $row->target = preg_replace( '#' . str_replace( '#', '\#', $r->source ) . '#i', $r->target, $path ); break;
                }
            }
        }
        if ( $row ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits=hits+1, last_hit=%s WHERE id=%d", current_time( 'mysql' ), $row->id ) ); // phpcs:ignore
            $to = $row->target;
            if ( ! preg_match( '#^https?://#i', $to ) ) { $to = home_url( '/' . ltrim( $to, '/' ) ); }
            if ( 410 === (int) $row->code ) { status_header( 410 ); nocache_headers(); echo 'Gone'; exit; }
            wp_redirect( $to, (int) $row->code ?: 301, 'NodeThemes SEO-AI' ); // phpcs:ignore
            exit;
        }
        if ( is_404() ) { self::log_404( $path ); }
    }

    private static function log_404( $path ) {
        global $wpdb;
        if ( strlen( $path ) > 190 || preg_match( '/\.(css|js|map|ico|png|jpe?g|gif|svg|webp|woff2?|ttf)$/i', $path ) ) { return; }
        $ref = isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 250 ) : ''; // phpcs:ignore
        $t = $wpdb->prefix . 'nra_404';
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (path, hits, referrer, last_seen) VALUES (%s,1,%s,%s) ON DUPLICATE KEY UPDATE hits=hits+1, last_seen=VALUES(last_seen)", $path, $ref, current_time( 'mysql' ) ) ); // phpcs:ignore
    }

    public static function slug_changed( $id, $after, $before ) {
        if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status || $before->post_name === $after->post_name ) { return; }
        if ( ! in_array( $after->post_type, (array) NRA_Settings::get( 'post_types' ), true ) ) { return; }
        $old = wp_parse_url( get_permalink( $before ), PHP_URL_PATH );
        $new = get_permalink( $after );
        $old_clone = clone $before;
        // rebuild old permalink from old slug
        $old = str_replace( $after->post_name, $before->post_name, (string) wp_parse_url( $new, PHP_URL_PATH ) );
        if ( $old && $old !== wp_parse_url( $new, PHP_URL_PATH ) ) { self::add( $old, $new, 301, 1 ); }
    }

    public static function add( $source, $target, $code = 301, $auto = 0, $regex = 0 ) {
        global $wpdb;
        $src = $regex ? $source : self::norm_path( $source );
        if ( ! $regex && self::norm_path( $src ) === self::norm_path( (string) wp_parse_url( $target, PHP_URL_PATH ) ) && ! preg_match( '#^https?://#i', $target ) ) { return false; }
        $t = self::table();
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE source=%s", $src ) ); // phpcs:ignore
        if ( $exists ) { $wpdb->update( $t, array( 'target' => $target, 'code' => (int) $code ), array( 'id' => (int) $exists ) ); return (int) $exists; }
        $wpdb->insert( $t, array( 'source' => $src, 'target' => $target, 'code' => (int) $code, 'is_regex' => (int) $regex, 'auto' => (int) $auto, 'created' => current_time( 'mysql' ) ) );
        return (int) $wpdb->insert_id;
    }

    public static function post_add() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nra_redirect_add' );
        $s = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        $t = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
        $c = isset( $_POST['code'] ) ? (int) $_POST['code'] : 301;
        if ( ! in_array( $c, array( 301, 302, 307, 410 ), true ) ) { $c = 301; }
        $rx = ! empty( $_POST['is_regex'] ) ? 1 : 0;
        $s = $rx ? $s : ( wp_parse_url( $s, PHP_URL_PATH ) ?: $s );
        if ( $s && ( $t || 410 === $c ) ) { self::add( $s, $t, $c, 0, $rx ); NRA_Admin::flash( 'ok', 'Redirect saved.' ); }
        else { NRA_Admin::flash( 'err', 'Enter a source and a target.' ); }
        wp_safe_redirect( admin_url( 'admin.php?page=nra-redirects' ) ); exit;
    }
    public static function post_del() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nra_redirect_del' );
        global $wpdb;
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( $id ) { $wpdb->delete( self::table(), array( 'id' => $id ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=nra-redirects' ) ); exit;
    }
    public static function post_clear() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'nra_404_clear' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}nra_404" ); // phpcs:ignore
        wp_safe_redirect( admin_url( 'admin.php?page=nra-redirects' ) ); exit;
    }
}
