# 🔧 ASSISTENTE IA CONVERSAZIONI - FIX v5.4.0

## 📋 COSA CONTIENE QUESTO ARCHIVIO

Questo ZIP contiene **6 FILE CORRETTI** che risolvono tutti gli errori critici trovati nel plugin:

### ✅ FIX APPLICATI:

1. **Versione sincronizzata** → 5.4.0 ovunque
2. **AJAX actions mancanti** → `assistente_ia_storico` e `assia_ping` registrate
3. **Variabile JavaScript** → `AssistenteIA.hash` aggiunta
4. **Localize script duplicato** → Rimosso dalla pagina RAG
5. **Struttura JavaScript** → Closure corretto, `assiaCaricaStorico` accessibile
6. **Global $post** → Non più modificata, usa variabile locale
7. **Validazione fonte** → Check array validi in `rileva_modifiche_snapshot`

---

## 🚀 ISTRUZIONI INSTALLAZIONE

### **METODO 1: Sostituzione Singoli File (RACCOMANDATO)**

1. **BACKUP COMPLETO** del plugin esistente
2. **Connettiti via FTP/SFTP** al server
3. **Naviga** in: `wp-content/plugins/assistente-ia-conversazioni/`
4. **Sostituisci questi file** con le versioni corrette:

```
assistente-ia-conversazioni.php                    ← File principale
includes/class-assistente-ia-ajax.php              ← Classe AJAX
includes/class-assistente-ia-frontend.php          ← Classe Frontend
includes/class-assistente-ia-admin-rag.php         ← Admin RAG
includes/class-assistente-ia-rag.php               ← Sistema RAG
public/js/assistente-ia.js                         ← JavaScript frontend
```

5. **Svuota cache** (browser + plugin cache se presente)
6. **Testa il plugin** (vedi sezione TEST)

---

### **METODO 2: Estrazione Diretta**

⚠️ **ATTENZIONE**: Questo metodo sostituisce SOLO i 6 file corretti, non l'intero plugin.

1. **BACKUP** del plugin esistente
2. **Scarica** questo ZIP
3. **Estrai** il contenuto nella directory del plugin:
   ```bash
   cd wp-content/plugins/assistente-ia-conversazioni/
   unzip assistente-ia-FIXED-v5.4.0.zip
   mv assistente-ia-fix/* .
   rm -rf assistente-ia-fix/
   ```
4. **Verifica permessi** file (644 per .php, .js)
5. **Testa il plugin**

---

## ✅ TEST POST-INSTALLAZIONE

Dopo aver sostituito i file, esegui questi test:

### **1. Verifica Versione**
- Vai su: **Plugin → Plugin Installati**
- Controlla che mostri: **Versione 5.4.0**

### **2. Test Frontend Chat**
- Apri una pagina del sito (non admin)
- Clicca sul bottone chat
- **Verifica**: La chat si apre correttamente
- **Verifica**: I messaggi precedenti vengono caricati (riidratazione)
- Apri **Console Browser** (F12) → **Network**
- Invia un messaggio
- **Verifica**: Vedi chiamata `assistente_ia_chat` (status 200)

### **3. Test Storico Conversazioni**
- Con la chat aperta, apri **Console** (F12)
- Ricarica la pagina
- **Verifica**: Vedi chiamata `assistente_ia_recupera_chat`
- **NO errori** tipo: "action assistente_ia_storico not found"

### **4. Test Admin RAG**
- Vai su: **Assistente IA → RAG (Embeddings)**
- Clicca: **"Avvia rigenerazione"**
- **Verifica**: La barra di progresso si muove
- **Verifica**: Nel log vedi: "Progresso: X/Y post..."
- **NO errori** in Console

### **5. Test JavaScript**
- Apri **Console Browser** (F12)
- Scrivi: `typeof assiaCaricaStorico`
- **Risultato atteso**: `"function"`
- **NO**: `"undefined"`

---

## 🐛 TROUBLESHOOTING

