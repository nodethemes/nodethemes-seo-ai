<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Background jobs for slow AI calls.
 *
 * The browser starts a job and then polls for the result, so no single web request has to stay open while a slow
 * model thinks. That keeps hosting time limits (the "Server error 500" after ~30-60 s) out of the picture.
 * A background request (non-blocking loopback) runs the real AJAX handler and stores its JSON answer. If the host cannot do
 * loopback requests, the first poll runs the job itself, which behaves like the old direct call.
 */
final class NRA_Job {
    public static $running = false;
    const TTL = 900;

    /** AJAX actions allowed to run as jobs */
    public static function allowed() {
        return apply_filters( 'nra_job_actions', array(
            'nra_ai_keywords', 'nra_ai_meta', 'nra_ai_short', 'nra_ai_article', 'nra_ai_faq', 'nra_ai_coach', 'nra_ai_autopilot',
            'nra_ai_fix', 'nra_ext_suggest', 'nra_ins_data', 'nra_ins_report', 'nra_ins_rewrite', 'nra_ins_setup', 'nra_ai_outline', 'nra_ai_section', 'nra_links_plan', 'nra_links_suggest_in', 'nra_links_suggest_out',
            'nra_prompt_run', 'nra_prompt_suggest', 'nra_suggest_summary',
        ) );
    }
    public static function init() {
        add_action( 'wp_ajax_nra_job_start', array( __CLASS__, 'start' ) );
        add_action( 'wp_ajax_nra_job_status', array( __CLASS__, 'status' ) );
        add_action( 'wp_ajax_nra_job_worker', array( __CLASS__, 'worker' ) );
        add_action( 'wp_ajax_nopriv_nra_job_worker', array( __CLASS__, 'worker' ) );
    }
    private static function key( $id ) { return 'nra_job_' . preg_replace( '/[^A-Za-z0-9]/', '', $id ); }

    public static function start() {
        check_ajax_referer( 'nra_admin', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) { NRA_Util::fail( 'Not allowed.' ); }
        $action = isset( $_POST['job_action'] ) ? sanitize_key( wp_unslash( $_POST['job_action'] ) ) : '';
        if ( ! in_array( $action, self::allowed(), true ) ) { NRA_Util::fail( 'This action cannot run in the background.' ); }
        $payload = wp_unslash( $_POST ); // phpcs:ignore
        unset( $payload['action'], $payload['job_action'] );
        $id    = wp_generate_password( 20, false );
        $token = wp_generate_password( 32, false );
        set_transient( self::key( $id ), array( 'uid' => get_current_user_id(), 'action' => $action, 'payload' => $payload, 'token' => $token, 'state' => 'queued', 'at' => time() ), self::TTL );
        wp_remote_post( admin_url( 'admin-ajax.php' ), array(
            'blocking' => false, 'timeout' => 1, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'body' => array( 'action' => 'nra_job_worker', 'job' => $id, 'token' => $token ),
            'cookies' => array(),
        ) );
        NRA_Util::ok( array( 'job' => $id ) );
    }

    /** loopback entry: no cookies, protected by the one-time token */
    public static function worker() {
        $id  = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore
        $tok = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore
        $j   = $id ? get_transient( self::key( $id ) ) : false;
        if ( ! $j || ! hash_equals( (string) $j['token'], $tok ) || 'queued' !== $j['state'] ) { wp_die( '', '', array( 'response' => 403 ) ); }
        ignore_user_abort( true );
        self::run( $id, $j );
        exit;
    }

