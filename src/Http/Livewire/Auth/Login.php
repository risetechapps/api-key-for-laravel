<?php

namespace RiseTechApps\ApiKey\Http\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Login extends Component
{

    public $email;
    public $password;
    public $remember = false;

    protected $rules = [
        'email' => 'required|email',
        'password' => 'required|min:6',
    ];

    public function login()
    {
        $this->validate();

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            return redirect()->intended('/dashboard');
        } else {
            session()->flash('error', 'Credenciais inválidas!');
        }
    }

    public function render()
    {
        return view('appkey::auth.login');
    }
}
