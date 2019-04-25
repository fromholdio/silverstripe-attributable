<?php

namespace Fromholdio\Attributable\Model;

use Fromholdio\Attributable\Extensions\Attributable;
use Fromholdio\Attributable\Extensions\Attribute;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\Versioned\Versioned;

class Attribution extends DataObject
{
    private static $table_name = 'Attribution';
    private static $singular_name = 'Attribution';
    private static $plural_name = 'Attributions';

    private static $extensions = [
        Versioned::class
    ];

    private static $db = [
        'AttributeKey' => 'Varchar(1000)',
        'ObjectKey' => 'Varchar(1000)'
    ];

    private static $has_one = [
        'Attribute' => DataObject::class,
        'Object' => DataObject::class
    ];

    protected static $attributes = [];
    protected static $objects = [];

    public static function register_attribute($class)
    {
        self::validate_attribute($class);
        self::$attributes[$class] = $class;
    }

    public static function register_object($class)
    {
        self::validate_object($class);
        self::$objects[$class] = $class;
    }

    public static function get_attributes()
    {
        $classes = self::$attributes;
        return $classes;
    }

    public static function get_attributes_for_dropdown($usePlural = false)
    {
        $classes = self::get_attributes();
        $source = [];
        foreach ($classes as $class) {
            if ($usePlural) {
                $name = $class::singleton()->i18n_plural_name();
            }
            else {
                $name = $class::singleton()->i18n_singular_name();
            }
            $source[$class] = $name;
        }
        return $source;
    }

    public static function get_attribute_by_url_segment($urlSegment)
    {
        $classes = self::get_attributes();
        foreach ($classes as $class) {
            $attrURLSegment = $class::singleton()->config()->get('attribute_url_segment');
            if ($attrURLSegment === $urlSegment) {
                return $class;
            }
        }
        return null;
    }

    public static function get_objects()
    {
        $classes = self::$objects;
        return $classes;
    }

    public static function validate_object($class)
    {
        return self::do_validation('object', [$class]);
    }

    public static function validate_objects($classes)
    {
        return self::do_validation('object', $classes);
    }

    public static function validate_attribute($class)
    {
        return self::do_validation('attribute', [$class]);
    }

    public static function validate_attributes($classes)
    {
        return self::do_validation('attribute', $classes);
    }

    protected static function do_validation($type, $classes)
    {
        if ($type !== 'attribute' && $type !== 'object') {
            throw new \InvalidArgumentException(
                'Invalid $type passed to Attribution::do_validation(). '
                . 'Expected "attribute" or "object"; '
                . $type . ' was supplied instead.'
            );
        }

        if (!is_array($classes)) {
            throw new \InvalidArgumentException(
                'Classes must be passed as an array to Attribution::do_validation(). '
                . gettype($classes) . ' was supplied instead.'
            );
        }

        if (empty($classes)) {
            throw new \InvalidArgumentException(
                'Attribution::do_validation() must be passed '
                . 'at least one class in $classes array. Array was empty.'
            );
        }

        // To confirm we're working with a non-associative array of class names as expected.
        $classes = array_values($classes);

        // Set extension class
        if ($type === 'attribute') {
            $extensionClass = Attribute::class;
        }
        else if ($type === 'object') {
            $extensionClass = Attributable::class;
        }

        // Ensure all supplied classes are valid
        foreach ($classes as $class) {

            $invalidMessage = 'Invalid class passed to Attribution::do_validation(): ';

            // Check exists
            if (!ClassInfo::exists($class)) {
                throw new \UnexpectedValueException(
                    $invalidMessage . ' ' . $class . ' does not exist.'
                );
            }

            // Check is extended by correct extension
            if (!$class::singleton()->has_extension($extensionClass)) {
                throw new \UnexpectedValueException(
                    $invalidMessage . ' ' . $class . ' is not extended by ' . $extensionClass
                );
            }
        }

        $self = self::singleton();
        if ($self->hasMethod('doValidation')) {
            $self->doValidation($type, $classes);
        }

        return true;
    }

    public static function get_from_pair(DataObjectInterface $object, $attribute)
    {
        self::validate_attribute(get_class($attribute));

        $attribution = Attribution::get()
            ->filter([
                'ObjectClass' => $object->getClassName(),
                'ObjectID' => $object->ID,
                'AttributeClass' => $attribute->getClassName(),
                'AttributeID' => $attribute->ID
            ])
            ->first();

        return $attribution;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->AttributeKey = $this->AttributeClass . '|' . $this->AttributeID;
        $this->ObjectKey = $this->ObjectClass . '|' . $this->ObjectID;
    }
}
