<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // <-- Importante: Adiciona a classe de Log
use App\Models\User;

class AutoLoginViaToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pega o token da URL
        $requestToken = $request->query('token');

        // Se não houver token na URL, simplesmente continua para a página de login normal.
        if (!$requestToken) {
            return $next($request);
        }

        // A partir daqui, sabemos que um token foi fornecido e tentaremos o login.
        Log::info('[AutoLogin] Tentativa de login automático via token iniciada.');

        $secureToken = config('auth.magic_login_token');

        // VERIFICAÇÃO 1: O token está configurado no .env?
        if (!$secureToken) {
            Log::error('[AutoLogin] FALHA: A variável MAGIC_LOGIN_TOKEN não está configurada no arquivo .env ou o cache de configuração não foi limpo.');
            return redirect()->route('login')->withErrors(['email' => 'A funcionalidade de login automático não está configurada corretamente.']);
        }

        // VERIFICAÇÃO 2: O token da URL bate com o token do .env?
        if (!hash_equals($secureToken, $requestToken)) {
            Log::warning('[AutoLogin] FALHA: O token fornecido na URL é inválido.');
            return redirect()->route('login')->withErrors(['email' => 'Token de acesso inválido.']);
        }
        

        // VERIFICAÇÃO 3: O usuário alvo existe no banco de dados?
        $user = User::where('email', 'admin@whatsappbusiness.com')->first();
        
        if (!$user) {
            Log::error('[AutoLogin] FALHA: O usuário "admin@whatsappbusiness.com" não foi encontrado no banco de dados.');
            return redirect()->route('login')->withErrors(['email' => 'Usuário de destino para login automático não encontrado.']);
        }

        // SUCESSO!
        Log::info('[AutoLogin] SUCESSO: Token válido e usuário encontrado. Realizando login.', ['user_id' => $user->id]);
        Auth::login($user);
        
        $request->session()->regenerate();
        $tenantId = $request->query('tenant_id');
        $request->session()->put('tenant_id', $tenantId);
        $request->session()->put('token', $secureToken);
        Log::info('[AutoLogin] Tenant ID salvo na sessão.', ['user_id' => $user->id, 'tenant_id' => $tenantId]);

        return redirect()->intended('dashboard');
    }
}