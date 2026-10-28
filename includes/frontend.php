<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** front-end output: title, description, robots, canonical, Open Graph, Twitter, schema, FAQ/takeaway blocks */
final class NRA_Frontend {

    public static function init() {
        if ( ! NRA_Settings::on( 'mod_meta' ) ) { return; }
        add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ), 99 );
        add_filter( 'document_title_separator', function ( $s ) { $x = NRA_Settings::get( 'sep' ); return $x ? $x : $s; }, 99 );
        add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
        add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
        add_filter( 'get_canonical_url', array( __CLASS__, 'canonical_filter' ), 99, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'attachment_redirect' ), 1 );
        add_filter( 'the_content', array( __CLASS__, 'append_blocks' ), 20 );
        add_filter( 'wp_insert_post_data', array( __CLASS__, 'auto_slug' ), 10, 2 );
        if ( NRA_Settings::on( 'mod_schema' ) ) {
            // our graph already has the breadcrumb trail; drop WooCommerce's second BreadcrumbList
            add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_empty_array', 99 );
        }
        if ( ! NRA_Settings::on( 'force_output' ) ) {
            // remove core canonical/robots duplicates where we output our own
            remove_action( 'wp_head', 'rel_canonical' );
        }
        remove_action( 'wp_head', 'wp_generator' );
    }

    /** the WooCommerce Shop page, when the shop archive is shown (its SEO box holds the shop's title and description) */
    private static function shop_page() {
        if ( ! function_exists( 'is_shop' ) || ! function_exists( 'wc_get_page_id' ) || ! is_shop() ) { return 0; }
        $id = (int) wc_get_page_id( 'shop' );
        return $id > 0 ? $id : 0;
    }

    // ------------------------------------------------------------ templating
    private static function vars() {
        $v = array(
            '%sitename%' => get_bloginfo( 'name' ), '%tagline%' => get_bloginfo( 'description' ), '%sep%' => NRA_Settings::get( 'sep' ) ? NRA_Settings::get( 'sep' ) : '-',
            '%year%' => gmdate( 'Y' ), '%title%' => '', '%term%' => '', '%archive%' => '', '%category%' => '', '%focuskw%' => '', '%author%' => '',
        );
        if ( is_singular() ) {
            $p = get_queried_object();
            if ( $p ) {
                $v['%title%'] = get_the_title( $p );
                $v['%focuskw%'] = (string) get_post_meta( $p->ID, '_nra_focuskw', true );
                $v['%author%'] = get_the_author_meta( 'display_name', $p->post_author );
                $cats = get_the_category( $p->ID );
                if ( $cats ) { $v['%category%'] = $cats[0]->name; }
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $t = get_queried_object();
            if ( $t && ! is_wp_error( $t ) ) { $v['%term%'] = $t->name; }
        } elseif ( is_post_type_archive() ) {
            $v['%archive%'] = post_type_archive_title( '', false );
        } elseif ( is_author() ) {
            $v['%archive%'] = get_the_author_meta( 'display_name', get_queried_object_id() );
        } elseif ( is_search() ) {
            $v['%archive%'] = sprintf( 'Search: %s', get_search_query() );
        }
        return $v;
    }
    private static function expand( $tpl ) {
        $out = strtr( $tpl, self::vars() );
        $sep = preg_quote( NRA_Settings::get( 'sep' ) ? NRA_Settings::get( 'sep' ) : '-', '/' );
        $out = preg_replace( '/\s+/', ' ', $out );
        $out = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $out ); // dangling separators from empty vars
        return trim( wp_strip_all_tags( $out ) );
    }

    // ------------------------------------------------------------ title
    public static function document_title() {
        if ( is_singular() ) {
            $p = get_queried_object();
            $custom = $p ? (string) get_post_meta( $p->ID, '_nra_title', true ) : '';
            if ( $custom ) { return self::expand( $custom ); }
            return self::expand( NRA_Settings::get( 'tpl_single' ) );
        }
        if ( is_front_page() || is_home() ) {
            $pid = (int) get_option( 'page_on_front' );
            if ( is_front_page() && $pid && is_singular() ) { return self::expand( NRA_Settings::get( 'tpl_single' ) ); }
            return self::expand( NRA_Settings::get( 'tpl_home' ) );
        }
        if ( is_category() || is_tag() || is_tax() ) {
            $t = get_queried_object();
            $custom = ( $t && ! is_wp_error( $t ) ) ? (string) get_term_meta( $t->term_id, 'nra_title', true ) : '';
            return self::expand( $custom ? $custom : NRA_Settings::get( 'tpl_term' ) );
        }
        if ( is_404() ) { return self::expand( 'Page not found %sep% %sitename%' ); }
        $shop = self::shop_page();
        if ( $shop ) {
            $custom = (string) get_post_meta( $shop, '_nra_title', true );
            if ( $custom ) { return self::expand( $custom ); }
        }
        return self::expand( NRA_Settings::get( 'tpl_archive' ) );
    }
    public static function filter_title( $t ) {
        $x = self::document_title();
        return $x ? $x : $t;
    }

    // ------------------------------------------------------------ description
    public static function description() {
        $d = '';
        if ( is_singular() ) {
            $p = get_queried_object();
            if ( $p ) {
                $d = (string) get_post_meta( $p->ID, '_nra_desc', true );
                if ( ! $d ) { $d = $p->post_excerpt ? $p->post_excerpt : NRA_Util::text( $p->post_content ); }
            }
        } elseif ( is_front_page() || is_home() ) {
            $d = NRA_Settings::get( 'home_desc' ); if ( ! $d ) { $d = get_bloginfo( 'description' ); }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $t = get_queried_object();
            if ( $t && ! is_wp_error( $t ) ) { $d = (string) get_term_meta( $t->term_id, 'nra_desc', true ); if ( ! $d ) { $d = wp_strip_all_tags( term_description( $t->term_id ) ); } }
        } elseif ( self::shop_page() ) {
            $shop = get_post( self::shop_page() );
            $d    = (string) get_post_meta( $shop->ID, '_nra_desc', true );
            if ( ! $d && $shop->post_excerpt ) { $d = $shop->post_excerpt; }
        }
        $d = trim( preg_replace( '/\s+/', ' ', strtr( wp_strip_all_tags( $d ), self::vars() ) ) );
        return NRA_Util::len( $d ) > 160 ? NRA_Util::cut( $d, 157, '...' ) : $d;
    }

    public static function image() {
        if ( is_singular() ) {
            $p = get_queried_object();
            if ( $p ) {
                $custom = (string) get_post_meta( $p->ID, '_nra_og_img', true );
                if ( $custom ) { return $custom; }
                $u = get_the_post_thumbnail_url( $p, 'large' );
                if ( $u ) { return $u; }
            }
        }
        $d = NRA_Settings::get( 'default_og' );
        return $d ? $d : ( NRA_Settings::get( 'org_logo' ) ? NRA_Settings::get( 'org_logo' ) : '' );
    }

    // ------------------------------------------------------------ canonical / robots
    public static function canonical() {
        if ( is_singular() ) {
            $p = get_queried_object();
            if ( $p ) {
                $c = (string) get_post_meta( $p->ID, '_nra_canonical', true );
                if ( $c ) { return esc_url_raw( $c ); }
                $u = get_permalink( $p );
                $pg = (int) get_query_var( 'page' );
                return $pg > 1 ? trailingslashit( $u ) . user_trailingslashit( $pg, 'single_paged' ) : $u;
            }
        }
        if ( is_front_page() ) { return trailingslashit( home_url() ); }
        if ( is_category() || is_tag() || is_tax() ) { $t = get_queried_object(); if ( $t && ! is_wp_error( $t ) ) { $l = get_term_link( $t ); if ( ! is_wp_error( $l ) ) { $pg = (int) get_query_var( 'paged' ); return $pg > 1 ? get_pagenum_link( $pg ) : $l; } } }
        if ( self::shop_page() ) { $pg = (int) get_query_var( 'paged' ); return $pg > 1 ? get_pagenum_link( $pg ) : get_permalink( self::shop_page() ); }
        if ( is_home() || is_archive() ) { $pg = (int) get_query_var( 'paged' ); return $pg > 1 ? get_pagenum_link( $pg ) : ''; }
        return '';
    }
    public static function canonical_filter( $url, $post ) {
        $c = $post ? (string) get_post_meta( $post->ID, '_nra_canonical', true ) : '';
        return $c ? esc_url_raw( $c ) : $url;
    }
    public static function robots( $r ) {
        if ( is_singular() ) {
            $p = get_queried_object();
            if ( $p ) {
                if ( get_post_meta( $p->ID, '_nra_noindex', true ) ) { $r['noindex'] = true; unset( $r['max-image-preview'] ); }
                if ( get_post_meta( $p->ID, '_nra_nofollow', true ) ) { $r['nofollow'] = true; }
            }
        } elseif ( is_search() && NRA_Settings::on( 'noindex_search' ) ) { $r['noindex'] = true; $r['follow'] = true; }
        elseif ( ( is_tag() || is_tax( 'product_tag' ) ) && NRA_Settings::on( 'noindex_tags' ) ) { $r['noindex'] = true; $r['follow'] = true; }
        elseif ( self::shop_page() && get_post_meta( self::shop_page(), '_nra_noindex', true ) ) { $r['noindex'] = true; $r['follow'] = true; }
        elseif ( is_author() && NRA_Settings::on( 'noindex_author' ) ) { $r['noindex'] = true; $r['follow'] = true; }
        elseif ( is_404() ) { $r['noindex'] = true; }
        if ( empty( $r['noindex'] ) ) { $r['max-snippet'] = '-1'; $r['max-video-preview'] = '-1'; $r['max-image-preview'] = 'large'; }
        return $r;
    }

    public static function attachment_redirect() {
        if ( ! NRA_Settings::on( 'attachment_redirect' ) || ! is_attachment() ) { return; }
        $p = get_queried_object();
        $to = ( $p && $p->post_parent ) ? get_permalink( $p->post_parent ) : home_url( '/' );
        wp_safe_redirect( $to, 301 );
        exit;
    }

    // ------------------------------------------------------------ head
    public static function head() {
        if ( is_admin() || is_feed() || is_robots() || is_embed() ) { return; }
        echo "\n<!-- NodeThemes SEO AI v" . esc_html( NRA_VERSION ) . " -->\n";
        $desc = self::description();
        if ( $desc ) { echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n"; }
        $can = self::canonical();
        if ( $can ) { echo '<link rel="canonical" href="' . esc_url( $can ) . "\">\n"; }
        $title = self::document_title();
        $type = is_singular() ? 'article' : 'website';
        $og_t = $title; $og_d = $desc;
        if ( is_singular() ) {
            $p = get_queried_object();
            if ( $p ) {
                $x = (string) get_post_meta( $p->ID, '_nra_og_title', true ); if ( $x ) { $og_t = $x; }
                $x = (string) get_post_meta( $p->ID, '_nra_og_desc', true ); if ( $x ) { $og_d = $x; }
                if ( 'product' === $p->post_type ) { $type = 'product'; }
            }
        }
        $img = self::image();
        $m = array(
            array( 'property', 'og:locale', get_locale() ), array( 'property', 'og:type', $type ), array( 'property', 'og:title', $og_t ),
            array( 'property', 'og:description', $og_d ), array( 'property', 'og:url', $can ? $can : '' ), array( 'property', 'og:site_name', get_bloginfo( 'name' ) ),
            array( 'property', 'og:image', $img ), array( 'name', 'twitter:card', $img ? 'summary_large_image' : 'summary' ),
            array( 'name', 'twitter:title', $og_t ), array( 'name', 'twitter:description', $og_d ), array( 'name', 'twitter:image', $img ),
        );
        $tw = NRA_Settings::get( 'twitter' );
        if ( $tw ) { $m[] = array( 'name', 'twitter:site', '@' . ltrim( $tw, '@' ) ); }
        if ( is_singular( 'post' ) ) {
            $m[] = array( 'property', 'article:published_time', get_the_date( 'c' ) );
            $m[] = array( 'property', 'article:modified_time', get_the_modified_date( 'c' ) );
        }
        foreach ( $m as $row ) { if ( '' !== (string) $row[2] ) { echo '<meta ' . $row[0] . '="' . esc_attr( $row[1] ) . '" content="' . esc_attr( $row[2] ) . "\">\n"; } }
        if ( NRA_Settings::on( 'mod_schema' ) ) { NRA_Schema::render(); }
        echo "<!-- / NodeThemes SEO AI -->\n\n";
    }

    // ------------------------------------------------------------ content blocks
    public static function append_blocks( $content ) {
        if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) { return $content; }
        $p = get_queried_object();
        if ( ! $p || ! in_array( $p->post_type, (array) NRA_Settings::get( 'post_types' ), true ) ) { return $content; }
        $m = NRA_Analyzer::meta( $p->ID );
        $pre = ''; $post = '';
        if ( NRA_Settings::on( 'append_takeaways' ) ) {
            $tk = array_filter( array_map( 'trim', array_map( 'strval', $m['takeaways'] ) ) );
            if ( $tk ) { $pre = '<div class="nra-takeaways"><strong>' . esc_html__( 'Key takeaways', 'nodethemes-seo-ai' ) . '</strong><ul>'; foreach ( $tk as $t ) { $pre .= '<li>' . esc_html( $t ) . '</li>'; } $pre .= '</ul></div>'; }
        }
        if ( NRA_Settings::on( 'append_faq' ) ) {
            $faq = array_filter( (array) $m['faq'], function ( $x ) { return is_array( $x ) && ! empty( $x['q'] ) && ! empty( $x['a'] ); } );
            if ( $faq ) {
                $post = '<div class="nra-faq"><h2>' . esc_html__( 'Frequently asked questions', 'nodethemes-seo-ai' ) . '</h2>';
                foreach ( $faq as $f ) { $post .= '<h3>' . esc_html( $f['q'] ) . '</h3><p>' . wp_kses_post( $f['a'] ) . '</p>'; }
                $post .= '</div>';
            }
        }
        return $pre . $content . $post;
    }

    // ------------------------------------------------------------ slug
    public static function auto_slug( $data, $postarr ) {
        if ( ! NRA_Settings::on( 'mod_autoslug' ) ) { return $data; }
        if ( ! in_array( $data['post_type'], (array) NRA_Settings::get( 'post_types' ), true ) ) { return $data; }
        if ( ! empty( $data['post_name'] ) || 'auto-draft' === $data['post_status'] ) { return $data; }
        $kw = ! empty( $postarr['ID'] ) ? (string) get_post_meta( $postarr['ID'], '_nra_focuskw', true ) : '';
        if ( $kw && ! NRA_Util::is_latin( $kw ) ) { return $data; } // let WP handle non-latin slugs
        if ( $kw ) { $data['post_name'] = sanitize_title( $kw ); }
        return $data;
    }
}
