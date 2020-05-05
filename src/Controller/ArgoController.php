<?php

namespace Drupal\argo\Controller;

use Drupal\argo\ArgoServiceInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\argo\ContentEntityExport;

use Drupal\Core\Database\Database;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManager;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\webform\Entity\Webform;
use Drupal\config_translation\ConfigNamesMapper;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use LogicException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Serialization\Yaml;

/**
 *
 */
class ArgoController extends ControllerBase {

  /**
   * @var \Drupal\argo\ArgoServiceInterface
   */
  private $argoService;

  /**
   * Argo constructor.
   *
   * @param \Drupal\argo\ArgoServiceInterface $argoService
   */
  public function __construct(ArgoServiceInterface $argoService) {
    $this->argoService = $argoService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('argo.service')
    );
  }

  /**
   * Lists updated editorial content entity metadata using a
   * single 'changed' field type.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function updatedContentEntities(Request $request) {
    $entityType = $request->get('type');
    $lastUpdate = intval($request->query->get('last-update'));
    $limit = intval($request->query->get('limit'));
    $offset = intval($request->query->get('offset'));

    $updated = $this->argoService->updated($entityType, $lastUpdate, $limit, $offset);

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

    $export = $this->argoService->export($entityType, $uuid);

    return new JsonResponse($export);
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

    $this->argoService->translate($entityType, $uuid, $translation);

    return new JsonResponse();
  }

    /**
   * Returns the uuid for a node
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
  public function nodeUuid(Request $request) {
    $nodeId = $request->get('node-id');

    $node = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->load($nodeId);

    return new JsonResponse(['uuid' => $node->uuid()]);
  }

  /**
   *
   */
  public function getTerms($vocabId) {
    $vocabulary = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vocabId, 0, NULL, TRUE);
    $terms = [];
    foreach ($vocabulary as $term) {
      $terms[] = [
        'id' => $term->id(),
        'name' => $term->getName(),
      ];
    }
    return $terms;
  }

  /**
   *
   */
  private function addHandlerProps(array $handlerMap,
                                   array $values,
                                   TypedConfigManager $typedConfigManager,
                                   array &$outProps) {
    foreach ($handlerMap as $name => $value) {
      // TODO: add translatable flags to schema and check for them.
      if (in_array($value['type'], ['label', 'text'])) {
        $outProps[$name] = [
          'label' => $value['label'],
          'value' => $values[$name],
        ];
      }
      elseif (strpos($value['type'], 'webform.handler.') === 0) {
        // TODO: should be able to add more than just webform.handler dynamic props.
        $dynamicMap = $typedConfigManager->getDefinition('webform.handler.' . $values['id'])['mapping'];
        $dynamicProps = [];
        $this->addHandlerProps($dynamicMap, $values[$name], $typedConfigManager, $dynamicProps);
        $outProps[$name] = $dynamicProps;
      }
    }
  }

  /**
   *
   */
  public function getConfigTranslatableProperties(ConfigNamesMapper $mapper) {
    /** @var \Drupal\Core\Config\TypedConfigManagerInterface $typed_config */
    $typed_config = \Drupal::service('config.typed');

    $properties = [];
    foreach ($mapper->getConfigNames() as $name) {
      $schema = $typed_config->get($name);
      $properties[$name] = $this->getTranslatableProperties($schema, NULL);
    }
    return $properties;
  }

  /**
   * {@inheritDoc}
   */
  public function getTranslatableProperties(TraversableTypedDataInterface $schema, $base_key) {
    $properties = [];
    $definition = $schema->getDataDefinition();
    if (isset($definition['form_element_class'])) {
      foreach ($schema as $key => $element) {
        $element_key = isset($base_key) ? "$base_key.$key" : $key;
        $definition = $element->getDataDefinition();

        if ($element instanceof TraversableTypedDataInterface) {
          $properties = array_merge($properties, $this->getTranslatableProperties($element, $element_key));
        }
        else {
          if (isset($definition['form_element_class'])) {
            $properties[] = $element_key;
          }
        }
      }
    }
    return $properties;
  }

  /**
   *
   */
  public function getConfigSourceData(ConfigNamesMapper $mapper,
                                      TypedConfigManagerInterface $typedConfigManager,
                                      ConfigFactoryInterface $configFactory) {
    $properties = $this->getConfigTranslatableProperties($mapper);
    $values = [];
    foreach ($properties as $config_name => $config_properties) {
      $config = $configFactory->get($config_name);
      foreach ($config_properties as $property) {
        $typedConfig = $typedConfigManager->get($config_name);
        $label = $this->getPropertyLabel($property, $typedConfig);

        $values[$config_name][$property] = [
          'label' => $label,
          'value' => $config->get($property),
        ];
      }
    }
    return $values;
  }

  /**
   *
   */
  private function getPropertyLabel($property, $typedConfig) {
    $elementNames = explode('.', $property);
    $element = $typedConfig;
    foreach ($elementNames as $elementName) {
      $element = $element->getElements()[$elementName];
    }
    return $element->getDataDefinition()->getLabel();
  }

  /**
   *
   */
  public function fetchIdsAndResetLog(Request $request) {
    $conn = Database::getConnection();
    $deleted = $conn->query('SELECT * FROM argo_entity_deletion')->fetchAll();
    // $conn->delete('argo_entity_deletion')->execute();
    return $this->json_response(200, [
      'deleted' => $deleted,
    ]);
  }

  /**
   *
   */
  public function saveConfigTargetData(Webform $webform, array $translation, LanguageManagerInterface $languageManager, ConfigNamesMapper $mapper, $langcode, $data) {
    // $names = $mapper->getConfigNames();
    //    if (!empty($names)) {
    //      foreach ($names as $name) {
    //        $config_translation = $languageManager->getLanguageConfigOverride($langcode, $name);
    //
    //        foreach ($data as $name => $properties) {
    //          foreach ($properties as $property => $value) {
    // $config_translation->set($property, html_entity_decode($value . ' (' . $langcode . ')'));
    //            $value;
    //          }
    //          $config_translation->save();
    //        }
    //      }
    //    }
    $config = $translation['data']['configs'][0];
    $name = $config['name'];
    $config_translation = $languageManager->getLanguageConfigOverride($langcode, $name);

    foreach ($config['properties'] as $property) {
      $config_translation->set($property['name'], html_entity_decode($property['value']));
      $config_translation->save();
    }

    $translationManager = \Drupal::service('webform.translation_manager');
    $sourceElements = $translationManager->getSourceElements($webform);

    foreach ($config['elements'] as $element) {
      foreach ($element['properties'] as $property) {
        $sourceElements[$element['name']][$property['name']] = $property['value'];
      }

      $vocabulary = $element['vocabulary'];
      if (isset($vocabulary)) {
        // Handle vocab.
        $vocabId = $vocabulary['name'];
        $srcVocab = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadTree($vocabId, 0, NULL, TRUE);
        foreach ($vocabulary['terms'] as $term) {
          $id = $term['id'];
          $name = $term['name'];
          // TODO.
          $srcTerm = NULL;
          foreach ($srcVocab as $i => $src) {
            if ($src->id() === $id) {
              $srcTerm = $src;
              break;
            }
          }

          if ($srcTerm === NULL) {
            continue;
          }

          if (!$srcTerm->hasTranslation($langcode)) {
            $srcTerm->addTranslation($langcode);
          }

          if ($srcTerm->language()->getId() == 'und') {
            continue;
          }
          $termTranslation = $srcTerm->getTranslation($langcode);
          // $termTranslation = $srcTerm;
          //          $termTranslation->setName(substr($name, 0, strlen($name) - strlen(' (zh-tw)')));
          $termTranslation->setName($name);
          $termTranslation->save();
        }
      }
    }

    // Process elements YAML.
    $translatedElementsYaml = Yaml::encode($sourceElements);
    $config_translation->set('elements', $translatedElementsYaml);
    $config_translation->save();
  }

  /**
   *
   */
  public function exportWebform(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response('', 405);
    }

    $webformId = $request->query->get('webformId');
    $webform = $this->loadWebform($webformId);
    list($elements, $result) = $this->webformExport($webform);

    return $this->json_response(200, $result);
  }

  /**
   *
   */
  public function testAll(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response('', 405);
    }

    $entityType = $request->query->get('type');
    // $entityId = $request->query->get('uuid');
    $part = intval($request->query->get('part'));

    $conn = Database::getConnection();

    // $nodeCounts = $conn->query('SELECT count(uuid) count FROM node')->fetchAll();
    //    $numNodes = intval($nodeCounts[array_keys($nodeCounts)[0]]->count);
    $limit = 100;
    $offset = $limit * $part;
    $entityStorage = \Drupal::entityTypeManager()
      ->getStorage($entityType);
    // $ids = $conn->query('SELECT uuid FROM node ORDER BY uuid ASC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();
    //    $ids = [];
    $export = [];

    $contentEntityTypes = [];
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $name => $type) {
      if ($type instanceof ContentEntityTypeInterface) {
        $contentEntityTypes[] = $type->id();
      }
    }

    $allIds = $entityStorage->getQuery()->execute();
    $ids = array_slice($allIds, $offset, $length = $limit);
    foreach ($ids as $key => $id) {
      $entity = $entityStorage
        ->load($id);
      $translation = (new ContentEntityExport())->export($entity);
      if (count($translation) > 0) {
        $export[] = $translation;
      }
    }

    return $this->json_response(200, $export);
  }

  /**
   *
   */
  public function webformMetadata(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response('', 405);
    }

    $webformId = $request->query->get('webformId');
    $webform = $this->loadWebform($webformId);
    list($elements, $export) = $this->webformExport($webform);

    // Add hash so clients can check if config has changed.
    $hash = md5(json_encode($export));

    $result = [
      'data' => [
        'id' => $webform->id(),
        'hash' => $hash,
        'langcode' => $webform->getLangcode(),
        'title' => $webform->label(),
      ],
    ];

    return $this->json_response(200, $result);
  }

  /**
   *
   */
  public function saveTargetData(Webform $webform, array $translation, array $mappers, ConfigurableLanguageManagerInterface $languageManager, ConfigEntityInterface $entity, $langcode, $data) {
    // If ($entity->getEntityTypeId() == 'field_config') {
    //      $id = $entity->getTargetEntityTypeId();
    //      $mapper = clone ($this->mappers[$id . '_fields']);
    //      $mapper->setEntity($entity);
    //    }
    //    else {.
    $mapper = clone ($mappers[$entity->getEntityTypeId()]);
    $mapper->setEntity($entity);
    // }
    // For retro-compatibility, if there is only one config name, we expand our
    // data.
    $names = $mapper->getConfigNames();
    if (count($names) == 1) {
      $expanded[$names[0]] = $data;
    }
    else {
      $expanded = $data;
    }
    $this->saveConfigTargetData($webform, $translation, $languageManager, $mapper, $langcode, $expanded);
  }

  /**
   *
   */
  public function translateWebform(Request $request) {
    $invalidMethod = $request->getMethod() !== 'POST';
    if ($invalidMethod) {
      return new Response('', 405);
    }

    $requestJson = json_decode($request->getContent(), TRUE);
    $webformId = $requestJson['data']['id'];
    $targetLangcode = $requestJson['data']['targetLangcode'];
    $newTranslation = [
      'properties' => [
        'title' => 'translated title',
        'description' => 'translated description',
        'category' => 'translated category',
      ],
      'elements' => [
        'name' => [
          '#title' => 'Your Name (zh-tw)',
          '#default_value' => '[webform-authenticated-user:display-name]',
        ],
      ],
      'settings' => ['confirmation_message' => 'Your message has been sent (zh-tw)'],
      'handlers' => [
        'email_confirmation' => [
          'label' => 'Email confirmation (zh-tw)',
          'settings' => ['subject' => '[webform_submission:values:subject:raw] (zh-tw)'],
        ],
        'email_notification' => ['label' => 'Email notification (zh-tw)'],
      ],
    ];

    $languageManager = \Drupal::service('language_manager');
    // $configName = 'webform.webform.' . $webformId;
    // Set configuration values based on form submission and source values.
    //    $configTranslation = $languageManager->getLanguageConfigOverride($targetLangcode, $configName);.
    // $previousConfigTranslation = $configTranslation->get();
    // $configName = 'webform.webform.' . $webformId;
    //    $typedConfigManager = \Drupal::service('config.typed');
    //    $webformMapping = $typedConfigManager->getDefinition($configName)['mapping'];
    // Lingotek method.
    $webform = $this->loadWebform($webformId);

    $mapper_manager = \Drupal::service('plugin.manager.config_translation.mapper');
    $mappers = $mapper_manager->getMappers();
    $mapper = clone ($mappers[$webform->getEntityTypeId()]);
    $mapper->setEntity($webform);

    $typedConfigManager = \Drupal::service('config.typed');

    $configFactory = \Drupal::configFactory();
    $data = $this->getConfigSourceData($mapper, $typedConfigManager, $configFactory);
    $expanded = $data[$mapper->getConfigNames()[0]];
    $this->saveTargetData($webform, $requestJson, $mappers, $languageManager, $webform, $targetLangcode, $expanded);

    // End lingotek.
    // Save terms.
    //
    //    // Basic properties
    //    foreach ($newTranslation['properties'] as $name => $value) {
    //      $isValidProperty = isset($webformMapping[$name]);
    //      if ($isValidProperty) {
    //        $configTranslation->set($name, $value);
    //      }
    //    }
    //
    //    // Process elements YAML
    //    $previousElementsTranslation = Yaml::decode($previousConfigTranslation['elements']);
    //    $newElementsTranslation = $newTranslation['elements'];
    //    $mergedElementsTranslation = $previousElementsTranslation;
    //    WebformElementHelper::merge($mergedElementsTranslation, $newElementsTranslation);
    //    $translatedElementsYaml = Yaml::encode($mergedElementsTranslation);
    //    $configTranslation->set('elements', $translatedElementsYaml);
    //
    //    // Settings
    //    $previousSettingsTranslation = $previousConfigTranslation['settings'];
    //    $newSettingsTranslation = $newTranslation['settings'];
    //    $mergedSettingsTranslation = $previousSettingsTranslation;
    //    WebformElementHelper::merge($mergedSettingsTranslation, $newSettingsTranslation);
    //    $configTranslation->set('settings', $mergedSettingsTranslation);
    //
    //    // Handlers
    //    $previousHandlersTranslation = $previousConfigTranslation['handlers'];
    //    $newHandlersTranslation = $newTranslation['handlers'];
    //    $mergedHandlersTranslation = $previousHandlersTranslation;
    //    WebformElementHelper::merge($mergedHandlersTranslation, $newHandlersTranslation);
    //    $configTranslation->set('handlers', $mergedHandlersTranslation);
    //
    //    $configTranslation->save();
    return $this->ok_json_response();
  }

  /**
   *
   */
  public function entityPath(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response('', 405);
    }

    $nodeIds = explode(',', $request->query->get('nodeIds'));
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($nodeIds);

    $paths = [];
    foreach ($nodes as $node) {
      $alias = $node->path->getValue()[0];
      if (isset($alias['alias'])) {
        $alias = $alias['alias'];
      }
      else {
        $alias = NULL;
      }

      $paths[] = [
        'nodeId' => $node->id(),
        'source' => '/node/' . $node->id(),
        'alias' => $alias,
      ];
    }
    return $this->json_response(200, ['data' => $paths]);
  }

  /**
   * There's no way to get base field definitions via JSON:API.
   * Field configurations for non-base fields are available, but there's no point in using 2 different
   * methods so I combine both definition types into one response here.
   */
  public function fieldDefinitions(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response('', 405);
    }

    $entityTypeId = $request->query->get('entityTypeId');
    $bundle = $request->query->get('bundle');

    $entityFieldManager = \Drupal::getContainer()->get('entity_field.manager');

    try {
      $definitions = $entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    }
    catch (LogicException $e) {
      // Thrown if a config entity type is given or if one of the entity keys is flagged as translatable.
      // Ignored if entity type is non-fieldable.
      $definitions = [];
    }

    $outDefs = [];
    foreach ($definitions as $field => $definition) {
      $isRequired = $definition->isRequired();
      $isTranslatable = $definition->isTranslatable();

      $label = $definition->getLabel();
      if ($label instanceof TranslatableMarkup) {
        $labelStr = $label->getUntranslatedString();
      }
      elseif (is_string($label)) {
        $labelStr = $label;
      }
      else {
        $labelStr = $field;
      }
      $outDef = [
        'field_name' => $field,
        'label' => $labelStr,
        'field_type' => $definition->getType(),
        'required' => $isRequired,
        'translatable' => $isTranslatable,
        'target_entity_type_id' => $definition->getTargetEntityTypeId(),
        'target_bundle' => $definition->getTargetBundle(),
      ];
      array_push($outDefs, $outDef);
    }
    return $this->json_response(200, ['data' => $outDefs]);
  }

  /**
   *
   */
  public function ok_json_response() {
    return $this->json_response(200, ['code' => 'OK'], FALSE);
  }

  /**
   *
   */
  public function error_json_response($code, $message) {
    return $this->json_response(200, ['message' => $message, 'code' => $code], TRUE);
  }

  /**
   *
   */
  public function json_response($statusCode, $json, $error = FALSE) {
    $json['error'] = $error;
    return new Response(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $statusCode,
      ['Content-Type' => 'application/json']);
  }

  /**
   * @param $elements
   * @param \Drupal\Core\Entity\EntityInterface $webform
   * @param $element
   */
  private function addTerms(&$elements, EntityInterface $webform) {
    foreach ($elements as $name => &$element) {
      $fullElement = $webform->getElement($name);
      if ($fullElement['#type'] === 'tableau_webform_term_select') {
        $vocabId = $fullElement['#vocabulary'];
        $element['vocabulary'] = ['name' => $vocabId];
        $terms = $this->getTerms($vocabId);
        $element['vocabulary'] = array_merge($element['vocabulary'], ['terms' => $terms]);
      }
    }
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function webformExport(Webform $webform): array {

    $mapper_manager = \Drupal::service('plugin.manager.config_translation.mapper');
    $mappers = $mapper_manager->getMappers();
    $mapper = clone ($mappers[$webform->getEntityTypeId()]);
    $mapper->setEntity($webform);

    $typedConfigManager = \Drupal::service('config.typed');

    $configFactory = \Drupal::configFactory();
    $configName = $webform->getConfigDependencyName();
    $configSourceData = $this->getConfigSourceData($mapper, $typedConfigManager, $configFactory)[$configName];

    // Elements.
    $translationManager = \Drupal::service('webform.translation_manager');
    $sourceElements = $translationManager->getSourceElements($webform);

    $elements = $configSourceData[$configName]['elements'];
    unset($configSourceData[$configName]['elements']);

    // For webforms, decode elements property and only include translatable fields.
    if (strpos($configName, 'webform.webform.') === 0) {
      $elements = $sourceElements;
    }

    $this->addTerms($elements, $webform);

    $result = [
      'data' => [
        // 'type' => 'webform--webform',
        'id' => $webform->id(),
        // 'langcode' => $webform->getLangcode(),
      ],
    ];

    /**
     *
     */
    function propertiesJson(array $configSourceData) {
      $result = [];
      foreach ($configSourceData as $name => $property) {
        if ($name === 'elements') {
          continue;
        }

        $result[] = [
          'name' => $name,
          'label' => $property['label'],
          'value' => $property['value'],
        ];
      }
      return $result;
    }

    /**
     *
     */
    function elementsJson(array $elements) {
      $result = [];
      foreach ($elements as $name => $element) {
        $properties = [];
        $vocabulary = NULL;
        foreach ($element as $elName => $value) {
          if ($elName === 'vocabulary') {
            $vocabulary = [
              'name' => $value['name'],
              'terms' => $value['terms'],
            ];
            continue;
          }

          $properties[] = [
            'name' => $elName,
            'value' => $value,
          ];
        }

        $newResult = [
          'name' => $name,
          'properties' => $properties,
        ];

        if ($vocabulary !== NULL) {
          $newResult['vocabulary'] = $vocabulary;
        }

        $result[] = $newResult;
      }
      return $result;
    }

    $result['data']['configs'][] = [
      'name' => $configName,
      'properties' => propertiesJson($configSourceData),
      'elements' => elementsJson($elements),
    ];
    return [$elements, $result];
  }

  /**
   * @param $webformId
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function loadWebform($webformId) {
    $webform = \Drupal::entityTypeManager()
      ->getStorage('webform')
      ->load($webformId);
    return $webform;
  }

}
