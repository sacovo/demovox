<?php

namespace Demovox;
/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://github.com/spschweiz/demovox
 * @since      1.0.0
 *
 * @package    Demovox
 * @subpackage Demovox/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Demovox
 * @subpackage Demovox/includes
 * @author     SP Schweiz
 */
class i18n
{
	public static array $cantons = [
		''   => '',
		'ag' => 'Aargau',
		'ai' => 'Appenzell Innerrhoden',
		'ar' => 'Appenzell Ausserrhoden',
		'be' => 'Bern',
		'bl' => 'Basel-Landschaft',
		'bs' => 'Basel-Stadt',
		'fr' => 'Fribourg',
		'ge' => 'Genève',
		'gl' => 'Glarus',
		'gr' => 'Graubünden',
		'ju' => 'Jura',
		'lu' => 'Luzern',
		'ne' => 'Neuchâtel',
		'nw' => 'Nidwalden',
		'ow' => 'Obwalden',
		'sg' => 'St. Gallen',
		'sh' => 'Schaffhausen',
		'so' => 'Solothurn',
		'sz' => 'Schwyz',
		'tg' => 'Thurgau',
		'ti' => 'Ticino',
		'ur' => 'Uri',
		'vd' => 'Vaud',
		'vs' => 'Valais',
		'zg' => 'Zug',
		'zh' => 'Zürich',
	];

	public static array $languages = [
		'de' => 'German',
		'fr' => 'French',
		'it' => 'Italian',
		'rm' => 'Romansh',
		'en' => 'English',
	];

	public static string $languageDefault = 'de';

	public static string $countryDefault = 'CH';

	public static function getLangsEnabled(): array
	{
		$languages = [];
		$glueLang = Settings::GLUE_LANG;
		foreach (self::$languages as $langId => $lang) {
			if (Settings::getCValue('is_language_enabled' . $glueLang . $langId)) {
				$languages[$langId] = $lang;
			}
		}
		return $languages;
	}

	public static function getLangs(): array
	{
		return self::$languages;
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function loadPluginTextdomain()
	{
		load_plugin_textdomain(
			'demovox',
			false,
			dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
		);
	}

}
