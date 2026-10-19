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
    - când în ziua aleasă nu sunt curse sau locuri, butonul „Ziua următoare →” de lângă mesaj trece la ziua de după, fără a redeschide calendarul pe telefon;
    - la o oră cu puține locuri (ex. 2), lista de pasageri se oprește la 2, iar sub ea scrie „La ora aleasă mai sunt doar 2 locuri libere online…”, cu telefonul (înainte lista se oprea fără explicație);
    - cititorul de ecran nu mai spune „săgeată” sau „ghilimea unghiulară” la butoanele „← Schimbă cursa”, „Ziua următoare →” și „‹ Ziua precedentă” (săgețile sunt doar decor);
    - o rută oprită și apoi pornită din nou apare imediat în formular: nici răspunsurile de eroare ale serverului („ruta nu există”) nu mai rămân în cache-ul site-ului;
    - pe iPhone-uri cu iOS mai vechi de 14.5 (Safari < 14.1), fereastra cu formularul deschisă peste pagină acoperă corect ecranul;
    - pe aceleași telefoane vechi și pe Safari < 15.4, cine folosește tastatura vede conturul pe butoane, pe alegerea monedei și în bannerul de cookies;
    - formularul (și fereastra lui pe telefon), pagina biletului, emailurile, bannerul de cookies și linkurile din subsol spun cititorului de ecran că textul e în română și când WordPress e pus în engleză (dacă plugin-ul nu e tradus), ca să nu fie pronunțat englezește;
  - **Bilet și liste:**
    - copiii sunt marcați „(copil)” pe bilet, în lista pentru șofer și în CSV;
    - CSV-ul cu pasageri pentru o singură rută are ruta în numele fișierului (ex. pasageri-2026-10-21-balti-iasi.csv);
    - CSV-ul cu pasageri nu mai lasă să treacă un „nume” pe care Excel l-ar executa ca formulă (ex. „+1+cmd|…”); telefoanele rămân la fel;
    - CSV-ul cu pasageri are la final coloanele „De incasat” (număr) și „Moneda”, goale la biletele plătite online: biroul adună direct în Excel cât are de încasat șoferul;
    - codul QR are text pentru cititoarele de ecran;
    - ziua săptămânii de pe bilet e mereu în română (nu „Tuesday” când biroul confirmă comanda cu adminul în engleză);
    - pagina biletului nu mai rămâne în cache;
    - pagina biletului rămâne deschisă la culoare și pe telefoanele cu „temă întunecată automată” (Chrome pe Android, Samsung Internet), ca biletul și codul QR să arate la fel când sunt arătate șoferului;
    - biletul unei curse din zi trecută apare gri „Cursa a avut loc pe …”, nu verde „valabil” (șoferul nu poate fi păcălit cu un bilet vechi);
    - dacă plata unui bilet e reluată (ex. după un card refuzat), pagina lui spune „Plata nu e finalizată”, nu „Bilet anulat”.
    - un link de bilet stricat sau incomplet arată „Biletul nu a fost găsit” cu telefonul firmei, ca clientul să poată suna;
    - pe pagina de după plată, dacă banca n-a confirmat încă plata, clientul vede „De obicei durează câteva secunde” și un buton „Verifică din nou” (nu și la plata refuzată, unde WooCommerce oferă „Plătește din nou”);
    - la printare, starea biletului rămâne pe foaie: un bilet anulat, neplătit sau dintr-o cursă trecută nu mai arată pe hârtie ca unul valabil.
    - butonul „Printează” de pe pagina biletului apare doar când poate funcționa (cu JavaScript); pagina se poate printa oricum din meniul browserului;
    - lista pentru șofer printată pe mai multe foi repetă capul tabelului pe fiecare foaie și nu rupe o rezervare (cu toți pasagerii ei) între două foi;
  - **Panoul „Gata de plăți?” cu cititor de ecran:** starea fiecărui rând se aude în cuvinte („Gata”, „De verificat”, „Lipsește”), nu doar ca ✔ ! ✘ colorate, iar fiecare buton „Rezolvă” spune ce rezolvă.
  - **Email de test în Setări:** butonul „Trimite un email de test la …” verifică înainte de lansare că emailurile site-ului (biletele, rezervările) chiar pleacă; dacă nu ajunge, pagina spune ce de făcut (emailul hostingului sau un plugin SMTP).
  - **„Locuri online pe cursă (implicit)” în Setări:** golit din greșeală sau 0 păstrează numărul de dinainte (înainte toate rutele fără număr propriu de locuri apăreau „complet” și nu se mai vindea nimic).
  - **Cursuri valutare în Setări:** un câmp golit din greșeală sau 0 păstrează cursul de dinainte (înainte devenea 0,0001, iar un bilet de 60 RON ar fi costat 0,01 MDL la plata în MDL).
  - **„Zile înainte” și „Pasageri pe rezervare” în Setări:** goliți din greșeală sau 0 păstrează valoarea de dinainte (înainte deveneau 1: vânzare doar pentru o zi și un singur pasager); la fel minutele de așteptare la coș și la plată. În formular, aceste câmpuri au acum minimul real (1, respectiv 5 și 10 minute), iar browserul îl spune direct pe câmp.
  - **Rute și orar:** dacă o oră de plecare e scrisă greșit (ex. „25:00” sau „8-45”), la salvare apare „Atenție: aceste ore nu au fost înțelese și nu s-au salvat”, cu orele respective (înainte cursa dispărea fără niciun mesaj). O rută care are bilete sau rezervări pentru curse viitoare nu se mai poate șterge din greșeală: apare mesajul să debifați „Activă” (vânzarea se oprește, călătorii rămân în lista pentru șofer). Dacă scoateți din orar o oră sau o zi pe care există deja bilete, la salvare apare lista curselor afectate (ex. „12.10.2026 08:45 (2)”), ca să anunțați pasagerii. În listă, o rută care nu se vinde apare „oprită: inactivă” sau „oprită: fără preț”. Un preț pentru copii mai mare decât cel pentru adulți (ex. 2400 în loc de 240) e semnalat la salvare. Un text mai lung decât încape (oraș peste 100 de caractere, loc de urcare peste 255) nu mai apare drept „Ruta a fost salvată” fără să se fi salvat: biroul primește un mesaj clar.
  - **Admin pe telefon:** listele Pasageri, Rezervări și Rute și orar devin carduri, iar câmpurile se ating ușor. În Setări și în formularul de rută, explicația de sub fiecare câmp e legată de câmp, ca un cititor de ecran să o spună odată cu eticheta.
  - **Pagina biletului:** butonul „Adaugă în calendar” pune cursa în calendarul telefonului (ora plecării, ruta, linkul biletului și un memento cu 2 ore înainte). Pagina are acum titlu și zonă principală marcate pentru cititoarele de ecran (verificată toată cu axe-core). „Adaugă în calendar” apare doar pe biletele valabile ale curselor care urmează, și ca link în emailul cu biletul sau rezervarea (inclusiv în varianta doar text a emailului comenzii). Dacă biletul are o comandă care n-a fost plătită niciodată, pe bilet apare „Achită acum”, care duce direct la plata comenzii (o comandă deja plătită sau rambursată nu cere a doua plată). La rezervările cu plata la urcare, evenimentul din calendar o numește „Rezervare” (ca pagina) și spune suma („De achitat la urcare: 240 MDL”).
  - **„Trimite biletul”:** pe telefon, pagina biletului valabil are și butonul „Trimite biletul”, care deschide meniul de partajare al telefonului (WhatsApp, Viber, SMS…) cu linkul biletului, de ex. ca părintele să trimită biletul copilului. Pe calculatoarele care nu pot partaja butonul nu apare. Mesajul are și textul cursei („Bilet LibertBus LB-…: Bălți → Iași, 13.10.2026 (marți), 08:45”), ca cine îl primește să vadă cursa fără să deschidă linkul. La o rezervare cu plata la urcare mesajul începe cu „Rezervare LibertBus …”, ca pagina.
  - **Finalizarea comenzii cu blocuri:** numele, telefonul și emailul scrise în formularul de rezervare vin completate și în finalizarea comenzii WooCommerce făcută cu blocuri (nu doar în cea clasică); datele deja salvate ale unui client cu cont nu se suprascriu.
  - **„Pasageri azi” în panou:** prima casetă din panoul LibertBus arată câți pasageri au cursă azi (plătiți online și cu plata la urcare) și, la atingere, deschide lista pentru șofer. Lângă ea, „Pasageri mâine” duce la lista de a doua zi (seara biroul pregătește cursele).
  - **Data în cuvinte:** sub câmpul „Data plecării” apare ziua scrisă în cuvinte („luni, 12 octombrie”). Pe telefoanele setate în engleză câmpul arată 10/12/2026 (lună/zi), ușor de confundat cu 10 decembrie.
  - **Grupuri mari:** când clientul alege numărul maxim de pasageri pe o rezervare online (8), sub câmpuri apare „Sunteți mai mult de 8 persoane? Pentru grupuri sunați la +373 …” cu telefonul ca link de apel.
  - **Locul de urcare:** fiecare rută are câmpul „Locul de urcare” (ex. „Bălți, Autogara Nord, peronul 3”), care apare în rezumatul din formular (înainte de plată), pe bilet, în emailuri, în comanda WooCommerce și ca loc în „Adaugă în calendar”. Gol = nu apare (calendarul pune orașul de plecare). De completat de birou pentru fiecare rută. Lista „Rute și orar” arată locul sub fiecare rută și, sus, câte rute active încă nu-l au. Lista pentru șofer filtrată pe o rută îl arată sus, și pe foaia printată (util pentru un șofer înlocuitor).
  - **Reamintire cu o zi înainte (opțională):** în Setări → „Reamintire cu o zi înainte”. Pornită, clientul primește în ziua dinaintea cursei (între 9:00 și 21:00) un email cu biletul, ora, locul de urcare și, la rezervările cu plata la urcare, mențiunea că plata se face la șofer. Câte un singur email pe bilet, chiar dacă WP-Cron pornește din două vizite deodată. Nu pleacă în zilele trecute la „Zile fără curse” și nici pentru o oră sau zi scoasă între timp din orar (acolo biroul anunță pasagerii). Oprită implicit: o porniți când vreți. Politica de confidențialitate generată de plugin numește reamintirea printre scopuri (o pagină deja creată se actualizează manual). În Rezervări, sub data rezervării, apare „reamintit 12.10 14:05” când emailul a plecat.
  - **Panoul „Gata de plăți?”: produsul intern.** Semnalează dacă „Bilet autocar” a fost stricat dintr-o editare în WooCommerce (stoc gestionat cu 0 bucăți, deci biletele nu mai intră în coș, sau fără „Virtual”, deci plata cere adresă), cu link direct la produs.
  - **Panoul „Gata de plăți?”: plata fără cont.** Verifică dacă WooCommerce lasă clienții să plătească fără cont (roșu dacă nu se poate deloc, atenție dacă sunt obligați să-și facă unul), cu linkul spre setare.
  - **Rute șterse:** rezervările vechi ale unei rute șterse după ultima ei cursă apar în Rezervări, în lista pentru șofer și în exportul de date personale cu „(rută ștearsă)”, nu cu o rută goală.
  - **Lista Rezervări:** filtrele arată câte sunt în fiecare stare („Rezervate (plata la urcare) (3)”), și pentru căutarea curentă; telefonul și emailul clientului sunt linkuri (sunați sau scrieți dintr-o atingere, ca în lista pentru șofer). Arată ultimele 200; când sunt mai multe, sub tabel scrie că pentru o rezervare mai veche se caută după cod, telefon, nume sau email.
  - **Emailul clientului:** o greșeală de tastare știută în domeniu („ion@gmail.con”, „gmial.com”, „yahoo.con”, „mail.r”) oprește rezervarea cu mesajul „ați vrut să scrieți ion@gmail.com?”: pe astfel de adrese biletul nu ar fi ajuns niciodată. Adresele corecte și domeniile necunoscute (ex. ale firmelor) trec ca până acum. Lista acoperă și greșelile dese „gmail.co”, „gmail.cpm”, „gmal.com”, „hotmail.co” și adresele Yandex („yandex.r”, „yadex.ru”), folosite des în Moldova.
  - **Telefonul clientului:** un număr scris cu 0 după prefixul țării („+373 069 184 111”, „+40 (0)740 123 456”) se salvează corect (+37369184111, +40740123456); înainte rămânea cu zeroul și biroul nu putea suna la el. Telefoanele fixe din România scrise local (021 123 4567, 0264 123 456) primesc și ele prefixul +40, ca biroul să le poată suna din Moldova.
  - **Ordinea orașelor:** în formular (plecări și destinații) și în sugestiile din admin, orașele sunt în ordinea alfabetică românească: „Bălți” după „Bacău”, „Rîșcani” înainte de „Roman”, „Ștefan Vodă” după „Sovata”. Înainte, orașele cu diacritice ajungeau la sfârșitul literei („Bălți” după „Buzău”).
  - **Rute cu orașe scrise altfel:** dacă la o rută nouă orașul e scris fără diacritice sau cu altă majusculă („chisinau”), se salvează ca pe celelalte rute („Chișinău”), ca să nu apară de două ori în lista de plecări. Scrierea se poate în continuare corecta din ruta respectivă.
  - **Pagina unui bilet anulat sau a unei curse trecute:** are butonul „Rezervați din nou online” (spre pagina rutei sau pagina cu formularul), nu doar „Printează”.
  - **Emailul de anulare către client:** pe lângă telefon, are linkul „Rezervați din nou online” spre pagina rutei (sau, dacă ruta nu are pagină, spre pagina cu formularul de rezervare).
  - **Emailul către birou la o rezervare:** telefonul și emailul clientului sunt linkuri — biroul sună sau răspunde dintr-o atingere.
  - **Emailul comenzii pe telefon:** în tabelul biletului, valorile (ruta, data, ora…) stau acum în dreptul etichetelor; stilul WooCommerce pentru ecrane mici le cobora și lungea tabelul.
  - **Browser prea vechi:** pe un telefon cu browser prea vechi pentru formular (fără fetch/Promise/TextDecoder) sau când formularul nu pornește, apare mesajul „Formularul nu merge în acest browser… sunați la …” cu telefonul de apăsat, nu un formular care nu răspunde.
  - **Internet slab la alegerea orei:** dacă verificarea locurilor eșuează (după 3 încercări), mesajul are butonul „Încearcă din nou” și telefonul, care se apasă direct pentru apel (la fel în „Pentru ziua aleasă nu mai sunt locuri online… sunați la …” și în mesajele de eroare de după trimitere, ex. „Pentru grupuri sunați la …”); clientul nu mai trebuie să schimbe data sau ruta ca să reîncerce.
  - **Curățarea automată:** dacă programarea ei orară se pierde (ex. plugin-ul e actualizat prin suprascrierea fișierelor, fără dezactivare și reactivare), se reface singură la următoarea vizită pe site. Altfel coșurile expirate nu s-ar mai elibera, iar datele personale nu s-ar mai șterge după 3 ani, cum promite politica de confidențialitate. Panoul „Gata de plăți?” arată și dacă rulează cu adevărat: dacă n-a mai rulat de peste o zi (WP-Cron pornește doar la vizite pe site și poate fi oprit), apare un avertisment cu data ultimei rulări și ce trebuie cerut hostingului.
  - **Lista Pasageri, de la o zi la alta:** butoanele „‹ Ziua precedentă” și „Ziua următoare ›” schimbă ziua dintr-o atingere, cu aceeași rută aleasă (pe telefon calendarul e incomod). Într-o zi fără pasageri, lista arată linkul „Următoarea zi cu pasageri: zz.ll.aaaa”.
  - **Lista Pasageri printată:** are la final coloana „Urcat”, cu o căsuță pe fiecare rând pe care șoferul bifează pasagerii urcați. Pe ecran și pe telefon coloana nu apare.
  - **Lista Pasageri (pentru șofer):** când în aceeași zi sunt mai multe curse, deasupra tabelului apar locurile pe fiecare cursă, câte una pe rând (ex. „07:00 Bălți → Iași: 3 locuri”), nu doar totalul zilei; se văd și pe foaia printată. Lângă locurile fiecărei curse apare și suma de încasat la urcare pe acel autobuz (ex. „07:00 Bălți → Iași: 3 locuri · de încasat la urcare 480 MDL”), nu doar totalul zilei.
  - **Emailurile de rezervare și anulare** pleacă și cu o variantă text (nu doar HTML): ajung mai rar în spam și se citesc bine în orice aplicație; subiectul emailului de rezervare are codul (ex. „Rezervare LB-AB12CD — Bălți → Iași, 20.10.2026 08:45”).
  - **Emailul text al comenzii WooCommerce:** un nume ca „D'Angelo” nu mai apare „DAngelo”, iar titlul e „BILETELE DUMNEAVOASTRĂ” (nu „DUMNEAVOASTRă”).
  - **Păstrarea datelor, ca în politica de confidențialitate:** la 3 ani după data cursei, numele, telefonul și emailul din rezervare se șterg automat; rămân doar codul, ruta, data și locurile (pentru statistici). Perioada se schimbă ușor dacă alegeți alta. Comenzile WooCommerce (cu datele de facturare) au propria setare: WooCommerce → Setări → Conturi și confidențialitate.
  - **Cererile clienților privind datele (Legea 195/2024, GDPR):** în Unelte → Exportă / Șterge datele personale, după emailul clientului, apar și se șterg și rezervările de bilete. Un bilet valabil pentru o cursă viitoare se păstrează până după cursă, cu mesaj explicativ. Numele pasagerilor copiate pe rândurile comenzii WooCommerce se șterg și ele: la cererea clientului, la anonimizarea făcută de WooCommerce și la 3 ani după cursă.
  - **Ghidul de confidențialitate WordPress** (Setări → Confidențialitate → Ghid) are un text de la plugin: ce date despre călători se prelucrează, cât se păstrează și cum se exportă sau se șterg la cerere.
  - **Rezervările anulate nu mai dispar după o zi:** curățenia zilnică ștergea și rezervările cu plata la urcare anulate de birou; acum șterge doar coșurile abandonate (fără cod de bilet). Rezervarea anulată rămâne la „Anulate”, iar clientul vede „Bilet anulat”, nu „Bilet negăsit”.
  - **Anulare din birou:** când biroul anulează o rezervare cu plata la urcare, clientul primește un email (cu codul, ruta, ora și telefonul), ca să nu vină degeaba la autocar; după anulare, lista rămâne pe aceeași căutare și același filtru (ex. telefonul clientului care a sunat); confirmarea „Anulați rezervarea?” spune dinainte că clientul primește email (sau, fără email, că trebuie sunat). Pentru o cursă care a trecut (clientul n-a venit) nu mai pleacă emailul „rezervarea a fost anulată”, iar confirmarea din admin spune asta.
  - **Căutare în Rezervări:** când sună un client, rezervarea se găsește după codul biletului, telefon (și scris 069…), nume, email sau numărul comenzii WooCommerce („#1234” din emailul de plată sau din Paynet), nu doar printre ultimele 200; filtrele de stare („Anulate”, „Plătite”…) păstrează căutarea.
  - **Biletele în comanda WooCommerce:** pe pagina unei comenzi din WooCommerce apare caseta „Bilete LibertBus” cu codul fiecărui bilet (link spre bilet, de retrimis clientului), cursa, locurile și starea.
  - **Zile fără curse (sărbători):** în Setări → „Zile fără curse” (ex. 25.12.2026, 01.01.2027): în acele zile formularul nu arată plecări pe nicio rută și nu se poate rezerva. Dacă există deja bilete în ziua închisă, salvarea avertizează cu numărul lor, ca biroul să anunțe pasagerii. Clientul care alege o zi închisă vede „În ziua aleasă nu sunt curse (zi liberă). Alegeți altă dată.”; data aleasă automat sare peste zilele închise. Lista Pasageri pentru o astfel de zi, dacă are bilete, arată sus un avertisment cu link spre Setări.
  - **Emailul către birou** la o rezervare nouă are linkul „Deschide rezervarea în admin”, care o arată direct, căutată după cod.
  - **Panoul „Gata de plăți?”** avertizează dacă bannerul de cookies e oprit sau dacă telefonul pentru clienți e incomplet; arată și dacă WooCommerce anonimizează comenzile finalizate (politica promite 3 ani), cu link direct la setare; verifică și că IDNO-ul firmei are 13 cifre (banca îl compară cu cel de pe site); avertizează dacă pagina de finalizare a comenzii folosește blocul „Checkout”, unde plata băncii poate lipsi dacă modulul ei nu are varianta pentru blocuri (soluția: [woocommerce_checkout]); arată și ultima eroare la trimiterea emailurilor din ultimele 7 zile (ex. hostingul blochează emailul și clienții nu primesc biletele), cu link spre un plugin SMTP (adresele de email din mesajul de eroare nu se păstrează).
  - **Setări mai sigure la salvare** (avertizarea apare imediat, nu doar în Panou):
    - IDNO-ul care nu are 13 cifre („are 12 cifre, nu 13”);
    - telefonul pentru clienți incomplet sau fără prefixul țării (clienții din România n-ar putea suna);
    - culoarea butoanelor scrisă fără „#” („1e40af”) se păstrează, nu mai revine pe tăcute la verde.
  - **Emailuri:**
    - un email terminat în „.con”, „.cmo”, „.comm” (la orice domeniu, ex. „ion@firma.con”) e oprit cu „.com” propus;
    - răspunsul automat de concediu al biroului nu mai pleacă la client (notificările interne sunt marcate „automate”).
  - **Pentru birou:**
    - pe lista de pasageri, butonul „Azi” lângă „Ziua precedentă / următoare”;
    - în „Rezervări”, dacă un filtru (ex. „Anulate”) ascunde rezultatele căutării, apare „Vezi rezultatele din toate stările (N)”;
    - căutarea din „Rezervări” găsește numele scrise fără diacritice („Stefan Turcanu” → „Ștefan Țurcanu”) și codul dictat la telefon fără liniuță („LB3KVU8W”, „lb 3kvu8w”);
    - în Setări, „Separator în CSV”: „punct și virgulă” pentru Excel în română (altfel tot rândul apare într-o coloană), cu sumele scrise 30,5;
    - pe lista de pasageri, o dată scrisă greșit de mână („31.11.2026”) nu mai arată „01.12.2026” fără pasageri, ci revine la ziua de azi.
  - **Bilet:** la printare, un bilet nu se mai rupe între două pagini; fără „Adaugă în calendar” la o rută ștearsă (linkul nu mergea).
  - **Accesibilitate:** butoanele formularului au contur vizibil la navigarea cu tastatura, și pe teme care îl ascund; linkurile din admin care se deschid într-o filă nouă o spun cititorului de ecran; cititorul de ecran anunță „Biletele se emit…” pe pagina de mulțumire și nota pentru grupuri de la listele de pasageri.
  - **Telefoane vechi** (iPhone cu iOS sub 11.3, Android 4.4): după 8 secunde fără răspuns apare „Încearcă din nou” (nu „Se verifică locurile…” la nesfârșit); tasta Tab în fereastra pasului 2 nu mai dă eroare.
  - **Română corectă** la numere: „cel mult 20 de locuri”, „mai mult de 20 de persoane”, „ultimele 200 de rezervări”; data din politici după ora Chișinăului și cu fusul „UTC+2” fix.
  - **Altele:**
    - arhiva de instalare se face cu `tests/build-zip.sh` (~120 KB, doar ce rulează pe site; fără teste și capturi de ecran);
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
2. **Datele firmei** pentru LibertBus → Setări: denumire, IDNO, adresă. Apar în politica de confidențialitate, cerută de Legea nr. 195/2024. Confirmați și cât timp se păstrează datele: rezervările 3 ani, mesajele 1 an, jurnalele de securitate 90 de zile. IDNO-ul se poate lipi și cu spații (ex. „1003 600 123 456”): se salvează doar cifrele.
3. **Regulile de anulare și rambursare**. Textul-model propune 100% / 50% / 0% în funcție de cât timp mai e până la plecare. Ajustați-l.
4. **Câte locuri pe cursă** se vând online.

### Ce pot face eu, cu acordul dumneavoastră
1. ~~Instalarea și activarea plugin-ului pe site.~~ Făcut. Rămâne actualizarea la versiunea de pe branch, cu arhiva făcută de `tests/build-zip.sh`.
2. Fusul orar → Chișinău. Moneda WooCommerce → MDL.
3. Paginile legale: confidențialitate și cookies sunt publicate și legate în subsol. Termenii, rambursarea și plata așteaptă verificarea dumneavoastră.
4. Pagină nouă „Rezervă bilet” cu formularul. Pe paginile de rută, formularul de contact se înlocuiește cu `[libertbus_rezervare from=".." to=".."]`. Butoanele „Rezervă” din „Orar Curse” vor duce spre formular.
5. Oprirea WooPayments, care nu funcționează în Moldova.
6. Test complet cu plata de test, apoi cu modul de test al băncii. Înainte: în Setări „Trimite un email de test”, iar în panoul „Gata de plăți?” toate rândurile verzi (inclusiv „Erorile PHP nu se afișează vizitatorilor”).
7. Scoaterea textului `[wbtm-...]` rămas pe pagini de la plugin-ul vechi.

### Recomandat separat (securitate)
- Actualizare PHP 7.4 → 8.2 sau mai nou, din panoul de hosting (după instalarea versiunii 1.5.0, panoul „Gata de plăți?” o semnalează cu galben până atunci).
- Actualizarea WordPress, WooCommerce, Elementor și Slider Revolution. Versiunea 6.3.3 de Slider Revolution are breșe cunoscute. După instalarea versiunii 1.5.0, panoul „Gata de plăți?” arată câte actualizări sunt în așteptare.
- Schimbarea parolei de administrator.
- Dacă schimbați și cheile de securitate WordPress (din wp-config.php sau cu un plugin de securitate): biletele emise cu 1.5.0 rămân valabile, au cheie proprie. Linkurile trimise de 1.4.1, aflată acum pe site, nu mai merg după schimbare. Faceți schimbarea după trecerea la 1.5.0 și după ce trec cursele deja vândute.

## Verificare automată (QA)

O sesiune programată la 30 de minute verifică:
- testele locale: teste automate, plus o rezervare cap-coadă cu plata de test, atât prin pagina de plată clasică, cât și prin cea „cu blocuri”;
- starea site-ului live: pagina principală, „Orar Curse”, panoul „Gata de plăți?”.

Ea face îmbunătățiri mici și sigure în cod, pe acest branch. Pe site-ul live nu modifică nimic fără acordul dumneavoastră.
