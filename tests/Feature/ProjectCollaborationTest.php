<?php

namespace Tests\Feature;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectCollaborationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_invitation_adds_participant_and_consumes_token(): void
    {
        Event::fake();

        $owner = $this->createUser('owner-invite@example.com');
        $invitee = $this->createUser('invitee@example.com');
        $project = $this->createProject($owner, 'Invite Demo');

        $invitation = ProjectInvitation::query()->create([
            'project_id' => $project->project_id,
            'inviter_user_id' => $owner->user_id,
            'invite_token' => 'invite-token-accept-001',
            'expires_at' => now()->addDay(),
        ]);

        $token = $invitee->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/project-invitations/accept', [
            'invite_token' => 'invite-token-accept-001',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'joined')
            ->assertJsonPath('project_id', $project->project_id);

        $this->assertDatabaseHas('project_participants', [
            'project_id' => $project->project_id,
            'user_id' => $invitee->user_id,
        ]);

        $this->assertDatabaseMissing('project_invitations', [
            'invitation_id' => $invitation->invitation_id,
        ]);

        Event::assertDispatched(ProjectRealtimeEvent::class, function (ProjectRealtimeEvent $event) use ($project, $invitee): bool {
            return $event->projectId === (int) $project->project_id
                && $event->name === 'realtime.project.participants.updated'
                && ($event->payload['action'] ?? '') === 'participant_joined'
                && (int) ($event->payload['participant_user_id'] ?? 0) === (int) $invitee->user_id;
        });
    }

    public function test_collaborator_cannot_view_invitation_token_via_show_endpoint(): void
    {
        $owner = $this->createUser('owner-invite-show@example.com');
        $collaborator = $this->createUser('collaborator-invite-show@example.com');
        $project = $this->createProject($owner, 'Invite show');

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $collaborator->user_id,
            'joined_at' => now(),
        ]);

        $invitation = ProjectInvitation::query()->create([
            'project_id' => $project->project_id,
            'inviter_user_id' => $owner->user_id,
            'invite_token' => 'invite-token-show-001',
            'expires_at' => now()->addDay(),
        ]);

        $token = $collaborator->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/project-invitations/'.$invitation->invitation_id)
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Access denied.',
            ]);
    }

    public function test_projects_index_returns_owned_and_collaborator_projects(): void
    {
        $owner = $this->createUser('owner-projects@example.com');
        $collaborator = $this->createUser('collaborator-projects@example.com');

        $ownedByCollaborator = $this->createProject($collaborator, 'My own project');
        $sharedProject = $this->createProject($owner, 'Shared project');

        ProjectParticipant::query()->create([
            'project_id' => $sharedProject->project_id,
            'user_id' => $collaborator->user_id,
            'joined_at' => now(),
        ]);

        $token = $collaborator->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/projects?per_page=200');

        $response->assertOk();

        $returnedIds = collect($response->json('data'))
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($ownedByCollaborator->project_id, $returnedIds);
        $this->assertContains($sharedProject->project_id, $returnedIds);
    }

    public function test_owner_cannot_be_added_as_project_participant(): void
    {
        $owner = $this->createUser('owner-participant@example.com');
        $project = $this->createProject($owner, 'Owner project');
        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/project-participants', [
            'project_id' => $project->project_id,
            'user_id' => $owner->user_id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Owner already has access.',
            ]);
    }

    public function test_realtime_heartbeat_returns_other_online_collaborators(): void
    {
        $owner = $this->createUser('owner-realtime@example.com');
        $alice = $this->createUser('alice-realtime@example.com');
        $bob = $this->createUser('bob-realtime@example.com');
        $project = $this->createProject($owner, 'Realtime project');

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $alice->user_id,
            'joined_at' => now(),
        ]);
        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $bob->user_id,
            'joined_at' => now(),
        ]);

        $bobToken = $bob->createToken('test')->plainTextToken;

        Cache::put('project:'.$project->project_id.':realtime:presence', [
            (string) $alice->user_id => [
                'user_id' => $alice->user_id,
                'name' => $alice->name,
                'path' => 'src/main.js',
                'cursor_row' => 5,
                'cursor_column' => 2,
                'seen_at' => now()->timestamp,
            ],
        ], now()->addSeconds(30));

        $response = $this->withToken($bobToken)->postJson('/api/projects/'.$project->project_id.'/realtime/heartbeat', [
            'path' => 'src/main.js',
            'cursor_row' => 8,
            'cursor_column' => 1,
        ]);

        $response->assertOk();
        $peers = collect($response->json('peers'));
        $alicePeer = $peers->firstWhere('user_id', $alice->user_id);

        $this->assertNotNull($alicePeer);
        $this->assertSame('src/main.js', $alicePeer['path']);
        $this->assertSame(5, $alicePeer['cursor_row']);
    }

    public function test_realtime_chat_send_and_fetch_messages(): void
    {
        $owner = $this->createUser('owner-chat@example.com');
        $alice = $this->createUser('alice-chat@example.com');
        $project = $this->createProject($owner, 'Chat project');

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $alice->user_id,
            'joined_at' => now(),
        ]);

        $aliceToken = $alice->createToken('test')->plainTextToken;

        $send = $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/chat', [
            'message' => 'Hello from Alice',
        ]);

        $send->assertStatus(201)->assertJsonPath('message.message', 'Hello from Alice');
        $messageId = (int) $send->json('message.id');
        $this->assertGreaterThan(0, $messageId);

        $fetch = $this->withToken($aliceToken)->getJson('/api/projects/'.$project->project_id.'/realtime/chat?after_id=0&limit=50');
        $fetch->assertOk();

        $messages = collect($fetch->json('messages'));
        $this->assertTrue($messages->contains(fn ($item) => (int) $item['id'] === $messageId));
    }

    public function test_realtime_editor_state_seeds_document_when_missing(): void
    {
        $owner = $this->createUser('owner-editor-state@example.com');
        $alice = $this->createUser('alice-editor-state@example.com');
        $project = $this->createProject($owner, 'Editor state project');

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $alice->user_id,
            'joined_at' => now(),
        ]);

        $aliceToken = $alice->createToken('test')->plainTextToken;

        $response = $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/main.js',
            'seed_content' => 'console.log("seed");',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('path', 'src/main.js')
            ->assertJsonPath('revision', 0)
            ->assertJsonPath('content', 'console.log("seed");');
    }

    public function test_realtime_editor_sync_applies_operation_and_increments_revision(): void
    {
        $owner = $this->createUser('owner-editor-sync@example.com');
        $alice = $this->createUser('alice-editor-sync@example.com');
        $project = $this->createProject($owner, 'Editor sync project');

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $alice->user_id,
            'joined_at' => now(),
        ]);

        $aliceToken = $alice->createToken('test')->plainTextToken;

        $seed = $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/main.js',
            'seed_content' => 'abc',
        ]);
        $seed->assertOk()->assertJsonPath('revision', 0);

        $first = $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/main.js',
            'client_id' => 'client-alice',
            'op_id' => 'op-1',
            'base_revision' => 0,
            'start' => 3,
            'delete_count' => 0,
            'insert_text' => 'd',
            'cursor_row' => 0,
            'cursor_column' => 3,
        ]);

        $first->assertOk()->assertJsonPath('status', 'ok');
        $firstRevision = (int) $first->json('revision');
        $this->assertSame(1, $firstRevision);

        $second = $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/main.js',
            'client_id' => 'client-alice',
            'op_id' => 'op-2',
            'base_revision' => 1,
            'start' => 1,
            'delete_count' => 1,
            'insert_text' => 'B',
            'cursor_row' => 0,
            'cursor_column' => 1,
        ]);

        $second->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame($firstRevision + 1, (int) $second->json('revision'));

        $state = $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/main.js',
        ]);

        $state->assertOk()->assertJsonPath('content', 'aBcd');
    }

    public function test_realtime_editor_sync_returns_conflict_with_missing_operations(): void
    {
        $owner = $this->createUser('owner-editor-conflict@example.com');
        $alice = $this->createUser('alice-editor-conflict@example.com');
        $bob = $this->createUser('bob-editor-conflict@example.com');
        $project = $this->createProject($owner, 'Editor conflict project');

        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $alice->user_id,
            'joined_at' => now(),
        ]);
        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $bob->user_id,
            'joined_at' => now(),
        ]);

        $aliceToken = $alice->createToken('test')->plainTextToken;
        $bobToken = $bob->createToken('test')->plainTextToken;

        $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/main.js',
            'seed_content' => 'abc',
        ])->assertOk();

        $this->withToken($aliceToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/main.js',
            'client_id' => 'client-alice',
            'op_id' => 'op-alice-1',
            'base_revision' => 0,
            'start' => 1,
            'delete_count' => 0,
            'insert_text' => 'X',
        ])->assertOk()->assertJsonPath('revision', 1);

        $conflict = $this->withToken($bobToken)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/main.js',
            'client_id' => 'client-bob',
            'op_id' => 'op-bob-1',
            'base_revision' => 0,
            'start' => 1,
            'delete_count' => 0,
            'insert_text' => 'Y',
        ]);

        $conflict->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('requires_resync', false);

        $operations = collect($conflict->json('operations'));
        $this->assertCount(1, $operations);
        $this->assertSame('op-alice-1', (string) ($operations->first()['op_id'] ?? ''));
    }

    public function test_realtime_endpoints_reject_non_collaborator(): void
    {
        $owner = $this->createUser('owner-locked@example.com');
        $intruder = $this->createUser('intruder-locked@example.com');
        $project = $this->createProject($owner, 'Locked realtime');

        $token = $intruder->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/projects/'.$project->project_id.'/realtime/heartbeat', [
                'path' => 'src/main.js',
            ])
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Access denied.',
            ]);

        $this->withToken($token)
            ->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
                'path' => 'src/main.js',
                'client_id' => 'intruder-client',
                'op_id' => 'intruder-op',
                'base_revision' => 0,
                'start' => 0,
                'delete_count' => 0,
                'insert_text' => 'alert("oops");',
            ])
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Access denied.',
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
        return Project::query()->create([
            'name' => $name,
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/tests/'.$name.'-'.$owner->user_id,
            'is_public' => false,
        ]);
    }
}
