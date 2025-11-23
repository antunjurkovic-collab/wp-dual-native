<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/class-dni-mr.php';
require_once __DIR__ . '/class-dni-cid.php';

class DNI_REST {
    public static function init(){
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(){
        // MR (JSON)
        register_rest_route('dual-native/v1', '/posts/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post_mr'],
            'permission_callback' => function($req){
                $id=(int)$req['id'];
                $allow = current_user_can('edit_post', $id);
                return apply_filters('dni_can_read_mr', $allow, $id, $req);
            },
            'args' => array(),
        ));

        // MR (Markdown)
        register_rest_route('dual-native/v1', '/posts/(?P<id>\d+)/md', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post_md'],
            'permission_callback' => function($req){
                $id=(int)$req['id'];
                $allow = current_user_can('edit_post', $id);
                return apply_filters('dni_can_read_mr', $allow, $id, $req);
            },
        ));

        // Public read-only MR (published only, can be relaxed via filter)
        register_rest_route('dual-native/v1', '/public/posts/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post_mr'],
            'permission_callback' => function($req){
                $id=(int)$req['id'];
                $post = get_post($id);
                $allow = ($post && $post->post_status === 'publish');
                return apply_filters('dni_can_read_public_mr', $allow, $id, $req);
            },
        ));
        register_rest_route('dual-native/v1', '/public/posts/(?P<id>\d+)/md', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post_md'],
            'permission_callback' => function($req){
                $id=(int)$req['id'];
                $post = get_post($id);
                $allow = ($post && $post->post_status === 'publish');
                return apply_filters('dni_can_read_public_mr', $allow, $id, $req);
            },
        ));

        register_rest_route('dual-native/v1', '/catalog', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_catalog'],
            'permission_callback' => function($req){ return current_user_can('edit_posts'); },
            'args' => array(
                'since' => array('required'=>false),
                'status' => array('required'=>false),
                'types' => array('required'=>false),
            ),
        ));

        // Insert blocks
        register_rest_route('dual-native/v1', '/posts/(?P<id>\d+)/blocks', array(
            'methods' => 'POST',
            'callback' => [__CLASS__, 'post_insert_block'],
            'permission_callback' => function($req){ $id=(int)$req['id']; return current_user_can('edit_post', $id); },
            'args' => array(),
        ));

        // AI suggestions (summary + tags) – simple heuristic
        register_rest_route('dual-native/v1', '/posts/(?P<id>\d+)/ai/suggest', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'ai_suggest'],
            'permission_callback' => function($req){ $id=(int)$req['id']; return current_user_can('edit_post', $id); },
        ));
    }

    public static function get_post_mr(WP_REST_Request $req){
        $id = (int)$req['id'];
        $mr = DNI_MR::build($id);
        if (!$mr) return new WP_REST_Response(array('error'=>'not_found'), 404);

        // Compute/Load CID and attach
        $cid = get_post_meta($id, '_dni_cid', true);
        if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid); }
        $mr['cid'] = $cid;

        // Conditional GET
        $inm = (string)$req->get_header('if-none-match');
        if ($inm === '' && isset($_SERVER['HTTP_IF_NONE_MATCH'])) { $inm = trim((string)$_SERVER['HTTP_IF_NONE_MATCH']); }
        $matches = false;
        if ($inm){
            foreach (explode(',', $inm) as $tok){
                $t = trim($tok);
                if (stripos($t, 'W/') === 0) { $t = trim(substr($t, 2)); }
                if (strlen($t) >= 2 && $t[0] === '"' && substr($t, -1) === '"') { $t = substr($t, 1, -1); }
                if ($t === $cid){ $matches = true; break; }
            }
        }
        $status = $matches ? 304 : 200;
        $resp = new WP_REST_Response($matches ? null : $mr, $status);
        $resp->header('ETag', '"' . $cid . '"');
        if (!empty($mr['modified'])){ $resp->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($mr['modified'])) . ' GMT'); }
        $resp->header('Cache-Control', 'max-age=0, must-revalidate');
        return $resp;
    }

    public static function get_post_md(WP_REST_Request $req){
        $id = (int)$req['id'];
        $mr = DNI_MR::build($id);
        if (!$mr) return new WP_REST_Response(array('error'=>'not_found'), 404);
        $md = self::to_markdown($mr);
        // Apply markdown filters BEFORE computing ETag/digest
        $md = apply_filters('dni_markdown', $md, $mr, $req);
        $etag = 'sha256-' . hash('sha256', $md);
        $inm = (string)$req->get_header('if-none-match');
        if ($inm === '' && isset($_SERVER['HTTP_IF_NONE_MATCH'])) { $inm = trim((string)$_SERVER['HTTP_IF_NONE_MATCH']); }
        $match = false;
        if ($inm){
            foreach (explode(',', $inm) as $tok){ $t=trim($tok); if (stripos($t,'W/')===0){ $t=trim(substr($t,2)); }
                if (strlen($t)>=2 && $t[0]=='"' && substr($t,-1)=='"'){ $t=substr($t,1,-1);} if ($t===$etag){ $match=true; break; } }
        }
        $status = $match ? 304 : 200;
        $resp = new WP_REST_Response($match?null:$md, $status);
        $resp->header('Content-Type', 'text/markdown; charset=UTF-8');
        $resp->header('ETag', '"'.$etag.'"');
        if (!empty($mr['modified'])){ $resp->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($mr['modified'])) . ' GMT'); }
        $resp->header('Cache-Control', 'max-age=0, must-revalidate');
        return $resp;
    }

    public static function get_catalog(WP_REST_Request $req){
        $since = $req->get_param('since');
        $status = $req->get_param('status'); // draft|publish|any
        $types  = $req->get_param('types');  // comma list or empty

        $post_status = in_array($status, array('draft','publish','any'), true) ? $status : 'any';
        $post_types = array('post','page');
        if (is_string($types) && trim($types) !== ''){ $post_types = array_map('trim', explode(',', $types)); }

        $args = array(
            'post_type' => $post_types,
            'post_status' => $post_status,
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        );
        $args = apply_filters('dni_catalog_args', $args, $req);
        if ($since){
            $args['date_query'] = array(array('column'=>'post_modified_gmt','after'=>$since));
        }
        $ids = get_posts($args);
        $items = array();
        foreach ((array)$ids as $id){
            if (!current_user_can('edit_post', $id)) continue;
            $mr = DNI_MR::build((int)$id);
            if (!$mr) continue;
            $cid = get_post_meta($id, '_dni_cid', true);
            if (!$cid){ $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid); }
            $items[] = array(
                'rid' => (int)$id,
                'cid' => $cid,
                'modified' => $mr['modified'],
                'status' => $mr['status'],
                'title' => $mr['title'],
            );
        }
        return array('count'=>count($items),'items'=>$items);
    }

    public static function post_insert_block(WP_REST_Request $req){
        $id = (int)$req['id'];
        $body = $req->get_json_params();
        if (!is_array($body)) return new WP_REST_Response(array('error'=>'invalid_json'), 400);

        // Optional: Safe-write guard via If-Match (current CID)
        $ifm = (string)$req->get_header('if-match');
        if ($ifm !== ''){
            $current_mr = DNI_MR::build($id);
            if ($current_mr){
                $cur = get_post_meta($id, '_dni_cid', true);
                if (!$cur){ $cur = DNI_CID::compute($current_mr); update_post_meta($id, '_dni_cid', $cur); }
                $ok = false;
                foreach (explode(',', $ifm) as $tok){
                    $t = trim($tok);
                    if ($t === '*'){ $ok = true; break; }
                    if (stripos($t, 'W/') === 0) { $t = trim(substr($t, 2)); }
                    if (strlen($t) >= 2 && $t[0] === '"' && substr($t, -1) === '"') { $t = substr($t, 1, -1); }
                    if ($t === $cur){ $ok = true; break; }
                }
                if (!$ok){
                    $resp = new WP_REST_Response(array('error'=>'precondition_failed','message'=>'If-Match did not match current CID'), 412);
                    $resp->header('ETag', '"'.$cur.'"');
                    return $resp;
                }
            }
        }

        $insert_where = isset($body['insert']) ? (string)$body['insert'] : 'append'; // append|prepend|index
        $index = isset($body['index']) ? max(0, (int)$body['index']) : null;
        $blocks_spec = array();
        if (isset($body['blocks']) && is_array($body['blocks'])){ $blocks_spec = $body['blocks']; }
        elseif (isset($body['block']) && is_array($body['block'])){ $blocks_spec = array($body['block']); }
        if (empty($blocks_spec)) return new WP_REST_Response(array('error'=>'missing_block'), 400);

        $html = '';
        foreach ($blocks_spec as $b){ $h = self::render_block_html($b); if ($h==='') return new WP_REST_Response(array('error'=>'unsupported_block'), 422); $html .= ($html?"\n\n":"").$h; }

        $post = get_post($id);
        if (!$post) return new WP_REST_Response(array('error'=>'not_found'), 404);

        $content = (string)$post->post_content;
        // Determine top-level block count BEFORE insertion and effective insertion index
        $count_before = 0; $inserted_at = 0;
        if (function_exists('parse_blocks')){
            $parsed_before = parse_blocks($content);
            if (is_array($parsed_before)) { $count_before = count($parsed_before); }
        }
        if ($insert_where === 'index'){
            $inserted_at = is_null($index) ? $count_before : min(max(0,$index), $count_before);
        } elseif ($insert_where === 'prepend'){
            $inserted_at = 0;
        } else { // append
            $inserted_at = $count_before;
        }

        if ($insert_where === 'index' && function_exists('parse_blocks') && function_exists('serialize_blocks')){
            $parsed = parse_blocks($content);
            $idx = $inserted_at;
            $head = array_slice($parsed, 0, $idx);
            $tail = array_slice($parsed, $idx);
            $new_content = serialize_blocks($head) . "\n\n" . $html . "\n\n" . serialize_blocks($tail);
        } elseif ($insert_where === 'prepend'){
            $new_content = $html . "\n\n" . $content;
        } else { // append
            $sep = (substr($content, -1) === "\n") ? "\n" : "\n\n";
            $new_content = $content . $sep . $html;
        }

        $u = wp_update_post(array('ID'=>$id, 'post_content'=>$new_content), true);
        if (is_wp_error($u)) return new WP_REST_Response(array('error'=>$u->get_error_message()), 500);

        delete_post_meta($id, '_dni_cid'); // force recompute
        $mr = DNI_MR::build($id);
        $cid = DNI_CID::compute($mr); update_post_meta($id, '_dni_cid', $cid);
        $mr['cid'] = $cid;

        // Compute top-level block count AFTER insertion
        $count_after = $count_before;
        if (function_exists('parse_blocks')){
            $parsed_after = parse_blocks($new_content);
            if (is_array($parsed_after)) { $count_after = count($parsed_after); }
        }

        $resp = new WP_REST_Response($mr, 200);
        // Symmetry: expose current ETag on write responses
        $resp->header('ETag', '"'.$cid.'"');
        // Expose insertion metadata via headers
        $resp->header('X-DNI-Top-Level-Count-Before', (string)$count_before);
        $resp->header('X-DNI-Inserted-At', (string)$inserted_at);
        $resp->header('X-DNI-Top-Level-Count', (string)$count_after);
        return $resp;
    }

    private static function render_block_html(array $b): string {
        $type = (string)$b['type'];
        $esc = function($s){ return esc_html((string)$s); };
        $html = '';
        switch ($type){
            case 'core/paragraph':
                if (!empty($b['content'])){ $html = '<!-- wp:paragraph --><p>'.$esc($b['content']).'</p><!-- /wp:paragraph -->'; }
                break;
            case 'core/heading':
                $level = isset($b['level']) ? max(1,min(6,(int)$b['level'])) : 2;
                if (!empty($b['content'])){ $html = '<!-- wp:heading --><h'.$level.'>'.$esc($b['content']).'</h'.$level.'><!-- /wp:heading -->'; }
                break;
            case 'core/list':
                $ordered = !empty($b['ordered']);
                $items = isset($b['items']) && is_array($b['items']) ? $b['items'] : array();
                if (!empty($items)){
                    $li = '';
                    foreach ($items as $it){ $li .= '<li>'.$esc($it).'</li>'; }
                    $tag = $ordered ? 'ol' : 'ul';
                    $html = '<!-- wp:list --><' . $tag . '>' . $li . '</' . $tag . '><!-- /wp:list -->';
                }
                break;
            case 'core/image':
                $url = isset($b['url']) ? esc_url_raw($b['url']) : '';
                $alt = isset($b['altText']) ? $esc($b['altText']) : '';
                if ($url){ $html = '<!-- wp:image --><figure class="wp-block-image"><img src="'.$url.'" alt="'.$alt.'"/></figure><!-- /wp:image -->'; }
                break;
            case 'core/code':
                if (!empty($b['content'])){ $html = '<!-- wp:code --><pre class="wp-block-code"><code>'.$esc($b['content']).'</code></pre><!-- /wp:code -->'; }
                break;
            case 'core/quote':
                if (!empty($b['content'])){ $html = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>'.$esc($b['content']).'</p></blockquote><!-- /wp:quote -->'; }
                break;
        }
        return apply_filters('dni_render_block_html', $html, $b);
    }

    private static function to_markdown(array $mr): string {
        $out = '';
        if (!empty($mr['title'])){ $out .= '# ' . $mr['title'] . "\n\n"; }
        $blocks = isset($mr['blocks']) && is_array($mr['blocks']) ? $mr['blocks'] : array();
        foreach ($blocks as $blk){
            $type = isset($blk['type']) ? $blk['type'] : '';
            if ($type === 'core/heading'){
                $lvl = isset($blk['level']) ? max(1,min(6,(int)$blk['level'])) : 2;
                $txt = isset($blk['content']) ? trim((string)$blk['content']) : '';
                if ($txt !== '') $out .= str_repeat('#', $lvl) . ' ' . $txt . "\n\n";
            } elseif ($type === 'core/paragraph' || $type === 'unknown'){
                $txt = isset($blk['content']) ? trim((string)$blk['content']) : '';
                if ($txt !== '') $out .= $txt . "\n\n";
            } elseif ($type === 'core/list'){
                $items = isset($blk['items']) && is_array($blk['items']) ? $blk['items'] : array();
                $ordered = !empty($blk['ordered']);
                foreach ($items as $idx=>$it){ $out .= ($ordered ? (($idx+1).'. ') : '- ') . $it . "\n"; }
                if (!empty($items)) $out .= "\n";
            } elseif ($type === 'core/image'){
                $alt = isset($blk['altText']) ? (string)$blk['altText'] : '';
                $url = isset($blk['url']) ? (string)$blk['url'] : '';
                if ($url !== '') $out .= '!['.$alt.']('.$url.')' . "\n\n";
            } elseif ($type === 'core/code'){
                $txt = isset($blk['content']) ? (string)$blk['content'] : '';
                if ($txt !== '') $out .= "```\n" . $txt . "\n```\n\n";
            } elseif ($type === 'core/quote'){
                $txt = isset($blk['content']) ? (string)$blk['content'] : '';
                if ($txt !== ''){ foreach (preg_split('/\r\n|\r|\n/', $txt) as $ln){ $out .= '> '.$ln."\n"; } $out .= "\n"; }
            }
        }
        return rtrim($out) . "\n";
    }

    public static function ai_suggest(WP_REST_Request $req){
        $id = (int)$req['id'];
        $mr = DNI_MR::build($id);
        if (!$mr) return new WP_REST_Response(array('error'=>'not_found'), 404);
        // Try external LLM if configured
        $enabled = (int) get_option('dni_llm_enabled', 0) === 1;
        if ($enabled){
            $provider = get_option('dni_llm_provider', 'openai');
            $api_url  = get_option('dni_llm_api_url', '');
            $api_key  = get_option('dni_llm_api_key', '');
            $model    = get_option('dni_llm_model', 'gpt-4o-mini');
            $timeout  = (int) get_option('dni_llm_timeout', 15);
            if ($api_url && $api_key){
                $prompt = self::build_prompt_for_llm($mr);
                $resp = self::call_llm($provider, $api_url, $api_key, $model, $prompt, $timeout);
                if (is_array($resp) && isset($resp['summary'])){
                    $resp = apply_filters('dni_ai_suggest', $resp, $mr, $req);
                    return $resp;
                }
            }
        }
        // Fallback: heuristic
        $s = self::heuristic_suggest($mr);
        $s = apply_filters('dni_ai_suggest', $s, $mr, $req);
        return $s;
    }

    private static function heuristic_suggest(array $mr): array {
        $text = (string)($mr['core_content_text'] ?? '');
        $words = preg_split('/\s+/', trim($text));
        $summary = implode(' ', array_slice($words, 0, 120));
        $stop = array('about','above','after','again','being','their','there','these','those','which','where','while','with','your','from','that','this','have','will','would','could','should','because','through','between','among','into','other','than');
        $freq = array();
        foreach ($words as $w){ $w = strtolower(preg_replace('/[^a-z0-9]/i','',$w)); if (strlen($w) < 5) continue; if (in_array($w,$stop,true)) continue; $freq[$w] = ($freq[$w]??0)+1; }
        arsort($freq); $tags = array_slice(array_keys($freq), 0, 5);
        $headings = array(); if (is_array($mr['blocks'])){ foreach ($mr['blocks'] as $b){ if (($b['type']??'')==='core/heading' && !empty($b['content'])) $headings[] = $b['content']; } }
        return array('summary'=>$summary, 'tags'=>$tags, 'headings'=>$headings);
    }

    private static function build_prompt_for_llm(array $mr): string {
        $title = (string)($mr['title'] ?? '');
        $text = (string)($mr['core_content_text'] ?? '');
        $headings = array(); if (is_array($mr['blocks'])){ foreach ($mr['blocks'] as $b){ if (($b['type']??'')==='core/heading' && !empty($b['content'])) $headings[] = $b['content']; } }
        $h = '';
        if (!empty($headings)){ $h = "\nHeadings:\n- " . implode("\n- ", array_map('strval', $headings)); }
        $txt = mb_substr($text, 0, 6000);
        return "Title: $title\n\nContent (truncated):\n$txt\n$h\n\nTask: Produce concise JSON with keys: summary (<= 120 words), tags (array of up to 5 concise lowercase tags). Output only JSON.";
    }

    private static function call_llm($provider, $api_url, $api_key, $model, $prompt, $timeout){
        $args = array('timeout'=>max(5,(int)$timeout));
        if ($provider === 'openai'){
            $body = array(
                'model' => $model,
                'messages' => array(
                    array('role'=>'system','content'=>'You are a helpful assistant. Return only compact JSON.'),
                    array('role'=>'user','content'=>$prompt),
                ),
                'temperature' => 0.2,
                'max_tokens' => 300,
            );
            $args['headers'] = array('Authorization'=>'Bearer '.$api_key, 'Content-Type'=>'application/json');
            $args['body'] = wp_json_encode($body);
            $r = wp_remote_post($api_url, $args);
            if (is_wp_error($r)) return null;
            $code = wp_remote_retrieve_response_code($r);
            $json = json_decode(wp_remote_retrieve_body($r), true);
            if ($code >= 200 && $code < 300 && is_array($json)){
                $content = $json['choices'][0]['message']['content'] ?? '';
                $parsed = json_decode($content, true);
                if (is_array($parsed) && isset($parsed['summary'])) return array('summary'=>$parsed['summary'],'tags'=>$parsed['tags'] ?? array(), 'provider'=>'openai');
            }
            return null;
        } else {
            // Generic endpoint expects {prompt, model?} and replies {summary, tags}
            $args['headers'] = array('Authorization'=>'Bearer '.$api_key, 'Content-Type'=>'application/json');
            $args['body'] = wp_json_encode(array('prompt'=>$prompt,'model'=>$model));
            $r = wp_remote_post($api_url, $args);
            if (is_wp_error($r)) return null;
            $json = json_decode(wp_remote_retrieve_body($r), true);
            if (is_array($json) && isset($json['summary'])) return array('summary'=>$json['summary'],'tags'=>$json['tags'] ?? array(), 'provider'=>'generic');
            return null;
        }
    }
}

DNI_REST::init();