    private static function run( $id, $j ) {
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 330 ); } // phpcs:ignore
        $j['state'] = 'running'; $j['at'] = time();
        set_transient( self::key( $id ), $j, self::TTL );
        // if PHP dies (fatal / time limit) record why
        $done = false;
        register_shutdown_function( function () use ( $id, &$done ) {
            if ( $done ) { return; }
            $e = error_get_last(); $cur = get_transient( self::key( $id ) );
            if ( ! $cur || 'running' !== $cur['state'] ) { return; }
            $fatal = $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
            if ( $fatal ) {
                $m = ( false !== stripos( $e['message'], 'Maximum execution time' ) ) ? 'The server time limit was reached. Choose a faster model in Settings (for example gpt-4o-mini) and try again.' : 'PHP error: ' . $e['message'] . ' in ' . basename( $e['file'] ) . ':' . $e['line'];
                $cur['state'] = 'error'; $cur['message'] = $m; unset( $cur['payload'] );
            } elseif ( empty( $cur['retry'] ) ) {
                // the host ended the background request early: run the job once more inside the next status check
                $cur['state'] = 'queued'; $cur['retry'] = 1; $cur['at'] = time() - 10;
            } else {
                $cur['state'] = 'error'; unset( $cur['payload'] );
                $cur['message'] = 'Your server stopped the job before the AI finished (a hosting time limit). Pick a faster model in Settings, or ask your host to raise max_execution_time and the proxy timeout to 180 seconds.';
            }
            set_transient( self::key( $id ), $cur, self::TTL );
        } );
        wp_set_current_user( (int) $j['uid'] );
        self::$running = true;
        $_POST = $_REQUEST = wp_slash( $j['payload'] ); // phpcs:ignore  (handlers unslash again)
        $die = function () { return function () { throw new NRA_Job_Done(); }; };
        add_filter( 'wp_die_ajax_handler', $die, 99 );
        ob_start();
        $err = '';
        try { do_action( 'wp_ajax_' . $j['action'] ); } catch ( NRA_Job_Done $e ) { /* handler finished normally */ } catch ( Throwable $e ) { $err = $e->getMessage(); }
        $out = ob_get_clean();
        remove_filter( 'wp_die_ajax_handler', $die, 99 );
        self::$running = false; $done = true;
        $res = json_decode( $out, true );
        $cur = get_transient( self::key( $id ) ); if ( ! is_array( $cur ) ) { $cur = $j; }
        unset( $cur['payload'] );
        if ( $err ) { $cur['state'] = 'error'; $cur['message'] = 'PHP error: ' . $err; }
        elseif ( is_array( $res ) && array_key_exists( 'success', $res ) ) { $cur['state'] = $res['success'] ? 'done' : 'error'; $cur['data'] = $res['data'] ?? array(); $cur['message'] = is_array( $res['data'] ?? null ) ? (string) ( $res['data']['message'] ?? '' ) : ''; }
        else { $cur['state'] = 'error'; $cur['message'] = 'The job returned an unexpected answer.'; }
        set_transient( self::key( $id ), $cur, self::TTL );
    }

    public static function status() {
        check_ajax_referer( 'nra_admin', 'nonce' );
        $id = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
        $j  = $id ? get_transient( self::key( $id ) ) : false;
        if ( ! $j || (int) $j['uid'] !== get_current_user_id() ) { NRA_Util::fail( 'This job has expired. Please try again.' ); }
        $age = time() - (int) $j['at'];
        // the loopback never started (some hosts block it): run the job here instead
        if ( 'queued' === $j['state'] && $age >= 6 ) {
            $keep = $_POST; self::run( $id, $j ); $_POST = $_REQUEST = $keep; // phpcs:ignore
            $j = get_transient( self::key( $id ) );
        } elseif ( 'running' === $j['state'] && $age > 345 ) {
            NRA_Util::fail( 'The job took too long and was stopped. Choose a faster model in Settings and try again.' );
        }
        if ( 'done' === $j['state'] ) { delete_transient( self::key( $id ) ); NRA_Util::ok( array( 'state' => 'done', 'data' => $j['data'] ) ); }
        if ( 'error' === $j['state'] ) { delete_transient( self::key( $id ) ); NRA_Util::fail( $j['message'] ? $j['message'] : 'The job failed.' ); }
        NRA_Util::ok( array( 'state' => $j['state'] ) );
    }
}
class NRA_Job_Done extends Exception {}
