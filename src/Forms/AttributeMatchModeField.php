<?php

namespace Fromholdio\Attributable\Forms;

use SilverStripe\Forms\DropdownField;

class AttributeMatchModeField extends DropdownField
{
    public const MATCH_MODE_ANY = 0;
    public const MATCH_MODE_ALL = 1;
}
