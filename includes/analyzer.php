<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** On-page SEO + GEO (AI-answer-readiness) scoring. Pure functions, used by metabox, bulk and dashboard. */
final class NRA_Analyzer {

    /** meta getter with defaults */
    public static function meta( $id ) {
        $g = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, '_nra_' . $k, true ); };
        return array(
            'kw' => $g( 'focuskw' ), 'title' => $g( 'title' ), 'desc' => $g( 'desc' ),
            'noindex' => $g( 'noindex' ), 'nofollow' => $g( 'nofollow' ), 'canonical' => $g( 'canonical' ),
            'og_title' => $g( 'og_title' ), 'og_desc' => $g( 'og_desc' ), 'og_img' => $g( 'og_img' ),
            'cornerstone' => $g( 'cornerstone' ), 'schema_type' => $g( 'schema_type' ),
            'faq' => (array) get_post_meta( $id, '_nra_faq', true ), 'takeaways' => (array) get_post_meta( $id, '_nra_takeaways', true ),
        );
    }

    public static function status_of( $score ) { return $score >= 80 ? 'good' : ( $score >= 50 ? 'ok' : 'bad' ); }

    /** $fix tells the editor which one-click AI helper applies: 'ai' = edit the text, 'ext' = suggest outbound sources, 'link' = internal links, '' = nothing to automate */
    private static function check( &$out, $id, $st, $label, $msg, $w = 1, $fix = '' ) { $out[] = array( 'id' => $id, 'status' => $st, 'label' => $label, 'msg' => $msg, 'w' => $w, 'fix' => ( 'good' === $st ) ? '' : $fix ); }

    /** words that tend to lift click-through (English and Persian) */
    private static function power_words() { return apply_filters( 'nra_power_words', array( 'best', 'ultimate', 'fast', 'faster', 'easy', 'pro', 'free', 'exclusive', 'proven', 'complete', 'guide', 'essential', 'simple', 'powerful', 'top', 'new', 'secret', 'instant', 'effective', 'trusted', 'خرید', 'بهترین', 'رایگان', 'کامل', 'آسان', 'سریع', 'ویژه', 'حرفه‌ای', 'اختصاصی', 'تضمینی', 'جدید', 'راهنمای' ) ); }

    private static function contains( $hay, $needle ) {
        $n = NRA_Util::norm( $needle );
        return '' !== $n && false !== strpos( NRA_Util::norm( $hay ), $n );
    }

    /**
     * @param array $d title, content (html), slug, kw, seo_title, desc, url, excerpt
     * @return array score, status, checks[], geo_score, geo_checks[], stats
     */
    public static function analyze( $d ) {
        $d = array_merge( array( 'title' => '', 'content' => '', 'slug' => '', 'kw' => '', 'seo_title' => '', 'desc' => '', 'excerpt' => '', 'post_id' => 0, 'faq' => array(), 'takeaways' => array(), 'type' => 'post' ), $d );
        if ( $d['post_id'] && 'post' === $d['type'] ) { $d['type'] = (string) get_post_type( $d['post_id'] ); }
        $is_product = ( 'product' === $d['type'] );
        $kw      = trim( $d['kw'] );
        $html    = (string) $d['content'];
        $text    = NRA_Util::text( $html );
        $wc      = NRA_Util::word_count( $text );
        $title   = $d['seo_title'] ? $d['seo_title'] : $d['title'];
        $tl      = NRA_Util::len( $title );
        $dl      = NRA_Util::len( $d['desc'] );
        $c       = array();

        // ---- links / images / headings from html
        $h = array( 'h1' => 0, 'h2' => 0, 'h3' => 0 ); $h2s = array(); $int = 0; $ext = 0; $ext_follow = 0; $longp = 0; $videos = 0; $tables = 0; $imgs = 0; $noalt = 0; $kw_alt = 0; $first = '';
        if ( '' !== trim( $html ) && class_exists( 'DOMDocument' ) ) {
            $prev = libxml_use_internal_errors( true );
            $dom  = new DOMDocument();
            $dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
            libxml_clear_errors(); libxml_use_internal_errors( $prev );
            foreach ( array( 'h1', 'h2', 'h3' ) as $t ) { foreach ( $dom->getElementsByTagName( $t ) as $n ) { $h[ $t ]++; if ( 'h2' === $t || 'h3' === $t ) { $h2s[] = $n->textContent; } } }
            $host = wp_parse_url( home_url(), PHP_URL_HOST );
            foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
                $href = $a->getAttribute( 'href' );
                if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) { continue; }
                $ph = wp_parse_url( $href, PHP_URL_HOST );
                if ( ! $ph || $ph === $host ) { $int++; } else { $ext++; if ( false === strpos( strtolower( $a->getAttribute( 'rel' ) ), 'nofollow' ) ) { $ext_follow++; } }
            }
            foreach ( $dom->getElementsByTagName( 'img' ) as $im ) {
                $imgs++;
                $alt = trim( $im->getAttribute( 'alt' ) );
                if ( '' === $alt ) { $noalt++; } elseif ( $kw && self::contains( $alt, $kw ) ) { $kw_alt++; }
            }
            $ps = $dom->getElementsByTagName( 'p' );
            foreach ( $ps as $pn ) { if ( NRA_Util::word_count( $pn->textContent ) > 120 ) { $longp++; } }
            foreach ( $ps as $pn ) { if ( NRA_Util::word_count( $pn->textContent ) >= 12 ) { $first = $pn->textContent; break; } }
            if ( '' === $first && $ps->length ) { $first = $ps->item( 0 )->textContent; }
            $tables = $dom->getElementsByTagName( 'table' )->length;
            $videos = $dom->getElementsByTagName( 'video' )->length;
            foreach ( $dom->getElementsByTagName( 'iframe' ) as $fr ) { if ( preg_match( '#(youtube|youtu\.be|vimeo|aparat|dailymotion|wistia)#i', $fr->getAttribute( 'src' ) ) ) { $videos++; } }
        }
        if ( '' === $first ) { $first = NRA_Util::cut( $text, 300 ); }

        // ---- SEO checks
        if ( '' === $kw ) {
            self::check( $c, 'kw', 'bad', 'Focus keyword', 'No focus keyword. Use "Find keywords" to let AI suggest one.', 3 );
        } else {
            self::check( $c, 'kw', 'good', 'Focus keyword', 'Set: "' . $kw . '".', 1 );
            self::check( $c, 'kw_title', self::contains( $title, $kw ) ? 'good' : 'bad', 'Keyword in SEO title', self::contains( $title, $kw ) ? 'The title contains the keyword.' : 'Put the focus keyword in the SEO title, ideally near the start.', 3, 'ai' );
            if ( self::contains( $title, $kw ) ) {
                $pos = mb_strpos( NRA_Util::norm( $title ), NRA_Util::norm( $kw ), 0, 'UTF-8' );
                self::check( $c, 'kw_title_pos', ( false !== $pos && $pos <= 25 ) ? 'good' : 'ok', 'Keyword near the title start', ( false !== $pos && $pos <= 25 ) ? 'Keyword appears early in the title.' : 'Moving the keyword closer to the beginning helps click-through.', 1 );
            }
            self::check( $c, 'kw_desc', self::contains( $d['desc'], $kw ) ? 'good' : 'bad', 'Keyword in meta description', self::contains( $d['desc'], $kw ) ? 'Present.' : 'Mention the focus keyword in the meta description.', 2, 'ai' );
            self::check( $c, 'kw_slug', self::contains( str_replace( '-', ' ', rawurldecode( $d['slug'] ) ), $kw ) ? 'good' : 'ok', 'Keyword in URL', self::contains( str_replace( '-', ' ', rawurldecode( $d['slug'] ) ), $kw ) ? 'The slug contains the keyword.' : 'Consider a short URL slug that contains the keyword.', ( $d['post_id'] && 'publish' === get_post_status( $d['post_id'] ) ) ? 0 : 1, ( $d['post_id'] && 'publish' === get_post_status( $d['post_id'] ) ) ? '' : 'ai' );
            self::check( $c, 'kw_intro', self::contains( $first, $kw ) ? 'good' : 'bad', 'Keyword in the introduction', self::contains( $first, $kw ) ? 'Used in the first paragraph.' : 'Use the keyword in the first paragraph.', 2, 'ai' );
            $kc = 0; $nt = NRA_Util::norm( $text ); $nk = NRA_Util::norm( $kw );
            if ( '' !== $nk ) { $kc = substr_count( $nt, $nk ); }
            $dens = $wc ? round( $kc * max( 1, count( NRA_Util::words( $kw ) ) ) / $wc * 100, 2 ) : 0;
            $goal = $wc ? max( 1, (int) round( $wc * 0.01 / max( 1, count( NRA_Util::words( $kw ) ) ) ) ) . '-' . max( 2, (int) round( $wc * 0.015 / max( 1, count( NRA_Util::words( $kw ) ) ) ) ) : '';
            if ( $wc < 50 ) { self::check( $c, 'dens', 'ok', 'Keyword repetition', 'Not enough text to measure.', 1 ); }
            elseif ( 0 === $kc ) { self::check( $c, 'dens', 'bad', 'Keyword repetition', 'The keyword never appears in the text. For ' . $wc . ' words use it about ' . $goal . ' times.', 2, 'ai' ); }
            elseif ( $dens > 3 ) { self::check( $c, 'dens', 'bad', 'Keyword repetition', 'Used ' . $kc . ' times (' . $dens . '%). That looks like stuffing; aim for about ' . $goal . ' uses and vary the wording.', 2, 'ai' ); }
            elseif ( $dens > 2.5 ) { self::check( $c, 'dens', 'ok', 'Keyword repetition', 'Used ' . $kc . ' times (' . $dens . '%). Slightly high; about ' . $goal . ' is ideal.', 1, 'ai' ); }
            elseif ( $dens < 0.5 ) { self::check( $c, 'dens', 'ok', 'Keyword repetition', 'Used only ' . $kc . ' time(s) (' . $dens . '%). For ' . $wc . ' words about ' . $goal . ' is ideal.', 1, 'ai' ); }
            else { self::check( $c, 'dens', 'good', 'Keyword repetition', $kc . ' use(s), ' . $dens . '%. Good.', 1 ); }
            $hh = 0; foreach ( $h2s as $x ) { if ( self::contains( $x, $kw ) ) { $hh++; } }
            if ( $h['h2'] + $h['h3'] > 0 ) { self::check( $c, 'kw_h2', $hh ? 'good' : 'ok', 'Keyword in a subheading', $hh ? 'Found in a subheading.' : 'Use the keyword or a close variation in at least one H2/H3.', 1, 'ai' ); }
            if ( $imgs ) { self::check( $c, 'kw_alt', $kw_alt ? 'good' : 'ok', 'Keyword in image alt', $kw_alt ? 'An image alt contains the keyword.' : 'Add the keyword (naturally) to one image alt text.', 1, 'ai' ); }
        }
        // title length
        if ( 0 === $tl ) { self::check( $c, 'tlen', 'bad', 'SEO title length', 'Missing.', 3, 'ai' ); }
        elseif ( $tl < 30 ) { self::check( $c, 'tlen', 'ok', 'SEO title length', $tl . ' characters. Short, you can use up to about 60.', 1, 'ai' ); }
        elseif ( $tl > 60 ) { self::check( $c, 'tlen', 'ok', 'SEO title length', $tl . ' characters. Google may cut it (about 60 is safe).', 2, 'ai' ); }
        else { self::check( $c, 'tlen', 'good', 'SEO title length', $tl . ' characters. Good.', 1 ); }
        if ( $tl > 0 ) {
            $has_num = (bool) preg_match( '/[0-9۰-۹]/u', $title );
            self::check( $c, 'tnum', $has_num ? 'good' : 'ok', 'Number in title', $has_num ? 'The title contains a number.' : 'A number ("7 ways", "2026") often lifts click-through.', 0, 'ai' );
            $pw = ''; foreach ( self::power_words() as $w0 ) { if ( self::contains( ' ' . $title . ' ', ' ' . $w0 . ' ' ) ) { $pw = $w0; break; } }
            self::check( $c, 'tpower', $pw ? 'good' : 'ok', 'Power word in title', $pw ? 'Uses "' . $pw . '".' : 'A strong word (best, complete, free, fast, ...) makes the result more clickable.', 0, 'ai' );
        }
        if ( 0 === $dl ) { self::check( $c, 'dlen', 'bad', 'Meta description', 'Missing. Google will make up its own snippet.', 3, 'ai' ); }
        elseif ( $dl < 70 ) { self::check( $c, 'dlen', 'ok', 'Meta description length', $dl . ' characters. Use 120-160 to sell the click.', 1, 'ai' ); }
        elseif ( $dl > 160 ) { self::check( $c, 'dlen', 'ok', 'Meta description length', $dl . ' characters. It will be truncated (max about 160).', 1, 'ai' ); }
        else { self::check( $c, 'dlen', 'good', 'Meta description length', $dl . ' characters. Good.', 1 ); }
        // content
        $min = $is_product ? 300 : 600;
        if ( 0 === $wc ) { self::check( $c, 'wc', 'bad', 'Content length', 'No text yet.', 2, 'ai' ); }
        elseif ( $wc < $min ) { self::check( $c, 'wc', 'bad', 'Content length', $wc . ' words. Thin content rarely ranks; aim for at least ' . $min . ' for this content type.', 2, 'ai' ); }
        else { self::check( $c, 'wc', 'good', 'Content length', $wc . ' words.', 1 ); }
        if ( $wc >= 400 ) { self::check( $c, 'struct', ( $h['h2'] >= 2 ) ? 'good' : 'ok', 'Subheadings', ( $h['h2'] >= 2 ) ? $h['h2'] . ' H2 headings structure the text.' : 'Break long text with at least 2 H2 headings.', 1, 'ai' ); }
        if ( $h['h1'] > 0 ) { self::check( $c, 'h1', 'ok', 'H1 inside content', 'The page title is already the H1. Use H2 and below inside the content.', 1 ); }
        // sentences / readability (rough, language-agnostic)
        $sent = NRA_Util::sentences( $text );
        if ( count( $sent ) >= 5 ) {
            $long = 0; foreach ( $sent as $s ) { if ( NRA_Util::word_count( $s ) > 20 ) { $long++; } }
            $pct = round( $long / count( $sent ) * 100 );
            self::check( $c, 'read', $pct <= 25 ? 'good' : ( $pct <= 40 ? 'ok' : 'bad' ), 'Sentence length', $pct . '% of sentences are longer than 20 words (aim for 25% or less).' . ( $pct > 25 ? ' Shorter sentences read (and get quoted) better.' : '' ), 1, 'ai' );
        }
        if ( $wc >= 150 ) {
            self::check( $c, 'para', 0 === $longp ? 'good' : 'ok', 'Paragraph length', 0 === $longp ? 'Paragraphs are short and easy to scan.' : $longp . ' paragraph(s) have more than 120 words. Split them for mobile readers.', 1, 'ai' );
            $has_list = (bool) preg_match( '/<(ul|ol|table)\b/i', $html );
            self::check( $c, 'lists', $has_list ? 'good' : 'ok', 'List or table', $has_list ? 'Lists/tables break up the text.' : 'Add a bullet list, numbered steps or a comparison table.', 1, 'ai' );
        }
        if ( $wc >= 1200 && $h['h2'] >= 4 ) {
            $has_toc = (bool) preg_match( '/\[nra_toc\]|wp-block-table-of-contents|id="toc|class="[^"]*\b(toc|ez-toc)/i', $html );
            self::check( $c, 'toc', $has_toc ? 'good' : 'ok', 'Table of contents', $has_toc ? 'A table of contents helps readers and gives Google jump links.' : 'Long page: add a table of contents (the shortcode [nra_toc] builds one from your headings).', 0, 'ai' );
        }
        // links
        self::check( $c, 'int', $int > 0 ? 'good' : 'bad', 'Internal links', $int > 0 ? $int . ' internal link(s).' : 'No internal links. Use the one-click internal-link helper (or Link Map).', 2, 'link' );
        if ( $wc > 200 ) {
            self::check( $c, 'ext', $ext > 0 ? 'good' : 'ok', 'Outbound links', $ext > 0 ? $ext . ' outbound link(s).' : 'No outbound link. Let AI suggest a few authoritative sources worth citing.', 1, 'ext' );
            if ( $ext > 0 ) { self::check( $c, 'ext_follow', $ext_follow > 0 ? 'good' : 'ok', 'Dofollow outbound link', $ext_follow > 0 ? $ext_follow . ' followed outbound link(s).' : 'All outbound links are nofollow. Make one trusted source normal (dofollow).', 0, 'ext' ); }
        }
        if ( strlen( rawurldecode( $d['slug'] ) ) > 0 ) { $sl = strlen( rawurldecode( $d['slug'] ) ); self::check( $c, 'urllen', $sl <= 75 ? 'good' : 'ok', 'URL length', $sl <= 75 ? $sl . ' characters. Short and clean.' : $sl . ' characters. Shorter URLs (under 75) are easier to share and rank.', 0, ( $d['post_id'] && 'publish' === get_post_status( $d['post_id'] ) ) ? '' : 'ai' ); }
        if ( $kw && $d['post_id'] ) {
            global $wpdb;
            $dup = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_nra_focuskw' AND LOWER(m.meta_value)=LOWER(%s) AND m.post_id<>%d AND p.post_status IN ('publish','draft','pending','future') LIMIT 5", $kw, (int) $d['post_id'] ) );
            self::check( $c, 'cannibal', $dup ? 'bad' : 'good', 'Unique keyword', $dup ? 'Same focus keyword is used on: ' . implode( ', ', array_map( function ( $i ) { return '#' . $i . ' ' . wp_strip_all_tags( get_the_title( $i ) ); }, $dup ) ) . '. Pages compete with each other; pick a different keyword or merge them.' : 'No other page targets this keyword.', 1 );
        }
        if ( $imgs ) { self::check( $c, 'alt', 0 === $noalt ? 'good' : 'bad', 'Image alt text', 0 === $noalt ? 'All ' . $imgs . ' image(s) have alt text.' : $noalt . ' of ' . $imgs . ' image(s) have no alt text.', 1, 'ai' ); }
        elseif ( $wc > 200 ) { self::check( $c, 'img', $videos ? 'good' : 'ok', 'Images / video', $videos ? 'A video is embedded.' : 'No image or video in the content. Add at least one (featured image counts for themes, but in-content media helps readers).', 0 ); }
        if ( $d['post_id'] ) {
            $inc = self::inbound_count( (int) $d['post_id'] );
            if ( null !== $inc ) { self::check( $c, 'inbound', $inc > 0 ? 'good' : 'bad', 'Links pointing to this page', $inc > 0 ? $inc . ' page(s) link here.' : 'Orphan page: no other page links here. Open Link Map.', 2 ); }
        }

        // ---- GEO checks (AI answer readiness)
        $g = array();
        $first150 = NRA_Util::cut( $text, 600 );
        $kwin = $kw ? self::contains( $first150, $kw ) : false;
        $fw = NRA_Util::word_count( $first );
        $ans_ok = ( $fw >= 35 && $fw <= 70 && ( ! $kw || self::contains( $first, $kw ) ) );
        self::check( $g, 'g_answer', $ans_ok ? 'good' : 'ok', 'Direct answer up front', $ans_ok ? 'The opening paragraph (' . $fw . ' words) answers the topic directly.' : 'Open with a 40-60 word paragraph that names the topic and answers it directly (now ' . $fw . ' words). AI engines quote openings.', 3, 'ai' );
        $q = 0; foreach ( $h2s as $x ) { if ( preg_match( '/[?؟]|^(how|what|why|when|which|who|can|is|does|do|are|should|چگونه|چطور|چیست|چرا|آیا|کدام)\b/iu', trim( $x ) ) ) { $q++; } }
        $faq = array_filter( (array) $d['faq'], function ( $x ) { return is_array( $x ) && ! empty( $x['q'] ) && ! empty( $x['a'] ); } );
        self::check( $g, 'g_faq', ( count( $faq ) >= 3 ) ? 'good' : ( ( count( $faq ) + $q ) >= 2 ? 'ok' : 'bad' ), 'Question-style content / FAQ', count( $faq ) >= 3 ? count( $faq ) . ' FAQ items ready (also output as FAQ schema).' : 'Add 3-6 real customer questions with short answers (use "Generate FAQ").', 3, 'ai' );
        $tk = array_filter( (array) $d['takeaways'] );
        self::check( $g, 'g_take', count( $tk ) >= 3 ? 'good' : 'ok', 'Key takeaways', count( $tk ) >= 3 ? count( $tk ) . ' takeaways.' : 'A short bullet summary is easy for AI to lift. Use "Generate takeaways".', 2, 'ai' );
        $lists = (int) preg_match_all( '/<(ul|ol|table)\b/i', $html );
        self::check( $g, 'g_struct', ( $lists > 0 && $h['h2'] >= 2 ) ? 'good' : 'ok', 'Extractable structure', ( $lists > 0 && $h['h2'] >= 2 ) ? 'Headings plus lists/tables.' : 'Use H2 headings plus at least one list or table.', 2, 'ai' );
        self::check( $g, 'g_table', $tables > 0 ? 'good' : 'ok', 'Data table', $tables > 0 ? 'A table gives AI engines clean data to quote.' : 'Add a comparison or specification table. AI answer engines love extracting from tables.', 1, 'ai' );
        $facts = (int) preg_match_all( '/\d[\d.,]*\s?(%|\$|€|USD|MB|GB|kg|cm|mm|days?|years?|hours?|minutes?)|[$€£]\s?\d/iu', $text );
        self::check( $g, 'g_facts', $facts >= 3 ? 'good' : 'ok', 'Concrete facts', $facts >= 3 ? 'Contains specific figures.' : 'AI engines prefer pages with specific, verifiable numbers and details (only real ones).', 0 );
        self::check( $g, 'g_cite', $ext > 0 ? 'good' : 'ok', 'Cited sources', $ext > 0 ? 'Outbound references present.' : 'Citing a primary source makes the page more trustworthy to answer engines.', 0, 'ext' );
        $upd = $d['post_id'] ? strtotime( get_post_field( 'post_modified_gmt', $d['post_id'] ) . ' UTC' ) : 0;
        if ( $upd ) { $months = ( time() - $upd ) / 2592000; self::check( $g, 'g_fresh', $months < 12 ? 'good' : 'ok', 'Freshness', $months < 12 ? 'Updated within the last year.' : 'Not updated for ' . floor( $months ) . ' months. Refresh facts and republish.', 0 ); }
        $ent = ( NRA_Settings::get( 'org_name' ) || get_bloginfo( 'name' ) );
        self::check( $g, 'g_entity', ( $ent && NRA_Settings::get( 'business' ) ) ? 'good' : 'ok', 'Brand / entity clarity', ( $ent && NRA_Settings::get( 'business' ) ) ? 'Business description is set, so Organization schema and llms.txt can describe you.' : 'Fill "About the business" in Settings so AI engines learn who you are.', 0 );

        $res = array( 'checks' => $c, 'geo_checks' => $g );
        $res['score']     = self::score( $c );
        $res['geo_score'] = self::score( $g );
        $res['status']    = self::status_of( $res['score'] );
        $res['geo_status'] = self::status_of( $res['geo_score'] );
        $res['stats']     = array( 'type' => $d['type'], 'words' => $wc, 'internal' => $int, 'external' => $ext, 'images' => $imgs, 'h2' => $h['h2'], 'title_len' => $tl, 'desc_len' => $dl );
        return $res;
    }

    private static function score( $checks ) {
        $tot = 0; $got = 0;
        foreach ( $checks as $c ) {
            if ( 0 === $c['w'] ) { continue; }
            $tot += $c['w'] * 2;
            $got += $c['w'] * ( 'good' === $c['status'] ? 2 : ( 'ok' === $c['status'] ? 1 : 0 ) );
        }
        return $tot ? (int) round( $got / $tot * 100 ) : 0;
    }

    public static function inbound_count( $id ) {
        global $wpdb;
        $t = $wpdb->prefix . 'nra_links';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return null; }
        if ( ! get_option( 'nra_graph_built' ) ) { return null; }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT src_id) FROM {$t} WHERE dst_id = %d", $id ) );
    }

    /** analyze a stored post and cache the scores */
    public static function analyze_post( $post, $save = true ) {
        $post = get_post( $post );
        if ( ! $post ) { return null; }
        $m = self::meta( $post->ID );
        $r = self::analyze( array(
            'post_id' => $post->ID, 'title' => $post->post_title, 'content' => do_shortcode_safe( $post->post_content ), 'slug' => $post->post_name,
            'kw' => $m['kw'], 'seo_title' => $m['title'], 'desc' => $m['desc'], 'faq' => $m['faq'], 'takeaways' => $m['takeaways'],
        ) );
        if ( $save ) { update_post_meta( $post->ID, '_nra_score', $r['score'] ); update_post_meta( $post->ID, '_nra_geo_score', $r['geo_score'] ); }
        return $r;
    }
}

/** keep analysis free of side effects from shortcodes: strip them instead of running them */
function do_shortcode_safe( $content ) { return strip_shortcodes( (string) $content ); }
