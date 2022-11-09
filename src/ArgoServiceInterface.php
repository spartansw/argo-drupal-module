<?php

namespace Drupal\argo;

/**
 * Argo service.
 */
interface ArgoServiceInterface {

  /**
   * Export configuration (entity) strings.
   *
   * @param string $langcode
   *   Language to retrieve (untranslated) config values for.
   * @param array $options
   *   List of options used to control export.
   *   [
   *     'include_translations' => 'Whether or not to include already translated
   *       values',
   *   ].
   *
   * @return mixed
   *   List of configuration strings as JSON. Each item consists of:
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
  public function exportConfig(string $langcode, array $options = []);

  /**
   * Import configuration (entity) translations.
   *
   * @param string $langcode
   *   The language to save the config translations in.
   * @param array $translations
   *   List of config string translations. Each item contains:
   *   [
   *     'config_id' => 'The unique identifier for the configuration',
   *     'key' => 'The config key of the string (e.g. settings.group.name)',
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'The translated value of the string.',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ].
   */
  public function translateConfig(string $langcode, array $translations);

  /**
   * Export content entity.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $traversableEntityTypes
   *   Traversable entity types.
   * @param array $traversableContentTypes
   *   Traversable content types.
   * @param array $publishedOnlyBundles
   *   Bundles to only export if published.
   * @param int $revisionId
   *   Entity revision ID.
   *
   * @return mixed
   *   Export object.
   */
  public function exportContent(string $entityType, string $uuid, array $traversableEntityTypes, array $traversableContentTypes, array $publishedOnlyBundles = NULL, int $revisionId = NULL);

  /**
   * Translate content entity.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $translations
   *   Translation object.
   * @param array $traversableEntityTypes
   *   Traversable entity types.
   */
  public function translateContent(string $entityType, string $uuid, array $translations, array $traversableEntityTypes);

  /**
   * Get updated.
   */
  public function getUpdated(string $entityType,
                             int $lastUpdate,
                             int $limit,
                             int $offset,
                             array $publishedOnlyBundles = NULL,
                             string $langcode = NULL);

  /**
   * Get deletion log.
   */
  public function getDeletionLog();

  /**
   * Reset deletion log.
   */
  public function resetDeletionLog(array $deleted);

  /**
   * Get entity UUID & revision ID.
   */
  public function entityInfo($type, $id);

  /**
   * Export UI strings.
   *
   * @param string $langcode
   *   Language to retrieve (untranslated) UI string values for.
   * @param array $options
   *   List of options used to control export.
   *   [
   *     'include_translations' => 'Whether or not to include already translated
   *       values',
   *   ].
   *
   * @return mixed
   *   List of UI strings as JSON object. Each item consists of:
   *    [
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'A copy of the original string to be replaced by the
   *       translator',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ]
   */
  public function exportLocale(string $langcode, array $options = []);

  /**
   * Import UI translations.
   *
   * @param string $langcode
   *   The language to save the translations in.
   * @param array $translations
   *   A list of UI translations of the format:
   *   [
   *     'string' => 'The original string value in the source language',
   *     'translation' => 'The translated value of the string.',
   *     'context' => 'Context for the string.',
   *     'url' => 'The url at which the string is found.',
   *   ].
   */
  public function translateLocale(string $langcode, array $translations);

}
