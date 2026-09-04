<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Service\GoogleAuthenticationService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use UnexpectedValueException;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless(config('services.google.enabled'), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(GoogleAuthenticationService $authenticationService): RedirectResponse
    {
        abort_unless(config('services.google.enabled'), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|GuzzleException) {
            return redirect('/login')->with(
                'message',
                'Google sign-in could not be completed. Please try again.'
            );
        }
        if (! $googleUser instanceof AbstractUser) {
            throw new UnexpectedValueException('Google returned an unsupported user profile.');
        }
        $rawUser = $googleUser->getRaw();
        $hostedDomain = strtolower((string) ($rawUser['hd'] ?? ''));
        $email = strtolower((string) $googleUser->getEmail());
        $emailDomain = strtolower((string) strrchr($email, '@'));
        /** @var array<int, string> $allowedDomains */
        $allowedDomains = config('services.google.allowed_domains', []);

        if (($rawUser['email_verified'] ?? false) !== true
            || $hostedDomain === ''
            || ! in_array($hostedDomain, $allowedDomains, true)
            || $emailDomain !== '@'.$hostedDomain) {
            return redirect('/login')->with(
                'message',
                'You are not authorized.'
            );
        }

        $subject = (string) $googleUser->getId();
        $name = trim((string) $googleUser->getName());
        $avatarUrl = $googleUser->getAvatar();
        abort_if($subject === '' || $name === '', 422);

        $user = $authenticationService->authenticate($subject, $email, $name, $avatarUrl);

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
