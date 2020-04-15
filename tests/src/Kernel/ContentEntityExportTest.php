<?php

namespace Drupal\argo\Tests;

use Drupal\KernelTests\KernelTestBase;

/**
 * Argo export test.
 *
 * @group argo
 */
class ContentEntityExportTest extends KernelTestBase {
  /**
   * Service under test.
   *
   * @var \Drupal\argo\ArgoService
   */
  protected $argoService;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'argo',
  ];

  /**
   * {@inheritDoc}
   */
  public function setUp() {
    parent::setUp();

    $this->argoService = \Drupal::service('argo.default');
  }

  /**
   * Test.
   */
  public function testExport() {
    $expectedResult = [];

    $actualResult = $this->argoService->export();

    $this->assertEqual($actualResult, $expectedResult);
  }

}
