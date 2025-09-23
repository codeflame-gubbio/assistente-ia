<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Gestione access token OAuth2 per Service Account (Google) con cache e rinnovo.
 * Accetta credenziali in Base64 oppure JSON in chiaro (auto-rilevamento).
 */
class Assistente_IA_Token {

    /** Legge il JSON del service account (in chiaro o Base64) dalle opzioni */
    protected static function leggi_config_service_account(): array {
        $raw = get_option('assia_credenziali_base64','');
        if ( empty($raw) ) { return []; }

        // Se inizia con "{", lo tratto come JSON in chiaro
        if ( strpos(ltrim($raw), '{') === 0 ) {
            $json = $raw;
        } else {
            // Altrimenti provo Base64
            $raw = preg_replace('/\s+/', '', $raw);
            $json = base64_decode($raw, true);
            if ($json === false || $json === null) { $json = $raw; }
        }
        $cfg = json_decode($json, true);
        if ( ! is_array($cfg) ) { return []; }

        // Normalizzo la chiave privata (newline)
        if ( ! empty($cfg['private_key']) && is_string($cfg['private_key']) ) {
            if ( strpos($cfg['private_key'], '\\\\n') !== false ) {
                $cfg['private_key'] = str_replace('\\\\n', "\n", $cfg['private_key']);
            } elseif ( strpos($cfg['private_key'], '\n') !== false ) {
                $cfg['private_key'] = str_replace('\n', "\n", $cfg['private_key']);
            }
        }
        return $cfg;
    }

    /** Restituisce un token valido (con cache in opzione) */
    public static function ottieni_token_accesso(): string {
        $s=get_option('assia_token_gemini');
        if(is_array($s)&&!empty($s['access_token'])&&!empty($s['expires_at'])){
            if(time() < (int)$s['expires_at']-60){ return $s['access_token']; }
        }
        return self::genera_token_accesso();
    }

    /** Genera un nuovo token con JWT firmato (service account) */
    public static function genera_token_accesso(): string {
        $cfg=self::leggi_config_service_account();
        if(empty($cfg['client_email'])||empty($cfg['private_key'])) return '';

        $header=['alg'=>'RS256','typ'=>'JWT'];
        $scope='https://www.googleapis.com/auth/cloud-platform';
        $now=time();
        $claim=['iss'=>$cfg['client_email'],'scope'=>$scope,'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600];
        $jwt_header=rtrim(strtr(base64_encode(wp_json_encode($header)),'+/','-_'),'=');
        $jwt_claim=rtrim(strtr(base64_encode(wp_json_encode($claim)),'+/','-_'),'=');
        $to_sign=$jwt_header.'.'.$jwt_claim;

        $sig=''; if(!openssl_sign($to_sign,$sig,$cfg['private_key'],'sha256')) return '';
        $jwt_sig=rtrim(strtr(base64_encode($sig),'+/','-_'),'=');
        $assertion=$to_sign.'.'.$jwt_sig;

        $res=wp_remote_post('https://oauth2.googleapis.com/token',[
            'body'=>['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion],
            'timeout'=>20
        ]);
        if(is_wp_error($res)) return '';
        $code=wp_remote_retrieve_response_code($res);
        $body=json_decode(wp_remote_retrieve_body($res),true);
        if(200!==$code||empty($body['access_token'])) return '';
        $token=$body['access_token']; $exp=$now+(int)($body['expires_in']??3600);
        update_option('assia_token_gemini',['access_token'=>$token,'expires_at'=>$exp]);
        return $token;
    }

    /** Check veloce credenziali */
    public static function diagnostica_credenziali(): array {
        $cfg=self::leggi_config_service_account();
        if(empty($cfg)) return ['ok'=>false,'note'=>'Credenziali non presenti o non valide (Base64/JSON).'];
        $manc=[]; foreach(['type','project_id','client_email','private_key'] as $k){ if(empty($cfg[$k])) $manc[]=$k; }
        if($manc) return ['ok'=>false,'note'=>'Campi mancanti: '.implode(', ',$manc)];
        return ['ok'=>true,'note'=>'Credenziali lette correttamente per '.$cfg['client_email']];
    }
}
