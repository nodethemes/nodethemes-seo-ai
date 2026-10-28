<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** internal link graph, orphan detection, "who should link here" suggestions, safe link insertion */
final class NRA_Links {

    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'reindex_post' ), 20, 2 );
        add_action( 'deleted_post', array( __CLASS__, 'forget_post' ) );
        foreach ( array( 'graph_batch', 'graph_data', 'suggest_in', 'suggest_out', 'apply', 'ai_anchor', 'undo' ) as $a ) {
            add_action( 'wp_ajax_nra_links_' . $a, array( __CLASS__, 'ajax_' . $a ) );
        }
    }
    private static function t() { global $wpdb; return $wpdb->prefix . 'nra_links'; }
    private static function guard() {
        NRA_Util::nonce();
        if ( ! current_user_can( 'edit_posts' ) ) { NRA_Util::fail( 'Not allowed.' ); }
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
            if ( $id ) { $out[] = array( $id, NRA_Util::cut( trim( wp_strip_all_tags( $x[2] ) ), 180 ) ); }
        }
        return $out;
    }
    public static function reindex_post( $id, $post = null ) {
        if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) { return; }
        $post = $post ? $post : get_post( $id );
        if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, (array) NRA_Settings::get( 'post_types' ), true ) ) { return; }
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
        return get_posts( array( 'post_type' => (array) NRA_Settings::get( 'post_types' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'has_password' => false ) );
    }
    public static function ajax_graph_batch() {
        self::guard();
        $ids = self::post_ids();
        $off = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $size = 25;
        if ( 0 === $off ) { global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . self::t() ); } // phpcs:ignore
        foreach ( array_slice( $ids, $off, $size ) as $pid ) { self::reindex_post( $pid ); }
        $done = ( $off + $size ) >= count( $ids );
        if ( $done ) { update_option( 'nra_graph_built', time(), false ); }
        NRA_Util::ok( array( 'done' => $done, 'next' => $off + $size, 'total' => count( $ids ) ) );
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
            $rows[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'type' => get_post_type( $id ), 'in' => isset( $in[ $id ] ) ? $in[ $id ] : 0, 'out' => isset( $out[ $id ] ) ? $out[ $id ] : 0, 'score' => (int) get_post_meta( $id, '_nra_score', true ), 'kw' => (string) get_post_meta( $id, '_nra_focuskw', true ), 'corner' => (bool) get_post_meta( $id, '_nra_cornerstone', true ) );
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
        NRA_Util::ok( array( 'nodes' => $rows, 'edges' => $edges, 'built' => (int) get_option( 'nra_graph_built', 0 ), 'total' => count( self::post_ids() ) ) );
    }

    // ------------------------------------------------------------ suggestions
    private static function keyphrase( $id ) {
        $kw = trim( (string) get_post_meta( $id, '_nra_focuskw', true ) );
        return $kw ? $kw : trim( get_the_title( $id ) );
    }
    /** phrases that make a good anchor for $target: focus keyword first, then title sub-phrases */
    private static function phrases( $target_id ) {
        $out = array();
        $kw = trim( (string) get_post_meta( $target_id, '_nra_focuskw', true ) );
        if ( $kw ) { $out[] = $kw; }
        $title = trim( preg_replace( '/\s+/', ' ', get_the_title( $target_id ) ) );
        if ( $title ) { $out[] = $title; }
        $w = preg_split( '/\s+/u', $title );
        if ( count( $w ) >= 3 ) {
            for ( $n = min( 4, count( $w ) - 1 ); $n >= 2; $n-- ) { for ( $i = 0; $i + $n <= count( $w ); $i++ ) { $out[] = implode( ' ', array_slice( $w, $i, $n ) ); } }
        }
        return array_values( array_unique( array_filter( $out, function ( $p ) { return NRA_Util::len( $p ) >= 4; } ) ) );
    }
    /** first place in $html (outside links/headings/code) where $phrase occurs; returns array( phrase_as_written, snippet ) or null */
    public static function find_spot( $html, $phrase ) {
        $parts = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        $skip = 0;
        $q = preg_quote( $phrase, '/' );
        $q = preg_replace( '/(?:\\ | )+/', '\s+', $q );
        foreach ( $parts as $p ) {
            if ( '' === $p ) { continue; }
            if ( '<' === $p[0] ) {
                if ( preg_match( '/^<(a|h[1-6]|script|style|button|code|pre|textarea|figcaption)\b/i', $p ) ) { $skip++; }
                elseif ( preg_match( '/^<\/(a|h[1-6]|script|style|button|code|pre|textarea|figcaption)>/i', $p ) ) { $skip = max( 0, $skip - 1 ); }
                continue;
            }
            if ( $skip ) { continue; }
            if ( preg_match( '/(?<![\p{L}\p{N}])(' . $q . ')(?![\p{L}\p{N}])/iu', $p, $m, PREG_OFFSET_CAPTURE ) ) {
                $pos = $m[1][1];
                $start = max( 0, $pos - 60 );
                $snip = ( $start ? '...' : '' ) . trim( substr( $p, $start, strlen( $m[1][0] ) + 120 + ( $pos - $start ) ) ) . '...';
                return array( $m[1][0], wp_strip_all_tags( $snip ) );
            }
        }
        return null;
    }
    /** insert link at first safe occurrence */
    public static function insert_link( $html, $phrase, $url ) {
        $parts = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        $skip = 0; $done = false;
        $q = preg_replace( '/(?:\\ | )+/', '\s+', preg_quote( $phrase, '/' ) );
        foreach ( $parts as $i => $p ) {
            if ( '' === $p ) { continue; }
            if ( '<' === $p[0] ) {
                if ( preg_match( '/^<(a|h[1-6]|script|style|button|code|pre|textarea|figcaption)\b/i', $p ) ) { $skip++; }
                elseif ( preg_match( '/^<\/(a|h[1-6]|script|style|button|code|pre|textarea|figcaption)>/i', $p ) ) { $skip = max( 0, $skip - 1 ); }
                continue;
            }
            if ( $skip || $done ) { continue; }
            $new = preg_replace_callback( '/(?<![\p{L}\p{N}])(' . $q . ')(?![\p{L}\p{N}])/iu', function ( $m ) use ( $url ) { return '<a href="' . esc_url( $url ) . '">' . $m[1] . '</a>'; }, $p, 1, $cnt );
            if ( $cnt ) { $parts[ $i ] = $new; $done = true; }
        }
        return $done ? implode( '', $parts ) : false;
    }
    private static function already_links( $src_id, $dst_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE src_id=%d AND dst_id=%d LIMIT 1', $src_id, $dst_id ) ); // phpcs:ignore
    }
    /** pages that should link TO $target (they mention the topic but do not link) */
    public static function suggest_in( $target_id, $limit = 12 ) {
        $phrases = self::phrases( $target_id );
        $res = array();
        if ( ! $phrases ) { return $res; }
        $inc = self::counts()[0];
        foreach ( self::post_ids() as $sid ) {
            if ( (int) $sid === (int) $target_id || self::already_links( $sid, $target_id ) ) { continue; }
            $c = get_post_field( 'post_content', $sid );
            foreach ( $phrases as $i => $ph ) {
                $hit = self::find_spot( $c, $ph );
                if ( $hit ) {
                    $res[] = array( 'src_id' => $sid, 'title' => get_the_title( $sid ), 'type' => get_post_type( $sid ), 'anchor' => $hit[0], 'snippet' => $hit[1], 'rank' => $i, 'in' => (int) ( $inc[ $sid ] ?? 0 ), 'edit' => get_edit_post_link( $sid, 'raw' ) );
                    break;
                }
            }
        }
        usort( $res, function ( $a, $b ) { return $a['rank'] <=> $b['rank']; } );
        return array_slice( $res, 0, $limit );
    }
    /** pages $src should link to */
    public static function suggest_out( $src_id, $limit = 10, $content = null ) {
        $c = null !== $content ? $content : get_post_field( 'post_content', $src_id );
        $res = array();
        foreach ( self::post_ids() as $tid ) {
            if ( (int) $tid === (int) $src_id || self::already_links( $src_id, $tid ) ) { continue; }
            foreach ( self::phrases( $tid ) as $i => $ph ) {
                $hit = self::find_spot( $c, $ph );
                if ( $hit ) { $res[] = array( 'dst_id' => $tid, 'title' => get_the_title( $tid ), 'type' => get_post_type( $tid ), 'anchor' => $hit[0], 'snippet' => $hit[1], 'rank' => $i - ( get_post_meta( $tid, '_nra_cornerstone', true ) ? 2 : 0 ), 'url' => get_permalink( $tid ) ); break; }
            }
        }
        usort( $res, function ( $a, $b ) { return $a['rank'] <=> $b['rank']; } );
        return array_slice( $res, 0, $limit );
    }
    public static function ajax_suggest_in() {
        self::guard();
        $t = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;
        if ( ! $t || ! get_post( $t ) ) { NRA_Util::fail( 'Page not found.' ); }
        $items = self::suggest_in( $t, 30 );
        $more = 0;
        if ( ! NRA_Pro::active() && count( $items ) > NRA_Pro::FREE_LINK_SUGGESTIONS ) { $more = count( $items ) - NRA_Pro::FREE_LINK_SUGGESTIONS; $items = array_slice( $items, 0, NRA_Pro::FREE_LINK_SUGGESTIONS ); }
        NRA_Util::ok( array( 'more' => $more, 'pro' => NRA_Pro::active(), 'items' => $items, 'target' => array( 'title' => get_the_title( $t ), 'url' => get_permalink( $t ), 'kw' => self::keyphrase( $t ) ) ) );
    }
    public static function ajax_suggest_out() {
        self::guard();
        $s = isset( $_POST['source'] ) ? (int) $_POST['source'] : 0;
        if ( ! $s || ! current_user_can( 'edit_post', $s ) ) { NRA_Util::fail( 'Not allowed.' ); }
        NRA_Util::ok( array( 'items' => self::suggest_out( $s ) ) );
    }
    /** apply: link anchor text inside source post pointing at target */
    public static function ajax_apply() {
        self::guard();
        $s = isset( $_POST['source'] ) ? (int) $_POST['source'] : 0;
        $t = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;
        $a = isset( $_POST['anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) : '';
        if ( ! $s || ! $t || '' === $a || ! current_user_can( 'edit_post', $s ) || ! get_post( $t ) ) { NRA_Util::fail( 'Not allowed or incomplete request.' ); }
        $post = get_post( $s );
        $live = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : null; // editor content that is not saved yet
        $new = self::insert_link( null !== $live ? $live : $post->post_content, $a, get_permalink( $t ) );
        if ( false === $new ) { NRA_Util::fail( 'Could not find a safe place for that anchor text (it may already be linked or inside a heading).' ); }
        if ( null !== $live ) { NRA_Util::ok( array( 'message' => 'Link added to the editor. Save the page to keep it.', 'content' => $new ) ); }
        update_post_meta( $s, '_nra_prev_content', wp_slash( $post->post_content ) );
        wp_update_post( wp_slash( array( 'ID' => $s, 'post_content' => $new ) ) );
        self::reindex_post( $s );
        NRA_Util::ok( array( 'message' => 'Link added.' ) );
    }
    /** programmatic apply used by the Pro bulk linker */
    public static function apply_link( $src, $dst, $anchor ) {
        $post = get_post( $src );
        if ( ! $post || ! get_post( $dst ) || ! current_user_can( 'edit_post', $src ) ) { return false; }
        $new = self::insert_link( $post->post_content, $anchor, get_permalink( $dst ) );
        if ( false === $new ) { return false; }
        update_post_meta( $src, '_nra_prev_content', wp_slash( $post->post_content ) );
        wp_update_post( wp_slash( array( 'ID' => $src, 'post_content' => $new ) ) );
        self::reindex_post( $src );
        return true;
    }
    public static function ajax_undo() {
        self::guard();
        $s = isset( $_POST['source'] ) ? (int) $_POST['source'] : 0;
        if ( ! $s || ! current_user_can( 'edit_post', $s ) ) { NRA_Util::fail( 'Not allowed.' ); }
        $prev = get_post_meta( $s, '_nra_prev_content', true );
        if ( '' === $prev ) { NRA_Util::fail( 'Nothing to undo.' ); }
        wp_update_post( wp_slash( array( 'ID' => $s, 'post_content' => $prev ) ) );
        delete_post_meta( $s, '_nra_prev_content' );
        self::reindex_post( $s );
        NRA_Util::ok( array( 'message' => 'Restored.' ) );
    }
    /** AI: ask for a better anchor / one-line reason for a pair (optional, used by the map) */
    public static function ajax_ai_anchor() {
        self::guard();
        $t = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;
        $ids = isset( $_POST['sources'] ) ? array_slice( array_map( 'intval', (array) $_POST['sources'] ), 0, 8 ) : array();
        if ( ! $t ) { NRA_Util::fail( 'Missing page.' ); }
        $list = '';
        foreach ( $ids as $i ) { $list .= '#' . $i . ': ' . get_the_title( $i ) . ' - ' . NRA_Util::cut( NRA_Util::text( get_post_field( 'post_content', $i ) ), 220 ) . "\n"; }
        $r = NRA_AI::json( NRA_AI::context(), "Target page to boost: \"" . get_the_title( $t ) . "\" (focus keyword: " . self::keyphrase( $t ) . ").\nCandidate source pages:\n<page_content>\n" . $list . "</page_content>\nFor each candidate say in one short sentence why linking to the target would help readers, and rate relevance 1-5.\nJSON: {\"items\":[{\"id\":123,\"why\":\"...\",\"relevance\":4}]}", array( 'max_tokens' => 900 ) );
        if ( ! $r['ok'] ) { NRA_Util::fail( $r['error'] ); }
        NRA_Util::ok( array( 'items' => (array) ( $r['data']['items'] ?? array() ) ) );
    }
}
