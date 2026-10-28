<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** JSON-LD graph: Organization, WebSite, WebPage, Article, Product, Breadcrumbs, FAQPage */
final class NRA_Schema {

    public static function graph() {
        $g   = array();
        $s   = NRA_Settings::all();
        $home = trailingslashit( home_url() );
        $org_id = $home . '#organization';
        $site_id = $home . '#website';

        // Organization / Person
        $org = array( '@type' => $s['org_type'] ? $s['org_type'] : 'Organization', '@id' => $org_id, 'name' => $s['org_name'] ? $s['org_name'] : get_bloginfo( 'name' ), 'url' => $home );
        if ( $s['org_logo'] ) { $org['logo'] = array( '@type' => 'ImageObject', 'url' => esc_url_raw( $s['org_logo'] ) ); $org['image'] = esc_url_raw( $s['org_logo'] ); }
        if ( $s['org_email'] ) { $org['email'] = $s['org_email']; }
        if ( $s['org_phone'] ) { $org['telephone'] = $s['org_phone']; }
        if ( trim( (string) $s['business'] ) ) { $org['description'] = NRA_Util::cut( trim( $s['business'] ), 300 ); }
        $social = array_values( array_filter( array_map( 'esc_url_raw', preg_split( '/\R/', (string) $s['org_social'] ) ) ) );
        if ( $social ) { $org['sameAs'] = $social; }
        $g[] = $org;

        $web = array( '@type' => 'WebSite', '@id' => $site_id, 'url' => $home, 'name' => get_bloginfo( 'name' ), 'publisher' => array( '@id' => $org_id ), 'inLanguage' => get_bloginfo( 'language' ) );
        if ( get_bloginfo( 'description' ) ) { $web['description'] = get_bloginfo( 'description' ); }
        $web['potentialAction'] = array( '@type' => 'SearchAction', 'target' => array( '@type' => 'EntryPoint', 'urlTemplate' => $home . '?s={search_term_string}' ), 'query-input' => 'required name=search_term_string' );
        $g[] = $web;

        if ( is_singular() ) {
            $p   = get_queried_object();
            if ( ! $p ) { return $g; }
            $url = get_permalink( $p );
            $m   = NRA_Analyzer::meta( $p->ID );
            $page_id = $url . '#webpage';
            $title = NRA_Frontend::document_title();
            $desc  = NRA_Frontend::description();
            $img   = NRA_Frontend::image();
            $page  = array( '@type' => ( is_page() ? 'WebPage' : 'WebPage' ), '@id' => $page_id, 'url' => $url, 'name' => $title, 'isPartOf' => array( '@id' => $site_id ), 'inLanguage' => get_bloginfo( 'language' ), 'datePublished' => get_the_date( 'c', $p ), 'dateModified' => get_the_modified_date( 'c', $p ) );
            if ( $desc ) { $page['description'] = $desc; }
            if ( $img ) { $page['primaryImageOfPage'] = array( '@type' => 'ImageObject', 'url' => $img ); }
            $crumbs = self::breadcrumbs();
            if ( count( $crumbs ) > 1 ) { $page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' ); }
            $g[] = $page;
            if ( count( $crumbs ) > 1 ) {
                $items = array(); $i = 1;
                foreach ( $crumbs as $c ) { $items[] = array( '@type' => 'ListItem', 'position' => $i++, 'name' => $c[0], 'item' => $c[1] ); }
                $g[] = array( '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items );
            }

            $type = $m['schema_type'];
            if ( ! $type ) { $type = ( 'product' === $p->post_type ) ? 'Product' : ( 'post' === $p->post_type ? 'BlogPosting' : '' ); }
            if ( 'none' !== $type && ( 'Article' === $type || 'BlogPosting' === $type || 'NewsArticle' === $type ) ) {
                $a = array( '@type' => $type, '@id' => $url . '#article', 'headline' => NRA_Util::cut( get_the_title( $p ), 110 ), 'mainEntityOfPage' => array( '@id' => $page_id ), 'datePublished' => get_the_date( 'c', $p ), 'dateModified' => get_the_modified_date( 'c', $p ), 'author' => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $p->post_author ), 'url' => get_author_posts_url( $p->post_author ) ), 'publisher' => array( '@id' => $org_id ), 'inLanguage' => get_bloginfo( 'language' ) );
                if ( $desc ) { $a['description'] = $desc; }
                if ( $img ) { $a['image'] = array( $img ); }
                $a['wordCount'] = NRA_Util::word_count( NRA_Util::text( $p->post_content ) );
                if ( $m['kw'] ) { $a['keywords'] = $m['kw']; }
                $g[] = $a;
            } elseif ( 'Product' === $type && function_exists( 'wc_get_product' ) ) {
                $pr = wc_get_product( $p->ID );
                if ( $pr ) {
                    $o = array( '@type' => 'Product', '@id' => $url . '#product', 'name' => get_the_title( $p ), 'url' => $url, 'description' => $desc ? $desc : NRA_Util::cut( NRA_Util::text( $p->post_content ), 300 ), 'brand' => array( '@id' => $org_id ) );
                    if ( $img ) { $o['image'] = array( $img ); }
                    if ( $pr->get_sku() ) { $o['sku'] = $pr->get_sku(); }
                    foreach ( array( 'product_brand', 'pa_brand', 'pwb-brand', 'yith_product_brand' ) as $btx ) {
                        if ( taxonomy_exists( $btx ) ) { $bt = get_the_terms( $p, $btx ); if ( $bt && ! is_wp_error( $bt ) ) { $o['brand'] = array( '@type' => 'Brand', 'name' => $bt[0]->name ); break; } }
                    }
                    if ( method_exists( $pr, 'get_global_unique_id' ) && $pr->get_global_unique_id() ) { $o['gtin'] = $pr->get_global_unique_id(); }
                    $props = array();
                    foreach ( $pr->get_attributes() as $at ) {
                        if ( is_object( $at ) && method_exists( $at, 'get_name' ) && function_exists( 'wc_attribute_label' ) ) { $av = $pr->get_attribute( $at->get_name() ); if ( '' !== $av ) { $props[] = array( '@type' => 'PropertyValue', 'name' => wc_attribute_label( $at->get_name() ), 'value' => $av ); } }
                    }
                    if ( $props ) { $o['additionalProperty'] = array_slice( $props, 0, 20 ); }
                    $price = $pr->get_price();
                    if ( '' !== $price ) {
                        $o['offers'] = array( '@type' => 'Offer', 'url' => $url, 'price' => wc_format_decimal( $price, wc_get_price_decimals() ), 'priceCurrency' => get_woocommerce_currency(), 'availability' => $pr->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'priceValidUntil' => gmdate( 'Y-12-31' ), 'seller' => array( '@id' => $org_id ) );
                    }
                    // only real reviews are ever output
                    if ( $pr->get_review_count() > 0 && (float) $pr->get_average_rating() > 0 ) {
                        $o['aggregateRating'] = array( '@type' => 'AggregateRating', 'ratingValue' => (string) $pr->get_average_rating(), 'reviewCount' => (string) $pr->get_review_count() );
                    }
                    $g[] = $o;
                }
            } elseif ( 'Product' === $type ) {
                $g[] = array( '@type' => 'Product', '@id' => $url . '#product', 'name' => get_the_title( $p ), 'url' => $url, 'description' => $desc, 'brand' => array( '@id' => $org_id ) );
            } elseif ( 'SoftwareApplication' === $type ) {
                $g[] = array( '@type' => 'SoftwareApplication', '@id' => $url . '#software', 'name' => get_the_title( $p ), 'url' => $url, 'description' => $desc, 'applicationCategory' => 'WebApplication', 'publisher' => array( '@id' => $org_id ) );
            }
            $faq = array_values( array_filter( (array) $m['faq'], function ( $x ) { return is_array( $x ) && ! empty( $x['q'] ) && ! empty( $x['a'] ); } ) );
            if ( $faq ) {
                $ents = array();
                foreach ( $faq as $f ) { $ents[] = array( '@type' => 'Question', 'name' => wp_strip_all_tags( $f['q'] ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_kses_post( $f['a'] ) ) ); }
                $g[] = array( '@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => $ents, 'isPartOf' => array( '@id' => $page_id ) );
            }
        } elseif ( is_front_page() || is_home() ) {
            // WebSite already output
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $t = get_queried_object();
            if ( $t && ! is_wp_error( $t ) ) {
                $g[] = array( '@type' => 'CollectionPage', '@id' => get_term_link( $t ) . '#webpage', 'url' => get_term_link( $t ), 'name' => NRA_Frontend::document_title(), 'isPartOf' => array( '@id' => $site_id ) );
            }
        }
        return apply_filters( 'nra_schema_graph', $g );
    }

    public static function breadcrumbs() {
        $c = array( array( get_bloginfo( 'name' ), trailingslashit( home_url() ) ) );
        if ( is_singular() ) {
            $p = get_queried_object();
            $tax = ( 'product' === $p->post_type ) ? 'product_cat' : ( 'post' === $p->post_type ? 'category' : '' );
            if ( $tax && taxonomy_exists( $tax ) ) {
                $terms = get_the_terms( $p, $tax );
                if ( $terms && ! is_wp_error( $terms ) ) {
                    $t = reset( $terms ); $chain = array();
                    while ( $t && ! is_wp_error( $t ) ) { array_unshift( $chain, $t ); $t = $t->parent ? get_term( $t->parent, $tax ) : null; }
                    foreach ( $chain as $t ) { $l = get_term_link( $t ); if ( ! is_wp_error( $l ) ) { $c[] = array( $t->name, $l ); } }
                }
            } elseif ( is_post_type_hierarchical( $p->post_type ) ) {
                foreach ( array_reverse( get_post_ancestors( $p ) ) as $aid ) { $c[] = array( get_the_title( $aid ), get_permalink( $aid ) ); }
            }
            $c[] = array( get_the_title( $p ), get_permalink( $p ) );
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $t = get_queried_object();
            if ( $t && ! is_wp_error( $t ) ) { $l = get_term_link( $t ); if ( ! is_wp_error( $l ) ) { $c[] = array( $t->name, $l ); } }
        }
        return $c;
    }

    public static function render() {
        $g = self::graph();
        if ( ! $g ) { return; }
        echo "<script type=\"application/ld+json\" class=\"nra-schema\">" . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $g ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore
    }
}
