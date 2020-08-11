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
   *
   * @return mixed
   *   Export object.
   */
  public function export(string $entityType, string $uuid);

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
  public function getUpdated(string $entityType, int $lastUpdate, int $limit, int $offset);

  /**
   * Get deletion log.
   */
  public function getDeletionLog();

  /**
   * Reset deletion log.
   */
  public function resetDeletionLog(array $deleted);

  /**
   * Get entity UUID.
   */
  public function entityUuid($type, $id);

}
