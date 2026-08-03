# Zadania·AP — prywatny portal zarządzania zadaniami

Lekki portal PHP 8 + MySQL (utf8mb4) do prowadzenia zadań prywatnych i służbowych,
z dziennikiem/konwersacją per zadanie (miejsce na wpisy Twoje i agentów AI),
harmonogramowaniem (termin + dzień planowany) i priorytetami P1–P5.
Zero frameworków i zależności — działa na każdym współdzielonym hostingu (CyberFolks).
Interfejs mobile-first pod iPhone'a, jasny i ciemny motyw automatycznie.

## Struktura plików

```
config.sample.php   wzorzec konfiguracji -> skopiuj jako config.php
install.php         instalator (uruchom raz, potem USUŃ z serwera)
login.php           logowanie
logout.php          wylogowanie
index.php           lista zadań + filtry (sfera, status, szukaj)
task.php            widok zadania: opis, dziennik, statusy, blok dla agenta AI
task_form.php       nowe zadanie / edycja
quick.php           „Wklej i utwórz" — zadanie z transkrypcji/maila/notatki
inbox.php           skrzynka HTTP (token) dla iOS Shortcuts / Power Automate
inc/bootstrap.php   rdzeń: sesje, PDO, CSRF, słowniki
inc/layout.php      szkielet HTML
assets/style.css    style
schema.sql          referencyjny schemat bazy
.htaccess           UTF-8 + blokada plików technicznych
```

## Wymagania

PHP 8.0+ z rozszerzeniem `pdo_mysql` (na CyberFolks wybierz PHP 8.2/8.3),
baza MySQL/MariaDB, HTTPS (Let's Encrypt w panelu — zwykle włączony automatycznie).

## Wdrożenie na cyberfolks.pl — krok po kroku

1. **Baza danych.** W panelu hostingu (DirectAdmin) wejdź w *Zarządzanie MySQL* →
   *Utwórz nową bazę danych*. Zanotuj: nazwę bazy, użytkownika, hasło.
   Host to zwykle `localhost`. (Nazwy pozycji w panelu mogą się nieznacznie różnić.)
2. **Konfiguracja.** Skopiuj `config.sample.php` jako `config.php` i wpisz dane z kroku 1.
   Jeśli chcesz używać skrzynki `inbox.php`, ustaw też długi losowy `INBOX_TOKEN`
   (np. wynik `openssl rand -hex 32`).
3. **Upload.** Wgraj całą zawartość katalogu (razem z `.htaccess`!) przez
   *Menedżer plików* w DirectAdmin albo FTP/SFTP do katalogu domeny,
   np. `domains/twojadomena.pl/public_html/zadania/`.
   Sensowny wariant: osobna subdomena `zadania.twojadomena.pl`.
4. **Wersja PHP.** W panelu ustaw dla tej domeny PHP 8.2 lub 8.3.
5. **Instalacja.** Otwórz w przeglądarce `https://…/zadania/install.php`,
   podaj login i hasło (min. 10 znaków). Instalator utworzy tabele
   i pierwsze zadanie: „Budowa portalu zadań".
6. **Sprzątanie.** **Usuń `install.php` z serwera.** Zaloguj się przez `login.php`.

### iPhone

W Safari otwórz portal → Udostępnij → **Do ekranu początkowego**.
Portal dostanie ikonę i zachowuje się jak aplikacja (meta `apple-mobile-web-app`).

## Model danych (skrót)

Zadanie: tytuł, opis, **sfera** (prywatne/służbowe), **status**
(nowe → w toku → oczekuje → zrobione/anulowane), **priorytet** P1–P5
(1 = krytyczny), **termin** (deadline) i **zaplanowane na** (dzień pracy),
źródło, znaczniki czasu. Dziennik: wpisy z autorem `ja` / `ai` / `system` —
to jest Twoja „konwersacja z AI" per zadanie.

## Integracja Outlook / Teams / iPhone (etapami)

**Etap 1 — ręcznie (od dziś):** kopiujesz treść maila/wiadomości i wklejasz
w „Wklej i utwórz" (`quick.php`). Pierwsza linia staje się tytułem.

**Etap 2 — skrzynka `inbox.php` (półautomatycznie):**

Test z terminala:

```bash
curl -X POST https://twojadomena.pl/zadania/inbox.php \
  -d token="TWÓJ_INBOX_TOKEN" \
  -d title="Oddzwonić do klienta X" \
  -d description="Szczegóły z maila…" \
  -d sphere=sluzbowe -d priority=2 -d due_date=2026-08-05 -d source=outlook
```

*iOS Shortcuts (Skróty):* nowy skrót → akcja **Pobierz zawartość URL** →
URL `https://…/inbox.php`, metoda POST, treść „Formularz", pola:
`token`, `title` (Zapytaj za każdym razem lub Wejście skrótu), `sphere`, `source=iphone`.
Skrót dodaj do arkusza udostępniania — zaznaczysz tekst maila w Outlooku na iPhonie,
Udostępnij → skrót → zadanie ląduje w portalu.

*Power Automate:* przepływ „Gdy otrzymam e-mail oflagowany / gdy powstanie zadanie
w To Do–Planner" → akcja **HTTP POST** na `inbox.php` z tymi samymi polami.
To zamyka pętlę Outlook/Teams → portal bez ręcznego kopiowania.

**Etap 3 (opcjonalny, później):** pełna synchronizacja przez Microsoft Graph API —
portal jest na to gotowy (tabela `tasks` ma pole `source`).

## Praca z agentem AI (czytanie ekranu, zdalne pisanie)

Portal jest server-side i ma czysty, semantyczny HTML — agent (np. Claude
w Chrome) czyta go bez problemu. Na stronie zadania jest sekcja
**„Kontekst dla agenta AI"** (`#agent-context-text`): pełna treść zadania
i dziennika jako zwykły tekst + instrukcja obsługi formularzy
(`#entry-form`, `#entry-author`, `#entry-content`, `#entry-submit`, `#status-actions`).

Przykładowe polecenie dla agenta:

> Otwórz https://…/zadania/task.php?id=7, przeczytaj sekcję „Kontekst dla
> agenta AI", wykonaj opisany tam następny krok, a wynik pracy dopisz jako
> nowy wpis w dzienniku z autorem „AI". Na końcu, jeśli zadanie ukończone,
> kliknij „→ Zrobione".

Logowanie do portalu wykonujesz sam (agentom nie podaje się haseł);
sesja trwa 30 dni, więc raz zalogowana przeglądarka wystarcza na długo.

## Bezpieczeństwo

Hasła: `password_hash()` (bcrypt/argon2). SQL: wyłącznie zapytania
parametryzowane (PDO). Formularze: tokeny CSRF. Sesja: cookie HttpOnly,
SameSite=Lax, Secure na HTTPS. Skrzynka: porównanie tokenu `hash_equals()`.
Po instalacji **usuń `install.php`**. Kopie zapasowe: eksport bazy
w phpMyAdmin (panel CyberFolks) raz na jakiś czas.

## Pomysły na rozwój (dziennik zadania #1)

Widok „Dziś" (scheduled_date = dziś + po terminie), powtarzalność zadań,
załączniki, eksport CSV, push przez ntfy.sh, pełna integracja Graph API.
