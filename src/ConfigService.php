<?php

namespace Drupal\argo;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Handles config (entity) export and translation.
 */
class ConfigService {

  /**
   * Argo settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The storage instance for reading configuration data.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The language manager.
   *
   * @var \Drupal\language\ConfigurableLanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * Creates a new instance of the Argo configuration translation service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The Drupal config factory.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The storage object to use for reading configuration data.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   Configuration manager service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed configuration manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StorageInterface $config_storage,
    ConfigManagerInterface $config_manager,
    TypedConfigManagerInterface $typed_config,
    LanguageManagerInterface $language_manager) {
    $this->configFactory = $config_factory;
    $this->configStorage = $config_storage;
    $this->configManager = $config_manager;
    $this->typedConfigManager = $typed_config;
    $this->languageManager = $language_manager;

    $this->config = $this->configFactory->get('argo.settings');
  }

  /**
   * Export a list of config strings for translation.
   *
   * @param string $langcode
   *   The langcode for which to export config strings.
   * @param array $options
   *   Options to control the export.
   *   [
   *     'include_translations' => 'Whether or not to include already translated
   *       values',
   *   ].
   *
   * @return array
   *   List of configuration strings:
   *    [
   *     'config_id' => 'The unique identifier for the configuration',
   *     'key' => 'The config key of the string (e.g. settings.group.name)',
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'A copy of the original string to be replaced by the
   *       translator',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ]
   */
  public function export(string $langcode, array $options = []) {
    // Merge in default options.
    $options += ['include_translations' => FALSE];
    $export = [];
    // Retrieves a list of configs which are eligible for Argo export.
    $translatable_configs = $this->config->get('config.translatable');
    foreach ($translatable_configs as $pattern) {
      // @todo Make this work with fuzzy sub keys. Currently this only works
      // when the key has the asterisk at the end of the config name.
      $prefix = str_replace('*', '', $pattern);

      foreach ($this->configStorage->listAll($prefix) as $config_name) {
        $config = $this->getTranslatableConfig($config_name);
        if (empty($config)) {
          // If there is nothing translatable in this configuration, skip it.
          continue;
        }

        $config_translation = $this->languageManager->getLanguageConfigOverride($langcode, $config_name)->get();

        // Webforms require special handling of their elements.
        if ($this->isWebformConfig($config_name)) {
          /** @var \Drupal\webform\WebformInterface $webform */
          $webform = $this->configManager->loadConfigEntityByName($config_name);
          /** @var \Drupal\webform\WebformTranslationManagerInterface $webform_translation_manager */
          $webform_translation_manager = \Drupal::service('webform.translation_manager');
          $config['elements'] = $webform_translation_manager->getSourceElements($webform);
          $config_translation['elements'] = $webform_translation_manager->getTranslationElements($webform, $langcode);
        }

        $items = $this->doExport($config);
        foreach ($items as $delta => &$item) {
          $item['config_id'] = $config_name;
          $translation = NestedArray::getValue($config_translation, explode('.', $item['key']));
          $has_translation = !empty($translation) && $translation !== $item['string'];
          if (!$options['include_translations'] && $has_translation) {
            unset($items[$delta]);
            continue;
          }
        }
        $export = array_merge($export, $items);
      }
    }

    return $export;
  }

