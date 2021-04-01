<?php

namespace Drupal\argo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\locale\StringDatabaseStorage;
use Drupal\tableau_i18n_locale\TableauLocaleStringDatabaseStorage;
use Drupal\tableau_i18n_locale\TableauLocaleStringInterface;

/**
 * Handles locale export and translation.
 */
class LocaleService {

  /**
   * Creates a new instance of the Argo locale translation service.
   */
  public function __construct(StringDatabaseStorage $stringStorage) {
      $this->stringStorage = $stringStorage;
  }

  /**
   * Export a list of UI strings for translation.
   *
   * @param string $langcode
   *   The langcode for which to export UI strings.
   * @param array $options
   *   Options to control the export.
   *   [
   *     'include_translations' => 'Whether or not to include already translated
   *       values',
   *   ]
   *
   * @return array
   *   List of UI strings:
   *    [
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'A copy of the original string to be replaced by the
   *       translator',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ]
   */
  public function export(string $langcode, array $options = []) {
    // Merge in default options.
    $options = ['include_translations' => TRUE] + $options;
    $items = [];
    // @todo remove dependency on tableau_i18n_locale module. Maybe invoke an event/hook so that custom Tableau modules
    //   can hook into this?
    $strings = $this->stringStorage->getStrings([
      'translated' => $options['include_translations'],
      'visibility' => TableauLocaleStringInterface::EXTERNAL,
    ]);

    foreach ($strings as $string) {
      // Try and retrieve metadata about the origins of this string.
      $locations = $string->getLocations();
      $url = '';
      if (isset($locations['path'])) {
        $url = implode(',', array_keys($locations['path']));
      }
      $items[] = [
        'string' => $string->getString(),
        'translation' => $string->getString(),
        'context' => $string->context,
        'url' => $url,
      ];
    }

    return $items;
  }

  /**
   * Imports a set of UI string translations.
   *
   * @param string $langcode
   *   The langcode to import the translations for.
   * @param array $translations
   *   A list of translations of the format:
   *   [
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'The translated value of the string.',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ]
   */
  public function import(string $langcode, array $translations) {
    foreach ($translations as $translation) {
        $string = $this->stringStorage->findString(['source' => $translation['string']]);
        $translated_string = $this->stringStorage->createTranslation([
            'lid' => $string->lid,
            'language' => $langcode,
            'translation' => $translation['translation'],
        ])->save();
    }
  }

}
