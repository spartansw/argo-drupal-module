<?php

namespace Drupal\argo\Controller;

use Drupal\Core\Config\TypedConfigManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webform\Utility\WebformElementHelper;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Serialization\Yaml;

class Argo extends ControllerBase {

  private function addHandlerProps(array $handlerMap,
                                   array $values,
                                   TypedConfigManager $typedConfigManager,
                                   array &$outProps) {
    foreach ($handlerMap as $name => $value) {
      //TODO: add translatable flags to schema and check for them
      if (in_array($value['type'], ['label', 'text'])) {
        $outProps[$name] = [
          'label' => $value['label'],
          'value' => $values[$name],
        ];
      }
      elseif (strpos($value['type'], 'webform.handler.') === 0) {
        //TODO: should be able to add more than just webform.handler dynamic props
        $dynamicMap = $typedConfigManager->getDefinition('webform.handler.' . $values['id'])['mapping'];
        $dynamicProps = [];
        $this->addHandlerProps($dynamicMap, $values[$name], $typedConfigManager, $dynamicProps);
        $outProps[$name] = $dynamicProps;
      }
    }
  }

  public function exportWebform(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response("", 405);
    }

    $webformId = $request->query->get('webformId');
    $webform = \Drupal::entityTypeManager()
      ->getStorage('webform')
      ->load($webformId);

    $configName = 'webform.webform.' . $webformId;
    $typedConfigManager = \Drupal::service('config.typed');
    $webformMapping = $typedConfigManager->getDefinition($configName)['mapping'];
    $outProperties = [];
    foreach ($webformMapping as $name => $value) {
      // Skip properties that need special processing
      if (in_array($name, ['elements', 'settings', 'handlers'])) {
        continue;
      }
      //TODO: add translatable flags to schema add test for those flags
      if (in_array($value['type'], ['label', 'text'])) {
        $outProperties[$name] = [
          'label' => $value['label'],
          'value' => $webform->get($name),
        ];
      }
    }

    // Parse elements YAML
    $elementsValue = Yaml::decode($webform->get('elements'));
    foreach ($elementsValue as &$element) {
      foreach ($element as $name => $value) {
        // Filter untranslatable
        if (in_array($name, [
          '#required',
          '#type',
          '#test',
          '#field_overrides',
        ])) {
          unset($element[$name]);
        }
      }
    }
    $outElements = [
      'label' => $webformMapping['elements']['label'],
      'value' => $elementsValue,
    ];

    // Settings
    $outSettings = [];
    foreach ($webform->getSettings() as $name => $value) {
      $settingDataType = $webformMapping['settings']['mapping'][$name];
      $settingType = $settingDataType['type'];
      if ($settingType === 'text' || $settingType === 'label') {
        $outSettings[$name] = [
          'label' => $settingDataType['label'],
          'value' => $value,
        ];
      }
    }

    // Handlers
    $handlerConfigs = $webform->getHandlers()->getConfiguration();
    $outHandlers = [];
    foreach ($handlerConfigs as $handlerName => $handlerConfig) {
      // Get translatable fields
      $handlerProps = [];
      $handlerMap = $webformMapping['handlers']['sequence']['mapping'];
      $this->addHandlerProps($handlerMap, $handlerConfig, $typedConfigManager, $handlerProps);
      $outHandlers[$handlerName] = $handlerProps;
    }

    $result = [
      'data' => [
        'type' => 'webform--webform',
        'id' => $webformId,
        'langcode' => $webform->getLangcode(),
        'properties' => $outProperties,
        'elements' => $outElements,
        'settings' => $outSettings,
        'handlers' => $outHandlers,
      ],
    ];

    // Add hash so clients can check if config has changed
    $hash = md5(json_encode($result));
    $result['data']['hash'] = $hash;

