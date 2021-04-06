<?php

namespace Fromholdio\Attributable\Extensions;

use Fromholdio\Attributable\Model\Attribution;
use Fromholdio\CommonAncestor\CommonAncestor;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\DataExtension;

class Attributable extends DataExtension
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

    public static function add_to_class($class, $extensionClass, $args = null)
    {
        $classInst = $class::singleton();
        if ($classInst->hasMethod('isAttributeFilterOnly')) {
            if ($classInst->isAttributeFilterOnly()) {
                return;
            }
        }
        Attribution::register_object($class);
    }

    public function getAttributes($attrClass, $scopeObject = null)
    {
        if (!$this->owner->ID) {
            return null;
        }

        $attributions = Attribution::get()->filter([
            'ObjectClass' => $this->owner->getClassName(),
            'ObjectID' => $this->owner->ID,
            'AttributeClass' => $attrClass
        ]);

        $attributeIDs = $attributions->columnUnique('AttributeID');

        if (empty($attributeIDs)) {
            return null;
        }

        $filter = ['ID' => $attributeIDs];

        if ($scopeObject && $scopeObject->exists()) {
            $scopeField = $attrClass::singleton()->config()->get('attribute_scope_field');
            if ($scopeField) {
                $filter[$scopeField] = $scopeObject->ID;
            }
        }

        return $attrClass::get()->filter($filter);
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

        return $fields;
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
                    $attributeScopeObject = $attributeScopeClass::get_by_id($attributeScopeID);
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
}
