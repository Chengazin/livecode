<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\ProjectTerminalSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'terminal.shared_secret' => 'test-terminal-secret',
            'terminal.gateway_ws_url' => 'ws://127.0.0.1:8090/terminal',
            'terminal.gateway_callback_secret' => 'test-terminal-callback-secret',
            'terminal.gateway_callback_header' => 'X-Terminal-Gateway-Secret',
            'terminal.max_open_sessions_per_user' => 2,
            'terminal.allow_collaborator_shared_sessions' => false,
            'terminal.allowed_shells' => ['powershell.exe', 'pwsh.exe', 'cmd.exe', '/bin/bash', '/bin/sh'],
        ]);
    }

    public function test_collaborator_can_create_private_terminal_session_and_issue_ticket(): void
    {
        $owner = $this->createUser('terminal-owner@example.com');
        $collaborator = $this->createUser('terminal-collab@example.com');
        $project = $this->createProject($owner, 'Terminal project');
        $this->addParticipant($project, $collaborator);

        Storage::disk('local')->makeDirectory($project->project_path.'/src');

        $token = $collaborator->createToken('test')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'My shell',
            'cwd' => '/src',
            'shared' => false,
            'shell' => 'powershell.exe',
        ]);

        $create->assertStatus(201)
            ->assertJsonPath('session.name', 'My shell')
            ->assertJsonPath('session.cwd', '/src')
            ->assertJsonPath('session.status', 'open')
            ->assertJsonPath('session.shared', false);

        $sessionId = (int) $create->json('session.terminal_session_id');
        $this->assertGreaterThan(0, $sessionId);

        $ticket = $this->withToken($token)->postJson(
            '/api/projects/'.$project->project_id.'/terminal/sessions/'.$sessionId.'/ticket'
        );

        $ticket->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('ws_url', 'ws://127.0.0.1:8090/terminal');

        $ticketValue = (string) $ticket->json('ticket');
        $this->assertNotSame('', $ticketValue);
        $this->assertStringContainsString('.', $ticketValue);
    }

    public function test_private_terminal_session_is_hidden_and_inaccessible_to_other_collaborator(): void
    {
        $owner = $this->createUser('terminal-owner-2@example.com');
        $alice = $this->createUser('terminal-alice@example.com');
        $bob = $this->createUser('terminal-bob@example.com');
        $project = $this->createProject($owner, 'Terminal private');

        $this->addParticipant($project, $alice);
        $this->addParticipant($project, $bob);

        $bobToken = $bob->createToken('test')->plainTextToken;
        $session = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $alice->user_id,
            'name' => 'Alice private',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);

        $sessionId = (int) $session->terminal_session_id;

        $list = $this->withToken($bobToken)->getJson('/api/projects/'.$project->project_id.'/terminal/sessions');
        $list->assertOk();

        $sessionIds = collect($list->json('sessions'))->pluck('terminal_session_id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($sessionId, $sessionIds);

        $ticket = $this->withToken($bobToken)->postJson(
            '/api/projects/'.$project->project_id.'/terminal/sessions/'.$sessionId.'/ticket'
        );

        $ticket->assertStatus(403)->assertJson(['message' => 'Access denied.']);
    }

    public function test_project_owner_can_create_shared_terminal_and_collaborator_can_use_it(): void
    {
        $owner = $this->createUser('terminal-owner-3@example.com');
        $collaborator = $this->createUser('terminal-collab-3@example.com');
        $project = $this->createProject($owner, 'Terminal shared');
        $this->addParticipant($project, $collaborator);

        $ownerToken = $owner->createToken('test')->plainTextToken;
        $collaboratorToken = $collaborator->createToken('test')->plainTextToken;

        $create = $this->withToken($ownerToken)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'Shared shell',
            'shared' => true,
        ])->assertStatus(201);

        $sessionId = (int) $create->json('session.terminal_session_id');

        $list = $this->withToken($collaboratorToken)->getJson('/api/projects/'.$project->project_id.'/terminal/sessions');
        $list->assertOk();

        $sessionIds = collect($list->json('sessions'))->pluck('terminal_session_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($sessionId, $sessionIds);

        $ticket = $this->withToken($collaboratorToken)->postJson(
            '/api/projects/'.$project->project_id.'/terminal/sessions/'.$sessionId.'/ticket'
        );

        $ticket->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_collaborator_cannot_create_shared_terminal_when_disabled_in_config(): void
    {
        $owner = $this->createUser('terminal-owner-4@example.com');
        $collaborator = $this->createUser('terminal-collab-4@example.com');
        $project = $this->createProject($owner, 'Terminal shared forbidden');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'Should fail',
            'shared' => true,
        ]);

        $response->assertStatus(403)->assertJson(['message' => 'Access denied.']);
    }

    public function test_terminal_open_session_limit_is_enforced_per_user_and_project(): void
    {
        $owner = $this->createUser('terminal-owner-5@example.com');
        $collaborator = $this->createUser('terminal-collab-5@example.com');
        $project = $this->createProject($owner, 'Terminal limits');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'A',
        ])->assertStatus(201);

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'B',
        ])->assertStatus(201);

        $third = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'C',
        ]);

        $third->assertStatus(429)->assertJson(['message' => 'Terminal session limit reached.']);
    }

    public function test_session_owner_can_close_terminal_and_it_rejects_further_ticket_requests(): void
    {
        $owner = $this->createUser('terminal-owner-6@example.com');
        $collaborator = $this->createUser('terminal-collab-6@example.com');
        $project = $this->createProject($owner, 'Terminal close');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
            'name' => 'Closable',
        ])->assertStatus(201);

        $sessionId = (int) $create->json('session.terminal_session_id');

        $close = $this->withToken($token)->postJson(
            '/api/projects/'.$project->project_id.'/terminal/sessions/'.$sessionId.'/close'
        );

        $close->assertOk()
            ->assertJsonPath('session.status', 'closed');

        $ticket = $this->withToken($token)->postJson(
            '/api/projects/'.$project->project_id.'/terminal/sessions/'.$sessionId.'/ticket'
        );

        $ticket->assertStatus(409)->assertJson(['message' => 'Terminal session is closed.']);

        $this->assertDatabaseHas('project_terminal_sessions', [
            'terminal_session_id' => $sessionId,
            'status' => 'closed',
        ]);
    }

    public function test_prune_command_deletes_only_old_closed_terminal_sessions(): void
    {
        $owner = $this->createUser('terminal-owner-prune@example.com');
        $project = $this->createProject($owner, 'Terminal prune');

        $oldClosed = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
            'name' => 'Old closed',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'closed',
            'closed_at' => now()->subHours(72),
            'last_activity_at' => now()->subHours(72),
        ]);

        $recentClosed = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
            'name' => 'Recent closed',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'closed',
            'closed_at' => now()->subHours(2),
            'last_activity_at' => now()->subHours(2),
        ]);

        $openSession = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
            'name' => 'Open session',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'open',
            'last_activity_at' => now()->subHours(72),
        ]);

        $legacyClosed = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
            'name' => 'Legacy closed',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'closed',
            'closed_at' => null,
            'last_activity_at' => now()->subHours(72),
        ]);

        ProjectTerminalSession::query()
            ->where('terminal_session_id', $legacyClosed->terminal_session_id)
            ->update(['updated_at' => now()->subHours(72)]);

        $this->artisan('terminal:sessions:prune', ['--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('project_terminal_sessions', [
            'terminal_session_id' => $oldClosed->terminal_session_id,
        ]);
        $this->assertDatabaseMissing('project_terminal_sessions', [
            'terminal_session_id' => $legacyClosed->terminal_session_id,
        ]);

        $this->assertDatabaseHas('project_terminal_sessions', [
            'terminal_session_id' => $recentClosed->terminal_session_id,
            'status' => 'closed',
        ]);
        $this->assertDatabaseHas('project_terminal_sessions', [
            'terminal_session_id' => $openSession->terminal_session_id,
            'status' => 'open',
        ]);
    }

    public function test_terminal_endpoints_reject_non_collaborator(): void
    {
        $owner = $this->createUser('terminal-owner-7@example.com');
        $intruder = $this->createUser('terminal-intruder@example.com');
        $project = $this->createProject($owner, 'Terminal locked');

        $token = $intruder->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/projects/'.$project->project_id.'/terminal/sessions')
            ->assertStatus(403)
            ->assertJson(['message' => 'Access denied.']);

        $this->withToken($token)
            ->postJson('/api/projects/'.$project->project_id.'/terminal/sessions', [
                'name' => 'Intruder shell',
            ])
            ->assertStatus(403)
            ->assertJson(['message' => 'Access denied.']);
    }

    public function test_gateway_callback_closes_terminal_session_and_stores_metadata(): void
    {
        $owner = $this->createUser('terminal-owner-8@example.com');
        $project = $this->createProject($owner, 'Terminal callback');

        $session = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
            'name' => 'Callback session',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);

        $response = $this
            ->withHeaders(['X-Terminal-Gateway-Secret' => 'test-terminal-callback-secret'])
            ->postJson('/api/terminal/gateway/sessions/'.$session->terminal_session_id.'/close', [
                'status' => 'closed',
                'reason' => 'process-exit',
                'runtime' => 'docker',
                'exit_code' => 0,
                'signal' => 15,
                'closed_at' => now()->toISOString(),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('session.status', 'closed');

        $this->assertDatabaseHas('project_terminal_sessions', [
            'terminal_session_id' => $session->terminal_session_id,
            'status' => 'closed',
        ]);

        $fresh = ProjectTerminalSession::query()->findOrFail($session->terminal_session_id);
        $meta = is_array($fresh->meta) ? $fresh->meta : [];
        $lastClose = is_array($meta['gateway_last_close'] ?? null) ? $meta['gateway_last_close'] : [];

        $this->assertSame('process-exit', (string) ($lastClose['reason'] ?? ''));
        $this->assertSame('docker', (string) ($lastClose['runtime'] ?? ''));
        $this->assertSame(0, (int) ($lastClose['exit_code'] ?? -1));
    }

    public function test_gateway_callback_requires_valid_secret_header(): void
    {
        $owner = $this->createUser('terminal-owner-9@example.com');
        $project = $this->createProject($owner, 'Terminal callback auth');

        $session = ProjectTerminalSession::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
            'name' => 'Auth session',
            'shell' => 'powershell.exe',
            'cwd' => '/',
            'shared' => false,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);

        $this->postJson('/api/terminal/gateway/sessions/'.$session->terminal_session_id.'/close', [
            'status' => 'closed',
            'reason' => 'idle-timeout',
        ])->assertStatus(401)->assertJson(['message' => 'Unauthorized.']);

        $this
            ->withHeaders(['X-Terminal-Gateway-Secret' => 'invalid-secret'])
            ->postJson('/api/terminal/gateway/sessions/'.$session->terminal_session_id.'/close', [
                'status' => 'closed',
                'reason' => 'idle-timeout',
            ])->assertStatus(401)->assertJson(['message' => 'Unauthorized.']);

        $this->assertDatabaseHas('project_terminal_sessions', [
            'terminal_session_id' => $session->terminal_session_id,
            'status' => 'open',
        ]);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test user',
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'language' => 'rus',
        ]);
    }

    private function createProject(User $owner, string $name): Project
    {
        $project = Project::query()->create([
            'name' => $name,
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/tests/'.strtolower(str_replace(' ', '-', $name)).'-'.$owner->user_id,
            'is_public' => false,
        ]);

        Storage::disk('local')->makeDirectory($project->project_path);

        return $project;
    }

    private function addParticipant(Project $project, User $user): void
    {
        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $user->user_id,
            'joined_at' => now(),
        ]);
    }
}
