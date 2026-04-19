<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Admin_Page
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Gestion des Créateurs',
            'Créateurs',
            'manage_woocommerce',
            'mp-creators',
            array($this, 'display_admin_page')
        );
    }

    public function enqueue_admin_scripts($hook)
    {
        if ('woocommerce_page_mp-creators' !== $hook) {
            return;
        }

        wp_enqueue_style('mp-creator-admin', MP_CREATOR_NOTIFIER_PLUGIN_URL . 'assets/admin.css', array(), MP_CREATOR_NOTIFIER_VERSION);
        wp_enqueue_script('mp-creator-admin', MP_CREATOR_NOTIFIER_PLUGIN_URL . 'assets/admin.js', array('jquery'), MP_CREATOR_NOTIFIER_VERSION, true);

        wp_localize_script('mp-creator-admin', 'mp_creator_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mp_creator_nonce'),
            'confirm_delete' => 'Êtes-vous sûr de vouloir supprimer ce créateur ?'
        ));
    }

    public function display_admin_page()
    {
        $creator_manager = MP_Creator_Manager::get_instance();
        $brands = $creator_manager->get_available_brands();
        $creators = MP_Creator_DB::get_instance()->get_all_creators();
        $taxonomy_exists = $creator_manager->verify_brand_taxonomy();
        
        // Récupérer le créateur en édition si applicable
        $creator = null;
        if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
            $creator = MP_Creator_DB::get_instance()->get_creator(intval($_GET['edit']));
            if (!$creator) {
                echo '<div class="notice notice-error"><p>Créateur non trouvé.</p></div>';
            }
        }
