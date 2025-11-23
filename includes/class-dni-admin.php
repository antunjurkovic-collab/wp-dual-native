<?php
if (!defined('ABSPATH')) { exit; }

class DNI_Admin {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function menu(){
        add_options_page(
            'Dual-Native',
            'Dual-Native',
            'manage_options',
            'dni-settings',
            [__CLASS__, 'render']
        );
    }

    public static function register_settings(){
        // Enabled: cast to int (0/1)
        register_setting('dni', 'dni_llm_enabled', array(
            'type' => 'integer',
            'sanitize_callback' => function($v){ return (int) !empty($v); },
            'show_in_rest' => false,
        ));
        // Provider & model: sanitize text
        register_setting('dni', 'dni_llm_provider', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest' => false,
        ));
        register_setting('dni', 'dni_llm_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest' => false,
        ));
        // API URL: ensure valid URL
        register_setting('dni', 'dni_llm_api_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'show_in_rest' => false,
        ));
        // API Key: strip whitespace, sanitize text
        register_setting('dni', 'dni_llm_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest' => false,
        ));
        // Timeout: integer seconds, default 30
        register_setting('dni', 'dni_llm_timeout', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 30,
            'show_in_rest' => false,
        ));
    }

    public static function render(){
        if (!current_user_can('manage_options')) return;
        $enabled = (int) get_option('dni_llm_enabled', 0) === 1;
        $provider = get_option('dni_llm_provider', 'openai');
        $api_url = get_option('dni_llm_api_url', 'https://api.openai.com/v1/chat/completions');
        $api_key = get_option('dni_llm_api_key', '');
        $model  = get_option('dni_llm_model', 'gpt-4o-mini');
        $timeout = (int) get_option('dni_llm_timeout', 15);
        ?>
        <div class="wrap">
          <h1>Dual-Native API Settings</h1>
          <form method="post" action="options.php">
            <?php settings_fields('dni'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Enable External LLM</th>
                <td><label><input type="checkbox" name="dni_llm_enabled" value="1" <?php checked($enabled); ?>/> Enable server‑side calls</label></td>
              </tr>
              <tr>
                <th scope="row">Provider</th>
                <td>
                  <select name="dni_llm_provider">
                    <option value="openai" <?php selected($provider,'openai'); ?>>OpenAI‑compatible</option>
                    <option value="generic" <?php selected($provider,'generic'); ?>>Generic JSON endpoint</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row">API URL</th>
                <td><input type="text" class="regular-text" name="dni_llm_api_url" value="<?php echo esc_attr($api_url); ?>"/></td>
              </tr>
              <tr>
                <th scope="row">API Key</th>
                <td><input type="password" class="regular-text" name="dni_llm_api_key" value="<?php echo esc_attr($api_key); ?>" autocomplete="off"/></td>
              </tr>
              <tr>
                <th scope="row">Model</th>
                <td><input type="text" class="regular-text" name="dni_llm_model" value="<?php echo esc_attr($model); ?>"/></td>
              </tr>
              <tr>
                <th scope="row">Timeout (seconds)</th>
                <td><input type="number" min="5" max="60" name="dni_llm_timeout" value="<?php echo esc_attr($timeout); ?>"/></td>
              </tr>
            </table>
            <?php submit_button(); ?>
          </form>
          <p class="description">When enabled, the “Suggest summary & tags” button will call the configured LLM. If disabled or an error occurs, the plugin falls back to a local heuristic.</p>
        </div>
        <?php
    }
}

DNI_Admin::init();
