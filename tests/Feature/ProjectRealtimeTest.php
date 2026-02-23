<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_collaborator_can_sync_editor_deltas_and_revision_advances(): void
    {
        $owner = $this->createUser('realtime-owner@example.com');
        $collaborator = $this->createUser('realtime-collaborator@example.com');
        $project = $this->createProject($owner, 'Realtime project');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;

        $state = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/main.js',
            'seed_content' => 'hello',
            'reset' => true,
        ]);

        $state
            ->assertOk()
            ->assertJsonPath('revision', 0)
            ->assertJsonPath('content', 'hello');

        $sync = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/main.js',
            'client_id' => 'client-a',
            'op_id' => 'op-a1',
            'base_revision' => 0,
            'start' => 5,
            'delete_count' => 0,
            'insert_text' => ' world',
        ]);

        $sync
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('operation.operation.insert_text', ' world');

        $nextState = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/main.js',
        ]);

        $nextState
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('content', 'hello world');
    }

    public function test_editor_sync_returns_conflict_with_missing_operations(): void
    {
        $owner = $this->createUser('realtime-owner-conflict@example.com');
        $collaborator = $this->createUser('realtime-collaborator-conflict@example.com');
        $project = $this->createProject($owner, 'Realtime conflict');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
            'path' => 'src/conflict.js',
            'seed_content' => 'abc',
            'reset' => true,
        ])->assertOk();

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/conflict.js',
            'client_id' => 'client-a',
            'op_id' => 'op-a1',
            'base_revision' => 0,
            'start' => 1,
            'delete_count' => 0,
            'insert_text' => 'X',
        ])->assertOk();

        $conflict = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/editor-sync', [
            'path' => 'src/conflict.js',
            'client_id' => 'client-b',
            'op_id' => 'op-b1',
            'base_revision' => 0,
            'start' => 2,
            'delete_count' => 0,
            'insert_text' => 'Y',
        ]);

        $conflict
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('requires_resync', false);

        $operations = $conflict->json('operations');
        $this->assertIsArray($operations);
        $this->assertNotEmpty($operations);
        $this->assertSame(1, (int) ($operations[0]['revision'] ?? 0));
    }

    public function test_presence_heartbeat_shares_cursor_and_selection(): void
    {
        $owner = $this->createUser('realtime-owner-presence@example.com');
        $first = $this->createUser('realtime-presence-a@example.com');
        $second = $this->createUser('realtime-presence-b@example.com');
        $project = $this->createProject($owner, 'Realtime presence');
        $this->addParticipant($project, $first);
        $this->addParticipant($project, $second);

        $firstToken = $first->createToken('test')->plainTextToken;
        $secondToken = $second->createToken('test')->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/projects/'.$project->project_id.'/realtime/heartbeat', [
            'path' => 'src/main.js',
            'cursor_row' => 4,
            'cursor_column' => 7,
            'selection_start_row' => 4,
            'selection_start_column' => 2,
            'selection_end_row' => 5,
            'selection_end_column' => 1,
        ])->assertOk();

        Auth::forgetGuards();

        $presence = $this->withToken($secondToken)
            ->getJson('/api/projects/'.$project->project_id.'/realtime/presence');

        $presence->assertOk();
        $peers = $presence->json('peers');
        $this->assertIsArray($peers);
        $this->assertCount(1, $peers);
        $this->assertSame((int) $first->user_id, (int) $peers[0]['user_id']);
        $this->assertSame(4, (int) $peers[0]['cursor_row']);
        $this->assertSame(7, (int) $peers[0]['cursor_column']);
        $this->assertSame(4, (int) $peers[0]['selection_start_row']);
        $this->assertSame(2, (int) $peers[0]['selection_start_column']);
        $this->assertSame(5, (int) $peers[0]['selection_end_row']);
        $this->assertSame(1, (int) $peers[0]['selection_end_column']);
    }

    public function test_chat_messages_are_stored_and_returned(): void
    {
        $owner = $this->createUser('realtime-owner-chat@example.com');
        $collaborator = $this->createUser('realtime-collaborator-chat@example.com');
        $project = $this->createProject($owner, 'Realtime chat');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/chat', [
            'message' => 'hello team',
        ])->assertStatus(201)->assertJsonPath('message.message', 'hello team');

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/realtime/chat', [
            'message' => 'second message',
        ])->assertStatus(201);

        $chat = $this->withToken($token)
            ->getJson('/api/projects/'.$project->project_id.'/realtime/chat');

        $chat->assertOk();
        $messages = $chat->json('messages');
        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);
        $this->assertSame('hello team', (string) $messages[0]['message']);
        $this->assertSame('second message', (string) $messages[1]['message']);
    }

    public function test_realtime_endpoints_reject_non_collaborator(): void
    {
        $owner = $this->createUser('realtime-owner-locked@example.com');
        $intruder = $this->createUser('realtime-intruder@example.com');
        $project = $this->createProject($owner, 'Realtime locked');

        $token = $intruder->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/projects/'.$project->project_id.'/realtime/editor-state', [
                'path' => 'src/main.js',
            ])
            ->assertStatus(403)
            ->assertJson(['message' => 'Access denied.']);

        $this->withToken($token)
            ->postJson('/api/projects/'.$project->project_id.'/realtime/chat', [
                'message' => 'no access',
            ])
            ->assertStatus(403)
            ->assertJson(['message' => 'Access denied.']);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test user',
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'language' => 'eng',
        ]);
    }

    private function createProject(User $owner, string $name): Project
    {
        return Project::query()->create([
            'name' => $name,
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/tests/'.strtolower(str_replace(' ', '-', $name)).'-'.$owner->user_id,
            'is_public' => false,
        ]);
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
