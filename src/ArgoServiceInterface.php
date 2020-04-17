<?php

namespace Drupal\argo;

/**
 * Argo service.
 */
interface ArgoServiceInterface {

  /**
   * @param string $entityType
   * @param string $uuid
   * @return mixed
   */
  public function export(string $entityType, string $uuid);

  /**
   * @param string $entityType
   * @param string $uuid
   * @param array $translation
   * @return mixed
   */
  public function translate(string $entityType, string $uuid, array $translation);

}
