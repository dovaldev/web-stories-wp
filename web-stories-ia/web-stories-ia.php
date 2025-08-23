<?php
/**
 * Plugin Name: Web Stories IA
 * Description: Crea historias Web utilizando la API de OpenAI y el plugin Web Stories.
 * Version: 0.1.0
 * Author: Web Stories IA
 * Text Domain: web-stories-ia
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Web_Stories_IA {

    private const OPTION_API_KEY = 'web_stories_ia_api_key';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'web_stories_ia_settings', self::OPTION_API_KEY );
    }

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'Web Stories IA', 'web-stories-ia' ),
            __( 'Web Stories IA', 'web-stories-ia' ),
            'manage_options',
            'web-stories-ia',
            [ $this, 'render_story_page' ],
            'dashicons-art'
        );

        add_submenu_page(
            'web-stories-ia',
            __( 'Settings', 'web-stories-ia' ),
            __( 'Settings', 'web-stories-ia' ),
            'manage_options',
            'web-stories-ia-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    private function get_api_key(): string {
        return (string) get_option( self::OPTION_API_KEY );
    }

    public function render_settings_page(): void {
        if ( isset( $_POST['web_stories_ia_verify_nonce'] ) && wp_verify_nonce( $_POST['web_stories_ia_verify_nonce'], 'web_stories_ia_verify' ) ) {
            $message = $this->verify_api_key();
            add_settings_error( 'web_stories_ia_messages', 'web_stories_ia_message', $message, 'updated' );
        }
        settings_errors( 'web_stories_ia_messages' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Web Stories IA Settings', 'web-stories-ia' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'web_stories_ia_settings' );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="web_stories_ia_api_key"><?php esc_html_e( 'OpenAI API Key', 'web-stories-ia' ); ?></label></th>
                        <td><input name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" id="web_stories_ia_api_key" type="password" value="<?php echo esc_attr( $this->get_api_key() ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post">
                <?php wp_nonce_field( 'web_stories_ia_verify', 'web_stories_ia_verify_nonce' ); ?>
                <?php submit_button( __( 'Verify API Key', 'web-stories-ia' ), 'secondary', 'web_stories_ia_verify' ); ?>
            </form>
        </div>
        <?php
    }

    private function verify_api_key(): string {
        $key = $this->get_api_key();
        if ( empty( $key ) ) {
            return __( 'Please save an API key first.', 'web-stories-ia' );
        }
        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                ],
                'timeout' => 20,
            ]
        );
        if ( is_wp_error( $response ) ) {
            return sprintf( __( 'Error connecting to OpenAI: %s', 'web-stories-ia' ), $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        return 200 === $code ? __( 'API key verified.', 'web-stories-ia' ) : __( 'API key invalid.', 'web-stories-ia' );
    }

    public function render_story_page(): void {
        if ( ! $this->is_web_stories_active() ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Web Stories plugin is required.', 'web-stories-ia' ) . '</p></div>';
            return;
        }
        if ( isset( $_POST['web_stories_ia_generate_nonce'] ) && wp_verify_nonce( $_POST['web_stories_ia_generate_nonce'], 'web_stories_ia_generate' ) ) {
            $this->handle_generation();
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Create Story with AI', 'web-stories-ia' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'web_stories_ia_generate', 'web_stories_ia_generate_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="web_stories_ia_template"><?php esc_html_e( 'Template', 'web-stories-ia' ); ?></label></th>
                        <td><?php $this->render_template_select(); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="web_stories_ia_prompt"><?php esc_html_e( 'Story topic', 'web-stories-ia' ); ?></label></th>
                        <td><input name="web_stories_ia_prompt" id="web_stories_ia_prompt" type="text" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="web_stories_ia_pages"><?php esc_html_e( 'Number of pages', 'web-stories-ia' ); ?></label></th>
                        <td><input name="web_stories_ia_pages" id="web_stories_ia_pages" type="number" value="5" min="1" class="small-text" /></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Generate Story', 'web-stories-ia' ) ); ?>
            </form>
        </div>
        <?php
    }

    private function render_template_select(): void {
        $templates = $this->get_templates();
        echo '<select name="web_stories_ia_template" id="web_stories_ia_template">';
        foreach ( $templates as $template ) {
            printf( '<option value="%1$s">%2$s</option>', esc_attr( $template->ID ), esc_html( $template->post_title ) );
        }
        echo '</select>';
    }

    /**
     * Retrieve templates from Web Stories plugin.
     */
    private function get_templates(): array {
        $args = [
            'post_type'      => 'web-story',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ];
        return get_posts( $args );
    }

    private function get_template_data( int $template_id ): array {
        if ( ! $template_id ) {
            return [];
        }
        $template = get_post( $template_id );
        if ( ! $template ) {
            return [];
        }
        $data = json_decode( $template->post_content_filtered, true );
        return $data['pages'] ?? [];
    }

    private function openai_request( string $prompt, string $key ) {
        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode(
                    [
                        'model' => 'gpt-4o-mini',
                        'input' => $prompt,
                    ]
                ),
                'timeout' => 60,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['output'][0]['content'][0]['text'] ?? '';
        $data = json_decode( $text, true );

        if ( empty( $data ) ) {
            return new WP_Error( 'web_stories_ia', __( 'Invalid response from OpenAI.', 'web-stories-ia' ) );
        }

        return $data;
    }

    private function handle_generation(): void {
        $key        = $this->get_api_key();
        if ( empty( $key ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Please configure the OpenAI API key first.', 'web-stories-ia' ) . '</p></div>';
            return;
        }
        $prompt      = sanitize_text_field( $_POST['web_stories_ia_prompt'] ?? '' );
        $pages       = absint( $_POST['web_stories_ia_pages'] ?? 1 );
        $template_id = absint( $_POST['web_stories_ia_template'] ?? 0 );

        $template_pages = $this->get_template_data( $template_id );

        $outline_prompt = sprintf(
            'Tema: %s. Devuelve JSON con "title", "description" y un arreglo "pages" de %d descripciones cortas de cada página.',
            $prompt,
            $pages
        );
        $outline = $this->openai_request( $outline_prompt, $key );

        if ( is_wp_error( $outline ) || empty( $outline['pages'] ) ) {
            $message = is_wp_error( $outline ) ? $outline->get_error_message() : __( 'Could not generate story outline.', 'web-stories-ia' );
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
            return;
        }

        $generated_pages = [];
        foreach ( $outline['pages'] as $index => $summary ) {
            $template_page = $template_pages[ $index ] ?? [];
            $page_prompt   = sprintf(
                'Usa el diseño JSON siguiente y crea el contenido de la página %d de una historia sobre "%s". Resumen: %s. Devuelve JSON compatible. Diseño: %s',
                $index + 1,
                $prompt,
                is_string( $summary ) ? $summary : wp_json_encode( $summary ),
                wp_json_encode( $template_page )
            );
            $page = $this->openai_request( $page_prompt, $key );
            if ( is_wp_error( $page ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $page->get_error_message() ) . '</p></div>';
                return;
            }
            $generated_pages[] = $page;
        }

        $story_id = wp_insert_post(
            [
                'post_type'    => 'web-story',
                'post_status'  => 'draft',
                'post_title'   => sanitize_text_field( $outline['title'] ?? $prompt ),
                'post_excerpt' => sanitize_text_field( $outline['description'] ?? '' ),
                'post_content_filtered' => wp_json_encode(
                    [
                        'pages' => $generated_pages,
                    ]
                ),
            ]
        );

        if ( is_wp_error( $story_id ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $story_id->get_error_message() ) . '</p></div>';
            return;
        }

        $edit_link = esc_url( admin_url( sprintf( 'post.php?post=%d&action=edit', $story_id ) ) );
        echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Story created. %sEdit story%s.', 'web-stories-ia' ), '<a href="' . $edit_link . '">', '</a>' ) . '</p></div>';
    }

    private function is_web_stories_active(): bool {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        return function_exists( 'is_plugin_active' ) && is_plugin_active( 'web-stories/web-stories.php' );
    }
}

new Web_Stories_IA();
