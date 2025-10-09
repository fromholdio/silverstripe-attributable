<?php

namespace Fromholdio\Attributable\Forms;

use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FormField;
use SilverStripe\View\Requirements;

class AttributeMatchField extends FieldGroup
{
    protected string $attrFieldName;
    protected string $attrMatchModeFieldName;

    public function __construct(string $name, FormField $attrField, string $attrMatchModeFieldName)
    {
        $title = $attrField->Title();
        $attrFieldName = $attrField->getName();
        $attrField->setTitle(false);
        $this->attrFieldName = $attrFieldName;
        $this->attrMatchModeFieldName = $attrMatchModeFieldName;
        $attrMatchModeField = $this->generateAttributeMatchModeField();
        parent::__construct($title, [$attrField, $attrMatchModeField]);
        $this->setName($name);
        Requirements::css('fromholdio/silverstripe-attributable: client/css/attributable.css');
    }

    public function setMatchModeAll(): self
    {
        $this->getAttributeMatchModeField()->setValue(1);
        return $this;
    }

    public function getAttributeField(): FormField
    {
        return $this->FieldList()->fieldByName($this->attrFieldName);
    }

    public function getAttributeMatchModeField(): AttributeMatchModeField
    {
        /** @var AttributeMatchModeField $field */
        $field = $this->FieldList()->fieldByName($this->attrMatchModeFieldName);
        return $field;
    }

    protected function generateAttributeMatchModeField(): AttributeMatchModeField
    {
        $attrMatchModeFieldName = $this->attrMatchModeFieldName;
        $field = AttributeMatchModeField::create(
            $attrMatchModeFieldName,
            false, [
                AttributeMatchModeField::MATCH_MODE_ANY => 'Match any',
                AttributeMatchModeField::MATCH_MODE_ALL => 'Match all'
            ]
        );
        $field->setHasEmptyDefault(false);
        return $field;
    }
}
