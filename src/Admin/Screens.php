<?php
/**
 * Admin screens: Search & Replace, Change domain, History.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Admin;

use IWorksIE\SearchReplace\Database\BackupStore;
use IWorksIE\SearchReplace\Database\Runs;
use IWorksIE\SearchReplace\Database\Tables;
use IWorksIE\SearchReplace\Engine\Matcher;
use IWorksIE\SearchReplace\Jobs\SearchReplaceJob;
use IWorksIE\SearchReplace\Support\RunConfig;
use IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Core;
use IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Strings;
use IWorksIE\SearchReplace\Vendor\IWorksIE\Core\UI;

/**
 * Server-rendered screens. All output is escaped.
 */
final class Screens {

	/**
	 * @var Core
	 */
	private $core;

	/**
	 * @var Ajax
	 */
	private $ajax;

	/**
	 * @param Core $core Core.
	 * @param Ajax $ajax Ajax (for job status).
	 */
	public function __construct( Core $core, Ajax $ajax ) {
		$this->core = $core;
		$this->ajax = $ajax;
	}

	/**
	 * Menu callback.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( (string) $this->core->config( 'capability' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'iworksie-search-replace' ) );
		}

		$tabs = array(
			'replace' => __( 'Search & replace', 'iworksie-search-replace' ),
			'domain'  => __( 'Change domain', 'iworksie-search-replace' ),
			'history' => __( 'History & undo', 'iworksie-search-replace' ),
		);

		$this->core->page()->render(
			array(
				'title'   => (string) $this->core->config( 'name' ),
				'version' => (string) $this->core->config( 'version' ),
				'tagline' => (string) $this->core->config( 'tagline' ),
				'tabs'    => $tabs,
			),
			function ( $active ) {
				$running = $this->ajax->job_running();
				$this->render_flash();
				$this->render_running( $running );
				switch ( $active ) {
					case 'domain':
						$this->render_domain( $running );
						break;
					case 'history':
						$this->render_history( $running );
						break;
					default:
						$this->render_replace( $running );
						break;
				}
			}
		);
	}

	/**
	 * One-time result notice after Apply / Undo.
	 *
	 * @return void
	 */
	private function render_flash() {
		$flash = get_option( SearchReplaceJob::FLASH_OPTION, null );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_option( SearchReplaceJob::FLASH_OPTION );

		if ( SearchReplaceJob::MODE_APPLY === $flash['mode'] ) {
			$t   = (array) $flash['totals'];
			$msg = sprintf(
				/* translators: 1: values changed, 2: rows, 3: tables */
				__( 'Done. Changed %1$s values in %2$s rows across %3$s tables. The original values are saved, so you can undo this from History & undo.', 'iworksie-search-replace' ),
				number_format_i18n( (int) $t['cells'] ),
				number_format_i18n( (int) $t['rows'] ),
				number_format_i18n( (int) $t['tables'] )
			);
			echo '<div class="notice notice-success iwiesr-flash"><p>' . esc_html( $msg ) . '</p>';
			if ( ! empty( $t['failed'] ) ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s: number of rows */
						__( '%s rows could not be written (the database refused the new value) and were left unchanged.', 'iworksie-search-replace' ),
						number_format_i18n( (int) $t['failed'] )
					)
				) . '</p>';
			}
			echo '<p><button type="button" class="button" data-iwiesr-undo="' . esc_attr( (string) $flash['run_id'] ) . '">' . esc_html__( 'Undo this run', 'iworksie-search-replace' ) . '</button></p>';
			echo '</div>';
			return;
		}

		if ( SearchReplaceJob::MODE_UNDO === $flash['mode'] ) {
			$msg = sprintf(
				/* translators: %s: number of values */
				__( 'Undo finished. Restored %s original values.', 'iworksie-search-replace' ),
				number_format_i18n( (int) $flash['restored'] )
			);
			$type = ( (int) $flash['conflicts'] > 0 || (int) $flash['failed'] > 0 ) ? 'warning' : 'success';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' iwiesr-flash"><p>' . esc_html( $msg ) . '</p>';
			if ( (int) $flash['conflicts'] > 0 ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s: number of values */
						__( '%s values were edited again after this run (or their row was deleted), so they were left as they are now. Their originals are still saved in History & undo.', 'iworksie-search-replace' ),
						number_format_i18n( (int) $flash['conflicts'] )
					)
				) . '</p>';
			}
			if ( (int) $flash['failed'] > 0 ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s: number of values */
						__( '%s values could not be restored by the database.', 'iworksie-search-replace' ),
						number_format_i18n( (int) $flash['failed'] )
					)
				) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Banner + progress while a run is in progress, and the shared progress bar.
	 *
	 * @param bool $running Whether a job is in progress.
	 * @return void
	 */
	private function render_running( $running ) {
		echo '<div class="iwiesr-runner" data-iwiesr-runner' . ( $running ? '' : ' hidden' ) . '>';
		if ( $running ) {
			$state = $this->core->jobs()->load_state( SearchReplaceJob::ID );
			$mode  = is_array( $state ) && isset( $state['mode'] ) ? (string) $state['mode'] : '';
			$label = __( 'A run was interrupted before it finished.', 'iworksie-search-replace' );
			if ( SearchReplaceJob::MODE_APPLY === $mode ) {
				$label = __( 'Changes were being applied when this run was interrupted. Everything changed so far is backed up. Resume to finish, or stop and undo it from History & undo.', 'iworksie-search-replace' );
			} elseif ( SearchReplaceJob::MODE_UNDO === $mode ) {
				$label = __( 'An undo was interrupted. Resume to finish restoring the original values.', 'iworksie-search-replace' );
			} elseif ( SearchReplaceJob::MODE_PREVIEW === $mode ) {
				$label = __( 'A preview was interrupted. Resume it or stop it to start a new one.', 'iworksie-search-replace' );
			}
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $label ) . '</p>';
			echo '<p><button type="button" class="button button-primary" data-iwiesr-resume>' . esc_html( Strings::get( 'resume' ) ) . '</button> ';
			echo '<button type="button" class="button" data-iwiesr-stop>' . esc_html__( 'Stop', 'iworksie-search-replace' ) . '</button></p></div>';
		}
		$progress_html = UI::progress( 'iwiesr-progress' );
		if ( $running ) {
			$state = $this->core->jobs()->load_state( SearchReplaceJob::ID );
			$pct   = is_array( $state ) && isset( $state['progress'] ) ? max( 0, min( 100, (int) $state['progress'] ) ) : 0;
			// Show where the interrupted run got to, not 0%.
			$progress_html = str_replace(
				array( 'aria-valuenow="0"', 'width:0%', '>0%</span>' ),
				array( 'aria-valuenow="' . $pct . '"', 'width:' . $pct . '%', '>' . $pct . '%</span>' ),
				$progress_html
			);
		}
		echo $progress_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UI::progress escapes; $pct is an int.
		echo '<p class="iwiesr-runner__cancel"><button type="button" class="button" data-iwiesr-cancel hidden>' . esc_html( Strings::get( 'cancel' ) ) . '</button></p>';
		echo '</div>';
		echo '<div class="iwiesr-errors" data-iwiesr-errors role="alert" hidden></div>';
	}

	/**
	 * The last preview, if it belongs to this tab.
	 *
	 * @param bool $domain Domain tab.
	 * @return array<string,mixed>|null
	 */
	private function preview_for( $domain ) {
		$preview = get_option( SearchReplaceJob::PREVIEW_OPTION, null );
		if ( ! is_array( $preview ) || empty( $preview['config']['mode'] ) ) {
			return null;
		}
		$is_domain = ( Matcher::MODE_DOMAIN === $preview['config']['mode'] );
		return $is_domain === (bool) $domain ? $preview : null;
	}

	/**
	 * Search & replace tab.
	 *
	 * @param bool $running Job in progress.
	 * @return void
	 */
	private function render_replace( $running ) {
		$preview = $this->preview_for( false );
		$c       = null !== $preview ? (array) $preview['config'] : array();
		$search  = isset( $c['search'] ) ? (string) $c['search'] : '';
		$replace = isset( $c['replace'] ) ? (string) $c['replace'] : '';
		$ignore  = isset( $c['case'] ) && 'ignore' === $c['case'];
		$regex   = isset( $c['mode'] ) && Matcher::MODE_REGEX === $c['mode'];

		echo '<form class="iwiesr-form" data-iwiesr-form novalidate>';
		echo '<input type="hidden" name="mode" value="plain" data-iwiesr-mode>';
		echo '<div class="iwiesr-card">';
		echo '<h2 class="iwiesr-card__title">' . esc_html__( 'What to change', 'iworksie-search-replace' ) . '</h2>';

		echo '<p class="iwiesr-field"><label for="iwiesr-search">' . esc_html__( 'Search for', 'iworksie-search-replace' ) . '</label>';
		echo '<input type="text" class="large-text code" id="iwiesr-search" name="search" value="' . esc_attr( $search ) . '" autocomplete="off" spellcheck="false" required></p>';

		echo '<p class="iwiesr-field"><label for="iwiesr-replace">' . esc_html__( 'Replace with', 'iworksie-search-replace' ) . '</label>';
		echo '<input type="text" class="large-text code" id="iwiesr-replace" name="replace" value="' . esc_attr( $replace ) . '" autocomplete="off" spellcheck="false">';
		echo '<span class="description">' . esc_html__( 'Leave empty to delete the matched text.', 'iworksie-search-replace' ) . '</span></p>';

		echo '<p class="iwiesr-short" data-iwiesr-short hidden><label><input type="checkbox" name="confirm_short" value="1"> ';
		echo esc_html__( 'I understand: very short searches can match inside other words (for example "cat" inside "category").', 'iworksie-search-replace' ) . '</label></p>';

		echo '<fieldset class="iwiesr-field"><legend>' . esc_html__( 'Upper and lower case', 'iworksie-search-replace' ) . '</legend>';
		echo '<label class="iwiesr-radio"><input type="radio" name="case" value="exact"' . checked( ! $ignore, true, false ) . '> ';
		echo '<strong>' . esc_html__( 'Match exactly', 'iworksie-search-replace' ) . '</strong> <span class="description">' . esc_html__( 'Searching "Apple" finds "Apple" only, not "apple" or "APPLE".', 'iworksie-search-replace' ) . '</span></label>';
		echo '<label class="iwiesr-radio"><input type="radio" name="case" value="ignore"' . checked( $ignore, true, false ) . '> ';
		echo '<strong>' . esc_html__( 'Ignore case', 'iworksie-search-replace' ) . '</strong> <span class="description">' . esc_html__( 'Searching "Apple" also finds "apple" and "APPLE", and replaces them all with exactly what you typed above.', 'iworksie-search-replace' ) . '</span></label>';
		echo '</fieldset>';

		echo '<details class="iwiesr-advanced"' . ( $regex ? ' open' : '' ) . '><summary>' . esc_html__( 'Advanced', 'iworksie-search-replace' ) . '</summary>';
		echo '<p><label><input type="checkbox" value="1" data-iwiesr-regex' . checked( $regex, true, false ) . '> ' . esc_html__( 'Treat the search as a regular expression', 'iworksie-search-replace' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'For developers. Type the pattern without delimiters, e.g. colou?r or (\d{4})-(\d{2}). Use $1, $2 in the replacement to reuse captured groups. Off by default: with it off, every character is matched literally.', 'iworksie-search-replace' ) . '</p>';
		echo '</details>';
		echo '</div>';

		$this->render_table_picker( $c );
		$this->render_submit( $running );
		echo '</form>';

		if ( null !== $preview ) {
			$this->render_preview( $preview, $running );
		}
	}

	/**
	 * Change domain tab.
	 *
	 * @param bool $running Job in progress.
	 * @return void
	 */
	private function render_domain( $running ) {
		$preview = $this->preview_for( true );
		$c       = null !== $preview ? (array) $preview['config'] : array();
		$old     = isset( $c['old_url'] ) ? (string) $c['old_url'] : '';
		$new     = isset( $c['new_url'] ) ? (string) $c['new_url'] : untrailingslashit( home_url() );
		$www     = null === $preview || ! empty( $c['www'] );

		echo '<form class="iwiesr-form" data-iwiesr-form novalidate>';
		echo '<input type="hidden" name="mode" value="domain">';
		echo '<div class="iwiesr-card">';
		echo '<h2 class="iwiesr-card__title">' . esc_html__( 'Move this site to a new address', 'iworksie-search-replace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use this after copying a site to a new domain, or moving from http to https. It finds every form the old address is stored in, including the ones a plain search misses.', 'iworksie-search-replace' ) . '</p>';

		echo '<p class="iwiesr-field"><label for="iwiesr-old">' . esc_html__( 'Old address', 'iworksie-search-replace' ) . '</label>';
		echo '<input type="url" class="large-text code" id="iwiesr-old" name="old_url" value="' . esc_attr( $old ) . '" placeholder="https://old-site.com" autocomplete="off" spellcheck="false" required></p>';

		echo '<p class="iwiesr-field"><label for="iwiesr-new">' . esc_html__( 'New address', 'iworksie-search-replace' ) . '</label>';
		echo '<input type="url" class="large-text code" id="iwiesr-new" name="new_url" value="' . esc_attr( $new ) . '" placeholder="https://new-site.com" autocomplete="off" spellcheck="false" required>';
		echo '<span class="description">' . esc_html__( 'Filled in with this site\'s current address. Include https:// and any sub-folder, with no trailing slash.', 'iworksie-search-replace' ) . '</span></p>';

		echo '<p><label><input type="checkbox" name="www" value="1"' . checked( $www, true, false ) . '> ' . esc_html__( 'Also change the www / non-www version of the old address', 'iworksie-search-replace' ) . '</label></p>';

		echo '<div class="iwiesr-covers"><p><strong>' . esc_html__( 'This covers:', 'iworksie-search-replace' ) . '</strong></p><ul>';
		echo '<li>' . esc_html__( 'http://, https:// and protocol-relative //old-site.com links (all become the new address, so http links are upgraded too)', 'iworksie-search-replace' ) . '</li>';
		echo '<li>' . esc_html__( 'JSON-escaped links such as https:\/\/old-site.com used by page builders and blocks', 'iworksie-search-replace' ) . '</li>';
		/* translators: %s: example of a URL-encoded address */
		echo '<li>' . esc_html( sprintf( __( 'URL-encoded links such as %s in share buttons and redirects', 'iworksie-search-replace' ), 'https%3A%2F%2Fold-site.com' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Links inside serialized settings (widgets, theme options), with lengths fixed so nothing breaks', 'iworksie-search-replace' ) . '</li>';
		echo '</ul><p class="description">' . esc_html__( 'It will not touch look-alike domains: old-site.com.au or old-site.company stay as they are. Plain mentions without http or // (such as email addresses) are not changed; use the Search & replace tab for those.', 'iworksie-search-replace' ) . '</p></div>';
		echo '</div>';

		$this->render_table_picker( $c );
		$this->render_submit( $running );
		echo '</form>';

		if ( null !== $preview ) {
			$this->render_preview( $preview, $running );
		}
	}

	/**
	 * Table checkboxes.
	 *
	 * @param array<string,mixed> $config Last config (for ticks), or empty for defaults.
	 * @return void
	 */
	private function render_table_picker( array $config ) {
		$tables   = Tables::available();
		$selected = ! empty( $config['tables'] ) ? (array) $config['tables'] : Tables::default_selection();
		$guids    = ! empty( $config['guids'] );

		echo '<div class="iwiesr-card">';
		echo '<h2 class="iwiesr-card__title">' . esc_html__( 'Where to look', 'iworksie-search-replace' ) . '</h2>';
		echo '<p class="iwiesr-picker__tools">';
		echo '<button type="button" class="button button-small" data-iwiesr-pick="core">' . esc_html__( 'WordPress tables', 'iworksie-search-replace' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-iwiesr-pick="all">' . esc_html__( 'All tables', 'iworksie-search-replace' ) . '</button> ';
		echo '<button type="button" class="button button-small" data-iwiesr-pick="none">' . esc_html__( 'None', 'iworksie-search-replace' ) . '</button> ';
		echo '<span class="iwiesr-picker__count" data-iwiesr-pick-count aria-live="polite"></span>';
		echo '</p>';
		echo '<fieldset class="iwiesr-picker"><legend class="screen-reader-text">' . esc_html__( 'Tables', 'iworksie-search-replace' ) . '</legend>';
		foreach ( $tables as $t ) {
			$id = 'iwiesr-t-' . md5( $t['name'] );
			echo '<label class="iwiesr-picker__item" for="' . esc_attr( $id ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="tables[]" value="' . esc_attr( $t['name'] ) . '"' . ( $t['core'] ? ' data-core="1"' : '' ) . checked( in_array( $t['name'], $selected, true ), true, false ) . '> ';
			echo '<span class="iwiesr-picker__name">' . esc_html( $t['name'] ) . '</span> ';
			echo '<span class="iwiesr-picker__size">' . esc_html( size_format( (int) $t['bytes'], 1 ) ? (string) size_format( (int) $t['bytes'], 1 ) : '0 B' ) . '</span>';
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="iwiesr-picker__note description">' . esc_html__( 'Passwords and this plugin\'s own data are never changed.', 'iworksie-search-replace' ) . '</p>';
		echo '<p><label><input type="checkbox" name="guids" value="1"' . checked( $guids, true, false ) . '> ' . esc_html__( 'Also change post GUIDs', 'iworksie-search-replace' ) . '</label><br>';
		echo '<span class="description">' . esc_html__( 'Usually leave this off. GUIDs are permanent IDs that feed readers use to spot new posts; WordPress recommends never changing them on a live site. Turn it on for a brand-new copy that has never been public.', 'iworksie-search-replace' ) . '</span></p>';
		echo '</div>';
	}

	/**
	 * Preview button.
	 *
	 * @param bool $running Job in progress.
	 * @return void
	 */
	private function render_submit( $running ) {
		echo '<p class="iwiesr-submit"><button type="submit" class="button button-primary button-hero" data-iwiesr-preview' . disabled( $running, true, false ) . '>' . esc_html__( 'Preview changes', 'iworksie-search-replace' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Nothing is changed yet. You will see every change first.', 'iworksie-search-replace' ) . '</span></p>';
	}

	/**
	 * Preview results.
	 *
	 * @param array<string,mixed> $preview Preview option.
	 * @param bool                $running Job in progress.
	 * @return void
	 */
	private function render_preview( array $preview, $running ) {
		$t       = (array) $preview['totals'];
		$config  = (array) $preview['config'];
		$samples = (array) $preview['samples'];

		echo '<section class="iwiesr-card iwiesr-preview" id="iwiesr-preview" aria-labelledby="iwiesr-preview-title">';
		echo '<h2 class="iwiesr-card__title" id="iwiesr-preview-title">' . esc_html__( 'Preview', 'iworksie-search-replace' );
		echo ' <span class="iwiesr-preview__when">' . esc_html(
			sprintf(
				/* translators: %s: time ago, e.g. "2 mins" */
				__( '%s ago', 'iworksie-search-replace' ),
				human_time_diff( (int) $preview['time'], time() )
			)
		) . '</span></h2>';

		if ( empty( $t['cells'] ) ) {
			echo '<p class="iwiesr-preview__none">' . esc_html__( 'No matches found. Nothing would change.', 'iworksie-search-replace' ) . '</p>';
			$this->render_skips( $preview );
			echo '<p><button type="button" class="button" data-iwiesr-discard>' . esc_html__( 'Clear preview', 'iworksie-search-replace' ) . '</button></p>';
			echo '</section>';
			return;
		}

		echo '<p class="iwiesr-preview__summary">' . esc_html(
			sprintf(
				/* translators: 1: matches, 2: values, 3: rows, 4: tables */
				__( '%1$s matches in %2$s values (%3$s rows) across %4$s tables would change.', 'iworksie-search-replace' ),
				number_format_i18n( (int) $t['matches'] ),
				number_format_i18n( (int) $t['cells'] ),
				number_format_i18n( (int) $t['rows'] ),
				number_format_i18n( (int) $t['tables'] )
			)
		) . '</p>';

		if ( self::touches_site_address( $config ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This changes your site address. After applying, you will need to log in again at the new address.', 'iworksie-search-replace' ) . '</p></div>';
		}

		$rows = array();
		foreach ( (array) $preview['stats'] as $table => $s ) {
			$note = self::reason_label( (string) $s['reason'] );
			if ( empty( $s['rows'] ) && '' === $note ) {
				continue;
			}
			if ( ! empty( $s['skipped'] ) ) {
				$note = trim(
					$note . ' ' . sprintf(
						/* translators: %s: number of values */
						_n( '%s value left alone (see below).', '%s values left alone (see below).', (int) $s['skipped'], 'iworksie-search-replace' ),
						number_format_i18n( (int) $s['skipped'] )
					)
				);
			}
			$rows[] = array(
				'table'  => (string) $table,
				'rows'   => number_format_i18n( (int) $s['rows'] ),
				'values' => number_format_i18n( (int) $s['cells'] ),
				'note'   => $note,
			);
		}
		$table_html = UI::table(
			array(
				'table'  => __( 'Table', 'iworksie-search-replace' ),
				'rows'   => __( 'Rows', 'iworksie-search-replace' ),
				'values' => __( 'Values', 'iworksie-search-replace' ),
				'note'   => __( 'Notes', 'iworksie-search-replace' ),
			),
			$rows
		);
		echo $table_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UI::table escapes.

		$this->render_skips( $preview );

		echo '<h3>' . esc_html__( 'Every change, row by row', 'iworksie-search-replace' ) . '</h3>';
		if ( count( $samples ) < (int) $t['cells'] ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: shown, 2: total */
					__( 'Showing the first %1$s of %2$s changed values. The counts above include all of them.', 'iworksie-search-replace' ),
					number_format_i18n( count( $samples ) ),
					number_format_i18n( (int) $t['cells'] )
				)
			) . '</p>';
		}
		echo '<ol class="iwiesr-samples">';
		foreach ( $samples as $s ) {
			echo '<li class="iwiesr-sample">';
			echo '<p class="iwiesr-sample__meta"><code>' . esc_html( (string) $s['table'] ) . '</code> · <code>' . esc_html( (string) $s['column'] ) . '</code> · ';
			echo esc_html(
				sprintf(
					/* translators: %s: row id */
					__( 'row %s', 'iworksie-search-replace' ),
					(string) $s['key']
				)
			);
			if ( (int) $s['count'] > 1 ) {
				echo ' · ' . esc_html(
					sprintf(
						/* translators: %s: number of matches */
						__( '%s matches', 'iworksie-search-replace' ),
						number_format_i18n( (int) $s['count'] )
					)
				);
			}
			echo '</p>';
			echo '<div class="iwiesr-sample__diff">';
			echo '<div class="iwiesr-sample__side iwiesr-sample__side--before"><span class="iwiesr-sample__label">' . esc_html__( 'Before', 'iworksie-search-replace' ) . '</span><code>' . self::segments_html( (array) $s['snippet'], false ) . '</code></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- segments_html escapes.
			echo '<div class="iwiesr-sample__side iwiesr-sample__side--after"><span class="iwiesr-sample__label">' . esc_html__( 'After', 'iworksie-search-replace' ) . '</span><code>' . self::segments_html( (array) $s['snippet'], true ) . '</code></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- segments_html escapes.
			echo '</div></li>';
		}
		echo '</ol>';

		echo '<div class="iwiesr-apply">';
		echo '<p><button type="button" class="button button-primary button-hero" data-iwiesr-apply="' . esc_attr( (string) $preview['id'] ) . '"' . disabled( $running, true, false ) . '>';
		echo esc_html(
			sprintf(
				/* translators: %s: number of values */
				_n( 'Apply changes to %s value', 'Apply changes to %s values', (int) $t['cells'], 'iworksie-search-replace' ),
				number_format_i18n( (int) $t['cells'] )
			)
		) . '</button> ';
		echo '<button type="button" class="button" data-iwiesr-discard>' . esc_html__( 'Discard preview', 'iworksie-search-replace' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Before anything is written, the original of every changed value is saved, so you can undo this run from History & undo. A full database backup is still a good idea before large changes.', 'iworksie-search-replace' ) . '</p>';
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Notes about values that were left alone.
	 *
	 * @param array<string,mixed> $preview Preview.
	 * @return void
	 */
	private function render_skips( array $preview ) {
		$skips = isset( $preview['skips'] ) ? (array) $preview['skips'] : array();
		if ( empty( $skips ) ) {
			return;
		}
		$labels = array(
			/* translators: %s: number of values */
			'serialized_unreadable' => __( '%s values hold serialized PHP data in a format this plugin could not read safely, so they were left unchanged.', 'iworksie-search-replace' ),
			/* translators: %s: number of values */
			'serialized_invalid'    => __( '%s serialized values would not have been readable after the change, so they were left unchanged.', 'iworksie-search-replace' ),
			/* translators: %s: number of values */
			'pattern_error'         => __( '%s values were too large or complex for the regular expression and were left unchanged.', 'iworksie-search-replace' ),
		);
		echo '<ul class="iwiesr-skips">';
		foreach ( $skips as $reason => $n ) {
			if ( ! isset( $labels[ $reason ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- strings above are literal and translated.
			echo '<li>' . esc_html( sprintf( $labels[ $reason ], number_format_i18n( (int) $n ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * History & undo tab.
	 *
	 * @param bool $running Job in progress.
	 * @return void
	 */
	private function render_history( $running ) {
		$runs = Runs::all();
		echo '<div class="iwiesr-card">';
		echo '<h2 class="iwiesr-card__title">' . esc_html__( 'Applied runs', 'iworksie-search-replace' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of runs */
				_n( 'The last %d run keeps its undo data. Older runs are removed automatically.', 'The last %d runs keep their undo data. Older runs are removed automatically.', Runs::keep(), 'iworksie-search-replace' ),
				Runs::keep()
			)
		) . '</p>';

		if ( empty( $runs ) ) {
			echo '<p class="iwiesr-empty">' . esc_html__( 'No changes applied yet. Runs you apply will appear here with an Undo button.', 'iworksie-search-replace' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped iwt-table iwiesr-history"><thead><tr>';
		foreach ( array( __( 'When', 'iworksie-search-replace' ), __( 'Change', 'iworksie-search-replace' ), __( 'Values', 'iworksie-search-replace' ), __( 'Status', 'iworksie-search-replace' ), __( 'Undo data', 'iworksie-search-replace' ), __( 'Actions', 'iworksie-search-replace' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$have_table = BackupStore::exists();
		foreach ( $runs as $id => $run ) {
			$stats  = $have_table ? BackupStore::stats( (string) $id ) : array(
				'rows'  => 0,
				'bytes' => 0,
			);
			$status = (string) $run['status'];
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $run['time'] ) ) . '</td>';
			echo '<td><code>' . esc_html( self::short( (string) $run['from'] ) ) . '</code> → <code>' . esc_html( self::short( (string) $run['to'] ) ) . '</code>';
			if ( Matcher::MODE_DOMAIN === $run['mode'] ) {
				echo ' <span class="iwt-pill">' . esc_html__( 'domain', 'iworksie-search-replace' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $run['cells'] ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $status, $running, (int) $stats['rows'] ) ) . '</td>';
			echo '<td>' . esc_html( $stats['rows'] > 0 ? (string) size_format( max( 1, (int) $stats['bytes'] ), $stats['bytes'] >= 1024 ? 1 : 0 ) : '—' ) . '</td>';
			echo '<td class="iwiesr-history__actions">';
			if ( $stats['rows'] > 0 && Runs::STATUS_UNDONE !== $status ) {
				echo '<button type="button" class="button" data-iwiesr-undo="' . esc_attr( (string) $id ) . '"' . disabled( $running, true, false ) . '>' . esc_html__( 'Undo', 'iworksie-search-replace' ) . '</button> ';
			}
			echo '<button type="button" class="button-link button-link-delete" data-iwiesr-delete-run="' . esc_attr( (string) $id ) . '"' . disabled( $running, true, false ) . '>' . esc_html__( 'Delete undo data', 'iworksie-search-replace' ) . '</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Undo puts back the original value of every cell this run changed. If a value was edited again since, it is left as it is now, so newer work is never overwritten. When undoing several runs, undo the newest first.', 'iworksie-search-replace' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Status wording.
	 *
	 * @param string $status  Stored status.
	 * @param bool   $running Job in progress.
	 * @param int    $left    Backup rows left.
	 * @return string
	 */
	private static function status_label( $status, $running, $left ) {
		switch ( $status ) {
			case Runs::STATUS_DONE:
				return __( 'Applied', 'iworksie-search-replace' );
			case Runs::STATUS_UNDONE:
				return __( 'Undone', 'iworksie-search-replace' );
			case Runs::STATUS_PARTIAL_UNDO:
				return sprintf(
					/* translators: %s: number of values */
					__( 'Partly undone: %s values had changed again and were left as they are', 'iworksie-search-replace' ),
					number_format_i18n( $left )
				);
			case Runs::STATUS_RUN:
				return $running ? __( 'In progress', 'iworksie-search-replace' ) : __( 'Stopped part-way (changes so far can be undone)', 'iworksie-search-replace' );
		}
		return $status;
	}

	/**
	 * Explain why a table was skipped.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason_label( $reason ) {
		switch ( $reason ) {
			case 'no_key':
				return __( 'Skipped: this table has no primary key, so changes could not be undone safely.', 'iworksie-search-replace' );
			case 'unreadable':
				return __( 'Skipped: the table could not be read.', 'iworksie-search-replace' );
			case 'query_failed':
				return __( 'Stopped: the database returned an error while reading this table.', 'iworksie-search-replace' );
		}
		return '';
	}

	/**
	 * Whether the config would change siteurl/home.
	 *
	 * @param array<string,mixed> $config Config.
	 * @return bool
	 */
	private static function touches_site_address( array $config ) {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$site = untrailingslashit( (string) get_option( 'siteurl' ) );
		$m    = RunConfig::matcher( $config );
		return null !== $m->first_match( $home ) || null !== $m->first_match( $site );
	}

	/**
	 * Render snippet segments.
	 *
	 * @param array<string,mixed> $snippet Snippet::build() output.
	 * @param bool                $after   After side.
	 * @return string Escaped HTML.
	 */
	public static function segments_html( array $snippet, $after ) {
		$html = ! empty( $snippet['cut_start'] ) ? '<span class="iwiesr-ellipsis">…</span>' : '';
		foreach ( (array) $snippet['segments'] as $seg ) {
			if ( ! is_array( $seg ) || ! isset( $seg[0], $seg[1] ) ) {
				continue;
			}
			if ( 't' === $seg[0] ) {
				$html .= esc_html( (string) $seg[1] );
				continue;
			}
			$text = $after ? ( isset( $seg[2] ) ? (string) $seg[2] : '' ) : (string) $seg[1];
			if ( '' === $text ) {
				$html .= '<mark class="iwiesr-removed" title="' . esc_attr__( 'removed', 'iworksie-search-replace' ) . '">∅</mark>';
				continue;
			}
			$html .= '<mark>' . esc_html( $text ) . '</mark>';
		}
		if ( ! empty( $snippet['cut_end'] ) ) {
			$html .= '<span class="iwiesr-ellipsis">…</span>';
		}
		return $html;
	}

	/**
	 * Shorten long text for tables.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( $text ) {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 60 ) {
			return mb_substr( $text, 0, 57, 'UTF-8' ) . '…';
		}
		return $text;
	}
}
