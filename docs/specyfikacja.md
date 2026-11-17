# Specyfikacja aplikacji Blancco

Data: 2026-11-17. Dokument do przeglądu przed implementacją.

## 1. Uzgodniony zakres

- Nowe repozytorium `Herd/blancco`, Laravel, pusta baza `blancco`.
- Przygotowanie zleceń, zarządzanie nimi, obsługa danych i raportów z Blancco API.
- Źródła: IdoSell, BC, AIKEN (sprzęt i COA), baza oddziałów, Bitrix.
- Zlecenia dokumentowe gotowe po przypisaniu serialu w BC. Oddziałowe i ręczne z serialem mogą działać bez BC.
- Jedna konfiguracja systemu dla zamówienia, zgodnie z dotychczasowym podejściem. Brak automatycznego rozdzielania różnych systemów między pozycje.
- Dotychczasowe reguły obrazów i licencji mają zostać zachowane; serial Lenovo pozostaje oryginalny.
- Ponowny import tego samego dokumentu i serialu nie tworzy zlecenia ani nie odnawia ważności.
- Pierwsza instalacja: trzy dni kalendarzowe łącznie z dniem utworzenia; poniedziałek–środa.
- Po sukcesie Reimage ponowienia tylko tego samego dnia, po komunikacie operatorowi. Bez przedłużania terminu przez kolejne wykonanie.
- Najnowsze zlecenie wiążące. Wyjątek zatwierdzony przez użytkownika: po anulowaniu wybieramy starsze, jeśli nadal uprawnia do instalacji.
- Wygasłe zlecenie prowadzi do instalacji ręcznej. Nie stosujemy wyjątku anulowania do wygaśnięcia.
- Pełny przegląd zleceń, anulowanie, ręczne dodanie po numerze dokumentu i po serialu.
- Role Administrator i Użytkownik: wszystkie możliwości jednakowe poza tworzeniem użytkowników, dostępnym tylko administratorowi. Bez publicznej rejestracji.
- Dane BC i IdoSell w panelu tylko do podglądu z lokalnego zapisu; synchronizacja w tle. Ręczne dodanie dokumentu może uruchomić zadanie jego pobrania.
- Lista raportów per serial, szczegóły i pobranie raportu. Bez trwałego przypisywania UUID raportu do zlecenia.
- Około 500 urządzeń dziennie; zlecenie dostępne do 10 minut od widocznego przypisania w BC przy działających źródłach.
- Bez historii z KeyDeploy i bez równoległego wykonywania instalacji przez oba rozwiązania.

## 2. Architektura — propozycja

Laravel zawiera panel, konektory źródeł, harmonogram i kolejkę zadań. Łączy się z bazą `blancco` na 192.168.12.203. Blancco_API pozostaje oddzielnym procesem i również korzysta z tej bazy; obsługuje żądania workflow bez zależności od dostępności serwera WWW aplikacji.

Laravel jest właścicielem migracji. Usługa Python nie wykonuje DDL. Oba repozytoria utrzymują wspólną, wersjonowaną specyfikację API i wymaganej wersji schematu. Migracje wdrażane w kolejności zapewniającej kompatybilność obu procesów.

Stan biznesowy jest wspólny w bazie. Reguł wyboru i ważności nie wolno implementować sprzecznie w PHP i Pythonie: wspólne scenariusze wejście/wynik, identyczne transakcje i testy zgodności w obu repozytoriach. Nie zakładamy, że samo oznaczenie statusu przez cron wystarcza do ochrony przed wygaśnięciem.

Daty przechowujemy w UTC, granice dni obliczamy w Europe/Warsaw. Interfejs pokazuje czas polski. Daty źródłowe zachowują informację o swojej strefie i pochodzeniu.

## 3. Synchronizacja — proponowane zadania aplikacji

| Zadanie | Częstotliwość początkowa | Wynik |
|---|---|---|
| Import IdoSell | co 2 min | zamówienia i konfiguracja źródłowa |
| Import oddziałów | co 2 min | dokumenty z serialem |
| Przypisania BC | co 2 min | aktualne przypisania do dokumentów |
| Wzbogacenie AIKEN i przygotowanie | po imporcie, ponowienia co 2 min | obraz, decyzja licencyjna, blokady |
| Porządkowanie ważności | co minutę | statusy widoczne w panelu |
| Wykrywanie niepotwierdzonych prób | co 5 min | stan wymagający sprawdzenia |
| Integracja Bitrix | kolejka z trwałym outboxem | dotychczasowe wymagane operacje CRM |

To wewnętrzny scheduler aplikacji, nie automatyzacja cockpit/cezar. Nie instalujemy go na serwerze w etapie specyfikacji.

Synchronizacje mają blokadę równoległego wykonania, limit czasu, licznik postępu, paginację i ponawianie z opóźnieniem. Postęp strony/partii zapisujemy dopiero po trwałym zapisie danych. Błąd jednej strony nie oznacza udanego pełnego przebiegu.

