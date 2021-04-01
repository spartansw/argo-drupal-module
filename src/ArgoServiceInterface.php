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
   */
  public function exportConfig(string $langcode, array $options = []);

  /**
   * Import configuration (entity) translations.
   *
   * @param string $langcode
   *   The language to save the config translations in.
   * @param array $translations
   *   List of config translations.
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
   * @param int $revisionId
   *   Entity revision ID.
   *
   * @return mixed
   *   Export object.
   */
  public function exportContent(string $entityType, string $uuid, array $traversableEntityTypes, array $traversableContentTypes, int $revisionId = NULL);

  /**
   * Translate content entity.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $translations
   *   Translation object.
   */
  public function translateContent(string $entityType, string $uuid, array $translations);

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
   *   Language to retrieve (untranslated) config values for.
   * @param array $options
   *   List of options used to control export.
   */
  public function exportLocale(string $langcode, array $options = []);

  /**
   * Import UI translations.
   *
   * @param string $langcode
   *   The language to save the config translations in.
   * @param array $translations
   *   List of config translations.
   */
  public function translateLocale(string $langcode, array $translations);

}
