<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assistente_IA_Modello_Vertex {

    public static function crea_endpoint_generate(): string {
        $loc = get_option('assia_localita','us-central1');
        $proj = get_option('assia_progetto_id','');
        $mod  = get_option('assia_modello','gemini-2.0-flash-001');
        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $loc, $proj, $loc, $mod
        );
    }
    public static function crea_endpoint_embeddings(): string {
        $loc = get_option('assia_localita','us-central1');
        $proj = get_option('assia_progetto_id','');
        $mod  = get_option('assia_modello_embedding','text-embedding-005');
        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict',
            $loc, $proj, $loc, $mod
        );
    }

    public static function genera_testo( string $prompt, array $meta = [] ): array {
        $token = Assistente_IA_Token::ottieni_token_accesso();
        if ( empty($token) ) {
            // Log errore
            Assistente_IA_Diagnostica_Modello::salva([
                'tipo'=>'generate','endpoint'=>self::crea_endpoint_generate(),'payload'=>self::corpo_richiesta_generazione($prompt),
                'risposta'=>null,'http_code'=>null,'errore'=>'Token non disponibile','id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
            ]);
            return ['errore'=>'Token non disponibile. Controlla credenziali.'];
        }

        $body = self::corpo_richiesta_generazione( $prompt );
        $res=wp_remote_post(self::crea_endpoint_generate(),[
            'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($body),
            'timeout'=>45
        ]);

        if(is_wp_error($res)){
            Assistente_IA_Diagnostica_Modello::salva([
                'tipo'=>'generate','endpoint'=>self::crea_endpoint_generate(),'payload'=>$body,
                'risposta'=>null,'http_code'=>null,'errore'=>$res->get_error_message(),
                'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
            ]);
            return ['errore'=>$res->get_error_message()];
        }

        $code=wp_remote_retrieve_response_code($res);
        $raw=wp_remote_retrieve_body($res);
        $json=json_decode($raw,true);

        if(200!==$code){
            $mess=$json['error']['message']??'Errore generazione';
            Assistente_IA_Diagnostica_Modello::salva([
                'tipo'=>'generate','endpoint'=>self::crea_endpoint_generate(),'payload'=>$body,
                'risposta'=>$raw,'http_code'=>$code,'errore'=>$mess,
                'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
            ]);
            return ['errore'=>$mess,'http'=>$code];
        }

        $testo=$json['candidates'][0]['content']['parts'][0]['text']??'';
        Assistente_IA_Diagnostica_Modello::salva([
            'tipo'=>'generate','endpoint'=>self::crea_endpoint_generate(),'payload'=>$body,
            'risposta'=>$json,'http_code'=>$code,'errore'=>null,
            'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
        ]);
        return ['testo'=>$testo,'grezzo'=>$json];
    }

    public static function calcola_embedding( string $testo, array $meta = [] ): array {
        $token = Assistente_IA_Token::ottieni_token_accesso();
        if ( empty($token) ) {
            Assistente_IA_Diagnostica_Modello::salva([
                'tipo'=>'embed','endpoint'=>self::crea_endpoint_embeddings(),'payload'=>['instances'=>[['content'=>$testo]]],
                'risposta'=>null,'http_code'=>null,'errore'=>'Token non disponibile',
                'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
            ]);
            return ['errore'=>'Token non disponibile'];
        }

        $payload = ['instances'=>[['content'=>$testo]]];
        $res=wp_remote_post(self::crea_endpoint_embeddings(),[
            'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($payload),
            'timeout'=>45
        ]);

        if(is_wp_error($res)){
            Assistente_IA_Diagnostica_Modello::salva([
                'tipo'=>'embed','endpoint'=>self::crea_endpoint_embeddings(),'payload'=>$payload,
                'risposta'=>null,'http_code'=>null,'errore'=>$res->get_error_message(),
                'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
            ]);
            return ['errore'=>$res->get_error_message()];
        }

        $code=wp_remote_retrieve_response_code($res);
        $raw=wp_remote_retrieve_body($res);
        $json=json_decode($raw,true);

        if(200!==$code){
            $mess=$json['error']['message']??'Errore embeddings';
            Assistente_IA_Diagnostica_Modello::salva([
                'tipo'=>'embed','endpoint'=>self::crea_endpoint_embeddings(),'payload'=>$payload,
                'risposta'=>$raw,'http_code'=>$code,'errore'=>$mess,
                'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
            ]);
            return ['errore'=>$mess,'http'=>$code];
        }

        $vet=$json['predictions'][0]['embeddings']['values']??[];
        Assistente_IA_Diagnostica_Modello::salva([
            'tipo'=>'embed','endpoint'=>self::crea_endpoint_embeddings(),'payload'=>$payload,
            'risposta'=>$json,'http_code'=>$code,'errore'=>null,
            'id_chat'=>$meta['id_chat']??null,'hash_sessione'=>$meta['hash_sessione']??null
        ]);
        return ['vettore'=>$vet,'grezzo'=>$json];
    }

    public static function corpo_richiesta_generazione( string $prompt ): array {
        $soglie=(array)get_option('assia_safety_soglie',[]);
        $mappa_categorie=[
            'sexually_explicit' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
            'hate_speech'       => 'HARM_CATEGORY_HATE_SPEECH',
            'harassment'        => 'HARM_CATEGORY_HARASSMENT',
            'dangerous_content' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
        ];
        $safety=[]; foreach($mappa_categorie as $chiave=>$enum){ $safety[]=['category'=>$enum,'threshold'=>$soglie[$chiave]??'BLOCK_NONE']; }

        $cfg=[
            'temperature'=>(float)get_option('assia_temperature',0.2),
            'maxOutputTokens'=>(int)get_option('assia_max_token',2048),
            'topP'=>(float)get_option('assia_top_p',0.9),
            'topK'=>(int)get_option('assia_top_k',40),
        ];

        // RUOLO DI SISTEMA: opzione con default anti-disclaimer
        $ruolo_sistema = trim((string)get_option('assia_ruolo_sistema',''));
        if ($ruolo_sistema === '') {
            $ruolo_sistema =
            "Parli come l'assistenza ufficiale di questo sito. Usi SEMPRE il blocco [Contesto pertinente] come fonte primaria.\n".
            "Evita frasi come «non ho accesso al sito». Se il contesto è insufficiente, chiedi una chiarificazione mirata.\n".
            "Rispondi in italiano, senza inventare dati o link non presenti nel contesto.";
        }

        $body=[
            'systemInstruction'=>[
                'role'=>'system',
                'parts'=>[ ['text'=>$ruolo_sistema] ]
            ],
            'contents'=>[
                [ 'role'=>'user','parts'=>[ ['text'=>$prompt] ] ]
            ],
            'generationConfig'=>$cfg,
            'safetySettings'=>$safety,
        ];
        if('si'===get_option('assia_attiva_google_search','no')){ $body['tools']=[[ 'googleSearch'=>(object)[] ]]; }
        return $body;
    }
}
