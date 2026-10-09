# LibertBus Bilete

Plugin WordPress/WooCommerce pentru vânzarea online a biletelor de autocar pe libertbus.md, cu plata cu cardul.
Clientul alege cursa, plătește cu cardul și primește biletul pe email.

## Ce face

- **Rute și orar**: plecare, destinație, ore, zile ale săptămânii, preț adult/copil, monedă (MDL/RON/EUR/USD), locuri online pe cursă. La activare se importă orarul real din pagina „Orar Curse” (85 de rute).
- **Formular de rezervare**: `[libertbus_rezervare]` (toate rutele) sau `[libertbus_rezervare from="Bălți" to="Iași"]` (pe pagina unei rute). Arată orele cu locuri libere, cere numele pasagerilor, telefon, email și calculează totalul.
- **Fusul orar**: orarul, „azi” și închiderea vânzării merg după ora Chișinăului (cu ora de vară), chiar dacă în WordPress e doar „UTC+2” fix; un oraș ales în Setări → Generale are întâietate.
- **Locuri fără vânzare dublă**: locurile se țin 15 minute în coș și 30 de minute cât se așteaptă plata, apoi se eliberează automat. Rezervarea folosește blocare în baza de date, deci două persoane nu pot lua ultimul loc în același timp.
- **Două butoane la final**: „Achit online cu cardul” sau „Rezerv, achit la urcare”.
  - Rezervarea fără plată ocupă locul, primește cod și link cu QR și trimite email clientului și biroului (expeditor: denumirea firmei sau „LibertBus”, nu „WordPress”; clientul răspunde direct biroului, biroul direct clientului).
  - Apare în lista de pasageri cu suma de încasat la urcare și se poate anula din admin.
  - Un telefon poate avea cel mult 3 rezervări neachitate (se schimbă din Setări). Numerele scrise local (069…, 07…, 00373…) se salvează ca +373… / +40…, deci contează o singură dată.
  - Pe o singură pagină se poate lăsa doar un buton: `mode="pay"` sau `mode="reserve"` în shortcode.
- **Plata în MDL, cu echivalent în RON**: implicit se încasează în MDL (cum lucrează Paynet), iar lângă preț apare informativ „≈ 62 RON” (și „≈ 234 MDL” la rutele cu preț în RON). Dacă banca acceptă și RON, se bifează RON în Setări și clientul alege singur moneda; comanda WooCommerce se face în moneda aleasă.
- **Plata**: prin WooCommerce, deci merge cu orice plugin de plată (Paynet, maib, Victoriabank, BT iPay). Plugin-ul nu atinge datele cardului.
- **Biletul**: cod `LB-XXXXXX` pe email, pe pagina de mulțumire și în contul clientului, plus link spre o pagină cu cod QR (bun de arătat șoferului sau de printat). Biletul se emite doar după confirmarea plății, iar o comandă anulată sau rambursată eliberează locurile.
- **Admin → LibertBus**:
  - **Panou**: lista „Gata de plăți?” cu ce mai lipsește (bancă, pagini legale, datele firmei, telefonul pentru clienți, bannerul de cookies, fus orar, monedă etc.);
  - **Rute și orar**;
  - **Pasageri**: lista de îmbarcare pe zi, printabilă, export CSV;
  - **Rezervări**;
  - **Setări**: locuri, timpi, cursuri valutare, datele firmei, plată de test.
- **Pagini legale**: termeni, anulare și rambursare, plata online (cerute de bancă), confidențialitate și cookies. Confidențialitatea și cookies se publică direct (le cere bannerul); celelalte se creează ca ciorne, cu datele firmei completate automat.
- **Plată de test**: o metodă vizibilă doar administratorilor, ca să verificați tot drumul fără bancă.
- **Butonul „Achit online cu cardul” e oprit implicit**: se pornește din Setări („Plata online cu cardul”) după ce metoda de plată a băncii e activă și testată. Până atunci clienții pot doar rezerva cu plata la urcare, iar serverul refuză plățile online.
- **Înlocuirea formularelor vechi Contact Form 7**, fără a modifica paginile (se anulează ștergând setarea):
  - „Înlocuiește formularele Contact Form 7 (ID-uri)”: ex. `210` = formularul din imaginea mare de pe pagina principală;
  - „Formularele de rută”: un formular cu titlul „Balti - Iasi” devine formularul rutei Bălți → Iași (46 de formulare se potrivesc).
