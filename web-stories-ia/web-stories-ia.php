<?php
/**
 * Plugin Name: Web Stories IA
 * Description: Crea historias Web utilizando la API de OpenAI y el plugin Web Stories.
 * Version: 0.1.0
 * Author: Web Stories IA
 * Text Domain: web-stories-ia
 */
use Google\Web_Stories\Services;
use Google\Web_Stories\Template_Post_Type;

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
        wp_enqueue_media();
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
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Images', 'web-stories-ia' ); ?></th>
                        <td>
                            <button type="button" class="button" id="web_stories_ia_add_image"><?php esc_html_e( 'Add Images', 'web-stories-ia' ); ?></button>
                            <ul id="web_stories_ia_images"></ul>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="web_stories_ia_categories"><?php esc_html_e( 'Categories', 'web-stories-ia' ); ?></label></th>
                        <td><input name="web_stories_ia_categories" id="web_stories_ia_categories" type="text" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="web_stories_ia_tags"><?php esc_html_e( 'Tags', 'web-stories-ia' ); ?></label></th>
                        <td><input name="web_stories_ia_tags" id="web_stories_ia_tags" type="text" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Generate Story', 'web-stories-ia' ) ); ?>
            </form>
        </div>
        <?php
        $select_images_text   = esc_js( __( 'Select Images', 'web-stories-ia' ) );
        $alt_text_placeholder = esc_js( __( 'Alt text', 'web-stories-ia' ) );
        ?>
        <script>
        jQuery(function($){
            var frame;
            $('#web_stories_ia_add_image').on('click', function(e){
                e.preventDefault();
                if (frame) {
                    frame.open();
                    return;
                }
                frame = wp.media({
                    title: '<?php echo $select_images_text; ?>',
                    multiple: true,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var attachments = frame.state().get('selection').toJSON();
                    attachments.forEach(function(att){
                        var alt = att.alt || '';
                        $('#web_stories_ia_images').append(
                            '<li><img src="'+att.sizes.thumbnail.url+'" />'
                            + '<input type="hidden" name="web_stories_ia_images['+att.id+'][id]" value="'+att.id+'" />'
                            + '<input type="text" name="web_stories_ia_images['+att.id+'][alt]" value="'+alt+'" placeholder="<?php echo $alt_text_placeholder; ?>" />'
                            + '</li>'
                        );
                    });
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    private function render_template_select(): void {
        $templates = $this->get_templates();
        echo '<select name="web_stories_ia_template" id="web_stories_ia_template">';
        foreach ( $templates as $template ) {
            printf(
                '<option value="%1$s" data-pages="%3$s">%2$s</option>',
                esc_attr( (string) $template['id'] ),
                esc_html( $template['title'] ),
                esc_attr( wp_json_encode( $template['pages'] ) )
            );
        }
        echo '</select>';
    }

    /**
     * Retrieve templates from Web Stories plugin via its REST API.
     */
    private function get_templates(): array {
        if ( ! class_exists( Services::class ) || ! class_exists( Template_Post_Type::class ) ) {
            return [];
        }

        $template_post_type = Services::get( 'template_post_type' );

        if ( ! $template_post_type instanceof Template_Post_Type ) {
            return [];
        }

        $response = wp_remote_get( rest_url( trailingslashit( $template_post_type->get_rest_url() ) ) );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return [];
        }

        return array_map(
            static function ( array $item ): array {
                return [
                    'id'    => (int) ( $item['id'] ?? 0 ),
                    'title' => $item['title']['rendered'] ?? '',
                    'pages' => $item['story_data']['pages'] ?? [],
                ];
            },
            $body
        );
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

    private function get_page_image_alts( array $page ): array {
        $alts    = [];
        $elements = $page['elements'] ?? [];
        foreach ( $elements as $element ) {
            if ( ( $element['type'] ?? '' ) !== 'image' ) {
                continue;
            }
            $alt = $element['resource']['alt'] ?? '';
            if ( ! $alt && ! empty( $element['resource']['id'] ) ) {
                $alt = get_post_meta( $element['resource']['id'], '_wp_attachment_image_alt', true );
            }
            if ( $alt ) {
                $alts[] = sanitize_text_field( $alt );
            }
        }
        return $alts;
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

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error(
                'web_stories_ia',
                sprintf(
                    /* translators: %d: HTTP response code */
                    __( 'OpenAI API request failed with status code %d.', 'web-stories-ia' ),
                    $code
                )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error(
                'web_stories_ia',
                sprintf(
                    __( 'Error decoding OpenAI response: %s', 'web-stories-ia' ),
                    json_last_error_msg()
                )
            );
        }

        if ( empty( $decoded['output'] ) || empty( $decoded['output'][0]['content'] ) ) {
            return new WP_Error( 'web_stories_ia', __( 'Invalid response from OpenAI.', 'web-stories-ia' ) );
        }

        $text = $decoded['output'][0]['content'][0]['text'] ?? '';
        $data = json_decode( $text, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error(
                'web_stories_ia',
                sprintf(
                    __( 'Error decoding OpenAI content: %s', 'web-stories-ia' ),
                    json_last_error_msg()
                )
            );
        }

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

        $images_input = $_POST['web_stories_ia_images'] ?? [];
        $images       = [];
        foreach ( $images_input as $img ) {
            $img_id = absint( $img['id'] ?? 0 );
            if ( ! $img_id ) {
                continue;
            }
            $alt = sanitize_text_field( $img['alt'] ?? '' );
            if ( $alt ) {
                update_post_meta( $img_id, '_wp_attachment_image_alt', $alt );
            }
            $images[ $img_id ] = $alt;
        }

        $categories_input = sanitize_text_field( $_POST['web_stories_ia_categories'] ?? '' );
        $category_names   = array_filter( array_map( 'trim', explode( ',', $categories_input ) ) );
        $category_ids     = [];
        foreach ( $category_names as $name ) {
            $term = get_term_by( 'name', $name, 'category' );
            if ( ! $term ) {
                $term = wp_insert_term( $name, 'category' );
            }
            if ( ! is_wp_error( $term ) ) {
                $category_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term->term_id );
            }
        }

        $tags_input = sanitize_text_field( $_POST['web_stories_ia_tags'] ?? '' );
        $tag_names  = array_filter( array_map( 'trim', explode( ',', $tags_input ) ) );
        $tag_ids    = [];
        foreach ( $tag_names as $name ) {
            $term = get_term_by( 'name', $name, 'post_tag' );
            if ( ! $term ) {
                $term = wp_insert_term( $name, 'post_tag' );
            }
            if ( ! is_wp_error( $term ) ) {
                $tag_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term->term_id );
            }
        }

        $template_pages = $this->get_template_data( $template_id );

        $outline_prompt = sprintf(
            'Tema: %s. Devuelve JSON con "title", "description" y un arreglo "pages" de %d descripciones cortas de cada página.',
            $prompt,
            $pages
        );
        if ( $images ) {
            $outline_prompt .= ' Imágenes: ' . wp_json_encode( $images ) . '.';
        }
        if ( $category_names ) {
            $outline_prompt .= ' Categorías: ' . implode( ', ', $category_names ) . '.';
        }
        if ( $tag_names ) {
            $outline_prompt .= ' Etiquetas: ' . implode( ', ', $tag_names ) . '.';
        }
        $outline = $this->openai_request( $outline_prompt, $key );

        if ( is_wp_error( $outline ) || empty( $outline['pages'] ) ) {
            $message = is_wp_error( $outline ) ? $outline->get_error_message() : __( 'Could not generate story outline.', 'web-stories-ia' );
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
            return;
        }

        $generated_pages = [];
        foreach ( $outline['pages'] as $index => $summary ) {
            $template_page = $template_pages[ $index ] ?? [];
            $image_alts    = $this->get_page_image_alts( $template_page );
            $alts_text     = $image_alts ? 'Alt de imágenes: ' . implode( '; ', $image_alts ) . '. ' : '';
            $page_prompt   = sprintf(
                'Usa el diseño JSON siguiente y crea el contenido de la página %1$d de una historia sobre "%2$s". %3$sResumen: %4$s. Devuelve JSON compatible. Diseño: %5$s',
                $index + 1,
                $prompt,
                $alts_text,
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

        if ( $category_ids ) {
            wp_set_post_terms( $story_id, $category_ids, 'category' );
        }
        if ( $tag_ids ) {
            wp_set_post_terms( $story_id, $tag_ids, 'post_tag' );
        }
        if ( $images ) {
            update_post_meta( $story_id, '_web_stories_ia_images', $images );
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
