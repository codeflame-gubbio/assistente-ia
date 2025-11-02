<?php
define( 'WP_CACHE', true ); // Added by WP Rocket


/**
 * Il file base di configurazione di WordPress.
 *
 * Questo file viene utilizzato, durante l’installazione, dallo script
 * di creazione di wp-config.php. Non è necessario utilizzarlo solo via web
 * puoi copiare questo file in «wp-config.php» e riempire i valori corretti.
 *
 * Questo file definisce le seguenti configurazioni:
 *
 * * Impostazioni del database
 * * Chiavi segrete
 * * Prefisso della tabella
 * * ABSPATH
 *
 * * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Impostazioni database - È possibile ottenere queste informazioni dal proprio fornitore di hosting ** //
/** Il nome del database di WordPress */
define( 'DB_NAME', "nrylsfra_guidamarketingcom" );

/** Nome utente del database */
define( 'DB_USER', "nrylsfra_giuseppe" );

/** Password del database */
define( 'DB_PASSWORD', "giuseppe73" );

/** Hostname del database */
define( 'DB_HOST', "localhost" );

/** Charset del database da utilizzare nella creazione delle tabelle. */
define( 'DB_CHARSET', 'utf8mb4' );

/** Il tipo di collazione del database. Da non modificare se non si ha idea di cosa sia. */
define( 'DB_COLLATE', '' );

/**#@+
 * Chiavi univoche di autenticazione e di sicurezza.
 *
 * Modificarle con frasi univoche differenti!
 * È possibile generare tali chiavi utilizzando {@link https://api.wordpress.org/secret-key/1.1/salt/ servizio di chiavi-segrete di WordPress.org}
 *
 * È possibile cambiare queste chiavi in qualsiasi momento, per invalidare tutti i cookie esistenti.
 * Ciò forzerà tutti gli utenti a effettuare nuovamente l'accesso.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '(-g_z-cRpG1CI;W!h yn~J-<d`ATu8<rR,25BUG8W[fFi6k)d2mUY06FvCGYtc2|' );
define( 'SECURE_AUTH_KEY',  'J[0Q@%`=A)T>FOZ Xq(ziJT,}Yo9!+cBy/A`3qPLS[G@C*#;UkHB[?<A4);bZ7->' );
define( 'LOGGED_IN_KEY',    ')-j(Dc!3,Uk.8 DOcY~{Xp@ !fnDcEjCPrx>~8QvjOw X2z[cD!d;gT90JNIJ,yL' );
define( 'NONCE_KEY',        'b6~WR+l:`@7W@HL,)!+Zx0Cgko~TN9UIA04> EI e8,/yh{ZFm&mu8UZN(zHfYm?' );
define( 'AUTH_SALT',        'xF];fD7go!:slI^Gce48Mz*,MeS<G%@z>KHu~>8VsacPcuxwAbVRJs`krqqF9d/<' );
define( 'SECURE_AUTH_SALT', '?gF%p;>]O5X*Duk{e>~L>eB}4#&,N:z:6@|A$eZP_(<H/QG9RVu$:va9G_4gPb{}' );
define( 'LOGGED_IN_SALT',   'bS(K19h@#$-#h+M+<N/^zOHKY~D_fxT=Rdwh]T^#D=B<;<|Svbs0<Y[n#<G<l)%9' );
define( 'NONCE_SALT',       '+>ieF0.c/wZux9PiQ+6)<CU`[ h4B5BHk;(i#|q77TzljNg-K&a(V8r}*9fL5<qL' );

/**#@-*/

/**
 * Prefisso tabella del database WordPress.
 *
 * È possibile avere installazioni multiple su di un unico database
 * fornendo a ciascuna installazione un prefisso univoco. Solo numeri, lettere e trattini bassi!
 */
$table_prefix = 'gb1_';

/**
 * Per gli sviluppatori: modalità di debug di WordPress.
 *
 * Modificare questa voce a TRUE per abilitare la visualizzazione degli avvisi durante lo sviluppo
 * È fortemente raccomandato agli sviluppatori di temi e plugin di utilizzare
 * WP_DEBUG all’interno dei loro ambienti di sviluppo.
 *
 * Per informazioni sulle altre costanti che possono essere utilizzate per il debug,
 * leggi la documentazione
 *
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Aggiungi qualsiasi valore personalizzato tra questa riga e la riga "Finito, interrompere le modifiche". */



/* Finito, interrompere le modifiche! Buona pubblicazione. */

/** Path assoluto alla directory di WordPress. */
define( 'DUPLICATOR_AUTH_KEY', '1Wl?#osC8hkTP80-%-4y/fS*%19>oVC/%sEx9~,avPTv)Eabv<%8E>_X?|1AJ|`(' );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Imposta le variabili di WordPress ed include i file. */
require_once ABSPATH . 'wp-settings.php';

