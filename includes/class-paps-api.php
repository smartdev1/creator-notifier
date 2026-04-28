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

    public function authenticate()
    {
        $client_id = get_option('mp_paps_client_id', '');
        $client_secret = get_option('mp_paps_client_secret', '');

        if (empty($client_id) || empty($client_secret)) {
            return new WP_Error('missing_credentials', 'Les identifiants PAPS ne sont pas configurés.');
        }

        $response = wp_remote_post($this->base_url . '/auth/login', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => json_encode(array(
                'clientId'     => $client_id,
                'clientSecret' => $client_secret,
            )),
            'timeout' => 15,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            error_log('MP PAPS - Erreur authentification: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 && $code !== 201) {
            $msg = isset($body['message']) ? $body['message'] : 'Erreur inconnue';
            error_log('MP PAPS - Auth échouée (HTTP ' . $code . '): ' . $msg);
            return new WP_Error('auth_failed', $msg);
        }

        if (empty($body['data']['token'])) {
            return new WP_Error('no_token', 'Aucun token reçu de l\'API PAPS.');
        }

        $this->token = $body['data']['token'];

        $expiration_date = isset($body['data']['expiration']) ? strtotime($body['data']['expiration']) : (time() + 3600);
        $ttl = max(60, $expiration_date - time() - 60);

        set_transient('mp_paps_token', $this->token, $ttl);
        set_transient('mp_paps_token_expiration', $expiration_date, $ttl);

        error_log('MP PAPS - Authentification réussie, token valide jusqu\'à ' . date('Y-m-d H:i:s', $expiration_date));
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
        delete_transient('mp_paps_token');
        delete_transient('mp_paps_token_expiration');
        $this->token = null;

        $result = $this->authenticate();

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'message' => 'Connexion à l\'API PAPS réussie ! Token obtenu avec succès.',
        );
    }
}