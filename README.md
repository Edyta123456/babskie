# Babskie

Prywatny pamiętnik online (oś czasu wyjazdów ze wspomnieniami i zdjęciami) dla grupy znajomych.

**Od 9.11.2026 działa na hostingu (PHP 8 + MySQL), nie na GitHub Pages i nie w Supabase.** Ten GitHub Pages nie uruchomi już aplikacji, bo potrzebny jest serwer PHP.

## Co jest w repozytorium

- `index.html`, `manifest.json`, ikony: strona (ta sama aplikacja co dawniej, zmieniony tylko sposób łączenia z danymi),
- `diary-client.js`: zastępuje bibliotekę Supabase w przeglądarce,
- `api.php`, `photo.php`, `lib.php`: serwer (logowanie wspólnym hasłem, wpisy, zdjęcia),
- `install.php`: jednorazowa instalacja (baza MySQL, hasło grupy),
- `import.php`: jednorazowy import kopii z Supabase,
- `.htaccess`, `robots.txt`: blokada plików konfiguracyjnych i indeksowania,
- `old/`: poprzednia wersja (GitHub Pages + Supabase), zachowana na pamiątkę.

## Instalacja w skrócie

1. Subdomena + baza MySQL w panelu hostingu.
2. Wgraj pliki do folderu głównego subdomeny.
3. Otwórz `/install.php`, podaj dane bazy i hasło grupy.
4. (opcjonalnie) wgraj folder `kopia` i otwórz `/import.php`.
5. Usuń `install.php` i `import.php` z serwera.

Pełna instrukcja i dokumentacja: w Projekcie „Prywatne albumy memories” (pliki 00–03).

W repozytorium nie ma haseł ani danych: `config.php` powstaje na serwerze przy instalacji i jest w `.gitignore`.