    return $this->json_response(200, $result);
  }

  public function translateWebform(Request $request) {
    $invalidMethod = $request->getMethod() !== 'POST';
    if ($invalidMethod) {
      return new Response("", 405);
    }

    $requestJson = json_decode($request->getContent(), TRUE);
    $webformId = $requestJson['id'];
    $targetLangcode = $requestJson['targetLangcode'];
    $newTranslation = [
      'properties' => [
        'title' => 'translated title',
        'description' => 'translated description',
        'category' => 'translated category',
      ],
      'elements' => [
        'name' => [
          '#title' => "Your Name (zh-tw)",
          '#default_value' => "[webform-authenticated-user:display-name]",
        ],
      ],
      'settings' => ['confirmation_message' => "Your message has been sent (zh-tw)"],
      'handlers' => [
        'email_confirmation' => [
          'label' => 'Email confirmation (zh-tw)',
          'settings' => ['subject' => '[webform_submission:values:subject:raw] (zh-tw)'],
        ],
        'email_notification' => ['label' => 'Email notification (zh-tw)'],
      ],
    ];

    $languageManager = \Drupal::service('language_manager');
    $configName = 'webform.webform.' . $webformId;

    // Set configuration values based on form submission and source values.
    $configTranslation = $languageManager->getLanguageConfigOverride($targetLangcode, $configName);

    $previousConfigTranslation = $configTranslation->get();

    $configName = 'webform.webform.' . $webformId;
    $typedConfigManager = \Drupal::service('config.typed');
    $webformMapping = $typedConfigManager->getDefinition($configName)['mapping'];

    // Basic properties
    foreach ($newTranslation['properties'] as $name => $value) {
      $isValidProperty = isset($webformMapping[$name]);
      if ($isValidProperty) {
        $configTranslation->set($name, $value);
      }
    }

    // Process elements YAML
    $previousElementsTranslation = Yaml::decode($previousConfigTranslation['elements']);
    $newElementsTranslation = $newTranslation['elements'];
    $mergedElementsTranslation = $previousElementsTranslation;
    WebformElementHelper::merge($mergedElementsTranslation, $newElementsTranslation);
    $translatedElementsYaml = Yaml::encode($mergedElementsTranslation);
    $configTranslation->set('elements', $translatedElementsYaml);

    // Settings
    $previousSettingsTranslation = $previousConfigTranslation['settings'];
    $newSettingsTranslation = $newTranslation['settings'];
    $mergedSettingsTranslation = $previousSettingsTranslation;
    WebformElementHelper::merge($mergedSettingsTranslation, $newSettingsTranslation);
    $configTranslation->set('settings', $mergedSettingsTranslation);

    // Handlers
    $previousHandlersTranslation = $previousConfigTranslation['handlers'];
    $newHandlersTranslation = $newTranslation['handlers'];
    $mergedHandlersTranslation = $previousHandlersTranslation;
    WebformElementHelper::merge($mergedHandlersTranslation, $newHandlersTranslation);
    $configTranslation->set('handlers', $mergedHandlersTranslation);

    $configTranslation->save();

    return $this->ok_json_response();
  }

  public function entityPath(Request $request) {
    $invalidMethod = $request->getMethod() !== 'GET';
    if ($invalidMethod) {
      return new Response("", 405);
    }

    $nid = $request->query->get('nid');
    $node = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->load($nid);

    $path = $node->path->getValue()[0];
    $hasAlias = isset($path['alias']);

    if (!$hasAlias) {
      $path['source'] = '/node/' . $node->id();
    }

    return $this->json_response(200, $path);
  }

  /*
   * There's no way to get base field definitions via JSON:API.
   * Field configurations for non-base fields are available, but there's no point in using 2 different
   * methods so I combine both definition types into one response here.
   */
  public function fieldDefinitions(Request $request) {
    $invalidMethod = $request->getMethod() !== 'POST';
    if ($invalidMethod) {
      return new Response("", 405);
    }

    $entityTypeId = $request->query->get('entityTypeId');
    $bundle = $request->query->get('bundle');

    $entityFieldManager = \Drupal::getContainer()->get('entity_field.manager');

    try {
      $definitions = $entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    } catch (LogicException $e) {
      // Thrown if a config entity type is given or if one of the entity keys is flagged as translatable.
      // Ignored if entity type is non-fieldable
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

  /*
   * Translates fields on a single entity
   */
  public function translation(Request $request) {
    $invalidMethod = $request->getMethod() !== 'POST';
    if ($invalidMethod) {
      return $this->json_response(405, ["error" => "405 Method Not Allowed"]);
    }

    //TODO: content moderation

    $requestJson = json_decode($request->getContent(), TRUE);
    $entityId = $requestJson['entityId'];
    $targetLangcode = $requestJson['targetLangcode'];
    $entityType = $requestJson['entityType'];
    $newTranslations = $requestJson['fieldTranslations'];

    $loadResult = \Drupal::entityTypeManager()
      ->getStorage($entityType)
      ->loadByProperties(['uuid' => $entityId]);
    if (empty($loadResult)) {
      return $this->error_json_response('INVALID_ENTITY_TYPE', 'Entity type "' . $entityType . '" not found');
    }
    $srcEntity = $loadResult[array_key_first($loadResult)];

    // TODO: why is srcEntity->isTranslatable() sometimes false? Translation settings say otherwise

    if (!$srcEntity->hasTranslation($targetLangcode)) {
      $srcEntity->addTranslation($targetLangcode, $srcEntity->getFields());
    }

    if ($srcEntity->language()->getId() == "und") {
      return $this->json_response(200, [
        'code' => 'LANG_UNDEFINED',
        'message' => "Entity cannot be translated if it is language-neutral",
      ], FALSE);
    }

    $entityTranslation = $srcEntity->getTranslation($targetLangcode);


    //TODO: Why is langcode and status marked as translatable if setting the value is not allowed?
    // This forces us to keep a whitelist of fields that are actually translatable
    $translatableFields = $srcEntity->getTranslatableFields($include_computed = FALSE);


    foreach ($translatableFields as $field) {
      $fieldName = $field->getName();
      $translationField = $entityTranslation->get($fieldName);

      $fieldHasNewTranslation = isset($newTranslations[$fieldName]);

      $originalFieldValue = $srcEntity->get($fieldName)->getValue();
      $fieldHasNoExistingTranslation = $entityTranslation->get($fieldName)->getValue() == NULL;

      if ($fieldHasNewTranslation) {
        $newFieldValue = $originalFieldValue;
        foreach ($newTranslations[$fieldName] as $newTranslation) {
          $valuePath = $newTranslation['valuePath'];
          $isArrayValue = $valuePath != NULL;
          if ($isArrayValue) {
            // Array values can have multiple values.
            // Figure out which value in field to translate.
            $splitPath = explode('/', $valuePath);
            $cur = &$newFieldValue[0];
            // First node is "", always followed by "value". All nodes after we need to follow to set
            // field value

            $pathNodes = array_slice($splitPath, 2);
            $isSerialized = FALSE;

            foreach ($pathNodes as $index => $node) {
              // Try unserializing if key not found
              if ($node === 'UNSERIALIZE') {
                $isSerialized = TRUE;
                $parsed = unserialize($cur);
                $target = &$parsed[$pathNodes[$index + 1]];
                $target = $newTranslation['value'];
                $cur = serialize($parsed);
                break;
              }
              $cur = &$cur[$node];
            }
            if (!$isSerialized) {
              $cur = $newTranslation['value'];
            }
          }
          else {
            // Need to wrap value in array?
            $newFieldValue = $newTranslation['value'];
            // Non-array values only have 1 value, so continue to next field
            continue;
          }
        }
        $translationField->setValue($newFieldValue);
      }
      elseif ($fieldHasNoExistingTranslation) {
        $translationField->setValue($originalFieldValue);
      }
    }

    try {
      $entityTranslation->save();
    } catch (\Drupal\Core\Entity\EntityStorageException $e) {
      return $this->error_json_response(200, $e->getMessage());
    }

    return $this->ok_json_response();
  }

  private
  function ok_json_response() {
    return $this->json_response(200, ['code' => 'OK'], FALSE);
  }

  private
  function error_json_response($code, $message) {
    return $this->json_response(200, ['message' => $message, 'code' => $code], TRUE);
  }

  private
  function json_response($statusCode, $json, $error = FALSE) {
    $json['error'] = $error;
    return new Response(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $statusCode,
      ["Content-Type" => "application/json"]);
  }
}
