<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/core/invoice_print.php';

function expect_invoice_value(string $expected, string $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
    }
}

function expect_invoice_exception(callable $operation, string $expectedMessage, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), $expectedMessage)) {
            throw new RuntimeException($label . ': unexpected message: ' . $error->getMessage());
        }
        return;
    }

    throw new RuntimeException($label . ': expected a validation error.');
}

$mixedItems = [
    ['amount' => '4080.00', 'print_type' => 'Non-Vat'],
    ['amount' => '4800.00', 'print_type' => 'Non-Vat'],
    ['amount' => '10800.00', 'print_type' => 'Non-Vat'],
    ['amount' => '4160.00', 'print_type' => 'Regular'],
];
$regularItems = array_map(
    static fn(array $item): array => ['amount' => $item['amount'], 'print_type' => 'Regular'],
    $mixedItems
);
$nonVatItems = array_map(
    static fn(array $item): array => ['amount' => $item['amount'], 'print_type' => 'Non-Vat'],
    $mixedItems
);

$regularMixed = calculate_invoice_print_totals($mixedItems, 'Regular', null, 'NO EWT');
expect_invoice_value('23840.00', $regularMixed['subtotal'], 'Mixed regular subtotal');
expect_invoice_value('3714.29', $regularMixed['regular_sales'], 'Mixed regular net sales');
expect_invoice_value('445.71', $regularMixed['vat_amount'], 'Mixed regular VAT');
expect_invoice_value('19680.00', $regularMixed['non_vat_sales'], 'Mixed non-VAT sales');
expect_invoice_value('23840.00', $regularMixed['total_due'], 'Mixed regular total due');

// The saved invoice-line Tax selection takes precedence over PRINT TYPE.
$taxColumnMixedItems = [
    ['amount' => '1000.00', 'tax_code' => 'Tax', 'print_type' => 'Non-Vat'],
    ['amount' => '2000.00', 'tax_code' => 'Non', 'print_type' => 'Regular'],
    ['amount' => '500.00', 'tax_code' => 'Tax', 'print_type' => 'Non-Vat'],
    ['amount' => '750.00', 'tax_code' => 'Exempt', 'print_type' => 'Regular'],
];
$taxColumnMixed = calculate_invoice_print_totals($taxColumnMixedItems, 'Regular', null, 'NO EWT');
expect_invoice_value('4250.00', $taxColumnMixed['subtotal'], 'Tax-column mixed subtotal');
expect_invoice_value('1339.29', $taxColumnMixed['regular_sales'], 'Tax-column taxable sales');
expect_invoice_value('160.71', $taxColumnMixed['vat_amount'], 'Tax-column mixed VAT');
expect_invoice_value('2750.00', $taxColumnMixed['non_vat_sales'], 'Tax-column non-taxable and exempt sales');
expect_invoice_value('4250.00', $taxColumnMixed['total_due'], 'Tax-column mixed total');
$taxColumnMixedEwt = calculate_invoice_print_totals($taxColumnMixedItems, 'Regular', null, '1% EWT');
expect_invoice_value('40.89', $taxColumnMixedEwt['withholding_amount'], 'Tax-column mixed EWT');
expect_invoice_value('4209.11', $taxColumnMixedEwt['total_due'], 'Tax-column mixed total after EWT');

// Existing invoice-header treatments remain authoritative outside Regular mode.
$headerNonVatMixed = calculate_invoice_print_totals($taxColumnMixedItems, 'Non-VAT', null, 'NO EWT');
expect_invoice_value('0.00', $headerNonVatMixed['vat_amount'], 'Non-VAT header mixed-line VAT');
expect_invoice_value('4250.00', $headerNonVatMixed['non_vat_sales'], 'Non-VAT header includes full invoice subtotal');
expect_invoice_value('0.00', $headerNonVatMixed['zero_rated_sales'], 'Non-VAT header zero-rated sales');

$headerZeroRatedMixed = calculate_invoice_print_totals($taxColumnMixedItems, 'Zero Rated', null, 'NO EWT');
expect_invoice_value('0.00', $headerZeroRatedMixed['vat_amount'], 'Zero Rated header mixed-line VAT');
expect_invoice_value('0.00', $headerZeroRatedMixed['non_vat_sales'], 'Zero Rated header non-VAT sales');
expect_invoice_value('4250.00', $headerZeroRatedMixed['zero_rated_sales'], 'Zero Rated header includes full invoice subtotal');

