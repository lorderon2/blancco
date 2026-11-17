# Obecne reguły i weryfikacja przed implementacją

Podstawa: statyczny odczyt worktree KeyDeploy `cez/b670bb75`, lokalnego Blancco_API i załączonego workflow. Nie odczytano produkcyjnego crontaba ani konfiguracji połączeń. To mapa zachowań kodu, a nie dowód, które warianty skryptów uruchamia serwer.

## Reguły do zachowania

| Obszar | Dowód w KeyDeploy | Postępowanie |
|---|---|---|
| IdoSell | iai.php: grupy product_group_ids, packed_ready, magazyn 1, strony po 100 | odtworzyć filtr i paginację; daty startu historycznego nie kopiować |
| Wykluczone produkty | iai.php saveOrdersToDatabase: 203122 i 25556 | zachować jako wersjonowaną regułę |
| Wiele grup Windows | iai.php countProductGroups > 1 pomija rekord | zachować brak automatycznej konfiguracji; pokazać powód w panelu |
| DPK poza Polską | iai.php: DPK=0 dla kraju poza Polska/Pl i niewysłanych rekordów | odtworzyć zakres przed wysyłką; brak flag KeyDeploy zastąpić stanem cyklu życia |
| Home poza Polską | iai.php: pominięcie przed zamianą Win10 Home na Pro | zachować kolejność reguł |
| Win10 Home -> Pro | iai.php: zamiana globalna, potem DPK=0 dla Win10 Home/Pro | zachować wynik biznesowy, nie globalny SQL aktualizujący historię |
| COA | task2.php: wymagany WINDOWS_10_PRO / WINDOWS_10_PRO_PRE; Units -> Units_Devices Category COA | zachować jeżeli to wariant używany przez cron; brak COA blokuje |
| Wybór AIKEN | task2.php: NOT -> lap, PC/AIO -> pc | zachować i pokazać źródło danych |
| Lenovo | task2.php: prefiks 1S+model+serial | usunąć zgodnie z wyraźną decyzją użytkownika |
| Obrazy standardowe | task2.php: PL -> 1WIN {wersja} PL, pozostałe mapowanie kraju | zachować dokładne ciągi i mapowanie |
| Obrazy oddziałowe | departments.php/create.php/create_multiple.php: 2WIN ... PL ODD | zachować, w tym podwójną spację w 2WIN 10  HOME PL ODD |
| Oddziały | departments.php: źródłowe orders JOIN shop, serial i OS, bieżący dzień | zachować pochodzenie; dodać bezpieczne wznowienie po awarii, bez utraty poprzedniego dnia |
| Bitrix HOME | task_add2.php: home_check=1 -> crm.deal.add, potem 3 | osobne zadanie, nie zależne od wysłania tasków KeyDeploy |
| Klucz do Bitrix | index3.php: odczyt najnowszego ProductKey z KeyDeploy /dpk i aktualizacja deala | wymagane zastąpienie źródła; nie zakładać, że bool hasDpk dostarcza klucz |

## Konkretne rozbieżności do wyjaśnienia dowodami

1. task.php i bc.php nie mają tego samego sprawdzania COA co task2.php. Potrzebny rzeczywisty harmonogram i wywoływany plik przed twierdzeniem „dokładnie tak samo”.
2. departments.php dopuszcza etykiety PRO PRE, ale nie przypisuje im obrazu w czterech gałęziach mapowania; późniejsze task2.php może uzupełnić pusty obraz standardowym. Nie poprawiamy tego niejawnie.
3. W repozytorium występuje konsument home_check=1; należy ustalić, kto i kiedy tę wartość ustawia. Bez tego nie da się odtworzyć pełnego procesu Bitrix.
4. Zastąpienie /dpk KeyDeploy wymaga potwierdzenia miejsca klucza w raportach Blancco lub innym aktualnym źródle. Sam przełącznik DPK ani obecność COA nie są równoważne z ostatnio wydanym kluczem.
5. Stabilna tożsamość i chronologia przypisań BC wymagają sprawdzenia dostępnych kolumn i przykładowych zanonimizowanych rekordów. Nie używamy przypadkowej kolejności SELECT.
6. product_group_ids jest konfiguracją w bazie, nie kompletną listą w kodzie. Odtworzyć aktualne mapowanie osobno; zakaz przenoszenia historii nie oznacza zakazu przeniesienia konfiguracji.
7. Źródła i przepustowość z serwera WWW oraz połączenie z bazą wymagają sprawdzenia przed wdrożeniem. Nie zakładamy dostępu na podstawie samego DNS.

