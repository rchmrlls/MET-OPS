<?php

declare(strict_types=1);

function invoice_print_discount_options(): array
{
    return [
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
}

function invoice_print_customer_snapshot(array $invoice, array $customer, int $customerId): array
{
    if ((int) ($invoice['customer_id'] ?? 0) === $customerId) {
        return [
            'customer_name_snapshot' => $invoice['customer_name_snapshot'] ?? null,
            'customer_tin_snapshot' => $invoice['customer_tin_snapshot'] ?? null,
            'customer_address_snapshot' => $invoice['customer_address_snapshot'] ?? null,
            'customer_terms_snapshot' => $invoice['customer_terms_snapshot'] ?? null,
        ];
    }

    return [
        'customer_name_snapshot' => $customer['client_name'] ?? null,
        'customer_tin_snapshot' => $customer['tin'] ?? null,
        'customer_address_snapshot' => $customer['business_address'] ?? null,
        'customer_terms_snapshot' => $customer['payment_terms'] ?? null,
    ];
}

function invoice_print_money_to_cents(mixed $value): int
{
    $value = trim((string) $value);
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $value, $matches)) {
        throw new RuntimeException('An invoice amount is invalid.');
    }

    $whole = (int) $matches[1];
    $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
    return $whole * 100 + $fraction;
}

function invoice_print_cents_to_decimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function invoice_print_round_ratio(int $amount, int $numerator, int $denominator): int
{
    if ($amount < 0 || $numerator < 0 || $denominator < 1) {
        throw new InvalidArgumentException('Invalid invoice calculation values.');
    }

    $whole = intdiv($amount, $denominator);
    $remainder = $amount % $denominator;
    return ($whole * $numerator)
        + intdiv(($remainder * $numerator) + intdiv($denominator, 2), $denominator);
}

function invoice_print_round_ratio_half_down(int $amount, int $denominator): int
{
    if ($amount < 0 || $denominator < 1) {
        throw new InvalidArgumentException('Invalid invoice calculation values.');
    }

    $whole = intdiv($amount, $denominator);
    $remainder = $amount % $denominator;
    return $whole + (2 * $remainder > $denominator ? 1 : 0);
}

function calculate_invoice_print_totals(
    array $items,
    string $vatMode,
    ?string $discountName,
    string $ewtMode
): array {
    $vatModes = ['Regular', 'Non-VAT', 'Zero Rated', 'Discount'];
    if (!in_array($vatMode, $vatModes, true)) {
        throw new RuntimeException('Select a valid invoice VAT treatment.');
    }
    if (!in_array($ewtMode, ['NO EWT', '1% EWT'], true)) {
        throw new RuntimeException('Select a valid withholding tax treatment.');
    }

    $discountOptions = invoice_print_discount_options();
    if ($discountName !== null && !array_key_exists($discountName, $discountOptions)) {
        throw new RuntimeException('Select a valid invoice discount.');
    }
    if ($vatMode === 'Discount') {
        throw new RuntimeException(
            'The workbook Discount VAT treatment produces unreliable totals and cannot be saved until its business rule is confirmed.'
        );
    }
    if ($discountName !== null) {
        throw new RuntimeException(
            'The workbook discount formulas produce errors or inconsistent totals and cannot be saved until their business rules are confirmed.'
        );
    }

    $subtotal = 0;
    $nonVatSubtotal = 0;
    foreach ($items as $item) {
        $amount = invoice_print_money_to_cents($item['amount'] ?? null);
        $subtotal += $amount;
        if (($item['print_type'] ?? '') === 'Non-Vat') {
            $nonVatSubtotal += $amount;
        } elseif (!in_array(($item['print_type'] ?? ''), ['', 'Regular'], true)) {
            throw new RuntimeException('An invoice line has an invalid print tax type.');
        }
    }

    $discountRate = $discountName === null ? 0 : $discountOptions[$discountName];
    $discount = invoice_print_round_ratio($subtotal, $discountRate, 10000);
    $discountedSales = $subtotal - $discount;
    $discountedNonVat = invoice_print_round_ratio($nonVatSubtotal, 10000 - $discountRate, 10000);
    $vatAmount = 0;
    $regularSales = 0;
    $zeroRatedSales = 0;
    $nonVatSales = 0;

    if ($vatMode === 'Non-VAT') {
        $nonVatSales = $discountedSales;
    } elseif ($vatMode === 'Zero Rated') {
        $zeroRatedSales = $discountedSales;
    } elseif ($vatMode === 'Discount') {
        $regularSales = invoice_print_round_ratio($discountedSales, 100, 112);
    } else {
        $taxableSales = max(0, $discountedSales - $discountedNonVat);
        $vatAmount = invoice_print_round_ratio($taxableSales, 12, 112);
        $regularSales = $taxableSales - $vatAmount;
        $nonVatSales = $discountedNonVat;
    }

    $withholdingNumerator = match ($vatMode) {
        'Regular' => (max(0, $subtotal - $nonVatSubtotal) * 25) + ($nonVatSubtotal * 28),
        'Non-VAT' => $subtotal,
        'Zero Rated' => $subtotal,
        default => 0,
    };
    $withholdingDenominator = match ($vatMode) {
        'Regular' => 2800,
        'Non-VAT' => 100,
        'Zero Rated' => 112,
        default => 1,
    };
    $withholding = $ewtMode === '1% EWT'
        ? invoice_print_round_ratio($withholdingNumerator, 1, $withholdingDenominator)
        : 0;
    // Excel rounds the displayed EWT and the due total independently from the unrounded formula.
    $withholdingDeduction = $ewtMode === '1% EWT'
        ? invoice_print_round_ratio_half_down($withholdingNumerator, $withholdingDenominator)
        : 0;

    return [
        'subtotal' => invoice_print_cents_to_decimal($subtotal),
        'discount_amount' => invoice_print_cents_to_decimal($discount),
        'regular_sales' => invoice_print_cents_to_decimal($regularSales),
        'vat_amount' => invoice_print_cents_to_decimal($vatAmount),
        'zero_rated_sales' => invoice_print_cents_to_decimal($zeroRatedSales),
        'non_vat_sales' => invoice_print_cents_to_decimal($nonVatSales),
        'withholding_amount' => invoice_print_cents_to_decimal($withholding),
        'total_due' => invoice_print_cents_to_decimal(max(0, $discountedSales - $withholdingDeduction)),
    ];
}
