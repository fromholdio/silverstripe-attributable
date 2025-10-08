<?php

namespace Fromholdio\Attributable\Extensions;

use Fromholdio\Attributable\Model\Attribution;
use Fromholdio\Attributable\Forms\AttributeListboxField;
use Fromholdio\CommonAncestor\CommonAncestor;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;

class Attribute extends Extension
{
    private static $attribute_force_selection = true;
    private static $attribute_only_one = false;
    private static $attribute_scope_field;
    private static $attribute_url_segment;
    private static $attribute_is_nested = false;

    private static $has_many = [
        'AttrAttributions' => Attribution::class . '.Attribute'
    ];

    /**
     * Request-level cache for field sources to avoid repeated queries
     */
    private static $_field_source_cache = [];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('AttrAttributions');
    }

    public function getAttributeLinkingMode()
    {
        $activeAttr = $this->getOwner()->getActiveAttribute();
        if (!$activeAttr) {
            return null;
        }

        if ($this->getOwner()->getAttributeIsCurrent($activeAttr)) {
            return 'current';
        }

        if ($this->getOwner()->getAttributeIsSection($activeAttr)) {
            return 'section';
        }

        return null;
    }

    public function getAttributeIsCurrent($currentAttr = null)
    {
        if (!$currentAttr) {
            $currentAttr = $this->getOwner()->getActiveAttribute();
        }

        if (!$currentAttr) {
            return false;
        }

        if ($currentAttr->ClassName !== $this->getOwner()->ClassName) {
            return false;
        }

        if ($currentAttr->ID === $this->getOwner()->ID) {
            return true;
        }

        return false;
    }

    public function getAttributeIsSection($currentAttr = null)
    {
        if (!$currentAttr) {
            $currentAttr = $this->getOwner()->getActiveAttribute();
        }

        if (!$currentAttr) {
            return false;
        }

        if ($currentAttr->ClassName !== $this->getOwner()->ClassName) {
            return false;
        }

        if ($currentAttr->ID === $this->getOwner()->ID) {
            return true;
        }

        $parentAttr = $currentAttr->getParentAttribute();
        if (!$parentAttr) {
            return false;
        }

        return $this->getOwner()->getAttributeIsSection($parentAttr);
    }

    public function getActiveAttribute()
    {
        $controller = Controller::curr();
        if (!in_array('getactiveattribute', $controller->allMethodNames())) {
            return null;
        }

        $activeAttr = $controller->getActiveAttribute();
        if (!$activeAttr) {
            return null;
        }

        return $activeAttr;
    }

    public function isAttributeNestingEnabled()
    {
        return $this->getOwner()->config()->get('attribute_is_nested');
    }

    public function getParentAttribute()
    {
        $parentAttr = null;
        if ($this->getOwner()->hasMethod('updateParentAttribute')) {
            $parentAttr = $this->getOwner()->updateParentAttribute($parentAttr);
        }
        return $parentAttr;
    }

    public function getAttributedObjects($objClassName)
    {
        if (!$this->getOwner()->ID) {
            return null;
        }

        if (is_array($objClassName))
        {
            $objClasses = $objClassName;
            $objClassesCommon = CommonAncestor::get_closest($objClasses);
            if ($objClassesCommon === DataObject::class) {
                throw new \InvalidArgumentException(
                    'If you pass an array of class names to getAttributedObjects they must '
                    . 'have a common ancestor class other than DataObject.'
                );
            }
        }
        else {
            $objClasses = ClassInfo::subclassesFor($objClassName);
            $objClassesCommon = $objClassName;
        }

        $attributions = Attribution::get()->filter([
            'AttributeClass' => $this->getOwner()->getClassName(),
            'AttributeID' => $this->getOwner()->ID,
            'ObjectClass' => $objClasses
        ]);

        $objectIDs = $attributions->columnUnique('ObjectID');

        if (empty($objectIDs)) {
            return null;
        }

        $filter = ['ID' => $objectIDs];

        return $objClassesCommon::get()->filter($filter);
    }

    public function getAttributeType()
    {
        return $this->owner->i18n_singular_name();
    }

    public function getAttributeURLSegment()
    {
        return $this->owner->config()->get('attribute_url_segment');
    }

    public function getAttributeScopeObjects()
    {
        $class = $this->owner->getClassName();
        $scopeField = $this->owner->config()->get('attribute_scope_field');
        $objects = null;

        if ($scopeField) {
            $scopeRelationName = substr($scopeField, 0, -2);
            $attrHasOnes = $this->owner->hasOne();
            if (!isset($attrHasOnes[$scopeRelationName])) {
                throw new \UnexpectedValueException(
                    'Invalid $attribute_scope_field for ' . $class . ' '
                    . 'Must be field name of a has_one relation, including "ID".'
                );
            }
            $scopeRelationClass = $attrHasOnes[$scopeRelationName];
            $objects = $scopeRelationClass::get();
        }

        if ($this->owner->hasMethod('updateAttributeScopeObjects')) {
            $objects = $this->owner->updateAttributeScopeObjects($objects);
        }

        return $objects;
    }

    public function getAttributeFieldName($scopeObject = null)
    {
        $class = $this->owner->getClassName();

        $onlyOne = $this->owner->config()->get('attribute_only_one');
        if ($onlyOne) {
            $fieldName = $class . '|one';
        }
        else {
            $fieldName = $class . '|many';
        }

        if ($scopeObject) {
            $fieldName .= '|' . $scopeObject->getClassName() . '|' . $scopeObject->ID;
        }

        if ($this->owner->hasMethod('updateAttributeFieldName')) {
            $fieldName = $this->owner->updateAttributeFieldName($fieldName);
        }

        return $fieldName;
    }

    public function getAttributeFieldLabel($scopeObject = null)
    {
        $onlyOne = $this->owner->config()->get('attribute_only_one');

        if ($scopeObject && $scopeObject->exists()) {
            $label = $scopeObject->Title;
        }
        else {
            if ($onlyOne) {
                $label = $this->owner->i18n_singular_name();
            }
            else {
                $label = $this->owner->i18n_plural_name();
            }
        }

        if ($this->owner->hasMethod('updateAttributeFieldLabel')) {
            $label = $this->owner->updateAttributeFieldLabel($label);
        }

        return $label;
    }

    public function getAttributeFieldSource($scopeObject = null)
    {
        $class = $this->owner->getClassName();
        $scopeID = $scopeObject ? $scopeObject->ID : 0;
        $cacheKey = $class . '_' . $scopeID;

        // Check request-level cache first
        if (isset(self::$_field_source_cache[$cacheKey])) {
            return self::$_field_source_cache[$cacheKey];
        }

        $scopeField = $this->owner->config()->get('attribute_scope_field');

        if ($class::singleton()->hasMethod('getDropdownTitle')) {
            $mapTitle = 'getDropdownTitle';
        }
        else {
            $mapTitle = 'Title';
        }

        if ($scopeField && $scopeObject) {
            $source = $class::get()
                ->filter([
                    $scopeField => $scopeObject->ID
                ])
                ->map('ID', $mapTitle)
                ->toArray();
        }
        else {
            $source = $class::get()->map('ID', $mapTitle)->toArray();
        }

        if ($this->owner->hasMethod('updateAttributeFieldSource')) {
            $source = $this->owner->updateAttributeFieldSource($source, $scopeObject);
        }

        // Cache the result for this request
        self::$_field_source_cache[$cacheKey] = $source;

        return $source;
    }

    /**
     * Clear the field source cache (called when attributes are modified)
     */
    public static function clearFieldSourceCache()
    {
        self::$_field_source_cache = [];
    }

    public function getAttributeFields(?DataObjectInterface $object = null)
    {
        $fields = [];
        $scopeObjects = $this->owner->getAttributeScopeObjects();

        if ($scopeObjects && $scopeObjects->count() > 0) {
            foreach ($scopeObjects as $scopeObject) {
                $fields[] = $this->owner->getAttributeField($object, $scopeObject);
            }
        }
        else {
            $fields[] = $this->owner->getAttributeField($object);
        }

        if ($this->owner->hasMethod('updateAttributeFields')) {
            $fields[] = $this->owner->updateAttributeFields($fields, $object);
        }

        return $fields;
    }

    public function getAttributeField(?DataObjectInterface $object = null, $scopeObject = null)
    {
        $onlyOne = $this->owner->config()->get('attribute_only_one');
        $forceSelection = $this->owner->config()->get('attribute_force_selection');

        $fieldName = $this->owner->getAttributeFieldName($scopeObject);
        $fieldLabel = $this->owner->getAttributeFieldLabel($scopeObject);
        $fieldSource = $this->owner->getAttributeFieldSource($scopeObject);

        if ($onlyOne) {

            $field = DropdownField::create($fieldName, $fieldLabel, $fieldSource);

            if (!$forceSelection) {
                $field->setEmptyString('- Select one -');
                $field->setHasEmptyDefault(true);
            }
        }
        else {
            $field = AttributeListboxField::create($fieldName, $fieldLabel, $fieldSource);
        }

        if ($object && $object->exists()) {

            if ($this->owner->getClassName() === $object->getClassName()) {
                if (isset($fieldSource[$object->ID])) {
                    unset($fieldSource[$object->ID]);
                    $field->setSource($fieldSource);
                }
            }

            $currentAttributes = $object->getAttributes(
                $this->owner->getClassName(),
                $scopeObject
            );

            if ($currentAttributes && $currentAttributes->count() > 0) {
                if ($onlyOne) {
                    $currentAttribute = $currentAttributes->first();
                    $value = $currentAttribute->ID;
                }
                else {
                    $value = $currentAttributes->columnUnique('ID');
                }
                $field->setValue($value);
            }
        }

        if ($this->owner->hasMethod('updateAttributeField')) {
            $field = $this->owner->updateAttributeField($field, $object, $scopeObject);
        }

        return $field;
    }

    public function getAttributeScopeFieldName()
    {
        return $this->getOwner()->config()->get('attribute_scope_field');
    }
}
