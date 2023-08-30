<?php

namespace Drupal\wisski_api\Normalizer;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\serialization\Normalizer\EntityNormalizer;
use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_core\WisskiEntityInterface;
use Drupal\wisski_salz\AdapterHelper;

/**
 * A normalizer for WisskiEntities.
 */
class WisskiEntityNormalizer extends EntityNormalizer {

  const ENTITY_TYPE = "wisski_individual";

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = WisskiEntityInterface::class;

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) {
    return $this->normalizeEntity($object);
  }

  /**
   * Normalize a WisskiEntity.
   *
   * @param \Dupal\wisski_core\Entity\WisskiEntity $entity
   *   The entity to be normalized.
   *
   * @return array
   *   The normalized entity.
   */
  public function normalizeEntity($entity) {
    $normalizedEntity = [];
    foreach ($entity as $fieldId => $fieldItem) {
      $fieldDefinition = $fieldItem->getFieldDefinition();
      $normalizedFieldItem = $this->normalizeFieldItem($fieldItem);

      // @todo figure out if separation by class is the right way to go
      // BaseFields.
      if ($fieldDefinition instanceof BaseFieldDefinition) {
        $normalizedEntity['meta'][$fieldId] = $normalizedFieldItem;
        continue;
      }
      // FieldConfigs.
      if ($fieldDefinition instanceof FieldConfig) {
        $normalizedEntity['fields'][$fieldId] = $normalizedFieldItem;
        continue;
      }
    }
    // dpm($normalizedEntity);
    return $normalizedEntity;
  }

  /**
   * Normalizes a single FieldItem.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $fieldItem
   *   The FieldItem to be formatted.
   *
   * @return array
   *   The normalized FieldItem.
   */
  private function normalizeFieldItem(FieldItemInterface|FieldItemListInterface $fieldItem) {
    // Get values and definition.
    $fieldValue = $fieldItem->getValue();
    $fieldDefinition = $fieldItem->getFieldDefinition();

    // Get the type of the field and the Label.
    $fieldType = $fieldDefinition->getType();
    $normalizedFieldItem['label'] = $fieldDefinition->getLabel();
    $normalizedFieldItem['type'] = $fieldDefinition->getType();

    // Normalize each of the values and return the result.
    $normalizedFieldValue = [];

    foreach ($fieldValue as $value) {
      $normalizedFieldValue[] = $this->normalizeFieldValue($fieldType, $value);
    }

    $normalizedFieldItem['value'] = $normalizedFieldValue;
    return $normalizedFieldItem;
  }

  /**
   * Normalizes a particiular FieldValue.
   *
   * @param string $fieldType
   *   The type of field.
   * @param array $value
   *   The value of the field.
   *
   * @return array
   *   The normalized field value.
   */
  private function normalizeFieldValue(string $fieldType, array $value) {
    // Handle each content type differently.
    $normalizedValue = [];
    switch ($fieldType) {
      case 'entity_reference':
        $normalizedValue = $this->normalizeEntityReference($value);
        break;

      case 'image':
      case 'link':
        $normalizedValue = $value;
        break;

      /*
       * Other contentTypes:
       *
       * 'string'
       * 'text_long'
       * 'integer'
       * 'language'
       * 'boolean
       */
      default:
        $normalizedValue = $this->defaultValueNormalizer($value);
    }
    return $normalizedValue;
  }

  /**
   * Normalize a FieldValue of type 'entity_reference'.
   *
   * Adds the URI of the referenced 'wisski_individual' to the value array.
   *
   * @param array $value
   *   The value of an entity_reference field.
   *
   * @return array
   *   The normalized value.
   */
  private function normalizeEntityReference(array $value) {
    // Entity reference.
    $targetId = NULL;
    if (!array_key_exists('target_id', $value)) {
      return $value;
    }
    $targetId = $value['target_id'];

    $normalizedValue = [];
    $normalizedValue['target_id'] = $targetId;

    if (array_key_exists('wisskiDisamb', $value)) {
      $normalizedValue['wisskiDisamb'] = $value['wisskiDisamb'];
    }
    // @todo find out what the following key
    // is for and in which cases it is set.
    if (array_key_exists('original_target_id', $value)) {
      $normalizedValue['original_target_id'] = $value['original_target_id'];
    }

    // Load the referenced entity from storage.
    $entity = $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->load($targetId);
    if (!$entity) {
      return $normalizedValue;
    }

    // Get the URI for the entity and set it.
    $uriAdapter = current(AdapterHelper::doGetUrisForDrupalId($targetId));
    $normalizedValue['target_uri'] = $uriAdapter->uri;
    // Set the adapter ID.
    $normalizedValue['target_adapter_id'] = $uriAdapter->adapter_id;

    // @todo find out which keys are unneccessary and remove them.
    return $normalizedValue;
  }

  /**
   * The default value normalizer.
   *
   * Just returns the ['value'] element from the value array.
   *
   * @param array $value
   *   The value of a FieldItem.
   *
   * @return mixed
   *   The value.
   */
  private function defaultValueNormalizer(array $value) {
    if (array_key_exists('value', $value)) {
      return $value['value'];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    return $this->denormalizeEntity($data);
  }

  /**
   * Denormalize a WissKI Entity.
   *
   * @param array $data
   *   The normalized entity.
   *
   * @return \Drupal\wisski_core\Entity\WisskiEntity
   *   The denormalized entity.
   */
  private function denormalizeEntity(array $data): WisskiEntity {
    $allFields = [];
    // Combine all fields.
    $allFields += $data['meta'];
    $allFields += $data['fields'];

    $entity_fields = [];
    foreach ($allFields as $fieldId => $field) {
      // $label = $field['label'];
      $type = $field['type'];
      $value = $field['value'];

      // In case of an entity reference get the EID from the URI.
      if ($type === "entity_reference") {
        if (array_key_exists('target_uri', $value)) {
          $value['target_id'] = AdapterHelper::getDrupalIdForUri($value['target_uri']);
        }
      }
      $entity_fields[$fieldId] = $value;
    }
    return $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->create($entity_fields);
  }

}
