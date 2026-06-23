# PZ MyDrive

Osobní cloudové úložiště ve stylu **Google Drive**, postavené čistě v **PHP** — žádný
Node.js, žádná databáze, žádný Docker. Nahrajete na běžný PHP / FTP hosting (Forpsi
apod.) a hned jede.

> Stejné prostředí a funkce jako myDrive / Google Drive, ale jako jeden přenosný
> PHP balík. NENÍ to port subnub/myDrive (ten potřebuje Node.js + MongoDB).

## Funkce

- **Můj disk** — procházení složek a souborů (seznam / mřížka / **strom**), drag-drop nahrávání (i celých složek), náhledy obrázků a videí, lightbox
- **Sdílení veřejným odkazem** („kdokoli s odkazem“) s **vygenerovaným heslem**, **platností ve dnech** a právem **jen číst / číst i zapisovat** (write = příjemce může nahrávat)
- **Hvězdičky**, **Nedávné**, **Koš** (obnova smazaného), **vyhledávání** napříč diskem, ukazatel zaplnění (kvóta)
- **Admin „Správa“** — přehled všech sdílení (úprava hesla/práv/expirace), **statistiky přenosů** (download/upload za den i měsíc) + aktivita, nastavení (název, kvóta, heslo)
- **Tmavý režim**, plná responzivita, klávesové zkratky (Delete, F2, Ctrl+A, Esc), zvířecí avatary pro orientaci

## Bezpečnost

- Ochrana proti průchodu cestou (path traversal), CSRF u všech akcí, CSP s nonce
- bcrypt hesla, throttling proti hádání hesla, idle + absolutní timeout session
- `lib/`, `data/`, `storage/` nedostupné přímo přes HTTP; soubory jen přes gateway

## Instalace

1. Nahrajte celou složku na web (kořen domény nebo podsložku, např. `/disk`).
2. Otevřete v prohlížeči — při prvním spuštění naběhne průvodce (`setup.php`):
   jméno + heslo majitele, název, adresa (base URL), kvóta. Vytvoří se `config.php`.
3. Hotovo — přihlaste se a máte svůj Disk.

**Požadavky:** PHP 7.4+ / 8.x, rozšíření `zip`, `fileinfo`, `mbstring`, zapisovatelný adresář.

Začít znovu od nuly: smažte `config.php` a otevřete web.

## Licence

MIT — viz [LICENSE](LICENSE). © Petr Závorka.
