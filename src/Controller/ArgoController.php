<?php

namespace Drupal\argo\Controller;

use Drupal\argo\ArgoServiceInterface;
use Drupal\argo\Exception\FieldNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\argo\Exception\NotFoundException;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Argo module controller.
 */
class ArgoController extends ControllerBase {

  /**
   * Argo service.
   *
   * @var \Drupal\argo\ArgoServiceInterface
   */
  private $argoService;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $logger;

  /**
   * Argo constructor.
   *
   * @param \Drupal\argo\ArgoServiceInterface $argoService
   *   Argo service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Argo logger.
   */
  public function __construct(ArgoServiceInterface $argoService, LoggerChannelInterface $logger) {
    $this->argoService = $argoService;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('argo.service'),
      $container->get('logger.channel.argo')
    );
  }

  /**
   * Exports config strings for translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function exportConfig(Request $request) {
    $langcode = $request->get('langcode');
    $include_translations = intval($request->query->get('include-translations', FALSE));
    $export = $this->argoService->exportConfig($langcode, [
      'include_translations' => $include_translations,
    ]);

    return new JsonResponse($export);
  }

  /**
   * Translates config strings into Drupal.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function translateConfig(Request $request) {
    $langcode = $request->get('langcode');
    $translations = Json::decode($request->getContent());

    try {
      $this->argoService->translateConfig($langcode, $translations);
    }
    catch (NotFoundException $e) {
      return new JsonResponse([
        'message' => $e->getMessage(),
      ],
        Response::HTTP_NOT_FOUND);
    }
    catch (\Exception $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'message' => $e->__toString(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new JsonResponse();
  }

  /**
   * Lists updated editorial content entity metadata.
   *
   * Uses a single 'changed' field type.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function updatedContentEntities(Request $request) {
    $entityType = $request->get('type');
    $publishedOnlyBundles = $request->get('published-only-bundles');
    $langcode = $request->get('langcode');
    $lastUpdate = intval($request->query->get('last-update'));
    $limit = intval($request->query->get('limit'));
    $offset = intval($request->query->get('offset'));

    try {
      $updated = $this->argoService->getUpdated($entityType, $lastUpdate, $limit, $offset,
        $publishedOnlyBundles, $langcode);
    }
    catch (\Exception $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'message' => $e->__toString(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new JsonResponse($updated);
  }

  /**
   * Exports a content entity for translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function exportContentEntity(Request $request) {
    $entityType = $request->get('type');
    $uuid = $request->get('uuid');
    $traversableEntityTypes = $request->get('entity-types');
    $traversableContentTypes = $request->get('content-types');
    $forceErrorCode = intval($request->query->get('force_error_code'));

    return $this->handleExport(function () use ($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, $revisionId) {
      return $this->argoService->exportContent($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes);
    }, $forceErrorCode);
  }

  /**
   * Exports a content entity revision for translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function exportContentEntityRevision(Request $request) {
    $entityType = $request->get('type');
    $uuid = $request->get('uuid');
    $traversableEntityTypes = $request->get('entity-types');
    $traversableContentTypes = $request->get('content-types');
    $revisionId = $request->get('revisionId');
    $forceErrorCode = intval($request->query->get('force_error_code'));

    return $this->handleExport(function () use ($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, $revisionId) {
      return $this->argoService->exportContent($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, $revisionId);
    }, $forceErrorCode);
  }

  private function handleExport($exportFunc, $forceErrorCode) {
    try {
      $export = $exportFunc();
      return new JsonResponse($export);
    } catch (NotFoundException $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'error' => [
          'code' => Response::HTTP_NOT_FOUND,
          'message' => $e->getMessage(),
          'errors' => []
        ]
      ],
        $forceErrorCode != 0 ? $forceErrorCode : Response::HTTP_NOT_FOUND);
    } catch (\Exception $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'error' => [
          'code' => Response::HTTP_INTERNAL_SERVER_ERROR,
          'message' => $e->__toString(),
          'errors' => []
        ]
      ],
        $forceErrorCode != 0 ? $forceErrorCode : Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Translates fields on a single entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function translateContentEntity(Request $request) {
    $entityType = $request->get('type');
    $uuid = $request->get('uuid');
    $translation = json_decode($request->getContent(), TRUE);
    $forceErrorCode = intval($request->query->get('force_error_code'));

    return $this->handleImport(function () use ($entityType, $uuid, $translation) {
      $this->argoService->translateContent($entityType, $uuid, $translation);
    }, $forceErrorCode);
  }

  private function handleImport($importFunc, $forceErrorCode) {
    try {
      $importFunc();
      return new JsonResponse();
    } catch (NotFoundException | FieldNotFoundException $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'error' => [
          'code' => Response::HTTP_NOT_FOUND,
          'message' => $e->getMessage(),
          'errors' => []
        ]
      ],
        $forceErrorCode != 0 ? $forceErrorCode : Response::HTTP_NOT_FOUND);
    } catch (\Exception $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'error' => [
          'code' => Response::HTTP_INTERNAL_SERVER_ERROR,
          'message' => $e->__toString(),
          'errors' => []
        ]
      ],
        $forceErrorCode != 0 ? $forceErrorCode : Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Fetch deleted entity IDs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getDeletionLog(Request $request) {
    $deleted = $this->argoService->getDeletionLog();
    return new JsonResponse($deleted);
  }

  /**
   * Reset deleted entity log.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function resetDeletionLog(Request $request) {
    try {
      $deleted = json_decode($request->getContent(), TRUE)['deleted'];
      $this->argoService->resetDeletionLog($deleted);
    }
    catch (\Exception $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'message' => $e->__toString(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new JsonResponse();
  }

  /**
   * Returns the uuid for an entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function entityUuid(Request $request) {
    $type = $request->get('type');
    $id = $request->get('id');

    $entityInfo = $this->argoService->entityInfo($type, $id);

    return new JsonResponse($entityInfo);
  }

  /**
   * Exports UI strings for translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function exportLocale(Request $request) {
    $langcode = $request->get('langcode');
    $include_translations = intval($request->query->get('include-translations', FALSE));
    $export = $this->argoService->exportLocale($langcode, [
      'include_translations' => $include_translations,
    ]);

    return new JsonResponse($export);
  }

  /**
   * Translates UI strings into Drupal.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function translateLocale(Request $request) {
    $langcode = $request->get('langcode');
    $translations = Json::decode($request->getContent());

    try {
      $this->argoService->translateLocale($langcode, $translations);
    }
    catch (\Exception $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'message' => $e->__toString(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new JsonResponse();
  }

}
