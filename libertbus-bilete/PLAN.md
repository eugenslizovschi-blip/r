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
    - calendarul nu lasă alegerea unei zile din trecut sau de după perioada de vânzare nici dacă JavaScript se încarcă greu;
    - numele nu se mai pierd cât clientul scrie pe internet slab;
    - adulții și copiii își păstrează numele când se schimbă numărul lor;
    - rezumatul arată prețul de adult și de copil;
    - după apăsarea butonului, sub el scrie „Vă ducem la plată…” / „Se trimite rezervarea…” (pe internet lent clientul nu mai apasă de două ori și nu închide pagina);
    - la numele pasagerilor, telefonul pune majusculă la fiecare cuvânt și nu mai „corectează” numele de familie (ex. „Rusu” → „Rush” pe iPhone);
    - pentru o rută care nu circulă zilnic, într-o zi fără curse mesajul spune și zilele de circulație („Ruta circulă doar: luni, joi.”), inclusiv în eroarea de la server (telefoane vechi, fără JavaScript).
  - **Bilet și liste:**
    - copiii sunt marcați „(copil)” pe bilet, în lista pentru șofer și în CSV;
    - CSV-ul cu pasageri pentru o singură rută are ruta în numele fișierului (ex. pasageri-2026-10-21-balti-iasi.csv);
    - CSV-ul cu pasageri nu mai lasă să treacă un „nume” pe care Excel l-ar executa ca formulă (ex. „+1+cmd|…”); telefoanele rămân la fel;
    - codul QR are text pentru cititoarele de ecran;
    - ziua săptămânii de pe bilet e mereu în română (nu „Tuesday” când biroul confirmă comanda cu adminul în engleză);
    - pagina biletului nu mai rămâne în cache;
    - biletul unei curse din zi trecută apare gri „Cursa a avut loc pe …”, nu verde „valabil” (șoferul nu poate fi păcălit cu un bilet vechi);
    - dacă plata unui bilet e reluată (ex. după un card refuzat), pagina lui spune „Plata nu e finalizată”, nu „Bilet anulat”.
    - un link de bilet stricat sau incomplet arată „Biletul nu a fost găsit” cu telefonul firmei, ca clientul să poată suna;
    - pe pagina de după plată, dacă banca n-a confirmat încă plata, clientul vede „De obicei durează câteva secunde” și un buton „Verifică din nou” (nu și la plata refuzată, unde WooCommerce oferă „Plătește din nou”);
    - la printare, starea biletului rămâne pe foaie: un bilet anulat, neplătit sau dintr-o cursă trecută nu mai arată pe hârtie ca unul valabil.
  - **„Locuri online pe cursă (implicit)” în Setări:** golit din greșeală sau 0 păstrează numărul de dinainte (înainte toate rutele fără număr propriu de locuri apăreau „complet” și nu se mai vindea nimic).
  - **Cursuri valutare în Setări:** un câmp golit din greșeală sau 0 păstrează cursul de dinainte (înainte devenea 0,0001, iar un bilet de 60 RON ar fi costat 0,01 MDL la plata în MDL).
  - **„Zile înainte” și „Pasageri pe rezervare” în Setări:** goliți din greșeală sau 0 păstrează valoarea de dinainte (înainte deveneau 1: vânzare doar pentru o zi și un singur pasager); la fel minutele de așteptare la coș și la plată. În formular, aceste câmpuri au acum minimul real (1, respectiv 5 și 10 minute), iar browserul îl spune direct pe câmp.
  - **Rute și orar:** dacă o oră de plecare e scrisă greșit (ex. „25:00” sau „8-45”), la salvare apare „Atenție: aceste ore nu au fost înțelese și nu s-au salvat”, cu orele respective (înainte cursa dispărea fără niciun mesaj). O rută care are bilete sau rezervări pentru curse viitoare nu se mai poate șterge din greșeală: apare mesajul să debifați „Activă” (vânzarea se oprește, călătorii rămân în lista pentru șofer). Dacă scoateți din orar o oră sau o zi pe care există deja bilete, la salvare apare lista curselor afectate (ex. „12.10.2026 08:45 (2)”), ca să anunțați pasagerii. În listă, o rută care nu se vinde apare „oprită: inactivă” sau „oprită: fără preț”. Un preț pentru copii mai mare decât cel pentru adulți (ex. 2400 în loc de 240) e semnalat la salvare.
  - **Admin pe telefon:** listele Pasageri, Rezervări și Rute și orar devin carduri, iar câmpurile se ating ușor.
  - **Pagina biletului:** butonul „Adaugă în calendar” pune cursa în calendarul telefonului (ora plecării, ruta, linkul biletului și un memento cu 2 ore înainte). Pagina are acum titlu și zonă principală marcate pentru cititoarele de ecran (verificată toată cu axe-core). „Adaugă în calendar” apare doar pe biletele valabile ale curselor care urmează, și ca link în emailul cu biletul sau rezervarea (inclusiv în varianta doar text a emailului comenzii). Dacă plata nu s-a terminat (ex. card refuzat), pe bilet apare „Achită acum”, care duce direct la plata comenzii.
  - **Lista Rezervări:** arată ultimele 200; când sunt mai multe, sub tabel scrie că pentru o rezervare mai veche se caută după cod, telefon, nume sau email.
  - **Lista Pasageri (pentru șofer):** când în aceeași zi sunt mai multe curse, deasupra tabelului apar locurile pe fiecare cursă (ex. „07:00 Bălți → Iași: 3 locuri · 19:00 …: 1 loc”), nu doar totalul zilei.
  - **Emailurile de rezervare și anulare** pleacă și cu o variantă text (nu doar HTML): ajung mai rar în spam și se citesc bine în orice aplicație; subiectul emailului de rezervare are codul (ex. „Rezervare LB-AB12CD — Bălți → Iași, 20.10.2026 08:45”).
  - **Emailul text al comenzii WooCommerce:** un nume ca „D'Angelo” nu mai apare „DAngelo”, iar titlul e „BILETELE DUMNEAVOASTRĂ” (nu „DUMNEAVOASTRă”).
  - **Păstrarea datelor, ca în politica de confidențialitate:** la 3 ani după data cursei, numele, telefonul și emailul din rezervare se șterg automat; rămân doar codul, ruta, data și locurile (pentru statistici). Perioada se schimbă ușor dacă alegeți alta. Comenzile WooCommerce (cu datele de facturare) au propria setare: WooCommerce → Setări → Conturi și confidențialitate.
  - **Cererile clienților privind datele (Legea 195/2024, GDPR):** în Unelte → Exportă / Șterge datele personale, după emailul clientului, apar și se șterg și rezervările de bilete. Un bilet valabil pentru o cursă viitoare se păstrează până după cursă, cu mesaj explicativ. Numele pasagerilor copiate pe rândurile comenzii WooCommerce se șterg și ele: la cererea clientului, la anonimizarea făcută de WooCommerce și la 3 ani după cursă.
  - **Ghidul de confidențialitate WordPress** (Setări → Confidențialitate → Ghid) are un text de la plugin: ce date despre călători se prelucrează, cât se păstrează și cum se exportă sau se șterg la cerere.
  - **Rezervările anulate nu mai dispar după o zi:** curățenia zilnică ștergea și rezervările cu plata la urcare anulate de birou; acum șterge doar coșurile abandonate (fără cod de bilet). Rezervarea anulată rămâne la „Anulate”, iar clientul vede „Bilet anulat”, nu „Bilet negăsit”.
  - **Anulare din birou:** când biroul anulează o rezervare cu plata la urcare, clientul primește un email (cu codul, ruta, ora și telefonul), ca să nu vină degeaba la autocar; după anulare, lista rămâne pe aceeași căutare și același filtru (ex. telefonul clientului care a sunat); confirmarea „Anulați rezervarea?” spune dinainte că clientul primește email (sau, fără email, că trebuie sunat).
  - **Căutare în Rezervări:** când sună un client, rezervarea se găsește după codul biletului, telefon (și scris 069…), nume sau email, nu doar printre ultimele 200; filtrele de stare („Anulate”, „Plătite”…) păstrează căutarea.
  - **Emailul către birou** la o rezervare nouă are linkul „Deschide rezervarea în admin”, care o arată direct, căutată după cod.
  - **Panoul „Gata de plăți?”** avertizează dacă bannerul de cookies e oprit sau dacă telefonul pentru clienți e incomplet; arată și dacă WooCommerce anonimizează comenzile finalizate (politica promite 3 ani), cu link direct la setare; verifică și că IDNO-ul firmei are 13 cifre (banca îl compară cu cel de pe site); avertizează dacă pagina de finalizare a comenzii folosește blocul „Checkout”, unde plata băncii poate lipsi dacă modulul ei nu are varianta pentru blocuri (soluția: [woocommerce_checkout]); arată și ultima eroare la trimiterea emailurilor din ultimele 7 zile (ex. hostingul blochează emailul și clienții nu primesc biletele), cu link spre un plugin SMTP (adresele de email din mesajul de eroare nu se păstrează).
  - **Altele:**
    - dezinstalarea cu setarea „Șterge datele” bifată șterge și linkul secret de previzualizare (rămânea în baza de date);
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