?>
        <div class="wrap">
            <h1>Gestion des Créateurs</h1>

            <?php if (!$taxonomy_exists): ?>
                <div class="notice notice-error">
                    <p><strong>Attention :</strong> La taxonomie "product_brand" n'existe pas. Veuillez vérifier que WooCommerce est correctement configuré.</p>
                </div>
            <?php elseif (empty($brands)): ?>
                <div class="notice notice-warning">
                    <p><strong>Information :</strong> Aucune marque trouvée. Vous devez d'abord <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_brand&post_type=product'); ?>">créer des marques</a> avant d'ajouter des créateurs.</p>
                </div>
            <?php endif; ?>

            <div class="mp-creator-admin">
                <!-- Formulaire d'ajout/modification -->
                <div class="mp-creator-form">
                    <h2><?php echo isset($_GET['edit']) && $creator ? 'Modifier le créateur' : 'Ajouter un créateur'; ?></h2>

                    <?php if (empty($brands) && $taxonomy_exists): ?>
                        <div class="notice notice-info inline">
                            <p>Aucune marque disponible. <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_brand&post_type=product'); ?>">Créer une marque</a></p>
                        </div>
                    <?php endif; ?>

                    <form id="mp-creator-form">
                        <?php if (isset($_GET['edit']) && $creator): ?>
                            <input type="hidden" name="id" value="<?php echo intval($creator->id); ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="name">Nom <span class="required">*</span></label></th>
                                <td>
                                    <input type="text" id="name" name="name" value="<?php echo $creator ? esc_attr($creator->name) : ''; ?>" class="regular-text" required maxlength="100">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email">Email <span class="required">*</span></label></th>
                                <td>
                                    <input type="email" id="email" name="email" value="<?php echo $creator ? esc_attr($creator->email) : ''; ?>" class="regular-text" required maxlength="100">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="phone">Téléphone</label></th>
                                <td>
                                    <input type="text" id="phone" name="phone" value="<?php echo $creator ? esc_attr($creator->phone) : ''; ?>" class="regular-text" maxlength="20">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brand_slug">Marque <span class="required">*</span></label></th>
                                <td>
                                    <select id="brand_slug" name="brand_slug" required <?php echo empty($brands) ? 'disabled' : ''; ?>>
                                        <option value="">Sélectionner une marque</option>
                                        <?php foreach ($brands as $brand): ?>
                                            <option value="<?php echo esc_attr($brand['slug']); ?>"
                                                <?php if ($creator && $creator->brand_slug === $brand['slug']) echo 'selected'; ?>>
                                                <?php echo esc_html($brand['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">La marque doit exister dans WooCommerce</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="address">Adresse</label></th>
                                <td>
                                    <textarea id="address" name="address" rows="4" class="regular-text"><?php echo $creator ? esc_textarea($creator->address) : ''; ?></textarea>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <?php if (isset($_GET['edit']) && $creator): ?>
                                <button type="submit" class="button button-primary" <?php echo empty($brands) ? 'disabled' : ''; ?>>Modifier le créateur</button>
                                <a href="<?php echo remove_query_arg('edit'); ?>" class="button">Annuler</a>
                            <?php else: ?>
                                <button type="submit" class="button button-primary" <?php echo empty($brands) ? 'disabled' : ''; ?>>Ajouter le créateur</button>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <!-- Liste des créateurs -->
                <div class="mp-creator-list">
                    <h2>Liste des créateurs (<?php echo count($creators); ?>)</h2>

                    <?php if (empty($creators)): ?>
                        <p>Aucun créateur enregistré.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Email</th>
                                    <th>Téléphone</th>
                                    <th>Marque</th>
                                    <th>Date d'ajout</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($creators as $c): ?>
                                    <tr>
                                        <td><?php echo esc_html($c->name); ?></td>
                                        <td><a href="mailto:<?php echo esc_attr($c->email); ?>"><?php echo esc_html($c->email); ?></a></td>
                                        <td><?php echo esc_html($c->phone ? $c->phone : '—'); ?></td>
                                        <td>
                                            <?php
                                            $brand = get_term_by('slug', $c->brand_slug, 'product_brand');
                                            if ($brand) {
                                                echo '<a href="' . admin_url('edit.php?product_brand=' . $c->brand_slug . '&post_type=product') . '">' . esc_html($brand->name) . '</a>';
                                            } else {
                                                echo '<span style="color: #dc3232;">⚠ Marque non trouvée</span>';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($c->created_at))); ?></td>
                                        <td>
                                            <a href="<?php echo add_query_arg('edit', $c->id); ?>" class="button button-small">Modifier</a>
                                            <button class="button button-small button-link-delete mp-delete-creator" data-id="<?php echo $c->id; ?>">Supprimer</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Messages de statut -->
                <div id="mp-creator-messages"></div>
            </div>
        </div>

        <!-- Section Debug Console -->
        <div style="margin-top: 30px; padding: 15px; background: #1d2327; color: #fff; border-radius: 4px;">
            <h3 style="color: #fff; margin-top: 0;">Console de Débogage</h3>
            <div id="debug-console" style="height: 200px; overflow-y: auto; background: #2c3338; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                <div> Console de débogage initialisée...</div>
            </div>
            <button type="button" id="clear-console" class="button" style="margin-top: 10px;">Effacer la console</button>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Fonction pour logger dans la console de débogage
            function debugLog(message, type = 'info') {
                var timestamp = new Date().toLocaleTimeString();
                var color = type === 'error' ? '#ff6b6b' : 
                           type === 'success' ? '#51cf66' : 
                           type === 'warning' ? '#ffd43b' : '#74c0fc';
                
                var logEntry = '<div style="margin-bottom: 5px; color: ' + color + ';">[' + timestamp + '] ' + message + '</div>';
                $('#debug-console').append(logEntry);
                $('#debug-console').scrollTop($('#debug-console')[0].scrollHeight);
                
                // Log également dans la console du navigateur
                console.log('[' + timestamp + '] ' + message);
            }

            // Effacer la console
            $('#clear-console').on('click', function() {
                $('#debug-console').html('<div> Console effacée...</div>');
                debugLog('Console effacée manuellement');
            });

            // Gestion du formulaire avec logs détaillés
            $('#mp-creator-form').on('submit', function(e) {
                e.preventDefault();
                
                debugLog('=== DÉBUT SOUMISSION FORMULAIRE ===', 'info');
                
                var formData = $(this).serializeArray();
                var isEdit = $('input[name="id"]').length > 0;
                
                // Log des données du formulaire
                debugLog('Données du formulaire:', 'info');
                formData.forEach(function(field) {
                    debugLog('  - ' + field.name + ': ' + (field.value || '(vide)'), 'info');
                });
                
                debugLog('Type d\'action: ' + (isEdit ? 'MODIFICATION' : 'CRÉATION'), 'info');
                
                // Validation côté client
                var name = $('#name').val();
                var email = $('#email').val();
                var brand = $('#brand_slug').val();
                
                if (!name || !email || !brand) {
                    debugLog('❌ Validation échouée: champs requis manquants', 'error');
                    return;
                }
                
                debugLog(' Validation côté client réussie', 'success');
                
                // Afficher le message de chargement
                $('#mp-creator-messages').html('<div class="mp-creator-message info">Traitement en cours...</div>');
                
                // Préparer les données pour AJAX
                var ajaxData = {
                    action: isEdit ? 'update_creator' : 'create_creator',
                    nonce: mp_creator_ajax.nonce
                };
                
                // Ajouter les données du formulaire
                formData.forEach(function(field) {
                    ajaxData[field.name] = field.value;
                });
                
                debugLog('Envoi de la requête AJAX...', 'info');
                
                $.ajax({
                    url: mp_creator_ajax.ajax_url,
                    type: 'POST',
                    data: ajaxData,
                    success: function(response) {
                        debugLog(' Réponse AJAX reçue:', 'success');
                        debugLog('Statut: ' + (response.valid ? 'SUCCÈS' : 'ÉCHEC'), response.valid ? 'success' : 'error');
                        debugLog('Message: ' + response.message, response.valid ? 'success' : 'error');
                        
                        if (response.valid) {
                            showMessage(response.message, 'success');
                            debugLog(' Opération réussie!', 'success');
                            
                            if (!isEdit) {
                                $('#mp-creator-form')[0].reset();
                                debugLog('Formulaire réinitialisé pour nouvelle création', 'info');
                            }
                            
                            // Recharger la page après 1.5 secondes
                            setTimeout(function() {
                                debugLog('Rechargement de la page...', 'info');
                                window.location.reload();
                            }, 1500);
                        } else {
                            showMessage(response.message, 'error');
                            debugLog('❌ Échec de l\'opération: ' + response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        debugLog('❌ Erreur AJAX:', 'error');
                        debugLog('Status: ' + status, 'error');
                        debugLog('Error: ' + error, 'error');
                        debugLog('Response: ' + xhr.responseText, 'error');
                        
                        showMessage('Une erreur réseau est survenue.', 'error');
                    },
                    complete: function() {
                        debugLog('=== FIN SOUMISSION FORMULAIRE ===', 'info');
                    }
                });
            });
            
            // Gestion de la suppression avec logs
            $('.mp-delete-creator').on('click', function() {
                var creatorId = $(this).data('id');
                var creatorName = $(this).closest('tr').find('td:first').text();
                
                debugLog('=== TENTATIVE DE SUPPRESSION ===', 'warning');
                debugLog('ID créateur: ' + creatorId, 'warning');
                debugLog('Nom créateur: ' + creatorName, 'warning');
                
                if (!confirm(mp_creator_ajax.confirm_delete + '\n\nCréateur: ' + creatorName)) {
                    debugLog('❌ Suppression annulée par l\'utilisateur', 'warning');
                    return;
                }
                
                var button = $(this);
                debugLog(' Confirmation reçue, envoi de la requête...', 'info');
                
                $.ajax({
                    url: mp_creator_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'delete_creator',
                        nonce: mp_creator_ajax.nonce,
                        id: creatorId
                    },
                    success: function(response) {
                        debugLog(' Réponse suppression reçue:', 'success');
                        debugLog('Statut: ' + (response.valid ? 'SUCCÈS' : 'ÉCHEC'), response.valid ? 'success' : 'error');
                        debugLog('Message: ' + response.message, response.valid ? 'success' : 'error');
                        
                        if (response.valid) {
                            showMessage(response.message, 'success');
                            button.closest('tr').fadeOut();
                            debugLog(' Créateur supprimé avec succès', 'success');
                        } else {
                            showMessage(response.message, 'error');
                            debugLog('❌ Échec de la suppression: ' + response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        debugLog('❌ Erreur AJAX lors de la suppression:', 'error');
                        debugLog('Status: ' + status, 'error');
                        debugLog('Error: ' + error, 'error');
                        
                        showMessage('Une erreur est survenue lors de la suppression.', 'error');
                    }
                });
            });
            
            // Fonction pour afficher les messages
            function showMessage(message, type) {
                $('#mp-creator-messages').html(
                    '<div class="mp-creator-message ' + type + '">' + message + '</div>'
                );
                
                // Supprimer le message après 5 secondes
                setTimeout(function() {
                    $('#mp-creator-messages').empty();
                }, 5000);
            }

            // Log au chargement de la page
            debugLog('Page admin MP Creator chargée', 'success');
            debugLog('Nombre de créateurs: ' + <?php echo count($creators); ?>, 'info');
            debugLog('Nombre de marques disponibles: ' + <?php echo count($brands); ?>, 'info');
            debugLog('Taxonomie product_brand: ' + (<?php echo $taxonomy_exists ? 'true' : 'false'; ?> ? 'PRÉSENTE' : 'ABSENTE'), 
                     <?php echo $taxonomy_exists ? "'success'" : "'error'"; ?>);
            
            // Log si en mode édition
            <?php if (isset($_GET['edit']) && $creator): ?>
            debugLog('Mode ÉDITION activé pour le créateur: ' + '<?php echo esc_js($creator->name); ?>', 'info');
            <?php endif; ?>
        });
        </script>
<?php
    }
}