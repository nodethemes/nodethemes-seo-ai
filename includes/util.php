<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** small shared helpers (text handling that works for Latin, Persian/Arabic and other Unicode text) */
final class NRA_Util {

    /** normalise text for matching: lower-case, unify Arabic/Persian letters and digits, ZWNJ -> space */
    public static function norm( $s ) {
        $s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        $s = strtr( $s, array(
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'ـ' => '',
            "\xE2\x80\x8C" => ' ', "\xE2\x80\x8F" => '', "\xE2\x80\x8E" => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ) );
        $s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $s ); // Arabic diacritics
        $s = preg_replace( '/[\-_\/]+/u', ' ', $s );
        $s = preg_replace( '/\s+/u', ' ', $s );
        return trim( $s );
    }

    /** html -> clean plain text */
    public static function text( $html ) {
        $html = strip_shortcodes( (string) $html );
        $html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
        $html = preg_replace( '#<!--.*?-->#s', ' ', $html );
        $html = preg_replace( '#</(p|div|li|h[1-6]|tr|br|blockquote|section|article)>#i', "\n", $html );
        $html = preg_replace( '#<br\s*/?>#i', "\n", $html );
        $t = wp_strip_all_tags( $html );
        $t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $t = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $t );
        $t = preg_replace( "/\n\s*\n+/", "\n", $t );
        return trim( $t );
    }

    public static function words( $text ) {
        $w = preg_split( '/[^\p{L}\p{N}\x{200C}]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
        return $w ? $w : array();
    }
    public static function word_count( $text ) { return count( self::words( $text ) ); }

    public static function sentences( $text ) {
        $s = preg_split( '/(?<=[\.\!\?\x{061F}\x{06D4}\x{2026}])\s+|\n+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
        return $s ? $s : array();
    }
    public static function len( $s ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s ); }
    public static function cut( $s, $n, $ellipsis = '' ) {
        $s = (string) $s;
        if ( self::len( $s ) <= $n ) { return $s; }
        $c = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
        $sp = function_exists( 'mb_strrpos' ) ? mb_strrpos( $c, ' ', 0, 'UTF-8' ) : strrpos( $c, ' ' );
        if ( $sp && $sp > $n * 0.6 ) { $c = function_exists( 'mb_substr' ) ? mb_substr( $c, 0, $sp, 'UTF-8' ) : substr( $c, 0, $sp ); }
        return rtrim( $c, " ,.;:-\n" ) . $ellipsis;
    }
    public static function is_latin( $text ) {
        $l = preg_match_all( '/\p{Latin}/u', (string) $text );
        $a = preg_match_all( '/\p{L}/u', (string) $text );
        return $a > 0 && ( $l / $a ) > 0.7;
    }

    /** tolerant JSON decode for model output (code fences, text before/after) */
    public static function json_loose( $s ) {
        $s = trim( (string) $s );
        $s = preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/m', '', $s );
        $d = json_decode( $s, true );
        if ( is_array( $d ) ) { return $d; }
        $a = strpos( $s, '{' ); $b = strrpos( $s, '}' );
        if ( false !== $a && false !== $b && $b > $a ) {
            $d = json_decode( substr( $s, $a, $b - $a + 1 ), true );
            if ( is_array( $d ) ) { return $d; }
        }
        $a = strpos( $s, '[' ); $b = strrpos( $s, ']' );
        if ( false !== $a && false !== $b && $b > $a ) {
            $d = json_decode( substr( $s, $a, $b - $a + 1 ), true );
            if ( is_array( $d ) ) { return $d; }
        }
        return null;
    }

    public static function post_types() {
        $t = (array) NRA_Settings::get( 'post_types' );
        $t = array_values( array_filter( $t, 'post_type_exists' ) );
        return $t ? $t : array( 'post', 'page' );
    }
    /** turn a PHP fatal inside our AJAX calls into a readable message instead of a blank HTTP 500 */
    public static function ajax_safety() {
        if ( ! wp_doing_ajax() || 0 !== strpos( (string) ( $_REQUEST['action'] ?? '' ), 'nra_' ) ) { return; } // phpcs:ignore
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 180 ); } // phpcs:ignore
        @ini_set( 'display_errors', '0' ); // phpcs:ignore
        register_shutdown_function( function () {
            $e = error_get_last();
            if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) { return; }
            while ( ob_get_level() ) { @ob_end_clean(); } // phpcs:ignore
            if ( ! headers_sent() ) { status_header( 200 ); header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); }
            $m = ( false !== stripos( $e['message'], 'Maximum execution time' ) ) ? 'The server time limit was reached while waiting for the AI. Choose a faster model in Settings (for example gpt-4o-mini) and try again.' : ( ( false !== stripos( $e['message'], 'memory' ) ) ? 'The server ran out of memory. Ask your host to raise the PHP memory_limit (256M or more).' : 'PHP error: ' . $e['message'] . ' in ' . basename( $e['file'] ) . ':' . $e['line'] );
            echo wp_json_encode( array( 'success' => false, 'data' => array( 'message' => $m ) ) );
        } );
    }
    /** AJAX nonce check; a background job was already authorised when it was started */
    public static function nonce() { if ( class_exists( 'NRA_Job' ) && NRA_Job::$running ) { return; } check_ajax_referer( 'nra_admin', 'nonce' ); }
    public static function ok( $data = array() ) { wp_send_json_success( $data ); }
    public static function fail( $msg, $extra = array() ) { wp_send_json_error( array_merge( array( 'message' => $msg ), $extra ) ); }

    /** path of a URL relative to the site root, normalised for redirect matching */
    public static function path_of( $url ) {
        $p = wp_parse_url( $url );
        $path = isset( $p['path'] ) ? $p['path'] : '/';
        $home = wp_parse_url( home_url( '/' ) );
        $base = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
        if ( $base && 0 === strpos( $path, $base ) ) { $path = substr( $path, strlen( $base ) ); }
        return '/' . ltrim( $path, '/' );
    }
    public static function ago( $ts ) { return $ts ? human_time_diff( $ts, time() ) . ' ago' : '-'; }
}
