<?php

namespace Fromholdio\Attributable\Forms;

use SilverStripe\Forms\ListboxField;
use SilverStripe\ORM\DataObjectInterface;

class AttributeListboxField extends ListboxField
{
    public function saveInto(DataObjectInterface $record)
    {
        $valueArray = $this->getValueArray();
        $fieldName = $this->getName();
        $record->{$fieldName} = implode(',', $valueArray);
    }

    public function setValue($value, $obj = null)
    {
        return parent::setValue($value, $obj);
    }

    public function loadFrom(DataObjectInterface $record)
    {
        return;
    }
}
