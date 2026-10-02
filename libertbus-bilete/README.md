# LibertBus Bilete

Plugin WordPress/WooCommerce pentru vânzarea online a biletelor de autocar pe libertbus.md, cu plata cu cardul.
Clientul alege cursa, plătește cu cardul și primește biletul pe email.

## Ce face

- **Rute și orar**: plecare, destinație, ore, zile ale săptămânii, preț adult/copil, monedă (MDL/RON/EUR/USD), locuri online pe cursă. La activare se importă orarul real din pagina „Orar Curse” (85 de rute).
- **Formular de rezervare**: `[libertbus_rezervare]` (toate rutele) sau `[libertbus_rezervare from="Bălți" to="Iași"]` (pe pagina unei rute). Arată orele cu locuri libere, cere numele pasagerilor, telefon, email și calculează totalul.
- **Locuri fără vânzare dublă**: locurile se țin 15 minute în coș și 30 de minute cât se așteaptă plata, apoi se eliberează automat. Rezervarea folosește blocare în baza de date, deci două persoane nu pot lua ultimul loc în același timp.
- **Plata**: prin WooCommerce, deci merge cu orice plugin de plată (Paynet, maib, Victoriabank, BT iPay). Plugin-ul nu atinge datele cardului.
- **Biletul**: cod `LB-XXXXXX` pe email, pe pagina de mulțumire și în contul clientului, plus link spre o pagină cu cod QR (bun de arătat șoferului sau de printat). Biletul se emite doar după confirmarea plății, iar o comandă anulată sau rambursată eliberează locurile.
- **Admin → LibertBus**:
  - **Panou**: lista „Gata de plăți?” cu ce mai lipsește (bancă, pagini legale, fus orar, monedă etc.);
  - **Rute și orar**;
  - **Pasageri**: lista de îmbarcare pe zi, printabilă, export CSV;
  - **Rezervări**;
  - **Setări**: locuri, timpi, cursuri valutare, datele firmei, plată de test.
- **Pagini legale cerute de bancă**: termeni, anulare și rambursare, plata online, confidențialitate. Se creează ca ciorne, cu datele firmei completate automat.
- **Plată de test**: o metodă vizibilă doar administratorilor, ca să verificați tot drumul fără bancă.

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

Pe o instalare de test (nu pe site-ul real): `wp eval-file wp-content/plugins/libertbus-bilete/tests/smoke.php`.
Rulează 39 de verificări: locuri, expirare, plată întârziată, anulare, validări.
