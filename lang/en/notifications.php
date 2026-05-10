<?php

return [
    'roles' => [
        'owner' => 'Owner',
        'maintainer' => 'Maintainer',
        'developer' => 'Developer',
        'viewer' => 'Viewer',
        'editor' => 'Editor',
        'admin' => 'Admin',
        'member' => 'Member',
    ],
    'templates' => [
        'invitation_received' => [
            'title' => 'Project Invitation',
            'message' => ':sender invited you to join the project ":project".',
        ],
        'invitation_accepted' => [
            'title' => 'Invitation Accepted',
            'message' => ':user accepted your invitation to ":project".',
        ],
        'invitation_declined' => [
            'title' => 'Invitation Declined',
            'message' => ':user declined your invitation to ":project".',
        ],
        'participant_added' => [
            'title' => 'Added to Project',
            'message' => ':actor added you to ":project" as :role.',
        ],
        'participant_removed' => [
            'title' => 'Removed from Project',
            'message' => ':actor removed you from ":project".',
        ],
        'role_changed' => [
            'title' => 'Role Changed',
            'message' => ':actor changed your role in ":project" from :old_role to :new_role.',
        ],
        'task_reassigned' => [
            'title' => 'Task Reassigned',
            'message' => 'Task ":task" has been reassigned.',
        ],
        'task_assigned' => [
            'title' => 'New Task Assigned',
            'message' => 'Task ":task" has been assigned to you.',
        ],
        'task_completed' => [
            'title' => 'Task Completed',
            'message' => 'Task ":task" has been completed.',
        ],
        'task_reopened' => [
            'title' => 'Task Reopened',
            'message' => 'Task ":task" has been reopened.',
        ],
        'task_unassigned' => [
            'title' => 'Task Unassigned',
            'message' => 'Task ":task" has been unassigned from you.',
        ],
    ],
];