expect_invoice_exception(
    static fn(): array => calculate_invoice_print_totals($taxColumnMixedItems, 'Discount', null, 'NO EWT'),
    'Discount VAT treatment produces unreliable totals',
    'Discount header continues to reject mixed line tax values'
);

$taxableOnly = calculate_invoice_print_totals([
    ['amount' => '1000.00', 'tax_code' => 'Tax', 'print_type' => 'Non-Vat'],
    ['amount' => '500.00', 'tax_code' => 'Tax', 'print_type' => 'Regular'],
], 'Regular', null, 'NO EWT');
expect_invoice_value('160.71', $taxableOnly['vat_amount'], 'Taxable-only VAT');
expect_invoice_value('0.00', $taxableOnly['non_vat_sales'], 'Taxable-only non-taxable sales');

$nonTaxableOnly = calculate_invoice_print_totals([
    ['amount' => '1000.00', 'tax_code' => 'Non', 'print_type' => 'Regular'],
    ['amount' => '500.00', 'tax_code' => 'Exempt', 'print_type' => 'Regular'],
], 'Regular', null, 'NO EWT');
expect_invoice_value('0.00', $nonTaxableOnly['vat_amount'], 'Non-taxable-only VAT');
expect_invoice_value('1500.00', $nonTaxableOnly['non_vat_sales'], 'Non-taxable-only sales');

$changedTaxLine = calculate_invoice_print_totals([
    ['amount' => '1000.00', 'tax_code' => 'Non', 'print_type' => 'Regular'],
    ['amount' => '500.00', 'tax_code' => 'Tax', 'print_type' => 'Regular'],
], 'Regular', null, 'NO EWT');
expect_invoice_value('53.57', $changedTaxLine['vat_amount'], 'Changing one tax line leaves the other line non-taxable');
expect_invoice_value('1000.00', $changedTaxLine['non_vat_sales'], 'Changed tax classification non-taxable sales');

$regularMixedEwt = calculate_invoice_print_totals($mixedItems, 'Regular', null, '1% EWT');
expect_invoice_value('233.94', $regularMixedEwt['withholding_amount'], 'Mixed regular EWT');
expect_invoice_value('23606.06', $regularMixedEwt['total_due'], 'Mixed regular total after EWT');

$regularOnly = calculate_invoice_print_totals($regularItems, 'Regular', null, 'NO EWT');
expect_invoice_value('21285.71', $regularOnly['regular_sales'], 'Regular-only net sales');
expect_invoice_value('2554.29', $regularOnly['vat_amount'], 'Regular-only VAT');
expect_invoice_value('0.00', $regularOnly['non_vat_sales'], 'Regular-only non-VAT sales');
expect_invoice_value('23840.00', $regularOnly['total_due'], 'Regular-only total due');
$regularOnlyEwt = calculate_invoice_print_totals($regularItems, 'Regular', null, '1% EWT');
expect_invoice_value('212.86', $regularOnlyEwt['withholding_amount'], 'Regular-only EWT');
expect_invoice_value('23627.14', $regularOnlyEwt['total_due'], 'Regular-only total after EWT');

$nonVatOnly = calculate_invoice_print_totals($nonVatItems, 'Regular', null, 'NO EWT');
expect_invoice_value('0.00', $nonVatOnly['regular_sales'], 'Non-VAT-only regular sales');
expect_invoice_value('0.00', $nonVatOnly['vat_amount'], 'Non-VAT-only VAT');
expect_invoice_value('23840.00', $nonVatOnly['non_vat_sales'], 'Non-VAT-only sales');
expect_invoice_value('23840.00', $nonVatOnly['total_due'], 'Non-VAT-only total due');
$nonVatOnlyEwt = calculate_invoice_print_totals($nonVatItems, 'Regular', null, '1% EWT');
expect_invoice_value('238.40', $nonVatOnlyEwt['withholding_amount'], 'Non-VAT-only EWT');
expect_invoice_value('23601.60', $nonVatOnlyEwt['total_due'], 'Non-VAT-only total after EWT');

