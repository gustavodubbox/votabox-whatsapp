<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class WebAuthController extends Controller
{
    /**
     * Exibe a página de login. O middleware AutoLoginViaToken irá interceptar
     * esta requisição se um token válido for fornecido.
     */
    public function showLoginPage()
    {
        return Inertia::render('Auth/Login');
    }
}