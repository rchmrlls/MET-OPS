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
    expect_invoice_value('212.86', $zeroRatedEwt['withholding_amount'], 'Zero Rated EWT');
    expect_invoice_value('23627.14', $zeroRatedEwt['total_due'], 'Zero Rated total after EWT');
}

$roundingFixtures = [
    ['Regular', '0.50', '0.00', '0.50'],
    ['Regular', '0.56', '0.01', '0.56'],
    ['Regular', '1.50', '0.01', '1.49'],
    ['Non-VAT', '0.50', '0.01', '0.50'],
    ['Non-VAT', '0.56', '0.01', '0.55'],
    ['Non-VAT', '1.50', '0.02', '1.49'],
    ['Zero Rated', '0.50', '0.00', '0.50'],
    ['Zero Rated', '0.56', '0.01', '0.56'],
    ['Zero Rated', '1.50', '0.01', '1.49'],
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
