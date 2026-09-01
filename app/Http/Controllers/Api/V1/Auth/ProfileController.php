<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    //

    public function uploadAvatar(Request $request)
    {
        return $this->uploadProfileMedia($request, 'avatar');
    }

    public function uploadBanner(Request $request)
    {
        return $this->uploadProfileMedia($request, 'banner');
    }

    private function uploadProfileMedia(Request $request, string $field)
    {
        $user = $request->user();

        $request->validate([
            $field => 'sometimes|nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile($field)) {
            if ($user->{$field}) {
                Storage::disk('s3')->delete($user->{$field});
            }

            $path = $request->file($field)->store('kulsah', 's3');
            $user->{$field} = $path;
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                $field => $user->{$field},
            ],
        ]);
    }

    private function getLocationFromCountryCode($countryCode)
    {
        $country = $this->resolveCountryFromCode($countryCode);

        return $country['name'] ?? null;
    }

    private function resolveCountryFromCode($countryCode): ?array
    {
        $normalized = strtoupper(trim((string) $countryCode));
        $normalizedDial = preg_replace('/\s+/', '', $normalized);

        foreach (config('countries') as $country) {
            $countryCodeValue = strtoupper(trim((string) ($country['code'] ?? '')));
            $dialCodeValue = preg_replace('/\s+/', '', strtoupper(trim((string) ($country['dial_code'] ?? ''))));

            if ($normalized === $countryCodeValue || $normalizedDial === $dialCodeValue) {
                return $country;
            }
        }

        return null;
    }

    //update user profile

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if ($request->filled('country_code')) {
            $request->merge(['country_code' => strtoupper(trim((string) $request->input('country_code')))]);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'phone' => [
                'sometimes|nullable',
                'string',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'dob' => 'sometimes|nullable|date',
            'gender' => 'sometimes|nullable|in:male,female',
            'location' => 'sometimes|nullable|string|max:255',
            'country_code' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        $data = $request->only([
            'name',
            'username',
            'phone',
            'dob',
            'gender',
            'location',
            'country_code',
        ]);

        if (! empty($data['country_code'])) {
            $country = $this->resolveCountryFromCode($data['country_code']);

            if (! $country) {
                return response()->json([
                    'country_code' => ['The selected country code is invalid.'],
                ], 422);
            }

            $data['country'] = $country['name'];
            $data['currency'] = $country['currency'] ?? null;
            $data['location'] = $data['location'] ?? $country['name'];
        }

        $user->fill($data);
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => new UserResource($user),
        ]);
    }
}