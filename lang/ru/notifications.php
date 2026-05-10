<?php

return [
    'roles' => [
        'owner' => 'Владелец',
        'maintainer' => 'Мейнтейнер',
        'developer' => 'Разработчик',
        'viewer' => 'Наблюдатель',
        'editor' => 'Редактор',
        'admin' => 'Администратор',
        'member' => 'Участник',
    ],
    'templates' => [
        'invitation_received' => [
            'title' => 'Приглашение в проект',
            'message' => ':sender пригласил(а) вас в проект ":project".',
        ],
        'invitation_accepted' => [
            'title' => 'Приглашение принято',
            'message' => ':user принял(а) ваше приглашение в ":project".',
        ],
        'invitation_declined' => [
            'title' => 'Приглашение отклонено',
            'message' => ':user отклонил(а) ваше приглашение в ":project".',
        ],
        'participant_added' => [
            'title' => 'Добавление в проект',
            'message' => ':actor добавил(а) вас в ":project" с ролью ":role".',
        ],
        'participant_removed' => [
            'title' => 'Удаление из проекта',
            'message' => ':actor удалил(а) вас из ":project".',
        ],
        'role_changed' => [
            'title' => 'Роль изменена',
            'message' => ':actor изменил(а) вашу роль в ":project" с ":old_role" на ":new_role".',
        ],
        'task_reassigned' => [
            'title' => 'Задача переназначена',
            'message' => 'Задача ":task" была переназначена.',
        ],
        'task_assigned' => [
            'title' => 'Новая назначенная задача',
            'message' => 'Вам назначена задача ":task".',
        ],
        'task_completed' => [
            'title' => 'Задача выполнена',
            'message' => 'Задача ":task" отмечена как выполненная.',
        ],
        'task_reopened' => [
            'title' => 'Задача переоткрыта',
            'message' => 'Задача ":task" была переоткрыта.',
        ],
        'task_unassigned' => [
            'title' => 'Задача снята',
            'message' => 'Задача ":task" снята с вас.',
        ],
    ],
];