## Co można zrobić bez nowych decyzji biznesowych

Szkielet Laravel, logowanie i role, migracje na bazie testowej, model czasu/stanów, lista i szczegóły zleceń, fixture integracji, kontrakt API i testy zgodności. Dokładne konektory i reguły wymagają powyższych dowodów; dostęp do produkcji osobno autoryzowany.

## Kryteria zgodności

Dla każdego starego scenariusza utrwalamy wejście (źródło/kraj/Windows/produkty/COA), oczekiwany obraz, use_dpk, zgodę lub blokadę i efekt Bitrix. Porównujemy wynik nowej aplikacji z używanym wariantem starego procesu. Różnica ma być uzgodniona albo naprawiona, nie ukryta jako refaktoryzacja.

Nie przenosimy testowych wysyłek tasków, CSV, starych wariantów old/ ani flag sync_flag/bc_sync jako nowego modelu domenowego. Historii zamówień i kluczy z KeyDeploy nie importujemy.

## Stan po implementacji lokalnej (2026-11-17)

Konektory i testy są gotowe do weryfikacji ze źródłami. Reguły `legacy-v1` odtwarzają zidentyfikowane ścieżki, ale nie stanowią dowodu pełnej zgodności z cronami. Szczególnie globalne aktualizacje iai.php mogły wpływać na ręczne zlecenia zależnie od kolejności skryptów; przed włączeniem należy porównać rzeczywisty harmonogram i przykłady. Dla niepotwierdzonego oddziałowego PRE nowy kod blokuje automat zamiast zgadywać obraz.

Domyślne kolumny BC Posting Date / Entry No_ są propozycją adaptera. Posting Date może być datą księgowania, a nie czasem przypisania. Aktualne kolumny i chronologię należy potwierdzić przed uruchomieniem; w konfiguracji można wskazać właściwe pole czasu. Zmiana lub wycofanie przypisania nie jest jeszcze automatycznie wnioskowane z braku wyniku.

Dokumentacja producenta raportów: https://blancco-support.atlassian.net/wiki/spaces/KB/pages/11636267/Exporting+reports+using+Management+Portal+API oraz https://blancco-support.atlassian.net/wiki/spaces/KB/pages/11636319/Pagination+and+using+cursor+as+part+of+Report+Export+API. Domyślny filtr @system_serial, pusta data wyłącza ograniczenie miesiąca, cursor jest przekazywany w JSON. URL eksportu i URL pojedynczego raportu są jawnie konfigurowalne, bo dokumentacja i eksport użytkownika używają różnych wariantów ścieżki.

## Weryfikacja podczas wdrożenia testowego

Produkcja KeyDeploy uruchamia co 10 minut łańcuch iai.php → departments.php → task2.php → task_add2.php; index3.php działa co 2 minuty. BC_SOURCE=api. Odczyt rzeczywistego endpointu ILE zwrócił HTTP200 oraz Entry_No i N24C_SystemCreatedAt. Dodano adapter API z grupowaniem dokumentów po20 i kontrolą nextLink; nie używa Posting_Date jako daty utworzenia przypisania. Kopiowane do nowej bazy product_group_ids są konfiguracją (64 mapowania), a nie historią zamówień. Grupy HOME_PRE występują w starej konfiguracji, ale nie mają rozstrzygniętej kompletnej reguły obrazu w dotychczasowym task2.php; automat pozostawia je zablokowane.
