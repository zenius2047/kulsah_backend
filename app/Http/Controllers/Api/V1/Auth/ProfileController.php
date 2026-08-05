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

    //update user profile


public function updateProfile(Request $request)
{
    $user = $request->user();

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
        'country_code' => 'sometimes|nullable|string|max:255',
    ]);

    // Update fields safely
    $user->fill($request->only([
        'name',
        'username',
        'phone',
        'dob',
        'gender',
        'location',
        'country_code',
    ]));

    $user->save();

    return response()->json([
        'message' => 'Profile updated successfully',
        'user' => new UserResource($user),
    ]);
}
}
