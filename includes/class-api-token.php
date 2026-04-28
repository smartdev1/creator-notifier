<?php

if (!defined('ABSPATH')) exit;

/**
 * MP_Creator_API_Token
 *
 * Génération, hachage et vérification des tokens d'authentification API.
 * Extrait de creator-notifier.php — aucune modification de comportement.
 */
class MP_Creator_API_Token
{
    public static function generate()
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash($token)
    {
        return hash('sha256', $token);
    }

    public static function verify($token, $stored_hash)
    {
        return hash_equals($stored_hash, self::hash($token));
    }

    public static function create_and_store()
    {
        $token = self::generate();
        update_option('mp_api_token_hash',       self::hash($token));
        update_option('mp_api_token_created_at', current_time('mysql'));
        return $token;
    }
}