# Plan: plata cu cardul pe libertbus.md

## Situația înainte de plugin (2 octombrie 2026)

- WordPress 6.4.3, tema Betheme, Elementor, WooCommerce 8.7.3. Serverul rulează PHP 7.4, care nu mai primește actualizări de securitate.
- Rezervarea se face prin formulare Contact Form 7, câte unul pe fiecare rută (aproximativ 45 de formulare). Ele trimit un email, fără plată și fără să verifice locurile libere.
- Orarul real e în pagina „Orar Curse”: 75 de linii. Prețurile sunt în MDL pentru cursele spre România și în RON pentru cele spre Moldova.
- Pe site există peste 50 de pagini de rută construite manual.
- WooCommerce:
  - toate metodele de plată sunt oprite;
  - moneda e EUR;
  - adresa magazinului e în Iași;
  - pagina de termeni nu e setată.
- Era instalat WooPayments (Stripe), care nu acceptă firme din Moldova.
- Plugin-ul „Bus Ticket Booking” avea date de test, expirate. L-am dezactivat la cerere.
- Fusul orar e un decalaj fix UTC+2, care greșește cu o oră vara.
- Contul `libertbus` are parola expusă în chat și trebuie schimbată.

## Ce e deja pe site-ul live (5 octombrie 2026)

- Plugin-ul LibertBus Bilete 1.4.1 e instalat și activ. Butonul „Achit online” e ascuns până porniți plata. Noul formular nu e încă pus în locul formularelor Contact Form 7.
- Bannerul de cookies (Legea nr. 195/2024, aplicabilă din 23 august 2026, și GDPR):
  - butoanele „Doar necesare”, „Setări” și „Accept toate”;
  - Google Analytics pornește doar după acord.
- Paginile „Politica de confidențialitate” și „Politica de cookies” sunt publicate. Linkurile lor și „Setări cookies” apar în subsol, sub „© 2026 Libertbus.md”.
- Paginile de termeni, rambursare și plată sunt create ca ciorne. Le publicați după ce le verificați.
- Versiunea 1.5.0, cu îmbunătățirile făcute după 1.4.1, e pe acest branch, nu pe live. O pun pe live doar cu acordul dumneavoastră:
  - **Cookies și lege:**
    - Google Tag Manager blocat până la acord;
    - toate cookies-urile de urmărire șterse la retragerea acordului;
    - din tastatură, „Setări” duce direct la prima bifă;
    - un acord dat pe un text mai vechi al politicii nu mai contează: bannerul reapare.
  - **Formularul de rezervare:**
    - după „Înapoi” din plată rămân data, ora și locurile corecte, iar butoanele merg;
    - câmpul cu eroarea de la server e marcat cu roșu;
    - numele nu se mai pierd cât clientul scrie pe internet slab;
    - adulții și copiii își păstrează numele când se schimbă numărul lor;
    - rezumatul arată prețul de adult și de copil.
  - **Bilet și liste:**
    - copiii sunt marcați „(copil)” pe bilet, în lista pentru șofer și în CSV;
    - codul QR are text pentru cititoarele de ecran;
    - ziua săptămânii de pe bilet e mereu în română (nu „Tuesday” când biroul confirmă comanda cu adminul în engleză);
    - pagina biletului nu mai rămâne în cache;
    - biletul unei curse din zi trecută apare gri „Cursa a avut loc pe …”, nu verde „valabil” (șoferul nu poate fi păcălit cu un bilet vechi);
    - dacă plata unui bilet e reluată (ex. după un card refuzat), pagina lui spune „Plata nu e finalizată”, nu „Bilet anulat”.
    - la printare, starea biletului rămâne pe foaie: un bilet anulat, neplătit sau dintr-o cursă trecută nu mai arată pe hârtie ca unul valabil.
  - **Admin pe telefon:** listele Pasageri, Rezervări și Rute și orar devin carduri, iar câmpurile se ating ușor.
  - **Anulare din birou:** când biroul anulează o rezervare cu plata la urcare, clientul primește un email (cu codul, ruta, ora și telefonul), ca să nu vină degeaba la autocar.
  - **Căutare în Rezervări:** când sună un client, rezervarea se găsește după codul biletului, telefon (și scris 069…), nume sau email, nu doar printre ultimele 200.
  - **Emailul către birou** la o rezervare nouă are linkul „Deschide rezervarea în admin”, care o arată direct, căutată după cod.
  - **Panoul „Gata de plăți?”** avertizează dacă bannerul de cookies e oprit sau dacă telefonul pentru clienți e incomplet.
  - **Altele:**
    - ziua de azi după ora Chișinăului;
    - expeditorul corect în emailurile de rezervare;
    - accesibilitate verificată automat (axe-core).

## Ce face plugin-ul LibertBus Bilete (gata)

Vezi `README.md`. Pe scurt:
1. Clientul alege ruta, data, ora și pasagerii.
2. Locurile se țin temporar.
3. Clientul plătește cu cardul prin WooCommerce.
4. Primește biletul cu cod QR pe email.
5. Administratorul vede lista de pasageri.

## Pași pentru lansare

### Ce faceți dumneavoastră
1. **Contractul cu banca**: e-commerce acquiring cu Paynet, maib sau Victoriabank. Pentru plăți în RON prin BT iPay e nevoie de firmă în România.
2. **Datele firmei** pentru LibertBus → Setări: denumire, IDNO, adresă. Apar în politica de confidențialitate, cerută de Legea nr. 195/2024. Confirmați și cât timp se păstrează datele: rezervările 3 ani, mesajele 1 an, jurnalele de securitate 90 de zile.
3. **Regulile de anulare și rambursare**. Textul-model propune 100% / 50% / 0% în funcție de cât timp mai e până la plecare. Ajustați-l.
4. **Câte locuri pe cursă** se vând online.

### Ce pot face eu, cu acordul dumneavoastră
1. ~~Instalarea și activarea plugin-ului pe site.~~ Făcut. Rămâne actualizarea la versiunea de pe branch.
2. Fusul orar → Chișinău. Moneda WooCommerce → MDL.
3. Paginile legale: confidențialitate și cookies sunt publicate și legate în subsol. Termenii, rambursarea și plata așteaptă verificarea dumneavoastră.
4. Pagină nouă „Rezervă bilet” cu formularul. Pe paginile de rută, formularul de contact se înlocuiește cu `[libertbus_rezervare from=".." to=".."]`. Butoanele „Rezervă” din „Orar Curse” vor duce spre formular.
5. Oprirea WooPayments, care nu funcționează în Moldova.
6. Test complet cu plata de test, apoi cu modul de test al băncii.
7. Scoaterea textului `[wbtm-...]` rămas pe pagini de la plugin-ul vechi.

### Recomandat separat (securitate)
- Actualizare PHP 7.4 → 8.2 sau mai nou, din panoul de hosting.
- Actualizarea WordPress, WooCommerce, Elementor și Slider Revolution. Versiunea 6.3.3 de Slider Revolution are breșe cunoscute.
- Schimbarea parolei de administrator.

## Verificare automată (QA)

O sesiune programată la 30 de minute verifică:
- testele locale: teste automate, plus o rezervare cap-coadă cu plata de test;
- starea site-ului live: pagina principală, „Orar Curse”, panoul „Gata de plăți?”.

Ea face îmbunătățiri mici și sigure în cod, pe acest branch. Pe site-ul live nu modifică nimic fără acordul dumneavoastră.
