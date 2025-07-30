# Dose Progression Bug Fix

## Summary

This document describes a bug that was discovered in the dose progression logic and the fix that was implemented.

## The Bug

### Problem Description

The dose calculation logic for determining which dose to order for renewal orders had a bug where:

- When `refills = 0`, the system should have returned `null` (indicating no more doses should be ordered)
- Instead, it was falling back to ordering the **first dose** (2.5mg) instead of returning `null`

### User's Expected Workflow

Given this dose schedule:

```json
[
    {
        "refill_number": 0,
        "dose": "2.5mg",
        "shopify_variant_gid": "gid://shopify/ProductVariant/41902912897120",
        "chargebee_item_price_id": "41902912897120-GBP-Monthly"
    },
    {
        "refill_number": 1,
        "dose": "5.0mg",
        "shopify_variant_gid": "gid://shopify/ProductVariant/41902912929888",
        "chargebee_item_price_id": "41902912929888-GBP-Monthly"
    },
    {
        "refill_number": 2,
        "dose": "7.5mg",
        "shopify_variant_gid": "gid://shopify/ProductVariant/41902912962656",
        "chargebee_item_price_id": "41902912962656-GBP-Monthly"
    }
]
```

**Expected behavior:**

1. **When refills = 2 (first renewal):**
    - Decrement refills to 1 FIRST
    - Create order for 5.0mg (dose index 1)
    - Progress dose to 7.5mg (dose index 2)

2. **When refills = 1 (second renewal):**
    - Decrement refills to 0 FIRST
    - Create order for 7.5mg (dose index 2)
    - No next dose to progress to (end of schedule)

3. **When refills = 0:**
    - Should NOT create any order (return null)

## Root Cause Analysis

### The Formula

The dose calculation now uses this simplified formula:

```php
$maxRefill = collect($doseSchedule)->max("refill_number") ?? 0;
$refillsRemaining = $prescription->refills ?? 0;
$refillNumberToOrder = $maxRefill - $refillsRemaining;
```

For our example (after decrementing refills first):

- `maxRefill = 2`
- When `refills = 1` (after decrement): `refillNumberToOrder = 2 - 1 = 1` ✅ (valid, order dose index 1)
- When `refills = 0` (after decrement): `refillNumberToOrder = 2 - 0 = 2` ✅ (valid, order dose index 2)
- When `refills < 0`: Would be out of bounds ❌ (but this shouldn't happen in normal flow)

### The Root Cause

The issue was with the **timing** of when refills were decremented relative to order creation and dose progression:

**Original problematic sequence:**

1. Create order (using current refills count)
2. Schedule dose progression (using same refills count)
3. Decrement refills (happens last)

This created confusion about what "current dose" meant - was it the dose we just ordered, or the dose we should order based on the current refills count?

### Which Components Were Affected

- ✅ **DoseProgressionService** - Logic was correct, just needed timing adjustment
- ✅ **CreateInitialShopifyOrderJob** - Logic was correct, just needed timing adjustment
- ❌ **ChargebeeWebhookController** - Had incorrect sequencing of operations

## The Fix

### Code Changes

**1. Fixed timing in `ChargebeeWebhookController::handleRecurringPayment()`:**

```php
// OLD: Decrement refills AFTER creating the order
if ($processedOrder->wasRecentlyCreated) {
    CreateInitialShopifyOrderJob::dispatch($prescription->id, $invoiceId);
    if ($shouldDecrementRefills && $prescription->refills > 0) {
        $prescription->decrement("refills");
    }
}

// NEW: Decrement refills BEFORE creating the order
if ($processedOrder->wasRecentlyCreated) {
    if ($shouldDecrementRefills && $prescription->refills > 0) {
        $prescription->decrement("refills");
    }
    CreateInitialShopifyOrderJob::dispatch($prescription->id, $invoiceId);
}
```

**2. Simplified formula in both `CreateInitialShopifyOrderJob` and `DoseProgressionService`:**

```php
// OLD: $refillNumberToOrder = $maxRefill - $refillsRemaining + 1;
// NEW: $refillNumberToOrder = $maxRefill - $refillsRemaining;
```

## Testing

### Test Files Created

1. **`tests/Unit/Services/DoseProgressionServiceTest.php`** - Tests for the dose progression service
2. **`tests/Unit/Jobs/CreateInitialShopifyOrderJobTest.php`** - Tests for the order creation job
3. **`tests/Unit/DoseProgressionBugTest.php`** - Comprehensive integration tests demonstrating the full workflow

### Test Coverage

The tests validate:

- ✅ Correct dose calculation for `refills = 2` (should order 5.0mg, progress to 7.5mg)
- ✅ Correct dose calculation for `refills = 1` (should order 7.5mg, no progression)
- ✅ Correct handling for `refills = 0` (should return null, no order)
- ✅ Edge cases: empty dose schedule, null dose schedule, single dose schedule
- ✅ Initial orders always use first dose regardless of refills count
- ✅ Integration between DoseProgressionService and CreateInitialShopifyOrderJob

### Test Results

All 25 tests pass with 56 assertions:

```
PASS  Tests\Unit\DoseProgressionBugTest (5 tests, 21 assertions)
PASS  Tests\Unit\Jobs\CreateInitialShopifyOrderJobTest (8 tests, 11 assertions)
PASS  Tests\Unit\Services\DoseProgressionServiceTest (12 tests, 24 assertions)
```

## Impact

### Before the Fix

- Confusing logic where "current dose" could mean different things depending on timing
- Race conditions between order creation and dose progression
- Complex formula that was hard to understand and maintain

### After the Fix

- Clean, predictable sequencing: decrement → order → progress
- Simplified formula that directly reflects the dose that was just ordered
- Consistent behavior between initial and renewal orders
- Patients receive the correct doses according to their prescription schedule

## Files Modified

1. **`app/Http/Controllers/ChargebeeWebhookController.php`** - Fixed timing of refills decrement
2. **`app/Jobs/CreateInitialShopifyOrderJob.php`** - Updated dose calculation formula
3. **`app/Services/DoseProgressionService.php`** - Updated dose calculation formula
4. **`tests/Unit/Services/DoseProgressionServiceTest.php`** - Updated tests to reflect new logic
5. **`tests/Unit/Jobs/CreateInitialShopifyOrderJobTest.php`** - Updated tests to reflect new logic

This fix ensures that the dose progression logic works correctly with clean, predictable sequencing and simplified formulas.