$zeroPricedLine = calculate_invoice_print_totals(
    [['amount' => '0.00', 'print_type' => 'Regular']],
    'Regular',
    null,
    'NO EWT'
);
expect_invoice_value('0.00', $zeroPricedLine['subtotal'], 'Zero-priced line subtotal');
expect_invoice_value('0.00', $zeroPricedLine['vat_amount'], 'Zero-priced line VAT');
expect_invoice_value('0.00', $zeroPricedLine['total_due'], 'Zero-priced line total due');

foreach ([$mixedItems, $regularItems, $nonVatItems] as $items) {
    $nonVatNoEwt = calculate_invoice_print_totals($items, 'Non-VAT', null, 'NO EWT');
    expect_invoice_value('23840.00', $nonVatNoEwt['non_vat_sales'], 'Non-VAT treatment sales');
    expect_invoice_value('23840.00', $nonVatNoEwt['total_due'], 'Non-VAT treatment total');

    $nonVatEwt = calculate_invoice_print_totals($items, 'Non-VAT', null, '1% EWT');
    expect_invoice_value('238.40', $nonVatEwt['withholding_amount'], 'Non-VAT treatment EWT');
    expect_invoice_value('23601.60', $nonVatEwt['total_due'], 'Non-VAT treatment total after EWT');

    $zeroRatedNoEwt = calculate_invoice_print_totals($items, 'Zero Rated', null, 'NO EWT');
    expect_invoice_value('23840.00', $zeroRatedNoEwt['zero_rated_sales'], 'Zero Rated sales');
    expect_invoice_value('23840.00', $zeroRatedNoEwt['total_due'], 'Zero Rated total');

    $zeroRatedEwt = calculate_invoice_print_totals($items, 'Zero Rated', null, '1% EWT');
    expect_invoice_value('238.40', $zeroRatedEwt['withholding_amount'], 'Zero Rated EWT');
    expect_invoice_value('23601.60', $zeroRatedEwt['total_due'], 'Zero Rated total after EWT');
}

$roundingFixtures = [
    ['Regular', '0.50', '0.00', '0.50'],
    ['Regular', '0.56', '0.01', '0.56'],
    ['Regular', '1.50', '0.01', '1.49'],
    ['Non-VAT', '0.50', '0.01', '0.50'],
    ['Non-VAT', '0.56', '0.01', '0.55'],
    ['Non-VAT', '1.50', '0.02', '1.49'],
    ['Zero Rated', '0.50', '0.01', '0.50'],
    ['Zero Rated', '0.56', '0.01', '0.55'],
    ['Zero Rated', '1.50', '0.02', '1.49'],
];
foreach ($roundingFixtures as [$vatMode, $amount, $expectedEwt, $expectedDue]) {
    $result = calculate_invoice_print_totals(
        [['amount' => $amount, 'print_type' => 'Regular']],
        $vatMode,
        null,
        '1% EWT'
    );
    expect_invoice_value($expectedEwt, $result['withholding_amount'], $vatMode . ' EWT at ' . $amount);
    expect_invoice_value($expectedDue, $result['total_due'], $vatMode . ' total due at ' . $amount);
}

$expectedDiscountBasisPoints = [
    'Intro Discount' => 500,
    '0.1' => 1000,
    '0.18' => 1800,
    '0.2' => 2000,
    'Distributor' => 979,
    'Supermarket' => 494,
    'Home' => 200,
    'High-Top' => 494,
    'Robinsons' => 1300,
    'LCC' => 1600,
    'Intro + 10%' => 1450,
    'Intro + 18%' => 2210,
    'Intro + 20%' => 2400,
];
if (invoice_print_discount_options() !== $expectedDiscountBasisPoints) {
    throw new RuntimeException('Workbook discount options or formula-derived rates have changed.');
}

foreach (array_keys($expectedDiscountBasisPoints) as $discountName) {
    foreach (['Regular', 'Non-VAT', 'Zero Rated', 'Discount'] as $vatMode) {
        foreach (['NO EWT', '1% EWT'] as $ewtMode) {
            $message = $vatMode === 'Discount'
                ? 'Discount VAT treatment produces unreliable totals'
                : 'discount formulas produce errors or inconsistent totals';
            expect_invoice_exception(
                static fn(): array => calculate_invoice_print_totals(
                    $mixedItems,
                    $vatMode,
                    $discountName,
                    $ewtMode
                ),
                $message,
                $vatMode . '/' . $discountName . '/' . $ewtMode
            );
        }
    }
}

