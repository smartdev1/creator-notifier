<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Paps_Settings
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
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_mp_test_paps_connection', array($this, 'handle_test_connection'));
        add_action('wp_ajax_mp_calculate_paps_fee_test', array($this, 'handle_fee_test'));
        add_action('wp_ajax_mp_simple_test', array($this, 'ajax_simple_test'));
    }

    public function ajax_simple_test()
    {
        error_log('[SIMPLE TEST] AJAX function works!');
        wp_send_json_success(array('message' => 'AJAX fonctionne!'));
    }

    public function add_settings_page()
    {
        add_submenu_page(
            'woocommerce',
            'Paramètres PAPS',
            'PAPS Livraison',
            'manage_woocommerce',
            'mp-paps-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'woocommerce_page_mp-paps-settings') {
            return;
        }
    }

    private function save_settings()
    {
        if (!isset($_POST['mp_paps_nonce']) || !wp_verify_nonce($_POST['mp_paps_nonce'], 'mp_paps_save_settings')) {
            return false;
        }

        if (!current_user_can('manage_woocommerce')) {
            return false;
        }

        update_option('mp_paps_enabled',        isset($_POST['mp_paps_enabled']) ? 1 : 0);
        update_option('mp_paps_client_id',       sanitize_text_field($_POST['mp_paps_client_id'] ?? ''));
        update_option('mp_paps_client_secret',   sanitize_text_field($_POST['mp_paps_client_secret'] ?? ''));
        update_option('mp_paps_default_origin',  sanitize_text_field($_POST['mp_paps_default_origin'] ?? ''));
        update_option('mp_paps_delivery_type',   in_array($_POST['mp_paps_delivery_type'] ?? '', array('STANDARD', 'EXPRESS')) ? $_POST['mp_paps_delivery_type'] : 'STANDARD');
        update_option('mp_paps_default_weight',  floatval($_POST['mp_paps_default_weight'] ?? 1));
        update_option('mp_paps_default_height',  floatval($_POST['mp_paps_default_height'] ?? 10));
        update_option('mp_paps_default_width',   floatval($_POST['mp_paps_default_width']  ?? 10));
        update_option('mp_paps_default_length',  floatval($_POST['mp_paps_default_length'] ?? 10));

        delete_transient('mp_paps_token');
        delete_transient('mp_paps_token_expiration');

        return true;
    }

    public function render_settings_page()
    {
        $saved = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mp_paps_save'])) {
            $saved = $this->save_settings();
        }

        $enabled         = get_option('mp_paps_enabled', false);
        $client_id       = get_option('mp_paps_client_id', '');
        $client_secret   = get_option('mp_paps_client_secret', '');
        $default_origin  = get_option('mp_paps_default_origin', '');
        $delivery_type   = get_option('mp_paps_delivery_type', 'STANDARD');
        $default_weight  = get_option('mp_paps_default_weight', 1);
        $default_height  = get_option('mp_paps_default_height', 10);
        $default_width   = get_option('mp_paps_default_width', 10);
        $default_length  = get_option('mp_paps_default_length', 10);

        $token_valid = (bool) get_transient('mp_paps_token');
