<?php

namespace App\Rules;

use App\Models\BranchDocument;
use App\Models\CafeBranch;
use App\Support\Tin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Branch-level TIN rules. Expects a value already in Tin::FORMAT_REGEX form.
 *
 *  - Main branch: branch code must be 00000 (head office).
 *  - Side branch: same 9-digit TIN as the cafe's main branch, a branch code
 *    other than 00000, and not already used by another branch of the cafe.
 *  - Either: no other active branch (of any cafe) may already hold the full TIN.
 */
class BranchTin implements ValidationRule
{
    private function __construct(
        private readonly bool $isMain,
        private readonly ?int $cafeId = null,
        private readonly ?int $ignoreBranchId = null,
    ) {}

    public static function forMainBranch(?int $ignoreBranchId = null): self
    {
        return new self(true, null, $ignoreBranchId);
    }

    public static function forSideBranch(?int $cafeId, ?int $ignoreBranchId = null): self
    {
        return new self(false, $cafeId, $ignoreBranchId);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(Tin::FORMAT_REGEX, $value)) {
            return;
        }

        $code = Tin::branchCode($value);

        if ($this->isMain) {
            if ($code !== Tin::HEAD_OFFICE_CODE) {
                $fail('The main branch TIN must end in 00000 (head office branch code).');
                return;
            }

            $this->failIfRegisteredElsewhere($value, $fail);
            return;
        }

        if ($code === Tin::HEAD_OFFICE_CODE) {
            $fail('Branch code 00000 is reserved for the main branch. Use this branch\'s own code (e.g. 00001).');
            return;
        }

        if (! $this->cafeId) {
            return;
        }

        $mainPrefix = Tin::prefix(Tin::mainBranchTin($this->cafeId));

        if ($mainPrefix && Tin::prefix($value) !== $mainPrefix) {
            $fail('TIN must start with your main branch TIN (' . Tin::formatPrefix($mainPrefix) . ').');
            return;
        }

        $digits = preg_replace('/\D/', '', $value);

        $taken = BranchDocument::where('doc_type', 'BIR')
            ->whereIn('branch_id', CafeBranch::where('cafe_id', $this->cafeId)
                ->when($this->ignoreBranchId, fn ($q) => $q->where('branch_id', '!=', $this->ignoreBranchId))
                ->select('branch_id'))
            ->pluck('tin_number')
            ->contains(fn ($tin) => preg_replace('/\D/', '', (string) $tin) === $digits);

        if ($taken) {
            $fail('Another one of your branches already uses this TIN branch code.');
            return;
        }

        $this->failIfRegisteredElsewhere($value, $fail);
    }

    /** Mirrors the unique index on branch_documents.active_tin_number. */
    private function failIfRegisteredElsewhere(string $value, Closure $fail): void
    {
        $exists = BranchDocument::where('tin_number', $value)
            ->when($this->ignoreBranchId, fn ($q) => $q->where('branch_id', '!=', $this->ignoreBranchId))
            ->exists();

        if ($exists) {
            $fail('This TIN is already registered to another cafe branch.');
        }
    }
}
