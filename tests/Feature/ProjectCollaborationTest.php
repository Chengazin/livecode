<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectCollaborationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_invitation_adds_participant_and_consumes_token(): void
    {
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