?>
        <div class="wrap">
            <h1>
                <span style="display:inline-flex;align-items:center;gap:10px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#2271b1" stroke-width="2">
                        <path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3" />
                        <rect x="9" y="11" width="14" height="10" rx="1" />
                        <circle cx="12" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                    </svg>
                    Paramètres PAPS Logistics
                </span>
            </h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Paramètres sauvegardés avec succès.</strong></p>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-top:20px;">

                <!-- Colonne principale -->
                <div>
                    <form method="post">
                        <?php wp_nonce_field('mp_paps_save_settings', 'mp_paps_nonce'); ?>

                        <!-- Activation -->
                        <div class="postbox" style="margin-bottom:20px;">
                            <div class="postbox-header">
                                <h2 class="hndle">⚙️ Activation</h2>
                            </div>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th>Activer PAPS</th>
                                        <td>
                                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                                <input type="checkbox" name="mp_paps_enabled" value="1" <?php checked($enabled, 1); ?> style="width:18px;height:18px;">
                                                <span>Activer le calcul automatique des frais de livraison PAPS lors du checkout</span>
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Identifiants API -->
                        <div class="postbox" style="margin-bottom:20px;">
                            <div class="postbox-header">
                                <h2 class="hndle">🔐 Identifiants API PAPS</h2>
                            </div>
                            <div class="inside">
                                <p style="color:#666;margin-bottom:16px;">
                                    Ces identifiants vous sont fournis par PAPS Logistics. Contactez
                                    <a href="https://www.papslogistics.com" target="_blank">papslogistics.com</a> pour obtenir vos accès API.
                                </p>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="mp_paps_client_id">Client ID <span style="color:red">*</span></label></th>
                                        <td>
                                            <input type="text" id="mp_paps_client_id" name="mp_paps_client_id"
                                                value="<?php echo esc_attr($client_id); ?>"
                                                class="regular-text" placeholder="votre_email@example.com" autocomplete="off">
                                            <p class="description">Votre adresse email ou identifiant PAPS</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="mp_paps_client_secret">Client Secret <span style="color:red">*</span></label></th>
                                        <td>
                                            <div style="display:flex;gap:8px;align-items:center;">
                                                <input type="password" id="mp_paps_client_secret" name="mp_paps_client_secret"
                                                    value="<?php echo esc_attr($client_secret); ?>"
                                                    class="regular-text" placeholder="••••••••••••" autocomplete="new-password">
                                                <button type="button" id="toggle-secret" class="button" title="Afficher/Masquer" style="padding:4px 8px;">
                                                    👁
                                                </button>
                                            </div>
                                            <p class="description">Votre mot de passe PAPS</p>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Statut du token -->
                                <div style="margin-top:12px;padding:10px 14px;border-radius:6px;border:1px solid <?php echo $token_valid ? '#c3e6cb' : '#f5c6cb'; ?>;background:<?php echo $token_valid ? '#d4edda' : '#f8d7da'; ?>;color:<?php echo $token_valid ? '#155724' : '#721c24'; ?>;">
                                    <?php if ($token_valid): ?>
                                        ✅ Token actif en cache — connexion établie avec l'API PAPS
                                    <?php else: ?>
                                        ⚠️ Aucun token en cache — une authentification sera effectuée au prochain calcul
                                    <?php endif; ?>
                                </div>

                                <!-- Bouton de test -->
                                <div style="margin-top:14px;display:flex;gap:12px;align-items:center;">
                                    <button type="button" id="btn-test-paps" class="button button-secondary">
                                        🔌 Tester la connexion
                                    </button>
                                    <span id="paps-test-result" style="font-weight:600;"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Configuration livraison -->
                        <div class="postbox" style="margin-bottom:20px;">
                            <div class="postbox-header">
                                <h2 class="hndle">🚚 Configuration de la livraison</h2>
                            </div>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th><label for="mp_paps_default_origin">Adresse d'origine <span style="color:red">*</span></label></th>
                                        <td>
                                            <input type="text" id="mp_paps_default_origin" name="mp_paps_default_origin"
                                                value="<?php echo esc_attr($default_origin); ?>"
                                                class="large-text" placeholder="ex: Dakar, Sénégal">
                                            <p class="description">L'adresse de votre entrepôt ou point de collecte (utilisée comme point de départ).</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="mp_paps_delivery_type">Type de livraison</label></th>
                                        <td>
                                            <select id="mp_paps_delivery_type" name="mp_paps_delivery_type">
                                                <option value="STANDARD" <?php selected($delivery_type, 'STANDARD'); ?>>Standard</option>
                                                <option value="EXPRESS" <?php selected($delivery_type, 'EXPRESS'); ?>>Express</option>
                                            </select>
                                            <p class="description">Type de livraison par défaut pour le calcul des frais.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Dimensions par défaut -->
                        <div class="postbox" style="margin-bottom:20px;">
                            <div class="postbox-header">
                                <h2 class="hndle">📏 Dimensions de colis par défaut</h2>
                            </div>
                            <div class="inside">
                                <p style="color:#666;margin-bottom:12px;">
                                    Ces valeurs sont utilisées pour les produits qui n'ont pas de dimensions définies dans WooCommerce.
                                </p>
                                <table class="form-table">
                                    <tr>
                                        <th>Poids par défaut (kg)</th>
                                        <td><input type="number" name="mp_paps_default_weight" value="<?php echo esc_attr($default_weight); ?>" step="0.1" min="0.1" style="width:100px;"> kg</td>
                                    </tr>
                                    <tr>
                                        <th>Hauteur par défaut (cm)</th>
                                        <td><input type="number" name="mp_paps_default_height" value="<?php echo esc_attr($default_height); ?>" step="1" min="1" style="width:100px;"> cm</td>
                                    </tr>
                                    <tr>
                                        <th>Largeur par défaut (cm)</th>
                                        <td><input type="number" name="mp_paps_default_width" value="<?php echo esc_attr($default_width); ?>" step="1" min="1" style="width:100px;"> cm</td>
                                    </tr>
                                    <tr>
                                        <th>Longueur par défaut (cm)</th>
                                        <td><input type="number" name="mp_paps_default_length" value="<?php echo esc_attr($default_length); ?>" step="1" min="1" style="width:100px;"> cm</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <p class="submit">
                            <input type="submit" name="mp_paps_save" class="button button-primary button-large" value="💾 Sauvegarder les paramètres">
                        </p>
                    </form>
                </div>

                <!-- Colonne latérale -->
                <div>

                    <!-- Simulateur de frais -->
                    <div class="postbox" style="margin-bottom:20px;">
                        <div class="postbox-header">
                            <h2 class="hndle">🧪 Simuler un calcul</h2>
                        </div>
                        <div class="inside">
                            <p style="color:#666;font-size:13px;">Testez le calcul des frais de livraison avec des adresses personnalisées.</p>
                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <div>
                                    <label style="font-size:12px;font-weight:600;">Origine</label>
                                    <input type="text" id="sim-origin" value="<?php echo esc_attr($default_origin); ?>" class="widefat" style="margin-top:3px;" placeholder="ex: Dakar, Sénégal">
                                </div>
                                <div>
                                    <label style="font-size:12px;font-weight:600;">Destination</label>
                                    <input type="text" id="sim-destination" class="widefat" style="margin-top:3px;" placeholder="ex: Abidjan, Côte d'Ivoire">
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                                    <div>
                                        <label style="font-size:12px;font-weight:600;">Quantité</label>
                                        <input type="number" id="sim-qty" value="1" min="1" class="widefat" style="margin-top:3px;">
                                    </div>
                                    <div>
                                        <label style="font-size:12px;font-weight:600;">Poids (kg)</label>
                                        <input type="number" id="sim-weight" value="<?php echo esc_attr($default_weight); ?>" min="0.1" step="0.1" class="widefat" style="margin-top:3px;">
                                    </div>
                                </div>
                                <button type="button" id="btn-sim-paps" class="button button-primary widefat">
                                    🔢 Calculer les frais
                                </button>
                                <div id="sim-result" style="display:none;padding:12px;border-radius:6px;border:1px solid #bee5eb;background:#d1ecf1;color:#0c5460;text-align:center;">
                                </div>
                                <div id="sim-error" style="display:none;padding:12px;border-radius:6px;border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Aide -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2 class="hndle">ℹ️ Aide & Informations</h2>
                        </div>
                        <div class="inside" style="font-size:13px;">
                            <p><strong>Comment ça fonctionne :</strong></p>
                            <ol style="padding-left:16px;color:#555;line-height:1.8;">
                                <li>Le client ajoute des articles au panier</li>
                                <li>Il renseigne son adresse de livraison</li>
                                <li>WooCommerce appelle l'API PAPS pour calculer le tarif</li>
                                <li>Le tarif s'affiche automatiquement dans le récapitulatif</li>
                            </ol>
                            <hr>
                            <p><strong>Activation de la méthode de livraison :</strong></p>
                            <p style="color:#555;">Après avoir configuré vos identifiants, activez la méthode <em>"PAPS Livraison"</em> dans :</p>
                            <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=shipping'); ?>" class="button widefat" style="text-align:center;">
                                WooCommerce → Livraison
                            </a>
                            <hr>
                            <p style="color:#555;"><strong>Dimensions produits :</strong> Pour un calcul précis, renseignez le poids et les dimensions de chaque produit dans son onglet <em>Données produit → Livraison</em>.</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {

                // Afficher/Masquer le secret
                $('#toggle-secret').on('click', function() {
                    var field = $('#mp_paps_client_secret');
                    field.attr('type', field.attr('type') === 'password' ? 'text' : 'password');
                });

                // Test de connexion
                $('#btn-test-paps').on('click', function() {
                    var btn = $(this);
                    var result = $('#paps-test-result');

                    btn.prop('disabled', true).text('⏳ Test en cours...');
                    result.text('').css('color', '#666');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mp_test_paps_connection',
                            nonce: '<?php echo wp_create_nonce('mp_paps_test'); ?>',
                            client_id: $('#mp_paps_client_id').val(),
                            client_secret: $('#mp_paps_client_secret').val(),
                        },
                        success: function(response) {
                            if (response.success) {
                                result.text('✅ ' + response.data.message).css('color', '#155724');
                            } else {
                                result.text('❌ ' + response.data.message).css('color', '#721c24');
                            }
                        },
                        error: function() {
                            result.text('❌ Erreur réseau').css('color', '#721c24');
                        },
                        complete: function() {
                            btn.prop('disabled', false).text('🔌 Tester la connexion');
                        }
                    });
                });

                // Simulateur de frais
                $('#btn-sim-paps').on('click', function() {
                    var btn = $(this);
                    var origin = $('#sim-origin').val().trim();
                    var destination = $('#sim-destination').val().trim();
                    var qty = parseInt($('#sim-qty').val()) || 1;
                    var weight = parseFloat($('#sim-weight').val()) || 1;

                    $('#sim-result, #sim-error').hide();

                    if (!origin || !destination) {
                        $('#sim-error').text('⚠️ Veuillez renseigner l\'origine et la destination.').show();
                        return;
                    }

                    btn.prop('disabled', true).text('⏳ Calcul en cours...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mp_calculate_paps_fee_test',
                            nonce: '<?php echo wp_create_nonce('mp_paps_fee_test'); ?>',
                            origin: origin,
                            destination: destination,
                            qty: qty,
                            weight: weight,
                            delivery_type: $('#mp_paps_delivery_type').val() || 'STANDARD',
                        },
                        success: function(response) {
                            if (response.success) {
                                var d = response.data;
                                var html = '<strong style="font-size:18px;">' + d.price_formatted + '</strong><br>';
                                html += '<small>Taille : ' + d.package_size + ' · Distance : ' + d.distance + ' km</small>';
                                $('#sim-result').html(html).show();
                            } else {
                                $('#sim-error').text('❌ ' + response.data.message).show();
                            }
                        },
                        error: function() {
                            $('#sim-error').text('❌ Erreur réseau').show();
                        },
                        complete: function() {
                            btn.prop('disabled', false).text('🔢 Calculer les frais');
                        }
                    });
                });
            });
        </script>
