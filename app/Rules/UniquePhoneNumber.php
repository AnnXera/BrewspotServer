<?php

namespace App\Rules;

use App\Models\CafeBranch;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No user and no cafe branch may already have this phone. Older rows store
 * numbers as 09…/639… rather than +639…, so every equivalent form is checked.
 * Expects a normalised +639XXXXXXXXX value; format is the regex rule's job.
 */
class UniquePhoneNumber implements ValidationRule
{
    public function __construct(
        private readonly ?string $ignoreUserUuid = null,
        private readonly ?string $ignoreBranchUuid = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\+639\d{9}$/', $value)) {
            return;
        }

        $local    = substr($value, 3); // 9XXXXXXXXX
        $variants = ["+63{$local}", "63{$local}", "0{$local}", $local];

        $usedByUser = User::whereIn('phone_number', $variants)
            ->when($this->ignoreUserUuid, fn ($q) => $q->where('uuid', '!=', $this->ignoreUserUuid))
            ->exists();

        if ($usedByUser) {
            $fail('This phone number is already used by another account.');
            return;
        }

        $usedByBranch = CafeBranch::whereIn('cafe_phonenumber', $variants)
            ->when($this->ignoreBranchUuid, fn ($q) => $q->where('uuid', '!=', $this->ignoreBranchUuid))
            ->exists();

        if ($usedByBranch) {
            $fail('This phone number is already used by a cafe branch.');
        }
    }
}
