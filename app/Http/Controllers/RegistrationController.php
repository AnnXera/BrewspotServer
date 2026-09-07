<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $service
    ) {}

    /**
     * POST /api/auth/validate-registration-step/{uuid?}
     */
    public function validateStep(Request $request, ?string $uuid = null): JsonResponse
    {
        $userId = $uuid ? User::where('uuid', $uuid)->value('user_id') : null;

        $step = $request->input('step');
        $rules = [];
        $messages = [];

        $data = $request->all();

        // Normalize phone numbers if present
        if (isset($data['phone_number']) && is_string($data['phone_number'])) {
            $raw = trim($data['phone_number']);
            if (!preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '63')) {
                    $digits = '0' . substr($digits, 2);
                }
                $data['phone_number'] = $digits;
            }
        }
        if (isset($data['cafe_phonenumber']) && is_string($data['cafe_phonenumber'])) {
            $raw = trim($data['cafe_phonenumber']);
            if (!preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '63')) {
                    $digits = '0' . substr($digits, 2);
                }
                $data['cafe_phonenumber'] = $digits;
            }
        }

        if ($step === 'personal' || isset($data['username']) || isset($data['phone_number'])) {
            if (isset($data['username'])) {
                $rules['username'] = [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('users', 'username')->ignore($userId, 'user_id'),
                ];
                $messages['username.required'] = 'Username is required.';
                $messages['username.unique'] = 'This username is already taken.';
            }

            if (isset($data['phone_number'])) {
                $rules['phone_number'] = [
                    'required',
                    'string',
                    'regex:/^09\d{9}$/',
                    Rule::unique('users', 'phone_number')->ignore($userId, 'user_id'),
                    Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at'),
                ];
                $messages['phone_number.required'] = 'Personal phone number is required.';
                $messages['phone_number.regex'] = 'Personal phone number must start with 09 and be 11 digits long (e.g., 09123456789).';
                $messages['phone_number.unique'] = 'This personal phone number is already registered or in use by a café.';
            }
        }

        if ($step === 'cafe' || isset($data['cafe_email']) || isset($data['cafe_phonenumber'])) {
            if (isset($data['cafe_email'])) {
                $rules['cafe_email'] = [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('cafe_branches', 'cafe_email')->whereNull('deleted_at'),
                ];
                $messages['cafe_email.required'] = 'Café email is required.';
                $messages['cafe_email.email'] = 'Please enter a valid café email address.';
                $messages['cafe_email.unique'] = 'This café email is already in use.';
            }

            if (isset($data['cafe_phonenumber'])) {
                $branchPhoneRules = [
                    'required',
                    'string',
                    'regex:/^09\d{9}$/',
                    Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at'),
                    Rule::unique('users', 'phone_number')->ignore($userId, 'user_id'),
                ];
                if (isset($data['phone_number'])) {
                    $branchPhoneRules[] = 'different:phone_number';
                    $messages['cafe_phonenumber.different'] = 'Café phone number and personal contact number must be different.';
                }
                $rules['cafe_phonenumber'] = $branchPhoneRules;
                $messages['cafe_phonenumber.required'] = 'Café phone number is required.';
                $messages['cafe_phonenumber.regex'] = 'Café phone number must start with 09 and be 11 digits long (e.g., 09123456789).';
                $messages['cafe_phonenumber.unique'] = 'This café phone number is already registered or in use by another branch.';
            }
        }

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Validation passed.',
        ], 200);
    }

    /**
     * POST /api/auth/register/{uuid}
     */
    public function register(RegisterRequest $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired registration link. No account found for this UUID.',
            ], 404);
        }

        $result = $this->service->register($user, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/auth/application/{uuid}
     */
    public function showApplication(string $uuid): JsonResponse
    {
        $result = $this->service->getApplicationDetails($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}