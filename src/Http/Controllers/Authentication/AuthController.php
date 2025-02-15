<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RiseTechApps\ApiKey\Features\AvatarGenerator;
use RiseTechApps\ApiKey\Http\Request\Authentication\LoginRequest;
use RiseTechApps\ApiKey\Http\Request\Authentication\RegisterRequest;
use RiseTechApps\ApiKey\Http\Resources\Authentication\AuthenticationMeResource;
use RiseTechApps\ApiKey\Models\Authentication;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        try {

            $data = $request->validated();

            $auth = Authentication::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $auth->apiKey()->create([
                'key' => bin2hex(random_bytes(64)),
                'active' => false
            ]);

            $profileImage = (new AvatarGenerator())->generateAvatar($data['name']);

            Storage::put('profiles/' . $auth->getKey() . '.png', $profileImage);
            $auth->addMediaFromDisk('profiles/' . $auth->getKey() . '.png')->toMediaCollection('profile');


            $auth->sendEmailVerificationNotification();

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }

    public function verifyEmail(Request $request)
    {
        try {
            $userId = $request->id;

            $user = Authentication::find($userId);

            if ($user != null) {
                $user->markEmailAsVerified();
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false]);

        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }

    public function login(LoginRequest $request)
    {
        try {
            $dataRequest = $request->validated();

            $auth = Authentication::where('email', $dataRequest['email'])->first();

            if (!$auth->hasVerifiedEmail()) {
                $auth->sendEmailVerificationNotification();
                return response()->json(['success' => false, 'email_verify' => false]);
            }

            if(auth()->attempt(['email' => $dataRequest['email'], 'password' => $dataRequest['password']])) {

                $data = AuthenticationMeResource::make(auth()->user())->jsonSerialize();
                $token = auth()->user()->createToken(auth()->user()->email);
                $data['token'] = $token->plainTextToken;

                return response()->json(['success' => true, 'data' =>  $data]);
            }

            return response()->json(['success' => false]);

        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }

    public function me(Request $request)
    {

        try {

            if(auth()->check()) {

                $data = AuthenticationMeResource::make(auth()->user())->jsonSerialize();
                return response()->json(['success' => true, 'data' =>  $data]);
            }

            return response()->json(['success' => false]);

        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }




//    public function showLogin()
//    {
//        return view('appkey::auth.login');
//    }
//
//    public function showRegister()
//    {
//        return view('appkey::auth.register');
//    }
//
//
//    public function login(LoginRequest $request)
//    {
//        $data = $request->validated();
//
//        $auth = Authentication::where('email', $data['email'])->first();
//
//        if(!$auth->hasVerifiedEmail()){
//            event(new Registered($auth));
//            session()->flash('error', 'Sua conta ainda não foi ativada, verique sua caixa de email');
//            return redirect()->back();
//
//        }
//
//        if(auth()->attempt(['email' => Str::upper($data['email']), 'password' => Str::lower($data['password'])])) {
//            $user = auth()->user();
//            return redirect()->route('dashboard');
//        }
//
//        session()->flash('error', 'Usuário ou senha incorretos');
//        return redirect()->back();
//
//    }
//
//    public function register(RegisterRequest $request)
//    {
//        $data = $request->validated();
//
//        $user = Authentication::create([
//            'name' => $data['name'],
//            'email' => $data['email'],
//            'password' => $data['password'],
//        ]);
//
//        $user->apiKey()->create([
//            'key' => bin2hex(random_bytes(64)),
//            'active' => false
//        ]);
//
//        event(new Registered($user));
//
//        auth()->login($user);
//
//        return view('appkey::auth.verification');
//    }
//
//    public function verifyEmail(Request $request)
//    {
//        $userId = $request->id;
//
//        $user = Authentication::find($userId);
//
//        if($user != null){
//            $user->markEmailAsVerified();
//            return view('appkey::auth.verification_success');
//        }
//
//        return view('appkey::auth.verification_error');
//    }
}
