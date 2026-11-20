<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** internal link graph and orphan detection (link suggestions and one-click linking are part of the Pro add-on) */
final class NTSEOAI_Links {

    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'reindex_post' ), 20, 2 );
        add_action( 'deleted_post', array( __CLASS__, 'forget_post' ) );
        foreach ( array( 'graph_batch', 'graph_data' ) as $a ) {
            add_action( 'wp_ajax_ntseoai_links_' . $a, array( __CLASS__, 'ajax_' . $a ) );
        }
    }
    public static function table() { return self::t(); }
    private static function t() { global $wpdb; return $wpdb->prefix . 'ntseoai_links'; }
    private static function guard() {
        NTSEOAI_Util::nonce();
        if ( ! current_user_can( 'edit_posts' ) ) { NTSEOAI_Util::fail( 'Not allowed.' ); }
    }

    // ------------------------------------------------------------ graph storage
    public static function extract( $html ) {
        $out = array();
        if ( '' === trim( (string) $html ) ) { return $out; }
        if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) { return $out; }
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( $m as $x ) {
            $href = html_entity_decode( $x[1] );
            if ( 0 === strpos( $href, '#' ) || preg_match( '#^(mailto|tel|javascript):#i', $href ) ) { continue; }
            $h = wp_parse_url( $href, PHP_URL_HOST );
            if ( $h && $h !== $host ) { continue; }
            if ( ! $h ) { $href = home_url( '/' . ltrim( $href, '/' ) ); }
            $id = url_to_postid( $href );
            if ( $id ) { $out[] = array( $id, NTSEOAI_Util::cut( trim( wp_strip_all_tags( $x[2] ) ), 180 ) ); }
        }
        return $out;
    }
    public static function reindex_post( $id, $post = null ) {
        if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) { return; }
        $post = $post ? $post : get_post( $id );
        if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, (array) NTSEOAI_Settings::get( 'post_types' ), true ) ) { return; }
        global $wpdb;
        $wpdb->delete( self::t(), array( 'src_id' => (int) $id ) );
        $seen = array();
        foreach ( self::extract( $post->post_content ) as $l ) {
            if ( (int) $l[0] === (int) $id || isset( $seen[ $l[0] ] ) ) { continue; }
            $seen[ $l[0] ] = 1;
            $wpdb->insert( self::t(), array( 'src_id' => (int) $id, 'dst_id' => (int) $l[0], 'anchor' => $l[1] ) );
        }
    }
    public static function forget_post( $id ) {
        global $wpdb;
        $wpdb->delete( self::t(), array( 'src_id' => (int) $id ) );
        $wpdb->delete( self::t(), array( 'dst_id' => (int) $id ) );
    }
    public static function post_ids_public() { return self::post_ids(); }
    private static function post_ids() {
        return get_posts( array( 'post_type' => (array) NTSEOAI_Settings::get( 'post_types' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'has_password' => false ) );
    }
    public static function ajax_graph_batch() {
        self::guard();
        if ( ! NTSEOAI_Job::$running ) { check_ajax_referer( 'ntseoai_admin', 'nonce' ); }
        $ids = self::post_ids();
        $off = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $size = 25;
        if ( 0 === $off ) { global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . self::t() ); } // phpcs:ignore
        foreach ( array_slice( $ids, $off, $size ) as $pid ) { self::reindex_post( $pid ); }
        $done = ( $off + $size ) >= count( $ids );
        if ( $done ) { update_option( 'ntseoai_graph_built', time(), false ); }
        NTSEOAI_Util::ok( array( 'done' => $done, 'next' => $off + $size, 'total' => count( $ids ) ) );
    }

    // ------------------------------------------------------------ stats / graph data
    public static function counts() {
        global $wpdb;
        $in = array(); $out = array();
        foreach ( (array) $wpdb->get_results( 'SELECT dst_id id, COUNT(DISTINCT src_id) n FROM ' . self::t() . ' GROUP BY dst_id' ) as $r ) { $in[ (int) $r->id ] = (int) $r->n; } // phpcs:ignore
        foreach ( (array) $wpdb->get_results( 'SELECT src_id id, COUNT(DISTINCT dst_id) n FROM ' . self::t() . ' GROUP BY src_id' ) as $r ) { $out[ (int) $r->id ] = (int) $r->n; } // phpcs:ignore
        return array( $in, $out );
    }
    public static function overview() {
        list( $in, $out ) = self::counts();
        $rows = array();
        foreach ( self::post_ids() as $id ) {
            $rows[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'type' => get_post_type( $id ), 'in' => isset( $in[ $id ] ) ? $in[ $id ] : 0, 'out' => isset( $out[ $id ] ) ? $out[ $id ] : 0, 'score' => (int) get_post_meta( $id, '_ntseoai_score', true ), 'kw' => (string) get_post_meta( $id, '_ntseoai_focuskw', true ), 'corner' => (bool) get_post_meta( $id, '_ntseoai_cornerstone', true ) );
        }
        return $rows;
    }
    public static function ajax_graph_data() {
        self::guard();
        $rows = self::overview();
        usort( $rows, function ( $a, $b ) { return ( $b['in'] + $b['out'] + ( $b['corner'] ? 5 : 0 ) ) <=> ( $a['in'] + $a['out'] + ( $a['corner'] ? 5 : 0 ) ); } );
        $rows = array_slice( $rows, 0, 120 );
        $ids = wp_list_pluck( $rows, 'id' );
        $edges = array();
        if ( $ids ) {
            global $wpdb;
            $list = implode( ',', array_map( 'intval', $ids ) );
            foreach ( (array) $wpdb->get_results( 'SELECT DISTINCT src_id s, dst_id d FROM ' . self::t() . " WHERE src_id IN ($list) AND dst_id IN ($list)" ) as $e ) { $edges[] = array( (int) $e->s, (int) $e->d ); } // phpcs:ignore
        }
        foreach ( $rows as &$r ) { $r['edit'] = get_edit_post_link( $r['id'], 'raw' ); } unset( $r );
        NTSEOAI_Util::ok( array( 'nodes' => $rows, 'edges' => $edges, 'built' => (int) get_option( 'ntseoai_graph_built', 0 ), 'total' => count( self::post_ids() ) ) );
    }
}