foreach (['NO EWT', '1% EWT'] as $ewtMode) {
    expect_invoice_exception(
        static fn(): array => calculate_invoice_print_totals($mixedItems, 'Discount', null, $ewtMode),
        'Discount VAT treatment produces unreliable totals',
        'Discount VAT mode without a discount/' . $ewtMode
    );
}

$historicalSnapshot = invoice_print_customer_snapshot(
    [
        'customer_id' => 9,
        'customer_name_snapshot' => 'Original Customer',
        'customer_tin_snapshot' => 'TIN-OLD',
        'customer_address_snapshot' => 'Original address',
        'customer_terms_snapshot' => '30 days',
    ],
    [
        'client_name' => 'Updated Customer',
        'tin' => 'TIN-NEW',
        'business_address' => 'Updated address',
        'payment_terms' => 'COD',
    ],
    9
);
expect_invoice_value('Original Customer', $historicalSnapshot['customer_name_snapshot'], 'Historical customer name');
expect_invoice_value('TIN-OLD', $historicalSnapshot['customer_tin_snapshot'], 'Historical customer TIN');
expect_invoice_value('Original address', $historicalSnapshot['customer_address_snapshot'], 'Historical customer address');
expect_invoice_value('30 days', $historicalSnapshot['customer_terms_snapshot'], 'Historical customer terms');

$missingHistoricalSnapshot = invoice_print_customer_snapshot(
    ['customer_id' => 9],
    [
        'client_name' => 'Updated Customer',
        'tin' => 'TIN-NEW',
        'business_address' => 'Updated address',
        'payment_terms' => 'COD',
    ],
    9
);
expect_invoice_value('', (string) $missingHistoricalSnapshot['customer_name_snapshot'], 'Missing historical name stays blank');
expect_invoice_value('', (string) $missingHistoricalSnapshot['customer_tin_snapshot'], 'Missing historical TIN stays blank');

$savedProductDescription = invoice_print_product_description([
    'product_name' => 'INTERNAL PRODUCT NAME',
    'description' => 'Authoritative invoice description',
]);
expect_invoice_value('Authoritative invoice description', $savedProductDescription, 'Saved invoice description is printed without the internal product name');

$savedMultilineDescription = "Original description\nwith source newline";
expect_invoice_value(
    $savedMultilineDescription,
    invoice_print_product_description([
        'product_name' => 'INTERNAL PRODUCT NAME',
        'description' => $savedMultilineDescription,
    ]),
    'Saved description text is preserved for single-line rendering'
);

expect_invoice_value(
    'Legacy product name',
    invoice_print_product_description(['product_name' => 'Legacy product name', 'description' => '']),
    'Legacy invoice falls back to its saved product name'
);

foreach ([1, 5, 14, 15, 28, 29, 42] as $lineCount) {
    $linesForPagination = array_map(
        static fn(int $lineNumber): array => ['line_number' => $lineNumber],
        range(1, $lineCount)
    );
    $pages = invoice_print_paginate_lines($linesForPagination);
    $flattenedLines = array_merge(...$pages);
    if ($flattenedLines !== $linesForPagination) {
        throw new RuntimeException('Invoice pagination omitted, duplicated, or reordered a line at ' . $lineCount . ' items.');
    }
    if (count($pages) !== (int) ceil($lineCount / 14)) {
        throw new RuntimeException('Invoice pagination returned an unexpected page count at ' . $lineCount . ' items.');
    }
    foreach ($pages as $page) {
        if (count($page) > 14) {
            throw new RuntimeException('An invoice print page exceeded the 14-row worksheet grid.');
        }
    }
}

$changedCustomerSnapshot = invoice_print_customer_snapshot(
    ['customer_id' => 9, 'customer_name_snapshot' => 'Original Customer'],
    [
        'client_name' => 'Newly Selected Customer',
        'tin' => 'TIN-NEW',
        'business_address' => 'New address',
        'payment_terms' => 'COD',
    ],
    10
);
expect_invoice_value('Newly Selected Customer', $changedCustomerSnapshot['customer_name_snapshot'], 'Explicit customer change');

echo "Invoice print calculations and customer snapshots passed.\n";
