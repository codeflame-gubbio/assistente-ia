# Assistente IA

Plugin WordPress per integrare un assistente basato su Google Vertex AI.

## Credenziali del service account

Le credenziali **non** vengono più salvate nel database. Fornire il JSON del service account in uno dei modi seguenti:

1. **Variabile d'ambiente** `ASSIA_SERVICE_ACCOUNT`
   - Inserire direttamente il contenuto JSON oppure la versione Base64.
2. **File esterno** indicato da `ASSIA_SERVICE_ACCOUNT_FILE`
   - Impostare la variabile d'ambiente con il percorso di un file protetto contenente il JSON o il Base64.
   - Il file deve trovarsi fuori dal plugin e non deve essere versionato.

Esempio `.env`:

```
ASSIA_SERVICE_ACCOUNT_FILE=/percorso/privato/credenziali.json
```

Il plugin leggerà le credenziali all'esecuzione senza archiviarle nel database.

## Configurazione

Le altre impostazioni (Project ID, modelli, ecc.) continuano ad essere gestite tramite la pagina "Assistente IA → Impostazioni" nel pannello di amministrazione di WordPress.