- **Formular compact în doi pași** (pentru căsuțele mici ale temei): pasul 1 = ruta, data, ora, pasagerii și prețul; pasul 2 (nume, telefon, email, butoane) se deschide într-o fereastră peste pagină, pe tot ecranul pe telefon, cu butoanele fixate jos.
- **Previzualizare**: formularele de previzualizare (Setări) se înlocuiesc și butonul de plată apare doar:
  - pentru adminul logat, pe o pagină privată (ex. `/previzualizare-bilete/`) sau cu `?lbb_preview=1`;
  - pentru oricine are **linkul secret** `/?lbb_preview=CHEIE` (merge pe orice pagină, fără login; cheia e în Setări, cu buton „Link nou de previzualizare” care o anulează pe cea veche). Paginile deschise așa nu intră în cache și nu se indexează.
  Ceilalți vizitatori văd site-ul neschimbat.
- **Cookies (banner minimalist, Legea nr. 195/2024 aplicabilă din 23.08.2026 și GDPR)**: la prima vizită apare jos un mesaj scurt cu „Doar necesare”, „Setări” și „Accept toate” („Doar necesare” la fel de mare ca „Accept toate”) și link spre Politica de cookies. În „Setări” se aleg separat Statistică (Google Analytics) și Marketing (reclame Google, sursa vizitei); fără Marketing, Google primește refuzul pentru reclame (Consent Mode).
  - Google Analytics, Google Tag Manager (și partea pusă imediat după `<body>`), Facebook Pixel, Hotjar, Clarity, Yandex și sursa vizitei din WooCommerce (`sbjs_*`) nu se încarcă deloc până la acord pentru categoria lor; scripturile se blochează în pagină, deci merge și cu cache. Pixelii din `<noscript>` (iframe-ul GTM, imaginea Facebook) se scot, fiindcă fără JavaScript acordul nu poate fi cerut.
  - Alegerea se ține 6 luni (`lbb_cookie_consent` = versiunea textului, categoriile și data acordului, ca dovadă). Un link spre `#lbb-cookies` (ex. „Setări cookies” în meniul de jos) sau butonul de pe Politica de cookies redeschide bannerul; la retragerea acordului se șterg cookies-urile de statistică. Dacă textul politicii se schimbă (versiune nouă), acordul vechi nu mai contează: bannerul reapare și statistica rămâne oprită până la o nouă alegere.
  - Sub textul de copyright din subsol apar linkurile spre paginile legale publicate și „Setări cookies” (se oprește din Setări, „Linkuri în subsol”).
  - Se oprește din Setări („Banner cookies”).
- Sub butoanele formularului de rezervare apare nota „Datele se folosesc doar pentru rezervare și călătorie” cu link spre Politica de confidențialitate.
- **Telefonul de suport** apare ca link de apel, pe un singur rând, în formular, pe pagina biletului/rezervării și în emailul de rezervare.

## Instalare

1. Arhivați folderul `libertbus-bilete` ca `.zip` și încărcați-l din Module → Adaugă → Încarcă modul. Apoi activați-l.
2. Mergeți la **LibertBus → Panou** și rezolvați ce e marcat cu ✘.

## Conectarea băncii

| Procesator | Plugin WooCommerce | Ce primiți de la ei |
|---|---|---|
| Paynet (MD) | modulul WooCommerce de la Paynet | Merchant Code, Secret Key, user/parolă API |
| maib (MD) | „maib Payment Gateway for WooCommerce” | Project ID, Project Secret, Signature Key |
| Victoriabank (MD, grupul BT) | „Victoriabank Payment Gateway for WooCommerce” | Merchant ID, Terminal ID, chei |
| Banca Transilvania (RO) | plugin BT iPay | user/parolă API iPay (firmă și cont în RO, RON) |

