<?php

namespace Fromholdio\Attributable\Extensions;

use Fromholdio\Attributable\Model\Attribution;
use Fromholdio\CommonAncestor\CommonAncestor;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class Attributable extends Extension
{
    private static $attributes_tab_path = 'Root.Attributes';
    private static $attributes_register;

    private static $has_many = [
        'Attributions' => Attribution::class . '.Object'
    ];

    private static $owns = [
        'Attributions'
    ];

    private static $cascade_deletes = [
        'Attributions'
    ];

    private static $cascade_duplicates = [
        'Attributions'
    ];

    public function getAttributes($attrClass, $scopeObject = null)
    {
        if (!$this->owner->ID) {
            return null;
        }

        if (is_array($attrClass))
        {
            $attrClasses = $attrClass;
            $attrClassesCommon = CommonAncestor::get_closest($attrClasses);
            if ($attrClassesCommon === DataObject::class) {
                throw new \InvalidArgumentException(
                    'If you pass an array of class names to getAttributes they must '
                    . 'have a common ancestor class other than DataObject.'
                );
            }
        }
        else {
            $attrClasses = ClassInfo::subclassesFor($attrClass);
            $attrClassesCommon = $attrClass;
        }

        $attributions = Attribution::get()->filter([
            'ObjectClass' => $this->owner->getClassName(),
            'ObjectID' => $this->owner->ID,
            'AttributeClass' => $attrClasses
        ]);

        $attributeIDs = $attributions->columnUnique('AttributeID');

        if (empty($attributeIDs)) {
            return null;
        }

        $filter = ['ID' => $attributeIDs];

        if ($scopeObject && $scopeObject->exists()) {
            $scopeField = $attrClassesCommon::singleton()->config()->get('attribute_scope_field');
            if ($scopeField) {
                $filter[$scopeField] = $scopeObject->ID;
            }
        }

        return $attrClassesCommon::get()->filter($filter);
    }

    public function syncAttributes($attrClass, array $attrIDs, $scopeObject = null)
    {
        Attribution::validate_attribute($attrClass);

        $newAttrs = $attrClass::get()->filter('ID', $attrIDs);
        $oldAttrs = $this->owner->getAttributes($attrClass, $scopeObject);

        // If no attributes were attached originally,
        // simply attach all new attributes and return.
        if (!$oldAttrs || $oldAttrs->count() === 0) {
            foreach ($newAttrs as $newAttr) {
                $this->owner->attachAttribute($newAttr);
            }
            return;
        }

        // If no new attributes, simply detach all existing and return.
        if ($newAttrs && $newAttrs->count() === 0) {
            $this->owner->detachAllAttributes($attrClass, $scopeObject);
            return;
        }

        // Determine the orphaned attributes - attributes that used to be
        // attached but are absent from the new attr list.
        $orphanAttrIDs = array_diff(
            $oldAttrs->columnUnique('ID'),
            $newAttrs->columnUnique('ID')
        );

        // If orphaned attributes, detach them.
        if (count($orphanAttrIDs) > 0) {
            $orphanAttrs = $attrClass::get()->filter('ID', $orphanAttrIDs);
            $this->owner->detachAttributes($orphanAttrs);
        }

        // Get array of original attributes with ID as key
        $oldAttrIDs = array_flip($oldAttrs->columnUnique('ID'));

        // Attach the new attributes
        foreach ($newAttrs as $newAttr) {
            if (!isset($oldAttrIDs[$newAttr->ID])) {
                $this->owner->attachAttribute($newAttr);
            }
        }
    }

    public function detachAllAttributes($attrClass, $scopeObject = null)
    {
        $attrs = $this->owner->getAttributes($attrClass, $scopeObject);
        foreach ($attrs as $attr) {
            $this->owner->detachAttribute($attr);
        }
        return true;
    }

    public function detachAttributes($attributes)
    {
        foreach ($attributes as $attribute) {
            $this->owner->detachAttribute($attribute);
        }
    }

    public function detachAttribute($attribute)
    {
        Attribution::validate_attribute(get_class($attribute));

        $existing = Attribution::get_from_pair($this->owner, $attribute);
        if ($existing) {
            $this->owner->Attributions()->remove($existing);
            // mark owner as changed
            if ($this->owner->hasExtension(Versioned::class)) {
                $this->owner->writeToStage(Versioned::DRAFT);
            } else {
                $this->owner->write();
            }
        }
    }

    public function attachAttributes($attributes)
    {
        foreach ($attributes as $attribute) {
            $this->owner->attachAttribute($attribute);
        }
    }

    public function attachAttribute($attribute)
    {
        Attribution::validate_attribute(get_class($attribute));

        $existing = Attribution::get_from_pair($this->owner, $attribute);

        if ($existing) {
            return $existing;
        }

        $attribution = Attribution::create();
        $attribution->ObjectClass = $this->owner->getClassName();
        $attribution->ObjectID = $this->owner->ID;
        $attribution->AttributeClass = $attribute->getClassName();
        $attribution->AttributeID = $attribute->ID;
        $attribution->write();

        $this->owner->Attributions()->add($attribution);
        // mark owner as changed
        if ($this->owner->hasExtension(Versioned::class)) {
            $this->owner->writeToStage(Versioned::DRAFT);
        } else {
            $this->owner->write();
        }

        return $attribution;
    }

    public function updateCMSFields(FieldList $fields)
    {

        $tabPath = $this->owner->config()->get('attributes_tab_path');
        if ($tabPath) {
            $attrFields = $this->owner->getAttributesFields();

            if ($attrFields && is_array($attrFields) && !empty($attrFields)) {
                $fields->addFieldsToTab($tabPath, $attrFields);
            }
        }

        $fields->removeByName('Attributions');

    }

    public function getAttributesFields($fieldNameKey = 'Attributes')
    {
        // Try to get from cache first (only for existing records)
        if ($this->owner->ID) {
            $cache = $this->getAttributesCMSFieldsCache();
            $cacheKey = $this->getAttributesFieldsCacheKey($fieldNameKey);

            if ($cache->has($cacheKey)) {
                $fields = $cache->get($cacheKey);
                // Fields are cached, but we need to populate current values
                $this->populateAttributeFieldValues($fields);
                return $fields;
            }
        }

        // Generate fields (not in cache or new record)
        $attrClasses = $this->owner->getAllowedAttributes();
        $fields = [];
        $fieldNames = [];
        foreach ($attrClasses as $attrClass) {
            $field = $attrClass::singleton()->getAttributeFields($this->owner);
            if (!$field) {
                continue;
            }
            if (is_a($field, FormField::class)) {
                $fieldName = $fieldNameKey . '|' . $field->getName();
                $field->setName($fieldName);
                $fields[] = $field;
                $fieldNames[] = $fieldName;
            }
            else if (is_array($field)) {
                foreach ($field as $childField) {
                    if (is_a($childField, FormField::class)) {
                        $childFieldName = $fieldNameKey . '|' . $childField->getName();
                        $childField->setName($childFieldName);
                        $fields[] = $childField;
                        $fieldNames[] = $childFieldName;
                    }
                }
            }
        }

        if (!empty($fields) && !empty($fieldNames)) {
            $saveField = HiddenField::create(
                'AttributesFieldNames',
                'AttributesFieldNames',
                implode(',', $fieldNames)
            );
            $fields[] = $saveField;
        }

        $this->getOwner()->invokeWithExtensions('updateAttributesFields', $fields);

        // Cache the field structure (with values) for this object class
        if ($this->owner->ID) {
            $cache = $this->getAttributesCMSFieldsCache();
            $cacheKey = $this->getAttributesFieldsCacheKey($fieldNameKey);
            $cache->set($cacheKey, $fields, 3600); // 1 hour TTL
        }

        return $fields;
    }

    /**
     * Populate current attribute values into cached fields
     */
    protected function populateAttributeFieldValues($fields)
    {
        if (!$this->owner->ID || empty($fields)) {
            return;
        }

        // Get all current attributions for this object
        $attrClasses = $this->owner->getAllowedAttributes();
        $currentAttributions = Attribution::get()->filter([
            'ObjectClass' => $this->owner->getClassName(),
            'ObjectID' => $this->owner->ID,
            'AttributeClass' => array_keys($attrClasses)
        ]);

        // Group by attribute class for easier lookup
        $attributionsByClass = [];
        foreach ($currentAttributions as $attribution) {
            $class = $attribution->AttributeClass;
            if (!isset($attributionsByClass[$class])) {
                $attributionsByClass[$class] = [];
            }
            $attributionsByClass[$class][] = $attribution->AttributeID;
        }

        // Populate field values
        foreach ($fields as $field) {
            if (!is_a($field, FormField::class)) {
                continue;
            }

            $fieldName = $field->getName();
            if ($fieldName === 'AttributesFieldNames') {
                continue;
            }

            // Parse field name to get attribute class
            $fieldMeta = explode('|', $fieldName);
            if (isset($fieldMeta[1])) {
                $attrClass = $fieldMeta[1];
                $mode = $fieldMeta[2] ?? 'many';

                if (isset($attributionsByClass[$attrClass])) {
                    if ($mode === 'one') {
                        $field->setValue($attributionsByClass[$attrClass][0] ?? null);
                    } else {
                        $field->setValue($attributionsByClass[$attrClass]);
                    }
                }
            }
        }
    }

    /**
     * Get the CMS fields cache instance
     */
    protected function getAttributesCMSFieldsCache()
    {
        return Injector::inst()->get(CacheInterface::class . '.AttributableCMSFieldsCache');
    }

    /**
     * Get cache key for this object's attribute fields
     * Note: Cache keys cannot contain: {}()/\@:
     */
    protected function getAttributesFieldsCacheKey($fieldNameKey = 'Attributes')
    {
        // Replace backslashes and other reserved characters with underscores
        $className = str_replace(['\\', '{', '}', '(', ')', '/', '@', ':'], '_', $this->owner->getClassName());
        return $className . '_' . $fieldNameKey;
    }

    /**
     * Clear the CMS fields cache for this object class
     */
    public function clearAttributesCMSFieldsCache()
    {
        $cache = $this->getAttributesCMSFieldsCache();
        $cacheKey = $this->getAttributesFieldsCacheKey();
        $cache->delete($cacheKey);
    }

    /**
     * Clear all CMS fields caches (called when attribute structure changes)
     */
    public static function clearAllAttributesCMSFieldsCaches()
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.AttributableCMSFieldsCache');
        $cache->clear();
    }

    public function saveAttributesFieldNames($value)
    {
        $fieldNames = explode(',', $value);

        foreach ($fieldNames as $fieldName) {

            $fieldMeta = explode('|', $fieldName);
            if (isset($fieldMeta[2])) {

                $attributeClass = $fieldMeta[1];
                $attributeMode = $fieldMeta[2];
                $attributeValue = $this->owner->{$fieldName};

                if ($attributeMode === 'one') {
                    $values = [$attributeValue];
                }
                else if ($attributeMode === 'many') {
                    $values = explode(',', $attributeValue);
                }
                else {
                    throw new \UnexpectedValueException(
                        'Should not have reached here in Attributable. '
                        . 'Something wrong with $attributeMode'
                    );
                }

                if (isset($fieldMeta[3]) && isset($fieldMeta[4])) {
                    $attributeScopeClass = $fieldMeta[3];
                    $attributeScopeID = $fieldMeta[4];
                    $attributeScopeObject = $attributeScopeClass::get()->byID($attributeScopeID);
                }
                else {
                    $attributeScopeObject = null;
                }

                $this->owner->syncAttributes($attributeClass, $values, $attributeScopeObject);
            }
        }
    }

    public function getAllowedAttributes()
    {
        $allowed = $this->owner->config()->get('allowed_attributes');
        if ($allowed && !empty($allowed)) {
            $valid = Attribution::validate_attributes($allowed);
            if ($valid === true) {
                $classes = $allowed;
            }
            else {
                throw new \UnexpectedValueException(
                    'Invalid $allowed_attributes value for ' . get_class($this->owner)
                );
            }
        }
        else {
            $classes = Attribution::get_attributes();
        }

        $disallowed = $this->getOwner()->config()->get('disallowed_attributes');
        if (!empty($disallowed)) {
            foreach ($disallowed as $class) {
                if (isset($classes[$class])) {
                    unset($classes[$class]);
                }
            }
        }

        return $classes;
    }

    public function isAllowedAttribute($className)
    {
        $allowedAttrs = $this->getAllowedAttributes();
        if (!$allowedAttrs || empty($allowedAttrs)) {
            return false;
        }
        return isset($allowedAttrs[$className]);
    }

    public function getRelatedObjectsByAttribute($attribute, array $objClassNames)
    {
        return Attribution::get_related_objects(
            get_class($attribute),
            [$attribute->ID],
            $objClassNames
        );
    }
}
