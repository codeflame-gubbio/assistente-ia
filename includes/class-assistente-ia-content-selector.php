<?php
/**
 * NUOVA CLASSE: Gestione selezione contenuti per embeddings
 * File: includes/class-assistente-ia-content-selector.php
 */

if ( ! defined('ABSPATH') ) exit;

class Assistente_IA_Content_Selector {

    public static function init(){
        add_action('admin_menu', [ __CLASS__, 'aggiungi_sottomenu' ], 100);
        add_action('admin_post_assia_save_content_selection', [ __CLASS__, 'salva_selezione' ]);
    }

    public static function aggiungi_sottomenu(){
        add_submenu_page(
            'assia',
            'Selezione Contenuti RAG',
            'Selezione Contenuti',
            'manage_options',
            'assia-content-selector',
            [ __CLASS__, 'render_pagina' ]
        );
    }

    public static function render_pagina(){
        if ( ! current_user_can('manage_options') ) {
            wp_die( __('Non hai i permessi per accedere a questa pagina.', 'assistente-ia') );
        }

        // Recupera selezioni salvate
        $selected_posts = get_option('assia_selected_posts', []);
        $selected_pages = get_option('assia_selected_pages', []);

        // Query per post e pagine
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        ?>
        <style>
            .assia-content-selector-wrap { max-width: 1200px; }
            .assia-cs-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .assia-cs-title { margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #111827; }
            .assia-cs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
            .assia-cs-item { display: flex; align-items: start; gap: 8px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fafafa; }
            .assia-cs-item:hover { background: #f3f4f6; }
            .assia-cs-item input[type="checkbox"] { margin-top: 2px; flex-shrink: 0; }
            .assia-cs-item-content { flex: 1; min-width: 0; }
            .assia-cs-item-title { font-weight: 600; color: #111827; font-size: 14px; margin: 0 0 4px 0; }
            .assia-cs-item-meta { font-size: 12px; color: #6b7280; }
            .assia-cs-actions { position: sticky; top: 32px; background: #fff; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .assia-cs-btn { display: inline-block; padding: 10px 16px; background: #111827; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; }
            .assia-cs-btn:hover { background: #374151; color: #fff; }
            .assia-cs-btn-secondary { background: #6b7280; }
            .assia-cs-info { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 4px; margin: 16px 0; }
            .assia-cs-warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin: 16px 0; }
            .assia-cs-stats { display: flex; gap: 20px; margin: 16px 0; }
            .assia-cs-stat { font-size: 14px; }
            .assia-cs-stat strong { color: #111827; font-size: 18px; }
        </style>

        <div class="wrap assia-content-selector-wrap">
            <h1>📝 Selezione Contenuti per RAG</h1>

            <div class="assia-cs-info">
                <strong>ℹ️ Come funziona:</strong><br>
                Seleziona quali <strong>post e pagine</strong> vuoi includere negli embeddings per il sistema RAG. 
                Solo i contenuti selezionati saranno indicizzati e utilizzati per rispondere alle domande.<br>
                <strong>I prodotti WooCommerce vengono sempre inclusi automaticamente</strong> (se WooCommerce è attivo).
            </div>

            <?php
            $total_posts = count($posts);
            $total_pages = count($pages);
            $selected_posts_count = count($selected_posts);
            $selected_pages_count = count($selected_pages);
            ?>

            <div class="assia-cs-stats">
                <div class="assia-cs-stat">
                    <strong><?php echo $selected_posts_count; ?></strong> / <?php echo $total_posts; ?> articoli selezionati
                </div>
                <div class="assia-cs-stat">
                    <strong><?php echo $selected_pages_count; ?></strong> / <?php echo $total_pages; ?> pagine selezionate
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="assia-content-form">
                <?php wp_nonce_field('assia_content_selection', 'assia_content_nonce'); ?>
                <input type="hidden" name="action" value="assia_save_content_selection">

                <!-- Azioni Rapide (sticky) -->
                <div class="assia-cs-actions">
                    <button type="button" id="assia-select-all-posts" class="assia-cs-btn assia-cs-btn-secondary">✓ Seleziona tutti gli articoli</button>
                    <button type="button" id="assia-deselect-all-posts" class="assia-cs-btn assia-cs-btn-secondary">✗ Deseleziona tutti gli articoli</button>
                    <button type="button" id="assia-select-all-pages" class="assia-cs-btn assia-cs-btn-secondary">✓ Seleziona tutte le pagine</button>
                    <button type="button" id="assia-deselect-all-pages" class="assia-cs-btn assia-cs-btn-secondary">✗ Deseleziona tutte le pagine</button>
                    <hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">
                    <button type="submit" class="assia-cs-btn">💾 Salva Selezione</button>
                    <p style="margin: 8px 0 0 0; color: #6b7280; font-size: 12px;">Dopo aver salvato, vai su <a href="<?php echo admin_url('admin.php?page=assistente-ia-rag'); ?>">RAG (Embeddings)</a> per rigenerare l'indice.</p>
                </div>

                <!-- ARTICOLI -->
                <div class="assia-cs-section">
                    <h2 class="assia-cs-title">📰 Articoli (<?php echo $total_posts; ?>)</h2>
                    
                    <?php if ( empty($posts) ): ?>
                        <p style="color: #6b7280;">Nessun articolo pubblicato.</p>
                    <?php else: ?>
                        <div class="assia-cs-grid">
                            <?php foreach($posts as $post): 
                                $checked = in_array($post->ID, $selected_posts) ? 'checked' : '';
                                $date = date_i18n('d/m/Y', strtotime($post->post_date));
                                $categories = get_the_category($post->ID);
                                $cat_names = $categories ? implode(', ', wp_list_pluck($categories, 'name')) : 'Nessuna categoria';
                            ?>
                                <label class="assia-cs-item">
                                    <input type="checkbox" name="selected_posts[]" value="<?php echo $post->ID; ?>" <?php echo $checked; ?> class="post-checkbox">
                                    <div class="assia-cs-item-content">
                                        <div class="assia-cs-item-title"><?php echo esc_html($post->post_title); ?></div>
                                        <div class="assia-cs-item-meta">
                                            <?php echo $date; ?> • <?php echo esc_html($cat_names); ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PAGINE -->
                <div class="assia-cs-section">
                    <h2 class="assia-cs-title">📄 Pagine (<?php echo $total_pages; ?>)</h2>
                    
                    <?php if ( empty($pages) ): ?>
                        <p style="color: #6b7280;">Nessuna pagina pubblicata.</p>
                    <?php else: ?>
                        <div class="assia-cs-grid">
                            <?php foreach($pages as $page): 
                                $checked = in_array($page->ID, $selected_pages) ? 'checked' : '';
                                $date = date_i18n('d/m/Y', strtotime($page->post_date));
                                $parent = $page->post_parent ? get_the_title($page->post_parent) : 'Nessun genitore';
                            ?>
                                <label class="assia-cs-item">
                                    <input type="checkbox" name="selected_pages[]" value="<?php echo $page->ID; ?>" <?php echo $checked; ?> class="page-checkbox">
                                    <div class="assia-cs-item-content">
                                        <div class="assia-cs-item-title"><?php echo esc_html($page->post_title); ?></div>
                                        <div class="assia-cs-item-meta">
                                            <?php echo $date; ?> • <?php echo esc_html($parent); ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- WooCommerce Info -->
                <?php if ( class_exists('WooCommerce') ): 
                    $products_count = wp_count_posts('product');
                    $published = $products_count->publish ?? 0;
                ?>
                    <div class="assia-cs-warning">
                        <strong>🛒 WooCommerce Rilevato</strong><br>
                        I tuoi <strong><?php echo $published; ?> prodotti</strong> vengono automaticamente inclusi negli embeddings. 
                        Non è necessario selezionarli manualmente.
                    </div>
                <?php endif; ?>

                <!-- Pulsante salva in fondo -->
                <div style="margin-top: 20px;">
                    <button type="submit" class="assia-cs-btn">💾 Salva Selezione</button>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($){
            // Seleziona/deseleziona tutti gli articoli
            $('#assia-select-all-posts').on('click', function(){
                $('.post-checkbox').prop('checked', true);
            });
            $('#assia-deselect-all-posts').on('click', function(){
                $('.post-checkbox').prop('checked', false);
            });

            // Seleziona/deseleziona tutte le pagine
            $('#assia-select-all-pages').on('click', function(){
                $('.page-checkbox').prop('checked', true);
            });
            $('#assia-deselect-all-pages').on('click', function(){
                $('.page-checkbox').prop('checked', false);
            });
        });
        </script>
        <?php
    }

    public static function salva_selezione(){
        if ( ! current_user_can('manage_options') ) {
            wp_die('Permessi insufficienti');
        }

        check_admin_referer('assia_content_selection', 'assia_content_nonce');

        $selected_posts = isset($_POST['selected_posts']) ? array_map('intval', $_POST['selected_posts']) : [];
        $selected_pages = isset($_POST['selected_pages']) ? array_map('intval', $_POST['selected_pages']) : [];

        update_option('assia_selected_posts', $selected_posts);
        update_option('assia_selected_pages', $selected_pages);

        wp_redirect( add_query_arg( ['page' => 'assia-content-selector', 'saved' => '1'], admin_url('admin.php') ) );
        exit;
    }

    /**
     * Recupera gli ID dei contenuti selezionati per l'indicizzazione
     */
    public static function get_selected_content_ids(): array {
        $posts = get_option('assia_selected_posts', []);
        $pages = get_option('assia_selected_pages', []);
        
        // Se non c'è nessuna selezione salvata, includi tutto (retrocompatibilità)
        if ( empty($posts) && empty($pages) ) {
            $all_posts = get_posts([
                'post_type' => ['post', 'page'],
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids'
            ]);
            return $all_posts;
        }

        return array_merge($posts, $pages);
    }
}