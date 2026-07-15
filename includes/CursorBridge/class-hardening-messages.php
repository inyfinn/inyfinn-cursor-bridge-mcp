<?php
/**
 * Catalog of user-facing messages (5 variants per case) for hardening operations.
 *
 * @package Inyfinn_Cursor_Bridge_MCP
 */

namespace Inyfinn_Cursor_Bridge;

defined( 'ABSPATH' ) || exit;

final class Hardening_Messages {

	/**
	 * Pick one of five variants for a message case (stable by feature+case).
	 *
	 * @return array{code:string,severity:string,message:string,variant:int}
	 */
	public static function get( string $case, string $feature = '', array $extra = array() ): array {
		$variants = self::catalog()[ $case ] ?? self::catalog()['unknown'];
		$index    = abs( crc32( $case . '|' . $feature ) ) % count( $variants );
		$text     = $variants[ $index ];

		$extra = array_merge( array( 'feature' => $feature ), $extra );

		foreach ( $extra as $key => $value ) {
			$text = str_replace( '{' . $key . '}', (string) $value, $text );
		}

		$severity = self::severity( $case );

		return array(
			'code'    => $case,
			'severity' => $severity,
			'message' => $text,
			'variant' => $index + 1,
			'feature' => $feature,
		);
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function catalog(): array {
		return array(
			'already_exists'         => array(
				'Taki kod już istnieje ({feature}). Nie wklejam ponownie — duplikat popsułby stronę.',
				'Wykryto istniejący fragment {feature}. Pomijam instalację, żeby nie zdublować hooków.',
				'{feature} jest już aktywne (znacznik lub sygnatura w pliku). Operacja anulowana.',
				'Nie można wkleić {feature}: podobny kod już jest na stronie. Zostawiam bez zmian.',
				'Ochrona przed duplikatem: {feature} już zainstalowane. Nic nie nadpisuję.',
			),
			'similar_exists'         => array(
				'Znaleziono podobny kod ({feature}) od innego źródła. Nie mieszam snippetów — może uszkodzić stronę.',
				'Konflikt: strona ma już własne SVG/login/upload. {feature} nie zostanie dodane automatycznie.',
				'Podobna funkcja istnieje ({hint}). Wklejenie {feature} grozi podwójnymi filtrami — stop.',
				'Nie instaluję {feature}: wykryto konkurencyjny kod ({hint}). Usuń stary ręcznie albo wybierz force (niezalecane).',
				'Bezpieczeństwo: podobny snippet już działa. {feature} pominięte — unikamy psucia mediów/logowania.',
			),
			'backup_failed'          => array(
				'Backup pliku {target} się nie udał. Bez kopii nie ruszam niczego — operacja przerwana.',
				'Nie mogę zapisać backupu {target}. Anuluję zmianę, żeby nie ryzykować utraty pliku.',
				'Backup wymagany przed edycją: zapis {target} nie powiódł się. Zero zmian.',
				'Katalog backupów niedostępny lub brak zapisu. {feature} nie zostanie zastosowane.',
				'Fail-safe: brak backupu = brak edycji. Sprawdź uprawnienia wp-content/inyfinn-cursor-bridge/backups/.',
			),
			'backup_ok'              => array(
				'Backup OK: {backup}. Można bezpiecznie kontynuować.',
				'Kopia zapasowa utworzona: {backup}.',
				'Zapisano backup przed zmianą: {backup}.',
				'Plik zabezpieczony kopią: {backup}.',
				'Backup gotowy ({backup}) — przechodzę do instalacji {feature}.',
			),
			'installed_mu'           => array(
				'Zainstalowano {feature} jako mu-plugin: {target}. functions.php nietknięty (preferowane).',
				'{feature} aktywne przez mu-plugin ({target}). Najbezpieczniejsza ścieżka.',
				'Sukces: mu-plugin {target} dla {feature}. Motyw nie był edytowany.',
				'{feature} wrzucone do mu-plugins — niezależne od zmiany motywu.',
				'Gotowe. {feature} = plik {target} (mu-plugin).',
			),
			'installed_functions'    => array(
				'Dodano {feature} na końcu functions.php (ostatnia opcja). Backup: {backup}.',
				'functions.php zaktualizowany o {feature} (z znacznikami BEGIN/END). Backup: {backup}.',
				'{feature} wklejone na koniec child theme functions.php. Przywróć z {backup} w razie problemów.',
				'Ostateczna ścieżka: {feature} → functions.php. Znaczniki pozwalają bezpiecznie usunąć.',
				'Sukces (functions.php): {feature}. Zawsze najpierw próbowałem mu-plugin — tym razem padło na motyw.',
			),
			'installed_config'       => array(
				'Dodano blok {feature} do wp-config.php (przed „That\'s all, stop editing!”). Backup: {backup}.',
				'wp-config.php: wstawiono {feature}. Kopia: {backup}.',
				'Stałe {feature} zapisane w wp-config. Przywrócenie: {backup}.',
				'{feature} w wp-config — tylko brakujące define(). Backup: {backup}.',
				'Konfiguracja {feature} zastosowana. Nie nadpisano istniejących define(). Backup: {backup}.',
			),
			'installed_htaccess'     => array(
				'Dodano dyrektywy {feature} do .htaccess (Apache/LiteSpeed). Backup: {backup}.',
				'.htaccess zaktualizowany o {feature}. Backup: {backup}.',
				'Limity PHP przez .htaccess ({feature}). Kopia: {backup}.',
				'{feature} w .htaccess. Na Nginx te dyrektywy są ignorowane — użyj panelu hostingu.',
				'Sukces .htaccess: {feature}. Backup: {backup}.',
			),
			'installed_userini'      => array(
				'Utworzono/zaktualizowano .user.ini ({feature}). Backup: {backup}.',
				'.user.ini: limity {feature}. Backup: {backup}.',
				'PHP limity przez .user.ini. Backup: {backup}.',
				'{feature} zapisane w .user.ini (działa na wielu shared hostingach). Backup: {backup}.',
				'Sukces .user.ini. Pamiętaj: zmiana może wymagać kilku minut propagacji. Backup: {backup}.',
			),
			'syntax_invalid'         => array(
				'Po wstrzyknięciu kod nie przechodzi walidacji PHP. Przywracam z backupu — strona bezpieczna.',
				'Syntax error w wyniku edycji {target}. Rollback automatyczny z {backup}.',
				'Walidacja PHP nieudana. Nic nie zostawiam uszkodzonego — przywrócono {backup}.',
				'Ochrona: plik {target} miałby błąd składni. Cofnięto z backupu.',
				'Nie wdrażam uszkodzonego kodu. Rollback OK. Sprawdź snippet {feature}.',
			),
			'not_writable'           => array(
				'Brak zapisu do {target}. Nie mogę zainstalować {feature}.',
				'Plik/katalog {target} nie jest zapisywalny. Uprawnienia hostingu wymagane.',
				'{feature} wymaga zapisu {target} — odmowa systemu plików.',
				'Nie da się zapisać {target}. Operacja anulowana bez zmian.',
				'Filesystem: {target} read-only. {feature} pominięte.',
			),
			'target_missing'         => array(
				'Brak pliku {target}. Nie tworzę functions.php od zera bez potwierdzenia.',
				'{target} nie istnieje. Instalacja {feature} niemożliwa tą ścieżką.',
				'Nie znaleziono {target}. Sprawdź child theme / ścieżkę public_html.',
				'{feature}: brak docelowego pliku {target}.',
				'Cel {target} nie istnieje — bezpiecznie przerywam.',
			),
			'wp_config_marker'       => array(
				'Nie znaleziono markera „stop editing” w wp-config.php. Nie ryzykuję wstawki w złym miejscu.',
				'wp-config.php ma niestandardową strukturę. {feature} nie wstawione automatycznie.',
				'Brak bezpiecznego miejsca wstawki w wp-config. Wklej ręcznie z docs lub użyj panelu hostingu.',
				'Ochrona wp-config: nieznany layout pliku. Anulowano {feature}.',
				'Nie edytuję wp-config bez markera stop-editing. Za duże ryzyko white-screen.',
			),
			'skipped_dangerous'      => array(
				'Odrzucono niebezpieczną wartość ({hint}) dla {feature}. Użyto bezpiecznego zamiennika.',
				'{hint} jest zbyt ryzykowne na produkcji. {feature} zastosowane w wersji bezpiecznej.',
				'Audyt: {hint} pominięte (security). Reszta {feature} OK.',
				'Nie włączam {hint} automatycznie (np. WP_ALLOW_REPAIR, 8000M). {feature} bez tego.',
				'Hardening: {hint} wyłączone z paczki. Szczegóły w docs/HARDENING.md.',
			),
			'force_required'         => array(
				'Aby nadpisać istniejący {feature}, przekaż force=true. Domyślnie chronię stronę.',
				'Duplikat {feature}. Bez force=true nic nie zmieniam.',
				'Wymagane świadome force=true, by zastąpić {feature}.',
				'Bezpieczny tryb: istniejący {feature} wymaga force.',
				'Nie nadpisuję bez force. {feature} już obecne.',
			),
			'dry_run'                => array(
				'Dry-run: {feature} zostałoby zainstalowane do {target}. Żadne pliki nie zmienione.',
				'Symulacja OK dla {feature} → {target}. Uruchom bez dry_run, by zastosować.',
				'Dry-run: brak konfliktów dla {feature}. Gotowe do instalacji.',
				'Tryb podglądu: {feature} przejdzie. Backup byłby w {backup}.',
				'Dry-run zakończony. {feature} — status: would_install.',
			),
			'unknown'                => array(
				'Nieznany status operacji hardening.',
				'Operacja zakończona ze statusem nieznanym.',
				'Brak komunikatu dla tego przypadku — sprawdź log.',
				'Hardening: nieoczekiwany wynik.',
				'Spróbuj ponownie lub sprawdź health-check.',
			),
		);
	}

	private static function severity( string $case ): string {
		$map = array(
			'already_exists'      => 'info',
			'similar_exists'      => 'warning',
			'backup_failed'       => 'error',
			'backup_ok'           => 'success',
			'installed_mu'        => 'success',
			'installed_functions' => 'success',
			'installed_config'     => 'success',
			'installed_htaccess'   => 'success',
			'installed_userini'    => 'success',
			'syntax_invalid'      => 'error',
			'not_writable'        => 'error',
			'target_missing'      => 'error',
			'wp_config_marker'    => 'error',
			'skipped_dangerous'   => 'warning',
			'force_required'      => 'warning',
			'dry_run'             => 'info',
		);

		return $map[ $case ] ?? 'info';
	}
}
