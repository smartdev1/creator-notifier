<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MP_Order_Handler
 *
 * Responsabilité unique : envoyer les emails de notification aux créateurs
 * lors d'une nouvelle commande WooCommerce.
 *
 * Corrections appliquées (notifier2.md) :
 *  - Utilise MP_Creator_Ownership_Resolver au lieu de wp_get_post_terms() direct
 *  - Un seul point d'entrée de hook (woocommerce_order_status_processing)
 *  - Suppression du hook woocommerce_thankyou (source de doubles)
 *  - Déduplication via postmeta `_mp_creator_notified`
 *  - Séparation claire emails / webhooks Laravel (emails ici, webhooks dans le fichier principal)
 */
class MP_Order_Handler
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
        /*
         * Hooks unifiés.
         *
         * AVANT (source de doubles) :
         *   woocommerce_order_status_processing  → handle_new_order (priorité 20)
         *   woocommerce_order_status_completed   → handle_new_order (priorité 20)
         *   woocommerce_thankyou                 → handle_new_order (priorité 20)  ← supprimé
         *
         * APRÈS :
         *   woocommerce_order_status_processing  → handle_new_order (priorité 20)
         *   woocommerce_order_status_completed   → handle_new_order (priorité 20)
         *
         * La déduplication via `_mp_creator_notified` empêche les doubles
         * si les deux statuts sont atteints pour une même commande.
         */
        add_action('woocommerce_order_status_processing', [$this, 'handle_new_order'], 20);
        add_action('woocommerce_order_status_completed',  [$this, 'handle_new_order'], 20);

        error_log('MP Order Handler: Hooks enregistrés (processing + completed).');
    }

    // =========================================================
    // HANDLER PRINCIPAL
    // =========================================================

    public function handle_new_order($order_id)
    {
        error_log("MP Order Handler: handle_new_order déclenché pour commande #{$order_id}");

        // --- Déduplication ---
        if (get_post_meta($order_id, '_mp_creator_notified', true)) {
            error_log("MP Order Handler: Commande #{$order_id} déjà notifiée, skip.");
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("MP Order Handler: Commande #{$order_id} introuvable.");
            return;
        }

        error_log("MP Order Handler: Traitement commande #{$order->get_order_number()} (statut: {$order->get_status()})");

        // --- Résolution créateurs via le resolver ---
        $resolver = MP_Creator_Ownership_Resolver::get_instance();
        $grouped  = $resolver->group_products_by_creator($order_id);

        if (empty($grouped)) {
            error_log("MP Order Handler: Aucun créateur trouvé pour commande #{$order_id}.");
            return;
        }

        error_log("MP Order Handler: " . count($grouped) . " créateur(s) identifié(s) pour commande #{$order_id}.");

        // --- Envoi des notifications ---
        $emails_sent = $this->notify_creators($grouped, $order);

        if ($emails_sent > 0) {
            update_post_meta($order_id, '_mp_creator_notified', current_time('mysql'));
            error_log("MP Order Handler: {$emails_sent} email(s) envoyé(s) pour commande #{$order_id}.");
        } else {
            error_log("MP Order Handler: Aucun email envoyé pour commande #{$order_id}.");
        }
    }

    // =========================================================
    // NOTIFICATIONS EMAIL
    // =========================================================

    /**
     * Envoie un email à chaque créateur avec uniquement ses produits.
     *
     * @param array  $grouped  Résultat de group_products_by_creator()
     * @param object $order    WC_Order
     * @return int  Nombre d'emails envoyés avec succès
     */
    private function notify_creators(array $grouped, $order)
    {
        $email_handler = MP_Creator_Email::get_instance();
        $db            = MP_Creator_DB::get_instance();
        $emails_sent   = 0;
        $total         = count($grouped);

        foreach ($grouped as $creator_id => $group) {
            $creator    = $group['creator'];
            $brand_data = [
                'brand_name' => $creator->brand_slug,
                'brand_slug' => $creator->brand_slug,
                'products'   => $group['products'],
            ];

            error_log("MP Order Handler: Envoi email à {$creator->name} ({$creator->email}) — " . count($group['products']) . " produit(s).");

            // Réinitialiser PHPMailer entre les envois pour éviter les
            // contaminations de destinataires (bug connu WordPress multi-envoi)
            $this->reset_phpmailer();

            // Délai léger entre emails si plusieurs créateurs
            if ($emails_sent > 0 && $total > 1) {
                sleep(2);
            }

            $sent = $email_handler->send_order_notification($creator, $brand_data, $order);

            // Log en base
            $subject = sprintf(
                __('New Order #%d for your products', 'mp-creator-notifier'),
                $order->get_id()
            );
            $db->log_notification(
                $creator->id,
                $order->get_id(),
                $subject,
                '',
                $sent ? 'sent' : 'failed',
                $sent ? null : 'PHPMailer returned false'
            );

            if ($sent) {
                error_log("MP Order Handler: Email envoyé à {$creator->email}.");
                $emails_sent++;
            } else {
                error_log("MP Order Handler: Échec envoi à {$creator->email}.");
                $this->log_phpmailer_error($creator->email);
            }
        }

        return $emails_sent;
    }

    // =========================================================
    // UTILITAIRES
    // =========================================================

    /**
     * Réinitialise PHPMailer entre les envois pour éviter les doublons
     * de destinataires sur les installations multi-email.
     */
    private function reset_phpmailer()
    {
        global $phpmailer;
        if (isset($phpmailer) && is_object($phpmailer)) {
            $phpmailer->clearAllRecipients();
            $phpmailer->clearAttachments();
            $phpmailer->clearCustomHeaders();
            $phpmailer->clearReplyTos();
        }
    }

    /**
     * Logue l'erreur PHPMailer si disponible.
     */
    private function log_phpmailer_error($email)
    {
        global $phpmailer;
        if (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
            error_log("MP Order Handler: Erreur PHPMailer pour {$email} — {$phpmailer->ErrorInfo}");
        }
    }

    // =========================================================
    // API PUBLIQUE (utilisée depuis l'extérieur si besoin)
    // =========================================================

    /**
     * Retourne les créateurs et leurs produits pour une commande donnée.
     * Utilisé par le filtre API REST filter_order_api_response().
     *
     * @param int $order_id
     * @return array
     */
    public function get_order_creators($order_id)
    {
        $resolver = MP_Creator_Ownership_Resolver::get_instance();
        $grouped  = $resolver->group_products_by_creator($order_id);
        $result   = [];

        foreach ($grouped as $creator_id => $group) {
            $result[] = [
                'creator'  => $group['creator'],
                'brand'    => $group['creator']->brand_slug,
                'products' => $group['products'],
                'total'    => $group['total'],
            ];
        }

        return $result;
    }
}