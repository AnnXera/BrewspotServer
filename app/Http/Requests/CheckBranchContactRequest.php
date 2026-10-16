<?php

namespace App\Http\Requests;

/**
 * Validates only the contact fields of StoreBranchRequest, so the add-branch
 * form can flag a taken email or phone before the owner reaches the BIR step.
 */
class CheckBranchContactRequest extends StoreBranchRequest
{
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['cafe_email', 'cafe_phonenumber']));
    }
}
