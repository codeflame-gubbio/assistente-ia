<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Funzioni di utilità generiche (sanitizzazione, rate limit, ecc.).
 */
class Assistente_IA_Utilita {

    /** Rileva IP reale (best effort) */
    public static function ottieni_indirizzo_ip(): string {
        $ip='';
        if(!empty($_SERVER['HTTP_CLIENT_IP'])){ $ip=sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP'])); }
        elseif(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){ $raw=sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])); $parts=explode(',',$raw); $ip=trim($parts[0]); }
        elseif(!empty($_SERVER['REMOTE_ADDR'])){ $ip=sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])); }
        return $ip ?: '0.0.0.0';
    }

    /** Rate limit per IP+sessione */
    public static function limita_richieste_utente(string $hash_sessione): void {
        $max=(int)get_option('assia_rate_limite_max',8);
        $fin=(int)get_option('assia_rate_limite_finestra_sec',60);
        if($max<=0||$fin<=0) return;
        $ip=self::ottieni_indirizzo_ip();
        $k='assia_rl_'.md5($ip.'|'.$hash_sessione);
        $c=(int)get_transient($k);
        if($c>=$max){ wp_send_json_error(['messaggio'=>'Hai raggiunto il limite di richieste; riprova tra poco.']); }
        set_transient($k,$c+1,$fin);
    }

    /** Pulisce in modo aggressivo un testo (tag, spazi, ecc.) */
    public static function pulisci_testo(string $t): string {
        $t=wp_strip_all_tags($t);
        $t=preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }

    /** Tronca un testo rispettando una lunghezza massima */
    public static function tronca(string $t,int $n=800): string {
        if(strlen($t)<= $n) return $t;
        return substr($t,0,$n-1).'…';
    }
}

