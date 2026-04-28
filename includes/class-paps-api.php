<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Paps_API
{
    private static $instance = null;
    private $base_url = 'https://api.papslogistics.com';
    private $token = null;
    private $token_expiration = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->token = get_transient('mp_paps_token');
        $this->token_expiration = get_transient('mp_paps_token_expiration');
    }

    public function authenticate($client_id = null, $client_secret = null)
    {
        // Si pas d'arguments, on prend les options
        if ($client_id === null) $client_id = get_option('mp_paps_client_id', '');
        if ($client_secret === null) $client_secret = get_option('mp_paps_client_secret', '');

        $log_prefix = '[PAPS DEBUG] ';

        error_log($log_prefix . '=== DÉBUT AUTHENTIFICATION ===');
        error_log($log_prefix . 'Client ID: ' . $client_id);

        if (empty($client_id) || empty($client_secret)) {
            $msg = 'Identifiants manquants.';
            error_log($log_prefix . 'ERREUR: ' . $msg);
            return new WP_Error('missing_credentials', $msg);
        }

        $auth_url = rtrim($this->base_url, '/') . '/auth/login';

        $payload = array(
            'clientId'     => $client_id,
            'clientSecret' => $client_secret,
        );

        $body_json = json_encode($payload);
        error_log($log_prefix . 'URL: ' . $auth_url);
        error_log($log_prefix . 'Payload: ' . $body_json);

        // UTILISER cURL DIRECTEMENT AU LIEU DE wp_remote_post
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $auth_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($body_json)
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Temporaire pour test
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response_body = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        error_log($log_prefix . 'cURL Response Code: ' . $response_code);
        if ($curl_error) {
            error_log($log_prefix . 'cURL Error: ' . $curl_error);
        }
        error_log($log_prefix . 'cURL Response: ' . $response_body);

        curl_close($ch);

        if ($curl_error) {
            return new WP_Error('curl_error', $curl_error);
        }

        $body = json_decode($response_body, true);

        if ($response_code !== 200 && $response_code !== 201) {
            $msg = isset($body['message']) ? $body['message'] : (isset($body['error']) ? $body['error'] : 'Erreur HTTP ' . $response_code);
            error_log($log_prefix . 'ECHEC AUTH: ' . $msg);
            return new WP_Error('auth_failed', $msg);
        }

        if (empty($body['data']['token'])) {
            error_log($log_prefix . 'ERREUR: Aucun token dans la réponse.');
            return new WP_Error('no_token', 'Aucun token reçu.');
        }

        $this->token = $body['data']['token'];
        $expiration_date = isset($body['data']['expiration']) ? strtotime($body['data']['expiration']) : (time() + 3600);
        $ttl = max(60, $expiration_date - time() - 60);

        set_transient('mp_paps_token', $this->token, $ttl);
        set_transient('mp_paps_token_expiration', $expiration_date, $ttl);

        error_log($log_prefix . 'SUCCÈS: Token obtenu.');
        return true;
    }

    private function get_token()
    {
        if ($this->token && $this->token_expiration && time() < ($this->token_expiration - 60)) {
            return $this->token;
        }

        $result = $this->authenticate();

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->token;
    }

    public function get_delivery_fee($origin, $destination, $size_details, $delivery_type = 'STANDARD')
    {
        $token = $this->get_token();

        if (is_wp_error($token)) {
            return $token;
        }

        if (empty($size_details) || !is_array($size_details)) {
            return new WP_Error('invalid_size_details', 'Les détails de taille des colis sont invalides.');
        }

        $payload = array(
            'origin'       => sanitize_text_field($origin),
            'destination'  => sanitize_text_field($destination),
            'deliveryType' => in_array($delivery_type, array('STANDARD', 'EXPRESS')) ? $delivery_type : 'STANDARD',
            'sizeDetails'  => $size_details,
        );

        $response = wp_remote_post($this->base_url . '/marketplace/price-of-multiple-parcels', array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'body'    => json_encode($payload),
            'timeout' => 20,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            error_log('MP PAPS - Erreur calcul frais: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 && $code !== 201) {
            $msg = isset($body['message']) ? $body['message'] : 'Erreur lors du calcul des frais de livraison.';
            error_log('MP PAPS - Calcul frais échoué (HTTP ' . $code . '): ' . $msg);

            if ($code === 401) {
                delete_transient('mp_paps_token');
                delete_transient('mp_paps_token_expiration');
                $this->token = null;

                $new_token = $this->get_token();
                if (is_wp_error($new_token)) {
                    return $new_token;
                }

                return $this->get_delivery_fee($origin, $destination, $size_details, $delivery_type);
            }

            return new WP_Error('api_error', $msg);
        }

        if (!isset($body['data'])) {
            return new WP_Error('invalid_response', 'Réponse invalide de l\'API PAPS.');
        }

        return array(
            'price'       => isset($body['data']['price']) ? intval($body['data']['price']) : 0,
            'packageSize' => isset($body['data']['packageSize']) ? $body['data']['packageSize'] : 'N/A',
            'distance'    => isset($body['data']['distance']) ? intval($body['data']['distance']) : 0,
        );
    }

    public static function build_size_details_from_cart($cart)
    {
        $size_details = array();

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];

            $weight = $product->get_weight() ? floatval($product->get_weight()) : 1;
            $height = $product->get_height() ? floatval($product->get_height()) : 10;
            $width  = $product->get_width()  ? floatval($product->get_width())  : 10;
            $length = $product->get_length() ? floatval($product->get_length()) : 10;

            $size_details[] = array(
                'quantity' => intval($quantity),
                'weight'   => max(0.1, $weight),
                'height'   => max(1, $height),
                'width'    => max(1, $width),
                'length'   => max(1, $length),
            );
        }

        return $size_details;
    }

    public function test_connection()
    {
        error_log('[PAPS TEST] === DÉBUT TEST CONNEXION ===');

        delete_transient('mp_paps_token');
        delete_transient('mp_paps_token_expiration');
        $this->token = null;

        $client_id = get_option('mp_paps_client_id', '');
        $client_secret = get_option('mp_paps_client_secret', '');

        error_log('[PAPS TEST] Client ID: ' . $client_id);
        error_log('[PAPS TEST] Client Secret length: ' . strlen($client_secret));

        // TEST DIRECT AVEC cURL (contourne wp_remote_post)
        $auth_url = 'https://api.papslogistics.com/auth/login';

        $payload = array(
            'clientId' => $client_id,
            'clientSecret' => $client_secret
        );

        $body_json = json_encode($payload);

        error_log('[PAPS TEST] Payload JSON: ' . $body_json);

        // Option 1: cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $auth_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($body_json)
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response_body = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        error_log('[PAPS TEST] cURL Response Code: ' . $response_code);
        error_log('[PAPS TEST] cURL Error: ' . $curl_error);
        error_log('[PAPS TEST] cURL Response Body: ' . $response_body);

        curl_close($ch);

        if ($response_code === 200 || $response_code === 201) {
            error_log('[PAPS TEST] SUCCÈS avec cURL!');
            return array(
                'success' => true,
                'message' => 'Connexion API PAPS réussie !'
            );
        }

        // Option 2: wp_remote_post avec débogage
        error_log('[PAPS TEST] Tentative avec wp_remote_post...');

        $args = array(
            'method'    => 'POST',
            'headers'   => array(
                'Content-Type' => 'application/json',
            ),
            'body'      => $body_json,
            'timeout'   => 30,
            'sslverify' => false,
        );

        error_log('[PAPS TEST] wp_remote_post args: ' . print_r($args, true));

        $response = wp_remote_post($auth_url, $args);

        if (is_wp_error($response)) {
            error_log('[PAPS TEST] wp_remote_post WP_Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => 'Erreur réseau: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('[PAPS TEST] wp_remote_post Response Code: ' . $code);
        error_log('[PAPS TEST] wp_remote_post Response Body: ' . $body);

        if ($code === 200 || $code === 201) {
            $data = json_decode($body, true);
            if (!empty($data['data']['token'])) {
                return array(
                    'success' => true,
                    'message' => 'Connexion API PAPS réussie !'
                );
            }
        }

        return array(
            'success' => false,
            'message' => 'Échec connexion. Code: ' . $code . ' - ' . $body
        );
    }
}
