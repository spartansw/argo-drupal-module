<?php

namespace Drupal\argo;

/**
 * Argo service.
 */
interface ArgoServiceInterface {

  /**
   *
   */
  public function export(string $entityType, string $uuid);

}
