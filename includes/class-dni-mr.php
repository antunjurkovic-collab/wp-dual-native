<?php
if (!defined('ABSPATH')) { exit; }

class DNI_MR {
    /** Build Machine Representation (MR) for a post */
    public static function build(int $post_id): ?array {
        $post = get_post($post_id);
        if (!$post) return null;

        $rid = (int)$post_id;
        $title = get_the_title($post);
        $status = $post->post_status;
        $modified = get_post_modified_time('c', true, $post);
        $published = get_post_time('c', true, $post);
        $author = array(
            'id' => (int)$post->post_author,
            'name' => get_the_author_meta('display_name', $post->post_author),
            'url' => get_author_posts_url($post->post_author),
        );

        $featured_image = null;
        $thumb_id = get_post_thumbnail_id($post);
        if ($thumb_id) {
            $img = wp_get_attachment_image_src($thumb_id, 'full');
            $alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
            if (is_array($img) && !empty($img[0])) {
                $featured_image = array('id'=>(int)$thumb_id,'url'=>$img[0],'alt'=>(string)$alt,'width'=>isset($img[1])?(int)$img[1]:null,'height'=>isset($img[2])?(int)$img[2]:null);
            }
        }

        // Categories and tags
        $categories = array();
        $cats = get_the_category($post_id);
        if (is_array($cats)){
            foreach ($cats as $c){ if ($c instanceof WP_Term){ $categories[] = array('id'=>(int)$c->term_id,'name'=>$c->name,'slug'=>$c->slug,'url'=>get_category_link($c->term_id)); } }
        }
        // Stabilize order for deterministic hashing
        if (!empty($categories)){
            usort($categories, function($a, $b){ return ($a['id'] ?? 0) <=> ($b['id'] ?? 0); });
        }
        $tags = array();
        $tag_terms = get_the_tags($post_id);
        if (is_array($tag_terms)){
            foreach ($tag_terms as $t){ if ($t instanceof WP_Term){ $tags[] = array('id'=>(int)$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'url'=>get_tag_link($t->term_id)); } }
        }
        // Stabilize order for deterministic hashing
        if (!empty($tags)){
            usort($tags, function($a, $b){ return ($a['id'] ?? 0) <=> ($b['id'] ?? 0); });
        }

        // Parse Gutenberg blocks
        $blocks = self::extract_blocks($post);
        $core_text = self::flatten_text($blocks);

        $mr = array(
            'rid' => $rid,
            'title' => $title,
            'status' => $status,
            'modified' => $modified,
            'published' => $published,
            'author' => $author,
            'image' => $featured_image,
            'categories' => $categories,
            'tags' => $tags,
            'word_count' => self::word_count($core_text),
            'core_content_text' => $core_text,
            'blocks' => $blocks,
            'links' => array(
                'human_url'      => get_permalink($post_id),
                'api_url'        => rest_url('dual-native/v1/posts/'.$post_id),
                'md_url'         => rest_url('dual-native/v1/posts/'.$post_id.'/md'),
                'public_api_url' => rest_url('dual-native/v1/public/posts/'.$post_id),
                'public_md_url'  => rest_url('dual-native/v1/public/posts/'.$post_id.'/md'),
            ),
        );
        $mr = apply_filters('dni_mr', $mr, $post_id);
        return $mr;
    }

    private static function flatten_text(array $blocks): string {
        $parts = array();
        foreach ($blocks as $b){
            if (!empty($b['content']) && is_string($b['content'])){ $parts[] = $b['content']; }
            if (!empty($b['items']) && is_array($b['items'])){ $parts[] = implode(' ', array_map('strval', $b['items'])); }
        }
        $txt = trim(implode(' ', $parts));
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('/\s+/u', ' ', $txt);
        return $txt;
    }

    private static function word_count(string $text): int { return max(0, str_word_count($text)); }

    private static function extract_blocks(WP_Post $post): array {
        $blocks = array();
        if (!function_exists('parse_blocks')) return $blocks;
        $parsed = parse_blocks($post->post_content ?? '');
        foreach ($parsed as $blk){ $blocks = array_merge($blocks, self::map_block($blk)); }
        $blocks = apply_filters('dni_blocks', $blocks, $post->ID, $post);
        return $blocks;
    }

    private static function map_block(array $block): array {
        $name = isset($block['blockName']) ? (string)$block['blockName'] : '';
        $html = isset($block['innerHTML']) ? (string)$block['innerHTML'] : '';
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        $out = array();

        $text = trim(wp_strip_all_tags($html, true));
        switch ($name){
            case 'core/paragraph':
                $out[] = array('type'=>'core/paragraph','content'=>$text);
                break;
            case 'core/heading':
                $level = isset($attrs['level']) ? (int)$attrs['level'] : 2;
                $level = max(1, min(6, $level));
                $out[] = array('type'=>'core/heading','level'=>$level,'content'=>$text);
                break;
            case 'core/list':
                $ordered = isset($attrs['ordered']) ? (bool)$attrs['ordered'] : (strpos($html, '<ol') !== false);
                $items = array();
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $m)){
                    foreach ($m[1] as $i){ $it = trim(wp_strip_all_tags($i, true)); if ($it !== '') $items[] = $it; }
                }
                $out[] = array('type'=>'core/list','ordered'=>$ordered,'items'=>$items);
                break;
            case 'core/image':
                $imageId = isset($attrs['id']) ? (int)$attrs['id'] : 0;
                $alt = isset($attrs['alt']) ? (string)$attrs['alt'] : '';
                $src = '';
                if (preg_match('/src=\"([^\"]+)\"/i', $html, $m2)){ $src = $m2[1]; }
                $out[] = array('type'=>'core/image','imageId'=>$imageId,'altText'=>$alt,'url'=>$src ?: null);
                break;
            case 'core/code':
            case 'core/preformatted':
                $out[] = array('type'=>'core/code','content'=>$text);
                break;
            case 'core/quote':
                $out[] = array('type'=>'core/quote','content'=>$text);
                break;
            default:
                if ($text !== '') $out[] = array('type'=>$name ?: 'unknown','content'=>$text);
        }

        // Nested blocks
        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])){
            foreach ($block['innerBlocks'] as $child){ $out = array_merge($out, self::map_block($child)); }
        }
        $out = apply_filters('dni_map_block', $out, $block);
        return $out;
    }
}
