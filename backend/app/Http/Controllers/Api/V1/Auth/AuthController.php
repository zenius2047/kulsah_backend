<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Services\FirebaseAuthService;
use App\Models\PasswordResetOtp;
use App\Http\Resources\UserResource;
use Laravel\Socialite\Socialite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\AuthOtpMail;
use App\Mail\LoginMail;
use App\Mail\ForgotPasswordMail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Traits\HashApiToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;


class AuthController extends Controller
{

    protected $firebase;

    public function __construct(FirebaseAuthService $firebase)
    {
        $this->firebase = $firebase;
    }
    //get countries from config/countries.php
    private function getCountries()
    {
        $countries = config('countries');
        return response()->json($countries);
    }

    // get country from country code
    private function getLocationFromCountryCode($countryCode)
    {
        $countries = config('countries');
        foreach ($countries as $country) {
            if ($country['code'] === $countryCode) {
                return $country['name'];
            }
        }
        return null;
    }

public function me()
{
    $user = auth()->user();

    return response()->json([
        'data' => new UserResource($user),
    ]);
}

    // get location from user sessions payload
 private function getLocationFromIp($ip)
{
    $response = Http::timeout(5)
        ->get("https://ipinfo.io/{$ip}/json");

    if (!$response->successful()) {
        return null;
    }

    $data = $response->json();

    return collect([
        $data['city'] ?? null,
        $data['region'] ?? null,
        $data['country'] ?? null,
    ])->filter()->implode(', ');
}

    //fetch user details
    public function user(Request $request)
    {
        return response()->json($request->user());
    }
    


    
    // register new user, by email or phone number, and send OTP to email or phone number for verification

