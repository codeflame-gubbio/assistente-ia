# Changelog - Assistente IA Conversazioni

## [6.0.2] - 2025-11-07

### 🐛 BUGFIX - Tag HTML visualizzati nel testo

#### Problema Risolto
- 🔧 **RISOLTO:** Tag `<p>` e altri tag HTML visualizzati come testo nella chat
- 🔧 **CAUSA:** Il typewriter carattere-per-carattere rendeva i tag HTML come testo
- ✅ **FIX:** Typewriter migliorato che gestisce correttamente l'HTML, animazione parola-per-parola

#### Dettagli Tecnici
Il vecchio typewriter aggiungeva caratteri uno per uno, quindi tag come `<p>Ciao</p>` venivano visualizzati letteralmente. La nuova versione:
1. Renderizza prima tutto l'HTML correttamente nel DOM
2. Trova tutti i nodi di testo
3. Anima solo il testo parola-per-parola, mantenendo intatti i tag HTML
4. Risultato: formattazione corretta + animazione fluida

Questo approccio è superiore al semplice rimuovere i tag perché mantiene la formattazione completa (paragrafi, link, grassetti, ecc.) e fa comunque l'animazione typewriter.

#### File Modificati
- `assistente-ia-conversazioni.php` - Versione 6.0.1 → 6.0.2
- `public/js/assistente-ia.js` - Nuova funzione `assiaTypewriter` che gestisce HTML
- `CHANGELOG.md` - Documentazione fix

---

## [6.0.1] - 2025-11-07

### 🐛 BUGFIX CRITICO - Risolto errore "Errore di connessione"

#### Correzioni
- 🔧 **RISOLTO:** Errore "Errore di connessione riprova" nella chat frontend
- 🔧 **CAUSA:** Action AJAX errata nel JavaScript (`assistente_ia_invia` invece di `assistente_ia_chat`)
- ✅ **FIX:** Corretta action in public/js/assistente-ia.js alla riga 229

#### Dettagli Tecnici
Il JavaScript chiamava `action:'assistente_ia_invia'` ma l'endpoint AJAX era registrato come `assistente_ia_chat`. Questo causava il fallimento di tutte le richieste chat dal frontend, anche se i test diagnostici nel pannello admin funzionavano correttamente.

#### File Modificati
- `assistente-ia-conversazioni.php` - Versione 6.0.0 → 6.0.1
- `public/js/assistente-ia.js` - Corretta action AJAX (riga 229)

---

## [6.0.0] - 2025-11-07

### 🎨 MAJOR UPDATE - Nuova UI Migliorata

#### Aggiunte
- ✨ Popup centrale responsive (480x650px invece di 340x320px)
- 🌑 Overlay sfondo scuro trasparente (65% opacità) con blur
- ⌨️ Effetto typewriter per messaggi AI (animazione lettera per lettera)
- 🎭 Animazioni fluide con transizioni CSS (0.3s ease)
- 📱 Responsive migliorato per mobile e tablet
- ♿ Accessibilità migliorata (aria-label, aria-modal, chiusura con ESC)
- 🎨 Design moderno con gradiente blu nell'header
- ✨ Scrollbar personalizzata
- 🔄 Hover effects migliorati sui bottoni
- 📊 Preloader animato più fluido

#### Modifiche
- 📝 Aggiornata versione da 5.9.5 a 6.0.0
- 🎨 CSS completamente rinnovato (v2.0)
- ⚙️ JavaScript aggiornato con sistema typewriter (v2.0)
- 🏗️ HTML modificato per includere overlay

#### Correzioni
- 🔧 Risolto errore "Unclosed '{'" in class-assistente-ia-frontend.php
- 🔧 Corretta chiusura parentesi graffa della classe Frontend
- 🔧 Validata sintassi PHP completa
- 🐛 Fix compatibilità caratteri Windows (CRLF)

#### File Modificati
- `assistente-ia-conversazioni.php` - Versione aggiornata a 6.0.0
- `public/css/assistente-ia.css` - CSS v2.0 migliorato
- `public/js/assistente-ia.js` - JS v2.0 con typewriter e overlay
- `includes/class-assistente-ia-frontend.php` - HTML con overlay e fix sintassi

---

## [5.9.5] - 2025-11-06

### Versione Stabile Precedente
- Sistema RAG con embeddings Vertex AI
- Rate limiting multi-livello
- Prompt modulare e personalizzabile
- Cronologia conversazioni persistente
- Integrazione Vertex AI (Gemini)

---

## Compatibilità

- **WordPress:** 5.0+
- **PHP:** 7.4+
- **jQuery:** 1.12+
- **Browser:** Chrome 90+, Firefox 88+, Safari 14+, Edge 90+

---

## Note di Aggiornamento

### Da 5.9.5 a 6.0.0
⚠️ **IMPORTANTE:** Dopo l'aggiornamento:
1. Svuota cache del browser (CTRL+F5)
2. Svuota cache di WordPress (se usi plugin di cache)
3. Testa il widget sul frontend

La nuova UI è retrocompatibile ma richiede lo svuotamento delle cache per funzionare correttamente.

---

**Formato:** [Semantic Versioning](https://semver.org/)  
**Stile:** [Keep a Changelog](https://keepachangelog.com/)
