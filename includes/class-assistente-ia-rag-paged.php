<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Assistente_IA_RAG_Paged {
    public static function prepara_job_indicizzazione(): array {
        $q = new WP_Query([ 'post_type'=>'post', 'posts_per_page'=>1, 'fields'=>'ids' ]);
        $tot = (int)$q->found_posts;
        set_transient('assia_job_indice', ['offset'=>0,'totale'=>$tot,'creati'=>0,'stato'=>'pronto'], 3600);
        return ['totale'=>$tot, 'stato'=>'pronto'];
    }
    public static function esegui_job_passaggio( int $batch=5 ): array {
        $st = get_transient('assia_job_indice');
        if(!is_array($st)) return ['errore'=>'Nessun job in corso'];
        $offset = (int)$st['offset']; $totale=(int)$st['totale']; $creati=(int)$st['creati'];
        $q = new WP_Query([ 'post_type'=>'post', 'posts_per_page'=>$batch, 'offset'=>$offset, 'fields'=>'ids', 'orderby'=>'ID', 'order'=>'ASC' ]);
        global $wpdb; $pref=$wpdb->prefix;
        foreach($q->posts as $pid){
            $tit = get_the_title($pid);
            $txt = wp_strip_all_tags( get_post_field('post_content',$pid) );
            $chunk = substr($txt, 0, 2000);
            $emb = apply_filters('assia_embedding_fun', ['embedding'=>base64_encode(substr(md5($chunk),0,16)), 'modello'=>'stub'], $chunk);
            $wpdb->insert($pref.'assistente_ia_embeddings',[
                'fonte'=>'post',
                'id_riferimento'=>$pid,
                'indice_chunk'=>0,
                'testo_chunk'=>$chunk,
                'embedding'=>$emb['embedding'],
                'modello'=>$emb['modello'],
                'creato_il'=>current_time('mysql')
            ]);
            $creati++;
        }
        $offset += count($q->posts);
        $stato = ($offset >= $totale) ? 'completato' : 'in_corso';
        set_transient('assia_job_indice', ['offset'=>$offset,'totale'=>$totale,'creati'=>$creati,'stato'=>$stato], 3600);
        $perc = $totale>0 ? round(($offset/$totale)*100,1) : 100;
        return ['indice'=>$offset,'totale'=>$totale,'creati'=>$creati,'stato'=>$stato,'percentuale'=>$perc];
    }
    public static function stato_job(): array {
        $st = get_transient('assia_job_indice');
        if(!is_array($st)) return ['stato'=>'nessuno'];
        $perc = $st['totale']>0 ? round(($st['offset']/$st['totale'])*100,1) : 100;
        return ['indice'=>$st['offset'],'totale'=>$st['totale'],'creati'=>$st['creati'],'stato'=>$st['stato'],'percentuale'=>$perc];
    }

        } finally {
            delete_transient($lock_key);
        }
}