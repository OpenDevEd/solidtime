<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Member;
use App\Models\Organization;
use App\Service\MemberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $organizationId = DB::table('external_auth_organizations')
            ->where('provider', 'google')
            ->value('organization_id');
        if (! is_string($organizationId)) {
            return;
        }

        DB::table('organizations')
            ->where('id', $organizationId)
            ->update(['currency' => 'GBP']);

        $this->createUserMappings($organizationId);
        $this->backfillProjectMembers($organizationId);
        $this->createZeroHourProjects($organizationId);
    }

    public function down(): void
    {
        // Imported activity and access data must not be deleted on rollback.
    }

    private function createUserMappings(string $organizationId): void
    {
        $mappings = [
            ['name' => 'Björn Haßler', 'email' => 'bjoern@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Taskeen Adam', 'email' => 'taskeen@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Laila Friese', 'email' => 'laila@opendeved.net', 'role' => Role::Employee],
            ['name' => 'Xuzel Villavicencio Peralta', 'email' => 'xuzel@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Nariman Moustafa', 'email' => 'nariman@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Elena Goretskaia', 'email' => 'elenaliftup@gmail.com', 'role' => Role::Employee],
            ['name' => 'Hassan Mansour', 'email' => 'hassan@opendeved.net', 'role' => Role::Owner],
            ['name' => 'Charity Kanyoza', 'email' => 'charity@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Christopher Klune', 'email' => 'christopher@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Nafisa Waziri', 'email' => 'nafisa@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Jennie Lester', 'email' => 'jennie@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Nothando Mtungwa', 'email' => 'nothando@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Gugulethu Dube', 'email' => 'gugulethu@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Madleen Frazer', 'email' => 'madleen@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Mohammed Charrad', 'email' => 'mohammed@opendeved.net', 'role' => Role::Admin],
            ['name' => 'Jill Makungu', 'email' => 'jill@opendeved.net', 'role' => Role::Employee],
        ];

        foreach ($mappings as $mapping) {
            $matches = DB::table('users')
                ->join('members', 'members.user_id', '=', 'users.id')
                ->where('members.organization_id', $organizationId)
                ->where(function ($query) use ($mapping): void {
                    $query->whereRaw('lower(users.email) = ?', [Str::lower($mapping['email'])])
                        ->orWhere('users.name', $mapping['name']);
                })
                ->select([
                    'users.id as user_id',
                    'users.name',
                    'users.email',
                    'users.is_placeholder',
                    'members.id as member_id',
                    'members.role as member_role',
                ])
                ->get();
            $emailMatches = $matches->filter(
                fn ($match): bool => Str::lower($match->email) === Str::lower($mapping['email'])
            );
            if ($emailMatches->count() === 1) {
                $match = $emailMatches->first();
            } elseif ($emailMatches->isEmpty() && $matches->count() === 1) {
                $match = $matches->first();
            } else {
                continue;
            }

            $placeholderMatches = $matches->filter(
                fn ($candidate): bool => $candidate->is_placeholder
                    && $candidate->user_id !== $match->user_id
                    && $candidate->name === $mapping['name']
            );
            if (! $match->is_placeholder && $placeholderMatches->count() === 1) {
                $this->mergePlaceholderIntoExistingMember(
                    $organizationId,
                    $placeholderMatches->first()->member_id,
                    $match->member_id,
                );
            }

            $existingMappingId = DB::table('external_auth_user_mappings')
                ->where('provider', 'google')
                ->where('email', Str::lower($mapping['email']))
                ->value('id');
            $values = [
                'user_id' => $match->user_id,
                'organization_id' => $organizationId,
                'role' => $mapping['role']->value,
                'updated_at' => now(),
            ];

            if (is_string($existingMappingId)) {
                DB::table('external_auth_user_mappings')
                    ->where('id', $existingMappingId)
                    ->update($values);
            } else {
                DB::table('external_auth_user_mappings')->insert([
                    'id' => (string) Str::uuid(),
                    'provider' => 'google',
                    'email' => Str::lower($mapping['email']),
                    'created_at' => now(),
                    ...$values,
                ]);
            }

            if (! $match->is_placeholder && $match->member_role !== Role::Owner->value) {
                DB::table('members')
                    ->where('id', $match->member_id)
                    ->update([
                        'role' => $mapping['role']->value,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('organization_invitations')
                ->where('organization_id', $organizationId)
                ->whereRaw('lower(email) = ?', [Str::lower($mapping['email'])])
                ->delete();
        }
    }

    private function mergePlaceholderIntoExistingMember(
        string $organizationId,
        string $placeholderMemberId,
        string $existingMemberId,
    ): void {
        $organization = Organization::query()->findOrFail($organizationId);
        $placeholderMember = Member::query()->with('user')->findOrFail($placeholderMemberId);
        $existingMember = Member::query()->findOrFail($existingMemberId);

        app(MemberService::class)->assignOrganizationEntitiesToDifferentMember(
            $organization,
            $placeholderMember,
            $existingMember,
        );

        $placeholderUser = $placeholderMember->user;
        $placeholderMember->delete();
        $placeholderUser->delete();
    }

    private function backfillProjectMembers(string $organizationId): void
    {
        $missingProjectMembers = DB::table('time_entries')
            ->join('members', 'members.id', '=', 'time_entries.member_id')
            ->leftJoin('project_members', function ($join): void {
                $join->on('project_members.project_id', '=', 'time_entries.project_id')
                    ->on('project_members.member_id', '=', 'time_entries.member_id');
            })
            ->where('time_entries.organization_id', $organizationId)
            ->whereNotNull('time_entries.project_id')
            ->whereNull('project_members.id')
            ->select([
                'time_entries.project_id',
                'time_entries.member_id',
                'members.user_id',
            ])
            ->distinct()
            ->get();

        $now = now();
        foreach ($missingProjectMembers->chunk(500) as $chunk) {
            DB::table('project_members')->insertOrIgnore(
                $chunk->map(fn ($projectMember): array => [
                    'id' => (string) Str::uuid(),
                    'billable_rate' => null,
                    'project_id' => $projectMember->project_id,
                    'member_id' => $projectMember->member_id,
                    'user_id' => $projectMember->user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }
    }

    private function createZeroHourProjects(string $organizationId): void
    {
        $projects = [
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q1AMJ ETH-Helpdesk'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q1AMJ ETH-World Bank ITE'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q1AMJ Hub Led Research TCPD - SUMMA - R4D'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Dissemination'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q1AMJ Y3O33_Hub-led Research 10 SL DPL — FCDO Main'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q1AMJ Y3O61_Sierra Leone IA —  Gates Main Phase II'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q2JAS 706_Dissemination FCDO MAIN'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q2JAS 97_TA - Sandbox FCDO ASEAN'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Helpdesk Climate and EdTech — FCDO Main Main Time & Materials — HDR218'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q3 OND 23_GPG Learning Products FCDO MAIN'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q3 OND 81_Data Collection IDRC Global'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q3 OND IDRC Global_Private Sector Time & Materials'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 All codes'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O22_Helpdesk — Gates Main, JFM23'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O10_Hub-led Research 2 DPL — FCDO Main'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O12_Hub-led Research 4 TPCD — IDRC'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O26_In-country engagement —  FCDO Main'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O26_In-country engagement —  Gates Main Phase II'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O3_Project Management —  FCDO Tz - Phase II'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O42_Research Topic Leads —  FCDO Main'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O21_Technical Assistance — Gates Main Phase II [C09_Malawi], JFM23'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 942_Initiatives 4'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 942_Initiatives 4'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 100_Research'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 96_TA-AI and EdTech'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 97_TA - Sandbox'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 98_MOE Helpdesk'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 99_SAMEO'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 99_SEAMEO'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 02_Consortium Management'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 11_Hub-led Research 3 GIS'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 175_HDR-WBG_Sierra_Leone'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 213_HDR_FCDO_Kenya_Caregiver_Engagement'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 225_HDR_FCDO_MENA_EiE_TLM'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 23_GPG Learning Products'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 34_Hub-led Research Cross-cutting'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 58_Cross-cutting Integrated Approach'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 60_Tanzania IA'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 67_Cross-cutting engagement'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 717_Climate Dissemination'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 83_South_Exchange'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 Y3012_Hub-led Research 4 TCPD'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 Y3O2_Consortium Management'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q4 23_GPG Learning Products'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 217_HDR_FCDO_Bangladesh_AI_Landscape'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 80_Research Design'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 812_Data collection_AI service'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 80_Research Design'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 Service Delivery'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2026/Q1 12_Hub-led Research 4 TCPD'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 12_Hub-led Research 4 TCPD'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => '2025/Q4 226_UNICEF_Thailand_OTT_Follow_On'],
            ['client' => 'EdTech Hub (R4D) (Project no. 004)', 'name' => 'Y3O65_Pakistan IA — FCDO Main [ETH-PAKISTAN-IA-FCDO]'],
            ['client' => 'OBSOLETE - Results for Development', 'name' => 'OBSOLETE - AdaptDev'],
        ];

        $clients = DB::table('clients')
            ->where('organization_id', $organizationId)
            ->whereIn('name', collect($projects)->pluck('client')->unique()->all())
            ->pluck('id', 'name');
        $now = now();

        foreach ($projects as $project) {
            $clientId = $clients->get($project['client']);
            if (! is_string($clientId)) {
                continue;
            }
            if (DB::table('projects')
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->where('name', $project['name'])
                ->exists()) {
                continue;
            }

            DB::table('projects')->insert([
                'id' => (string) Str::uuid(),
                'name' => $project['name'],
                'color' => '#'.substr(md5($project['client']."\0".$project['name']), 0, 6),
                'billable_rate' => null,
                'is_public' => false,
                'client_id' => $clientId,
                'organization_id' => $organizationId,
                'created_at' => $now,
                'updated_at' => $now,
                'is_billable' => true,
                'archived_at' => null,
                'estimated_time' => null,
                'spent_time' => 0,
            ]);
        }
    }
};
