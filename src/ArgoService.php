<?php

namespace Drupal\argo;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Interacts with Argo.
 */
class ArgoService implements ArgoServiceInterface {
  /**
   * The core entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The service constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The core entity type manager service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

}
