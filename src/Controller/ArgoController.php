<?php

namespace Drupal\argo\Controller;

use Drupal\argo\ArgoServiceInterface;
use Drupal\argo\Exception\FieldNotFoundException;
use Drupal\argo\Exception\InvalidLanguageException;
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

    return $this->handleErrors($request, function () use ($langcode, $include_translations) {
      return $this->argoService->exportConfig($langcode, [
        'include_translations' => $include_translations,
      ]);
    });
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

    return $this->handleErrors($request, function () use ($langcode, $translations) {
      $this->argoService->translateConfig($langcode, $translations);
      return NULL;
    });
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

    return $this->handleErrors($request, function () use (
      $entityType,
      $lastUpdate,
      $limit,
      $offset,
      $publishedOnlyBundles,
      $langcode
    ) {
      return $this->argoService->getUpdated($entityType, $lastUpdate, $limit, $offset,
        $publishedOnlyBundles, $langcode);
    });
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
    $publishedOnlyBundles = $request->get('published-only-bundles');

    return $this->handleErrors($request, function () use ($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, $publishedOnlyBundles) {
      return $this->argoService->exportContent($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, $publishedOnlyBundles);
    });
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

    return $this->handleErrors($request, function () use ($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, $revisionId) {
      return $this->argoService->exportContent($entityType, $uuid, $traversableEntityTypes, $traversableContentTypes, NULL, $revisionId);
    });
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
    $traversableEntityTypes = $request->get('entity-types');
    $translation = json_decode($request->getContent(), TRUE);

    return $this->handleErrors($request, function () use ($entityType, $uuid, $translation, $traversableEntityTypes) {
      $this->argoService->translateContent($entityType, $uuid, $translation, $traversableEntityTypes);
      return NULL;
    });
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
    return $this->handleErrors($request, function () {
      return $this->argoService->getDeletionLog();
    });
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
    return $this->handleErrors($request, function () use ($request) {
      $deleted = json_decode($request->getContent(), TRUE)['deleted'];
      $this->argoService->resetDeletionLog($deleted);
      return NULL;
    });
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

    return $this->handleErrors($request, function () use ($type, $id) {
      return $this->argoService->entityInfo($type, $id);
    });
  }

  /**
   * Returns successful result from service, or else a standardized expected or unexpected error message.
   *
   * Also provides ability to specify an HTTP response code for errors.
   */
  private function handleErrors(Request $request, callable $func) {
    $forceErrorCode = intval($request->query->get('force_error_code'));

    try {
      $result = $func();
    }
    catch (NotFoundException | FieldNotFoundException | InvalidLanguageException $e) {
      $this->logger->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'error' => [
          'code' => Response::HTTP_NOT_FOUND,
          'message' => $e->getMessage(),
          'errors' => []
        ]
      ],
        $forceErrorCode != 0 ? $forceErrorCode : Response::HTTP_NOT_FOUND);
    }
    catch (\Exception $e) {
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
    return new JsonResponse($result);
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

    return $this->handleErrors($request, function () use ($langcode, $include_translations) {
      return $this->argoService->exportLocale($langcode, [
        'include_translations' => $include_translations,
      ]);
    });
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

    return $this->handleErrors($request, function () use ($langcode, $translations) {
      $this->argoService->translateLocale($langcode, $translations);
      return NULL;
    });

  }

}
