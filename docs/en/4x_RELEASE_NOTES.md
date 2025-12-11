# silverstripe-attributable 4.0.0 Release Notes

## Overview

Version 4.0.0 introduces fixes for attribute field processing that resolve issues with CMS field ordering, subclass handling, attribute detachment, and orphan Attribution records. This release requires SilverStripe 5.0 or later.

**For SilverStripe 5.x and 6.x implementations, this release is backwards-compatible.** The only breaking change is the removal of SilverStripe 4.x support.

## Requirements

- **SilverStripe Framework 5.x or 6.x** (SilverStripe 4.x is no longer supported)

This version uses `setDynamicData()` and `getDynamicData()` methods which were introduced in SilverStripe 5.x.

---

## The Problems

### 1. Field Ordering Dependency

The previous implementation processed attribute values immediately within `saveAttributesFieldNames()`, which is invoked when the hidden `AttributesFieldNames` field has its `saveInto()` method called during form submission.

SilverStripe processes form fields in FieldList order (following tab order). If attribute fields were moved to a tab that appears *after* the tab containing the hidden `AttributesFieldNames` field, the attribute field values would not yet be populated when `saveAttributesFieldNames()` executed.

**Result:** Attribute selections appeared to save but were silently cleared on the next page load.

### 2. Orphan Attribution Records (First Write)

When saving a new (unsaved) record, the previous implementation would call `syncAttributes()` before the parent record had been written. This created Attribution records with `ObjectID = 0`, as `$this->owner->ID` was not yet assigned.

**Result:** Orphan Attribution records accumulated in the database, unlinked to any parent object.

### 3. Subclassed Attributes Not Populating

When an Attribute class has subclasses (e.g., `Resource` with subclasses `Tip`, `Article`), Attribution records store the concrete class name. However, `populateAttributeFieldValues()` only queried for the configured base class, not its subclasses.

**Result:** CMS fields appeared empty on reload despite Attribution records existing in the database.

### 4. Attribute Detachment Orphaning Records

The `detachAttribute()` method used `$this->owner->Attributions()->remove($existing)` to remove attributions. For a `has_many` relation, `remove()` only sets the foreign key (`ObjectID`) to 0 — it doesn't delete the record.

**Result:** Orphan Attribution records with `ObjectID = 0` accumulated when users cleared attribute selections.

---

## The Solutions

### Fix 1 & 2: Deferred Attribute Processing

Attribute processing is now deferred to the `onAfterWrite()` and `onAfterSkippedWrite()` hooks using SilverStripe's dynamic data mechanism.

**How It Works:**

1. `saveAttributesFieldNames()` now stashes the field names value using `setDynamicData()` instead of processing immediately
2. `onAfterWrite()` retrieves and processes the stashed value after the record (and all form fields) have been written
3. `onAfterSkippedWrite()` handles the edge case where only attribute fields changed (no database field changes on the parent record)
4. Dynamic data is cleared immediately before processing to prevent recursion

### Fix 3: Subclass Expansion for Field Population

`populateAttributeFieldValues()` now expands each configured base class to include all its subclasses when querying Attribution records, and maps concrete classes back to their base class when populating field values.

This aligns with the existing behaviour in `getAttributes()` and `get_related_objects()`, which already used `ClassInfo::subclassesFor()` for subclass expansion.

### Fix 4: Proper Attribution Deletion

`detachAttribute()` now uses `$existing->delete()` instead of `$this->owner->Attributions()->remove($existing)`. This properly deletes the Attribution record rather than orphaning it.

For Versioned records, `delete()` removes from Draft; the `$owns` relationship handles Live cleanup when the owner publishes.

---

## Flow Comparison

### Before (3.x)

```
Form Submitted
    ↓
AttributesFieldNames.saveInto() called
    ↓
saveAttributesFieldNames() processes immediately  ← Problem: Later fields not yet populated
    ↓
syncAttributes() called                           ← Problem: $this->owner->ID may be 0
    ↓
Attribution records written
    ↓
Other form fields processed
    ↓
Parent record write()
```

### After (4.0)

```
Form Submitted
    ↓
AttributesFieldNames.saveInto() called
    ↓
saveAttributesFieldNames() stashes value in dynamic data
    ↓
All other form fields processed
    ↓
Parent record write()
    ↓
onAfterWrite() triggered                          ← $this->owner->ID is now valid
    ↓
processStoredAttributeFieldNames() called
    ↓
Dynamic data cleared (prevents recursion)
    ↓
syncAttributes() called with correct values
    ↓
Attribution records written to Draft stage
    ↓
publishRecursive() publishes Attributions via $owns
```

---

## Backwards Compatibility

For **SilverStripe 5.x and 6.x** implementations:

- **API unchanged:** All public methods retain their existing signatures
- **Configuration unchanged:** No changes to `$allowed_attributes`, `$attributes_tab_path`, or other config
- **Behaviour unchanged:** Attributes are still synced on save; the only difference is *when* during the save cycle

The timing change (post-write vs pre-write) is a bug fix. Any code that relied on Attribution records existing before `onBeforeWrite()` completed was depending on unintended behaviour.

---

## Orphan Record Cleanup

If you have been using previous versions, you may have orphan Attribution records from the first-write bug. These can be identified and removed:

```sql
-- Identify orphans
SELECT * FROM Attribution WHERE ObjectID = 0;

-- Remove orphans (run on both Draft and Live if using separate databases)
DELETE FROM Attribution WHERE ObjectID = 0;
DELETE FROM Attribution_Live WHERE ObjectID = 0;
DELETE FROM Attribution_Versions WHERE ObjectID = 0;
```

Review the results of the SELECT query before deleting to confirm these are indeed orphan records.

---

## Upgrade Path

1. Ensure your project is running SilverStripe 5.0 or later
2. Update your composer requirement: `"fromholdio/silverstripe-attributable": "^4.0"`
3. Run `composer update fromholdio/silverstripe-attributable`
4. Optionally, clean up orphan records using the SQL above
5. Test attribute field saving in your CMS
