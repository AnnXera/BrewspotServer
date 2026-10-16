<?php

namespace App\Support;

use App\Models\BranchDocument;
use App\Models\CafeBranch;

/**
 * BIR TIN helpers. A TIN is XXX-XXX-XXX-BBBBB: the first 9 digits identify the
 * taxpayer (shared by every branch of the business) and the last 5 are the
 * branch code, where 00000 is the head office (our main branch).
 */
class Tin
{
    public const FORMAT_REGEX = '/^\d{3}-\d{3}-\d{3}-\d{5}$/';

    public const FORMAT_MESSAGE = 'TIN must follow the format XXX-XXX-XXX-XXXXX (9-digit TIN + 5-digit branch code).';

    public const HEAD_OFFICE_CODE = '00000';

    /**
     * Formats 14 digits as XXX-XXX-XXX-XXXXX. Anything else is returned as-is
     * so the format rule can reject it.
     */
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 14) {
            return trim($value);
        }

        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-'
            . substr($digits, 6, 3) . '-' . substr($digits, 9, 5);
    }

    /** First 9 digits, ignoring dashes/spaces. Works for older 12-digit TINs too. */
    public static function prefix(?string $tin): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $tin);

        return strlen($digits) >= 9 ? substr($digits, 0, 9) : null;
    }

    public static function branchCode(string $tin): string
    {
        return substr(preg_replace('/\D/', '', $tin), 9);
    }

    public static function formatPrefix(string $prefix): string
    {
        return substr($prefix, 0, 3) . '-' . substr($prefix, 3, 3) . '-' . substr($prefix, 6, 3);
    }

    /** The main branch's BIR TIN for a cafe, if one is on file. */
    public static function mainBranchTin(int $cafeId): ?string
    {
        $mainBranchId = CafeBranch::where('cafe_id', $cafeId)
            ->where('branch_type', 'main')
            ->value('branch_id');

        if (! $mainBranchId) {
            return null;
        }

        return BranchDocument::where('branch_id', $mainBranchId)
            ->where('doc_type', 'BIR')
            ->value('tin_number');
    }
}