  /**
   * Imports a set of config (entity) translations.
   *
   * @param string $langcode
   *   The langcode to import the translations for.
   * @param array $translations
   *   A list of translations of the format:
   *   [
   *     'config_id' => 'The unique identifier for the configuration',
   *     'key' => 'The config key of the string (e.g. settings.group.name)',
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'The translated value of the string.',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ].
   */
  public function import(string $langcode, array $translations) {
    $configs = [];

    foreach ($translations as $translation) {
      $config_id = $translation['config_id'];
      $key = $translation['key'];
      $translation = $translation['translation'];
      // Load the config translation and statically store in an array so we
      // can save all of them at once at the very end.
      if (!isset($configs[$config_id])) {
        $configs[$config_id] = $this->languageManager->getLanguageConfigOverride($langcode, $config_id);

        // Webforms require special handling of their webform elements. We
        // parse the YAML to a nested array so each property of the elements
        // can be translated separately.
        if ($this->isWebformConfig($config_id)) {
          /** @var \Drupal\webform\WebformInterface $webform */
          $webform = $this->configManager->loadConfigEntityByName($config_id);
          /** @var \Drupal\webform\WebformTranslationManagerInterface $webform_translation_manager */
          $webform_translation_manager = \Drupal::service('webform.translation_manager');
          $configs[$config_id]->set('elements', $webform_translation_manager->getTranslationElements($webform, $langcode));
        }
      }
      $config = $configs[$config_id];
      $config->set($key, $translation);
    }

    // Bulk save all the changes.
    foreach ($configs as $config_id => $config) {
      // Convert webform elements back to YAML prior to saving.
      if ($this->isWebformConfig($config_id)) {
        $elements = $config->get('elements') ?? [];
        $elements_yaml = ($elements) ? Yaml::encode($elements) : '';
        $config->set('elements', $elements_yaml);
      }

      $config->save();
    }
  }

  /* Internal helper functions. */

  /**
   * Checks if a given config name is used for a webform entity.
   *
   * @param string $name
   *   The name of the configuration entity.
   *
   * @return bool
   *   TRUE if the configuration is a webform, FALSE otherwise.
   */
  private function isWebformConfig($name) {
    return (strpos($name, 'webform') === 0);
  }

  /**
   * Gets list of translatable data for a given config name/id.
   *
   * @param string $name
   *   Configuration object name.
   *
   * @return array
   *   Array of translatable elements of the default configuration in $name.
   */
  protected function getTranslatableConfig(string $name) {
    // Create typed configuration wrapper based on install storage data.
    $data = $this->configStorage->read($name);
    $typed_config = $this->typedConfigManager->createFromNameAndData($name, $data);
    if ($typed_config instanceof TraversableTypedDataInterface) {
      return $this->getTranslatableData($typed_config);
    }
    return [];
  }

  /**
   * Gets translatable configuration data for a typed configuration element.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface $element
   *   Typed configuration element.
   *
   * @return array|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   A nested array matching the exact structure under $element with only the
   *   elements that are translatable wrapped into a TranslatableMarkup. If the
   *   provided $element is not traversable, the return value is a single
   *   TranslatableMarkup.
   */
  protected function getTranslatableData(TypedDataInterface $element) {
    $translatable = [];
    if ($element instanceof TraversableTypedDataInterface) {
      foreach ($element as $key => $property) {
        $value = $this->getTranslatableData($property);
        if (!empty($value)) {
          $translatable[$key] = $value;
        }
      }
    }
    else {
      // Something is only translatable by Locale if there is a string in the
      // first place.
      $value = $element->getValue();
      $definition = $element->getDataDefinition();
      if (!empty($definition['translatable']) && $value !== '' && $value !== NULL) {
        return $value;
      }
    }
    return $translatable;
  }

  /**
   * Prepares a config string value for export.
   *
   * @param mixed $value
   *   The source configuration value.
   * @param string $key
   *   The key in the configuration at which the string is found.
   * @param array $export
   *   The result array of items to populate.
   *
   * @return array
   *   Array of config string data ready for export.
   */
  protected function doExport($value, $key = NULL, array &$export = []) {
    if (is_array($value)) {
      foreach ($value as $nested_key => $nested_value) {
        $parent_key = implode('.', array_filter([$key, $nested_key]));
        $this->doExport($nested_value, $parent_key, $export);
      }
    }
    else {
      $export[] = [
        'key' => $key,
        'string' => $value,
        'translation' => $value,
        // @todo figure out a way to provide context to the translator.
        'context' => '@todo',
        // @todo figure out a way to provide a url to the string in context.
        'url' => '@todo',
      ];
    }

    return $export;
  }

}
