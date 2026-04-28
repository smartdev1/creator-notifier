<?php

if (!defined('ABSPATH')) {
    exit;
}

class MP_Creator_Email {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('woocommerce_email_classes', array($this, 'add_email_class'));
    }
    
    public function send_order_notification($creator, $brand_data, $order) {
        $to = $creator->email;
        $subject = $this->get_email_subject($order, $brand_data['brand_name']);
        $message = $this->get_email_content($creator, $brand_data, $order);
        $headers = $this->get_email_headers();
        
        // Utiliser wp_mail pour envoyer l'email
        $sent = wp_mail($to, $subject, $message, $headers);
        
        if (!$sent) {
            error_log('MP Creator Notifier - Erreur envoi email à: ' . $to);
        }
        
        return $sent;
    }
    
    private function get_email_subject($order, $brand_name) {
        return sprintf('Nouvelle commande pour vos produits %s - Commande #%s', 
            $brand_name, 
            $order->get_order_number()
        );
    }
    
    private function get_email_content($creator, $brand_data, $order) {
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Notification de commande</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f8f8; padding: 20px; text-align: center; border-radius: 5px; }
        .content { background: white; padding: 20px; border-radius: 5px; margin-top: 20px; }
        .product-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .product-table th, .product-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .product-table th { background-color: #f8f8f8; }
        .footer { margin-top: 30px; padding: 20px; background: #f8f8f8; text-align: center; border-radius: 5px; font-size: 14px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Nouvelle Commande Reçue</h1>
            <p>Bonjour <?php echo esc_html($creator->name); ?>,</p>
        </div>
        
        <div class="content">
            <p>Une nouvelle commande a été passée pour vos produits <strong><?php echo esc_html($brand_data['brand_name']); ?></strong>.</p>
            
            <h2>Détails de la commande</h2>
            <p><strong>Numéro de commande :</strong> #<?php echo esc_html($order->get_order_number()); ?></p>
            <p><strong>Date :</strong> <?php echo esc_html($order->get_date_created()->format('d/m/Y H:i')); ?></p>
            
            <h3>Produits commandés :</h3>
            <table class="product-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Quantité</th>
                        <th>Prix unitaire</th>
                        <th>SKU</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($brand_data['products'] as $product): ?>
                    <tr>
                        <td><?php echo esc_html($product['name']); ?></td>
                        <td><?php echo esc_html($product['quantity']); ?></td>
                        <td><?php echo wc_price($product['price']); ?></td>
                        <td><?php echo esc_html($product['sku']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <h3>Informations client :</h3>
            <p>
                <strong>Nom :</strong> <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?><br>
                <strong>Email :</strong> <?php echo esc_html($order->get_billing_email()); ?><br>
                <strong>Téléphone :</strong> <?php echo esc_html($order->get_billing_phone()); ?><br>
                <strong>Adresse :</strong><br>
                <?php echo esc_html($order->get_billing_address_1()); ?><br>
                <?php echo esc_html($order->get_billing_postcode() . ' ' . $order->get_billing_city()); ?><br>
                <?php echo esc_html($order->get_billing_country()); ?>
            </p>
        </div>
        
        <div class="footer">
            <p>Cet email a été envoyé automatiquement par le système de notification MP Creator.</p>
            <p>Ne pas répondre à cet email.</p>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    private function get_email_headers() {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_from_name() . ' <' . $this->get_from_address() . '>'
        );
        
        return $headers;
    }
    
    private function get_from_name() {
        return get_bloginfo('name');
    }
    
    private function get_from_address() {
        return get_option('admin_email');
    }
    
    public function add_email_class($email_classes) {
        // On pourrait ajouter une classe d'email WooCommerce personnalisée ici si nécessaire
        return $email_classes;
    }
}