Pașii sunt aceiași pentru oricare:

1. Instalați plugin-ul procesatorului.
2. Introduceți datele primite.
3. Activați-l în WooCommerce → Setări → Plăți.
4. Faceți o plată în modul de test al băncii.

## Teste

Toate testele rulează pe o instalare de test, niciodată pe site-ul real.

- `tests/setup-local.sh <director>` pregătește WordPress 6.4.3 (ca pe site, fără actualizări automate) + WooCommerce + Contact Form 7 pe http://127.0.0.1:8080 și verificatorul de compatibilitate PHP. Cu `WP_VERSION=7.1.2` se verifică o actualizare a WordPress (QA-ul a trecut și pe 7.1.2). Comenzile WooCommerce se țin pe rând în HPOS (ore pare) și în tabelele vechi „posts” (ore impare), ca rundele automate să le verifice pe amândouă; `LBB_ORDER_STORAGE=hpos|posts` fixează una.
- `tests/qa.sh` rulează tot (vezi comentariul din fișier):
  - sintaxa PHP și **compatibilitatea cu PHP 7.4+** (serverul libertbus.md rulează PHP 7.4);
  - **controalele de securitate WordPress** (WPCS): tot ce se afișează e escapat, interogările SQL sunt pregătite, formularele verifică nonce-ul, datele primite sunt curățate, textele traductibile au explicații pentru traducători;
  - `tests/smoke.php`: 189 verificări (prețul de copil mai mare decât cel de adult e semnalat la salvarea rutei, o rezervare pe o zi în care ruta nu circulă e refuzată cu zilele de circulație în mesaj, lista de rute spune de ce o rută e oprită: inactivă sau fără preț, o oră sau o zi scoasă din orar, care are deja rezervări, e semnalată la salvare, o rută cu bilete pentru curse viitoare nu se poate șterge, panoul verifică IDNO-ul firmei: 13 cifre, numărul implicit de locuri golit sau 0 păstrează valoarea anterioară, un curs valutar golit din greșeală sau 0 păstrează cursul anterior, orele de plecare scrise greșit la o rută sunt semnalate la salvare, la plata refuzată pagina comenzii nu mai promite bilete, pe pagina de după plată, cât banca n-a confirmat încă, apare „Verifică din nou”, dezinstalarea cu „șterge datele” nu lasă opțiuni în urmă, ștergerea la cerere și curățenia de 3 ani curăță și numele de pe comandă, anonimizarea WooCommerce șterge și numele pasagerilor de pe comandă și din rezervare, exportul și ștergerea datelor personale din Unelte WordPress: rezervările clientului apar și se șterg, biletul pentru o cursă viitoare rămâne, panoul arată dacă WooCommerce anonimizează comenzile finalizate, după 3 ani de la cursă numele, telefonul și emailul din rezervări se șterg automat, ca în politica de confidențialitate, curățenia zilnică nu mai șterge rezervările anulate de birou, doar coșurile abandonate, calendarul are limitele de dată și fără JavaScript, codul rezervării în subiectul emailului către client, în emailul text al comenzii numele cu apostrof rămân întregi și titlul are majuscule corecte, emailurile pleacă și cu variantă text lizibilă, CSV-ul cu pasageri neutralizează și formulele de tip „+1+cmd|…”, telefoanele rămân neatinse, linkul din emailul către birou deschide direct rezervarea, ziua săptămânii pe bilet în română chiar dacă adminul lucrează în engleză, API-ul public pentru ore: date greșite refuzate, rute inexistente sau dezactivate ascunse, 31 februarie fără plecări, fără cache, emailul către client când biroul anulează o rezervare, căutarea în Rezervări după cod, telefon scris local, email și nume cu diacritice, cu filtrul de stare păstrat, câmpul-capcană pentru roboți verificat printr-o cerere reală: robotul e refuzat fără rezervare, omul trece, biletul unei curse din zi trecută apare „Cursa a avut loc”, nu „valabil”, biletul cu plata reluată apare „neachitat”, nu „anulat”, card refuzat → locul se eliberează, reîncercare → locul se ține din nou, plată → bilet, rambursare → bilet anulat, versiunea din antet = LBB_VERSION, copiii marcați „(copil)” pe bilet și în liste, panoul semnalează un telefon pentru clienți incomplet, Google Tag Manager blocat până la acord, inclusiv `<noscript>` și codul de după `<body>`, panoul avertizează când bannerul de cookies e oprit, emailurile de rezervare vin de la firmă, cu Reply-To spre birou/client, pagina Setări: fiecare setare are câmp și o salvare fără modificări nu schimbă nimic, fusul orar: cu „UTC+2” fix în WordPress orarul merge după Chișinău, cu ora de vară, nota de confidențialitate din formular, setările „Banner cookies” și „Linkuri în subsol” verificate pe pagina reală, blocarea scripturilor de statistică, emailurile de rezervare, locuri, expirare, plată întârziată, anulare, rezervare la urcare, monede, potrivirea formularelor după titlu, buton de plată oprit, diacritice, linkul secret, telefonul de pe bilet și din mesajele „sunați-ne”, numerele scrise local 069… / 07… aduse la +373 / +40, numerele și emailurile prea lungi refuzate cu mesaj clar, numele fără litere refuzate, versiunea JS/CSS schimbată la fiecare modificare a fișierelor);
  - în browser: rezervare cu plată de test, vizitator nelogat (inclusiv răspunsul blocat de protecția hostingului și o dată trecută sau prea îndepărtată, pe care Safari de pe iPhone o permite, un nume fără litere oprit direct în browser și zilele de circulație arătate pentru o rută care nu merge zilnic), plată în RON și rezervare la urcare, telefon greșit prins de server (numele, data, ora și emailul rămân completate), previzualizare cu 5 pasageri și linkul secret (cheie corectă, cheie greșită, fără cache), eroare de la server pe o pagină cu 2 formulare (mesajul doar în formularul trimis, cu focus);
  - admin: Panou, Rute și orar, Pasageri, Rezervări și Setări se deschid fără erori (și fără avertismente PHP în jurnal), iar lista de pasageri se descarcă în CSV cu antet și diacritice corecte; ruta cu bilete nu se poate șterge, iar scoaterea orelor cu bilete din orar arată cursele afectate; un preț de copil mai mare decât cel de adult e semnalat;
  - cookies: bannerul pe iPhone SE și desktop, Google Analytics, Google Tag Manager și `sbjs_*` blocate până la acord, „Doar necesare”, „Accept toate”, „Setări” doar cu statistică (de la tastatură cursorul ajunge pe prima bifă) (fără reclame), retragerea acordului (șterge cookies-urile Google, Facebook, Yandex, TikTok, Hotjar, Clarity), linkurile din subsol, paginile legale publicate;
  - accesibilitate (`tests/e2e-a11y.js`, cu axe-core descărcat de `tests/setup-local.sh`): formularul, bannerul de cookies cu „Setări” deschis și pagina biletului, pe telefon, plus paginile LibertBus din admin — etichete, contrast, roluri ARIA, text alternativ;
  - **8 dispozitive** (iPhone SE … desktop 1920), 5 browsere în paralel: fără scroll orizontal, butoane de minim 40px, text de 16px în câmpuri, ora netăiată, ambele butoane pe ecran, telefonul și sumele pe un singur rând.
- `tests/e2e-devices.js` se poate rula și pe site-ul real (doar citire), cu `BASE=https://libertbus.md WP_USER=… WP_PASS=… PARALLEL=1`: protecția hostingului blochează rafalele de cereri.
