<?php

namespace Fromholdio\Attributable\Model;

use Fromholdio\Attributable\Extensions\Attributable;
use Fromholdio\Attributable\Extensions\Attribute;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\Versioned\Versioned;

class Attribution extends DataObject implements Flushable
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

    public static function get_attributes()
    {
        // retrieve from cache
        $cache = self::get_cache();
        $cacheKey = self::get_attributes_cache_key();
        if ($cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }
        return null;
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
        // retrieve from cache
        $cache = self::get_cache();
        $cacheKey = self::get_objects_cache_key();
        if ($cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }
        return null;
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
    
    /**
     * This function is triggered early in the request if the "flush" query
     * parameter has been set. Each class that implements Flushable implements
     * this function which looks after it's own specific flushing functionality.
     *
     * @see FlushMiddleware
     */
    public static function flush()
    {
        self::get_cache()->clear();
        
        // build attributes cache
        $attributes = [];
        $classes = ClassInfo::subclassesFor(DataObject::class);
        foreach ($classes as $class) {
            if ($class::has_extension(Attribute::class)) {
                self::validate_attribute($class);
                $attributes[$class] = $class;
            }
        }
        $cache = self::get_cache();
        $cacheKey = self::get_attributes_cache_key();
        $cache->set($cacheKey, $attributes);
        
        // build objects cache
        $objects = [];
        $classes = ClassInfo::subclassesFor(DataObject::class);
        foreach ($classes as $class) {
            if ($class::has_extension(Attributable::class)) {
                $classInst = $class::singleton();
                if ($classInst->hasMethod('isAttributeFilterOnly')) {
                    if ($classInst->isAttributeFilterOnly()) {
                        break;
                    }
                }
                self::validate_object($class);
                $objects[$class] = $class;
            }
        }
        $cache = self::get_cache();
        $cacheKey = self::get_objects_cache_key();
        $cache->set($cacheKey, $objects);
                
    }
    
    private static function get_cache() {
        return Injector::inst()->get(CacheInterface::class . '.AttributionCache');
    }
    
    private static function get_attributes_cache_key() {
        return implode('-', ['Attributes']);
    }
    
    private static function get_objects_cache_key() {
        return implode('-', ['Objects']);
    }
}
