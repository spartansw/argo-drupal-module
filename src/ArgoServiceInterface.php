<?php

namespace Drupal\argo;

/**
 * Argo service.
 */
interface ArgoServiceInterface {

  /**
   * Export.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param int $revisionId
   *   Entity revision ID.
   *
   * @return mixed
   *   Export object.
   */
  public function export(string $entityType, string $uuid, int $revisionId = NULL);

  /**
   * Translate.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $translation
   *   Translation object.
   */
  public function translate(string $entityType, string $uuid, array $translation);

  /**
   * Get updated.
   */
  public function getUpdated(string $entityType,
                             bool $onlyPublished,
                             int $lastUpdate,
                             int $limit,
                             int $offset,
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

}