    public function register(Request $request)
    {
       // register with email or phone number
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string|min:8',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female',
            'location' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        //determine if user is registering with email or phone number
        $isEmailRegistration = $request->filled('email');
        $isPhoneRegistration = $request->filled('phone');
        

        // if is email, get country as location from user's IP address and or session, if is phone number, get country from country code, by matching country code with country code in config/countries.php, if both email and phone number are provided, prioritize email for location    
            if ($isEmailRegistration) {
                $location = $this->getLocationFromIp($request->ip());
            } elseif ($isPhoneRegistration) {
                $location = $this->getLocationFromCountryCode($request->country_code);
            } else {
                $location = null;
            }

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'dob' => $request->dob,
            'gender' => $request->gender,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'location' => $location,
        ]);


        // assign default role to user
        if($user) {
            $user->roles()->attach(3); // attach default role with id 3
        }

        // generate OTP
        $otp = $this->generateOtp();
        // save OTP to database
        DB::table('activation_otp')->insert([
            'user_id' => $user->id,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(10),
        ]);

        // send OTP to email
        if($request->email) {
            $this->sendOtpEmail($request->email, $otp, $request->name);
        }

        // send OTP to phone number
        if($request->phone) {
            $this->sendOtpSms($request->phone, $otp);
        }

        // generate access token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully. Please verify your account with the OTP sent to your email or phone number.',
            'access_token' => $token,
        ], 201);

        
        }

    //write a function to check if username exist in the database
    public function existUsername(Request $request)
{
    $request->validate([
        'username' => 'required|string|min:3'
    ]);

    $exists = User::where('username', $request->username)->exists();

    return response()->json([
        'available' => !$exists,
        'message' => $exists
            ? 'Username is already taken'
            : 'Username is available'
    ]);
}


    // manage user authentication
    // login with email and password
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:phone|email|exists:users,email',
            'phone' => 'required_without:email|string|exists:users,phone',
            'password' => 'required|string|min:8',
        ]);

        

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // i need to be activated before i can log in, so I check only the field the user supplied.
        if ($request->filled('email')) {
            $user = User::where('email', $request->email)->first();
        } else {
            $user = User::where('phone', $request->phone)->first();
        }

        if (!$user || !$user->activated) {
            return response()->json([
                'message' => 'Your account is not activated. Please check your email or phone for the OTP to activate your account.'
            ], 403);
        }

        // determine if user is logging in with email or phone number
        if ($request->filled('email')) {
            $credentials = [
                'email' => $request->email,
                'password' => $request->password,
            ];
        } else {
            $credentials = [
                'phone' => $request->phone,
                'password' => $request->password,
            ];
        }

            if (!Auth::attempt($credentials)) {
                return response()->json([
                    'message' => 'Invalid credentials'
                ], 401);
            }

        $user = Auth::user();
      
        // send OTP to email or phone number for verification
        $otp = $this->generateOtp();
        // save OTP to database
        DB::table('activation_otp')->updateOrInsert(
            ['user_id' => $user->id],
            ['otp' => $otp, 'expires_at' => now()->addMinutes(10)]
        );

        // send OTP to phone number
        if($user->phone) {
            $this->sendOtpSms($user->phone, $otp);  
        }

        // always prioritize email for location, if email is not available, use phone number to get location, if both are not available, set location to null
        if ($user->email) {
            $location = $this->getLocationFromIp($request->ip());
        } elseif ($user->phone) {
            $location = $this->getLocationFromCountryCode($user->country_code);
        } else {
            $location = null;
        }

          // send location, device and who has logged in with the a device to users email
        
          // =====================
        // BUILD DEVICE + LOGIN PAYLOAD (MISSING PIECE)
        // =====================
          $agent = new Agent();

            $payload = [
                'ip_address' => $request->ip(),
                'location' => $location,
                'login_time' => now()->toDateTimeString(),
                'device' => [
                    'browser' => $agent->browser(),
                    'platform' => $agent->platform(),
                    'device' => $agent->device() ?? 'Unknown Device',
                    'is_mobile' => $agent->isMobile(),
                ],
            ];

        // =====================
        // SEND LOGIN EMAIL ALERT
        // =====================
        if ($user->email) {
            $this->sendEmailForLogin(
                $user->email,
                $user->name,
                $payload
            );
        }
        // update user location
        DB::table('users')->where('id', $user->id)->update(['location' => $location]);

        // generate access token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User logged in successfully',
            'access_token' => $token,
            'user' => new UserResource($user),
        ]);
    }







    // activate user account with OTP
    public function activateAccount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = $request->user();
        $otpRecord = DB::table('activation_otp')->where('user_id', $user->id)->first();
        if (!$otpRecord) {
            return response()->json(['message' => 'OTP not found'], 404);
        }
        if ($otpRecord->otp !== $request->otp) {
            return response()->json(['message' => 'Invalid OTP'], 400);
        }
        if (now()->greaterThan($otpRecord->expires_at)) {
            return response()->json(['message' => 'OTP expired'], 400);
        }
        // activate user account
        DB::table('users')->where('id', $user->id)->update(['activated' => true, 'activated_at' => now()]);
        // delete OTP record
        DB::table('activation_otp')->where('user_id', $user->id)->delete();
        return response()->json([
            'message' => 'User account activated successfully',
            'user' => new UserResource($user),
            ]);
    }

    // resend OTP for account activation
    public function resendOtp(Request $request)
    {
        $user = $request->user();
        // generate new OTP
        $otp = $this->generateOtp();
        // update OTP in database
        DB::table('activation_otp')->updateOrInsert(
            ['user_id' => $user->id],
            ['otp' => $otp, 'expires_at' => now()->addMinutes(10)]
        );
        // send OTP to email
        if($user->email) {
            $this->sendOtpEmail($user->email, $otp, $user->name);
        }
        // send OTP to phone number
        if($user->phone) {
            $this->sendOtpSms($user->phone, $otp);
        }
        return response()->json(['message' => 'OTP resent successfully']);
    }


    // login with providers like gooogle,apple,facebook using provider and provider_id, if user with provider and provider_id exists, log in the user, if not, create a new user with provider and provider_id and log in the user
    // let use laravel socialite for this, but since we are building a custom authentication system, we will not use socialite's built-in authentication, instead we will use socialite to get user details from the provider and then create or log in the user in our system
        /**
     * Unified Social Login (Google / Facebook / Apple via Firebase)
     */
    public function socialLogin(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'provider' => 'required|string', // google | facebook | apple
        ]);

        // 1. Get Firebase user data
        $firebaseUser = $this->firebase->getUserData($request->token);

        $provider = $request->provider;
        $providerId = $firebaseUser['provider_id'];
        $email = $firebaseUser['email'];
        $name = $firebaseUser['name'];
        $avatar = $firebaseUser['avatar'];

        // 2. Try find user by provider + provider_id
        $user = User::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        // 3. Try link by email if not found
        if (!$user && $email) {
            $user = User::where('email', $email)->first();

            if ($user) {
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar' => $avatar,
                ]);
            }
        }

        // 4. Create user if still not found
        if (!$user) {

            $location = $this->getLocationFromIp($request->ip());

            $user = User::create([
                'name' => $name ?? 'User',
                'username' => $this->generateUsername($name ?? 'user'),
                'email' => $email,
                'password' => Hash::make(Str::random(16)),
                'location' => $location,

                // 🔥 CLEAN SOCIAL LOGIN STRUCTURE
                'provider' => $provider,
                'provider_id' => $providerId,
                'avatar' => $avatar,
            ]);

            // assign default role
            $user->roles()->attach(3);
        }

        // 5. Create Laravel token (Sanctum)
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Social login successful',
            'access_token' => $token,
            'user' => new UserResource($user),
        ]);
    }


    
    public function forgottonPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:phone|nullable|email|exists:users,email',
            'phone' => 'required_without:email|nullable|string|exists:users,phone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user = User::when($request->email, function ($query) use ($request) {
                        return $query->where('email', $request->email);
                    })
                    ->when($request->phone, function ($query) use ($request) {
                        return $query->where('phone', $request->phone);
                    })
                    ->first();

        $otp = $this->generateOtp();

        PasswordResetOtp::updateOrCreate(
            [
                'user_id' => $user->id,
            ],
            [
                'otp' => $otp,
                'expires_at' => now()->addMinutes(10),
            ]
        );

        // Send Email
        if ($request->email) {
             $this->sendResetOtp($user->email, $otp, $user->name);
        }

        // Send SMS
        if ($request->phone) {
            $message = "Your password reset OTP is {$otp}. It expires in 10 minutes.";

            // Arkesel SMS
            $this->sendOtpSms($request->phone, $otp);
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP sent successfully.'
        ]);
    }

    // reset password
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'otp' => 'required|string',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user = User::when($request->email, function ($query) use ($request) {
                        return $query->where('email', $request->email);
                    })
                    ->when($request->phone, function ($query) use ($request) {
                        return $query->where('phone', $request->phone);
                    })
                    ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $otpRecord = PasswordResetOtp::where('user_id', $user->id)
            ->where('otp', $request->otp)
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.'
            ], 400);
        }

        if ($otpRecord->expires_at->isPast()) {
            return response()->json([
                'status' => false,
                'message' => 'OTP has expired.'
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        $otpRecord->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully.'
        ]);
    }


    // logout user
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        // delete otp and otp_activate,activated field for user
        DB::table('activation_otp')->where('user_id', $request->user()->id)->delete();
        DB::table('users')->where('id', $request->user()->id)->update(['activated_at' => null, 'activated' => false]);
        return response()->json(['message' => 'Successfully logged out']);
    }

    // private function createNewToken($token)
    private function createNewToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user()
        ]);
    }

    // private function respondWithToken($token)
    private function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user()
        ]);
    }

    // private function guard()
    private function guard()
    {
        return auth()->guard();
    }

    // generate OTP
    private function generateOtp()
    {
        $otp = rand(100000, 999999);
        return $otp;
    }

    // send OTP email
    private function sendOtpEmail($email, $otp, $name)
    {
        Mail::to($email)->send(new AuthOtpMail($otp, $name));
    }

    // send reset otp
    private function sendResetOtp($email,$otp,$name){
        Mail::to($email)->send(new ForgotPasswordMail($otp,$name));
    }

    // send login reminder email
    private function sendLoginReminderEmail($email, $otp, $name)
    {
        Mail::to($email)->send(new LoginReminderMail($otp, $name));
    }

   // send another emaill after user logs in, with the device the user logs in with, location
        private function sendEmailForLogin($email, $name, $payload)
    {
        if (!$email) return;

        Mail::to($email)->send(
            new LoginMail(
                $name,
                $payload['ip_address'] ?? null,
                $payload['location'] ?? null,
                $payload['device'] ?? [],
                $payload['login_time'] ?? now()->toDateTimeString()
            )
        );
    }
    

        private function generateUsername($name)
    {
        $base = Str::slug($name);
        $username = $base;

        $count = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . $count;
            $count++;
        }

        return $username;
    }


    // send otp sms
     private function sendOtpSms($phone, $otp)
{
    $apiKey   = env('MNOTIFY_SERVICE_API_KEY');
    $senderId = "IGF LINK";

    $apiEndpoint = env('MNOTIFY_URL');

   $postData = [
    'key'       => $apiKey,
    'recipient' => [$phoneNumber],
    'message'   => $message,
    'sender' => $senderId,
    ];


    $ch = curl_init($apiEndpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 // DISABLE SSL verification (for local testing ONLY)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        \Log::error('mNotify cURL error', [
            'error' => curl_error($ch),
        ]);
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    // Log raw response
    \Log::info('mNotify raw response', [
        'response' => $response,
        'phone' => $phoneNumber,
    ]);

    // Try to decode JSON response
    $decoded = json_decode($response, true);

    // SUCCESS CONDITIONS
    if (
        $response === '2000' ||
        (is_array($decoded) && isset($decoded['code']) && $decoded['code'] == '2000')
    ) {
        return true;
    }

    // FAILURE
    \Log::warning('mNotify SMS failed', [
        'response' => $response,
    ]);

    return false;
}




}
