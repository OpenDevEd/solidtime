<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Weekday;
use App\Service\Dto\UserAgentDto;
use App\Service\TimezoneService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    /**
     * Show the general profile settings screen.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Profile/Show', [
            'timezones' => app(TimezoneService::class)->getSelectOptions(),
            'weekdays' => Weekday::toSelectArray(),
            'sessions' => $this->sessions($request),
        ]);
    }

    /**
     * Get the current sessions.
     *
     * @return array<int, object{agent: array{is_desktop: bool, platform: string|null, browser: string|null}, ip_address: string, is_current_device: bool, last_active: string}&\stdClass>
     */
    public function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return collect(
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->orderBy('last_activity', 'desc')
                ->get()
        )->map(function (object $session) use ($request): object {
            $agent = $this->createAgent(is_string($session->user_agent) ? $session->user_agent : '');

            return (object) [
                'agent' => [
                    'is_desktop' => $agent->isDesktop(),
                    'platform' => $agent->platform(),
                    'browser' => $agent->browser(),
                ],
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : '',
                'is_current_device' => $session->id === $request->session()->getId(),
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            ];
        })->all();
    }

    /**
     * Create a new agent instance from the given session.
     */
    protected function createAgent(string $userAgent): UserAgentDto
    {
        return tap(new UserAgentDto, fn ($agent) => $agent->setUserAgent($userAgent));
    }
}
