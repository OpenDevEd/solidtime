<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Service\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class E2eAuthenticationController extends Controller
{
    public function __invoke(Request $request, UserService $userService): Response
    {
        $configuredToken = (string) config('app.e2e_auth_token');
        $providedToken = (string) $request->header('X-E2E-Auth-Token');
        abort_unless($configuredToken !== '' && hash_equals($configuredToken, $providedToken), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $user = $userService->createUser(
            $validated['name'],
            $validated['email'],
            $validated['password'],
            $validated['timezone'],
            Weekday::Monday,
            null,
            verifyEmail: true,
        );

        Auth::login($user);
        $request->session()->regenerate();

        return response()->noContent();
    }
}
