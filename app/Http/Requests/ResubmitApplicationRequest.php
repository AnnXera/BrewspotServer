<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Repository\RegistrationRepository;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * POST /api/auth/application/{uuid}/resubmit
 *
 * Same fields as registration. Only a rejected owner whose rejection allows
 * resubmission may send it; anyone else is refused before validation runs.
 */
class ResubmitApplicationRequest extends RegisterRequest
{
    public function authorize(): bool
    {
        $user = User::where('uuid', $this->route('uuid'))->first();

        return $user
            && $user->status === 'rejected'
            && app(RegistrationRepository::class)->findResubmittableApplication($user) !== null;
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'This application cannot be edited.',
            ], 403)
        );
    }
}