<?php
    }

    public function handle_test_connection()
    {

        file_put_contents(__DIR__ . '/paps-debug.log', "handle_test_connection called at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

        check_ajax_referer('mp_paps_test', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Accès refusé.'));
        }

        $client_id     = sanitize_text_field($_POST['client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');

        if (empty($client_id) || empty($client_secret)) {
            wp_send_json_error(array('message' => 'Veuillez renseigner le Client ID et le Client Secret.'));
        }

        $old_id     = get_option('mp_paps_client_id');
        $old_secret = get_option('mp_paps_client_secret');

        update_option('mp_paps_client_id', $client_id);
        update_option('mp_paps_client_secret', $client_secret);
        delete_transient('mp_paps_token');
        delete_transient('mp_paps_token_expiration');

        $paps   = MP_Paps_API::get_instance();
        $result = $paps->test_connection();

        if (!$result['success']) {
            update_option('mp_paps_client_id', $old_id);
            update_option('mp_paps_client_secret', $old_secret);
        }

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    public function handle_fee_test()
    {
        check_ajax_referer('mp_paps_fee_test', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Accès refusé.'));
        }

        $origin        = sanitize_text_field($_POST['origin'] ?? '');
        $destination   = sanitize_text_field($_POST['destination'] ?? '');
        $qty           = max(1, intval($_POST['qty'] ?? 1));
        $weight        = max(0.1, floatval($_POST['weight'] ?? 1));
        $delivery_type = in_array($_POST['delivery_type'] ?? '', array('STANDARD', 'EXPRESS')) ? $_POST['delivery_type'] : 'STANDARD';

        $default_height = floatval(get_option('mp_paps_default_height', 10));
        $default_width  = floatval(get_option('mp_paps_default_width', 10));
        $default_length = floatval(get_option('mp_paps_default_length', 10));

        $size_details = array(
            array(
                'quantity' => $qty,
                'weight'   => $weight,
                'height'   => $default_height,
                'width'    => $default_width,
                'length'   => $default_length,
            ),
        );

        $paps   = MP_Paps_API::get_instance();
        $result = $paps->get_delivery_fee($origin, $destination, $size_details, $delivery_type);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $currency = get_woocommerce_currency_symbol();
        $price_formatted = number_format($result['price'], 0, ',', ' ') . ' ' . $currency;

        wp_send_json_success(array(
            'price'           => $result['price'],
            'price_formatted' => $price_formatted,
            'package_size'    => $result['packageSize'],
            'distance'        => number_format($result['distance'], 0, ',', ' '),
        ));
    }
}