### **Problema: Chat non si apre**
**Soluzione**:
1. Svuota cache browser (Ctrl+Shift+R)
2. Verifica Console → cercare errori JavaScript
3. Verifica file `public/js/assistente-ia.js` sostituito correttamente

### **Problema: "action not found" in AJAX**
**Soluzione**:
1. Verifica file `includes/class-assistente-ia-ajax.php` sostituito
2. Vai su: **Assistente IA → Impostazioni**
3. Clicca: **"Salva impostazioni"** (riattiva hooks)

### **Problema: RAG non si avvia**
**Soluzione**:
1. Verifica file `includes/class-assistente-ia-admin-rag.php` sostituito
2. Apri Console → Network
3. Cerca errori 403 o 500
4. Se vedi "bad-nonce": ricarica pagina admin

### **Problema: Versione ancora vecchia**
**Soluzione**:
1. Verifica file principale sostituito
2. Disattiva e riattiva il plugin
3. Svuota cache OPcache se presente:
   ```bash
   # Da SSH
   service php-fpm reload
   ```

---

## 📊 DIFFERENZE RISPETTO ALLA VERSIONE PRECEDENTE

### **File Modificati** (6 totali):

| File | Righe Modificate | Fix Principali |
|------|------------------|----------------|
| assistente-ia-conversazioni.php | 2 | Versione 5.4.0 |
| class-assistente-ia-ajax.php | +12 | Actions storico/ping |
| class-assistente-ia-frontend.php | +2 | Variabile hash |
| class-assistente-ia-admin-rag.php | -8 | Rimosso duplicato |
| class-assistente-ia-rag.php | ~50 | Fix global $post + validazione |
| assistente-ia.js | +30 | Closure corretto |

### **File NON Modificati** (restano originali):

Tutti gli altri file del plugin restano invariati. Questa è una **patch mirata** che corregge SOLO i bug trovati.

---

## 🔒 SICUREZZA

✅ **Tutti i fix rispettano**:
- Nonce verification
- Capability checks (`manage_options`)
- SQL prepared statements
- Input sanitization
- Output escaping

❌ **Nessuna vulnerabilità introdotta**

---

## 📞 SUPPORTO

### **In caso di problemi**:

1. **Verifica log errori PHP**:
   ```bash
   tail -f /var/log/php-fpm/error.log
   ```

2. **Attiva debug WordPress** (wp-config.php):
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

3. **Controlla log WordPress**:
   ```
   wp-content/debug.log
   ```

---

## 📝 CHANGELOG

### **v5.4.0 - FIX PATCH**
- ✅ Sincronizzata versione plugin
- ✅ Registrate AJAX actions mancanti (storico, ping)
- ✅ Aggiunta variabile hash a localize_script
- ✅ Rimosso wp_localize_script duplicato
- ✅ Corretta struttura JavaScript (closure)
- ✅ Fix global $post non modificata
- ✅ Aggiunta validazione fonte in snapshot
- 🔧 Migliorata gestione errori AJAX
- 🔧 Ottimizzato caricamento storico conversazioni

---

## ⚠️ NOTE IMPORTANTI

1. **Questa è una patch**, non una reinstallazione completa
2. **NON cancellare** il database o le impostazioni esistenti
3. **Mantieni backup** prima di applicare modifiche
4. **Testa in staging** se possibile
5. Gli **embeddings esistenti** NON vengono toccati

---

## ✅ COMPATIBILITÀ

- **WordPress**: 5.8+
- **PHP**: 7.4+
- **MySQL**: 5.7+
- **WooCommerce**: 5.0+ (opzionale)

---

**Data Release**: 02 Novembre 2025  
**Versione Plugin**: 5.4.0  
**Tipo**: Bug Fix Patch  
**Priorità**: ⚠️ Alta (errori critici AJAX)

---

💡 **Suggerimento**: Dopo aver applicato il fix, testa tutte le funzionalità principali del plugin per assicurarti che tutto funzioni correttamente.