Brak rekordu w niepełnym odczycie lub awaria źródła nie jest dowodem anulowania. Weryfikacja wycofanych przypisań musi korzystać z pełnego odczytu właściwego zakresu lub jawnego zdarzenia źródła.

Dokładne czasy zadań wymagają pomiaru. Budżet 10 minut obejmuje wszystkie etapy, a nie tylko pobranie BC; mierzymy czas od źródłowego przypisania do gotowości i opóźnienie każdego konektora.

## 4. Panel

- Pulpit: gotowe zlecenia, braki danych, blokady, ostatnie sukcesy, problemy integracji.
- Lista wszystkich zleceń z filtrem serialu, dokumentu, źródła, statusu, obrazu i daty; paginacja po stronie serwera.
- Szczegóły: zamówienie, urządzenia, dane źródłowe, konfiguracja wynikowa i zastosowane reguły, ważność, historia prób i zmian, powód blokady/anulowania.
- Formularz dokumentu: źródło + numer dokumentu; wynik pobrania lub konkretna informacja, czego nie znaleziono.
- Formularz seriali: konfiguracja i pochodzenie ręczne/oddziałowe; deduplikacja oraz historia autora.
- Anulowanie zlecenia z potwierdzeniem i powodem. Bez ukrytej funkcji odnawiania wygasłych zleceń; użytkownik jej nie zamówił.
- Lista raportów urządzenia: wszystkie strony, daty, wynik, szczegóły i pobranie. Awaria chmury nie blokuje odczytu lokalnych zleceń.
- Administracja użytkownikami; logowanie, wylogowanie, reset hasła. Nowi użytkownicy tylko przez administratora.

„Pełne dane” oznaczają dane operacyjne pobrane do realizacji procesu, nie wszystkie dane osobowe klienta. Nie kopiujemy adresów/kontaktów bez potrzeby. Klucze licencji są maskowane w listach i wyłączone z logów technicznych.

## 5. Dostęp i eksploatacja

Osobne konta bazy dla migracji, aplikacji i API. API ma odczyt niezbędnej konfiguracji oraz zapis prób i wyników, bez administracji użytkownikami i bez DDL. Połączenie między serwerami przez uzgodniony prywatny kanał/TLS, nie publiczny port MySQL.

Bearer dla workflow poza repozytorium, z możliwością rotacji. Hasła i klucze integracji w konfiguracji środowiska. Bez kopiowania sekretów z KeyDeploy do dokumentów lub kodu. CSRF panelu nie jest mechanizmem autoryzacji API.

Backup bazy, odtworzenie testowe, logi z rotacją, alarmy nieudanych synchronizacji i wykrywanie zalegającej kolejki. Kopie i konfiguracja muszą umożliwiać odtworzenie usługi na obu serwerach.

## 6. Etapy wykonania

1. Przegląd tej specyfikacji i macierzy zgodności starych reguł; wyjaśnienie rozbieżności, które rzeczywiście zmieniają zachowanie.
2. Szkielet Laravel, logowanie, model danych, migracje i testy cyklu życia na osobnej bazie testowej.
3. Konektory z lokalnymi kontraktami/fixture, scheduler, kolejka, deduplikacja i obserwowalność.
4. Panel i operacje ręczne; API Python dostosowane w osobnym repozytorium do nowej bazy.
5. Konfiguracja i próby workflow na urządzeniu testowym, w tym brak API, ponowienie zapisu i wygaśnięcie.
6. Przygotowanie wdrożenia i odcięcia starego procesu; produkcyjne operacje osobno autoryzowane.

## 7. Przełączenie

Nowa baza nie otrzymuje starych zleceń. Moment startu importu musi być jawnym parametrem uruchomienia, a import IdoSell nie może zachować historycznej daty startu z 2025 r. Bez migracji historii dopuszczamy listę jawnie wskazanych bieżących dokumentów wymagających przygotowania.

Przed startem: backup i plan cofnięcia, zweryfikowane obrazy/licencje, dostęp sieciowy, zgodne API/schema i test BAR. W oknie przełączenia wyłączamy generowanie tasków KeyDeploy, przełączamy API oraz workflow. Nie wyłączamy bez zastępstwa zależnych czynności Bitrix. Nie wracamy automatycznie do starej bazy po błędzie nowego API.

Testy przed odbiorem: wiele seriali/dokument, ponowne pobranie bez odnowienia, nowe zamówienie na serial, anulowanie i powrót do starszego, brak takiego powrotu po wygaśnięciu, granice dni i zmiana czasu, oba warianty DPK, COA, ręczna instalacja, równoległy start, utracona odpowiedź i powtórzony callback, brak BC/AIKEN/chmury oraz potwierdzenie celu 10 min.
